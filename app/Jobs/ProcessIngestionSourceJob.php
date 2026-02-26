<?php

namespace App\Jobs;

use App\Models\Bookmark;
use App\Models\IngestionSource;
use App\Models\KnowledgeItem;
use App\Events\IngestionSourceDeduped;
use App\Jobs\InferContextFolderJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Ingestion\IngestionContentResolver;
use App\Services\Ingestion\QualityScorer;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ai\FolderEmbeddingScheduler;

class ProcessIngestionSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $ingestionSourceId,
        public bool $force = false,
        public ?array $folderIds = null,
        public ?string $folderCreatedBy = null,
        public bool $runSync = false,
    ) {}

    public function handle(): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('ProcessIngestionSourceJob:' . $this->ingestionSourceId, [
            'ingestion_source_id' => $this->ingestionSourceId,
            'force' => $this->force,
        ]);

        $src = IngestionSource::find($this->ingestionSourceId);
        if (!$src) {
            $logger->flush('not_found');
            return;
        }

        // Best-effort: attach folder boundaries (context scoping)
        try {
            $folderIds = array_values(array_filter(array_map('strval', (array) ($this->folderIds ?? [])), fn($v) => $v !== ''));
            if (!empty($folderIds) && method_exists($src, 'folders') && Schema::hasTable('ingestion_source_folders')) {
                $attach = [];
                $createdBy = $this->folderCreatedBy ?: (string) ($src->user_id ?? '');
                foreach ($folderIds as $fid) {
                    $attach[$fid] = [
                        'created_by' => $createdBy !== '' ? $createdBy : null,
                        'created_at' => now(),
                    ];
                }
                $src->folders()->syncWithoutDetaching($attach);
                $logger->capture('folders.attached', ['folder_ids' => $folderIds, 'created_by' => $createdBy !== '' ? $createdBy : null]);
                try {
                    app(FolderEmbeddingScheduler::class)->markStaleAndSchedule($folderIds, (string) $src->organization_id);
                } catch (\Throwable) {
                    // non-fatal
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal; ingestion continues
            $logger->capture('folders.attach_failed', ['error' => $e->getMessage()]);
        }

        $src->status = 'processing';
        $src->error = null;
        $src->save();
        $logger->capture('status_processing', ['source' => $src->toArray()]);

        try {
            $resolver = app(IngestionContentResolver::class);
            $completed = false;

            switch ($src->source_type) {
                case 'bookmark':
                    $logger->capture('branch', ['type' => 'bookmark']);
                    $completed = $this->processBookmark($src, $logger, $resolver);
                    break;
                case 'text':
                    $logger->capture('branch', ['type' => 'text']);
                    $completed = $this->processText($src, $logger);
                    break;
                default:
                    throw new \RuntimeException('Unsupported source_type: '.$src->source_type);
            }

            if ($completed) {
                $src->status = 'completed';
                $src->save();
                $logger->flush('completed', ['source' => $src->toArray()]);
            }
        } catch (\Throwable $e) {
            $src->status = 'failed';
            $src->error = $e->getMessage();
            $src->save();
            Log::warning('ingestion.failed', ['id' => $src->id, 'type' => $src->source_type, 'error' => $e->getMessage()]);
            $logger->flush('error', ['error' => $e->getMessage()]);
        }
    }

    protected function processBookmark(IngestionSource $src, ContentGenBatchLogger $logger, IngestionContentResolver $resolver): bool
    {
        if (!$src->source_id) throw new \InvalidArgumentException('bookmark source requires source_id');
        $bookmark = Bookmark::find($src->source_id);
        if (!$bookmark) throw new \RuntimeException('Bookmark not found');

        // Resolve content strictly from internal models (no HTTP)
        $raw = $resolver->resolve($src);
        if ($raw === null || trim($raw) === '') {
            // Crash the job in line with Phase 2.1.2 contract
            throw new \RuntimeException('No internal content for ingestion source');
        }

        // AI folder inference (best-effort; never blocks ingestion)
        try { dispatch(new InferContextFolderJob((string) $src->id)); } catch (\Throwable) {}

        $hash = hash('sha256', $this->normalizeForHash($raw));
        // For eval harness runs, isolate KnowledgeItem uniqueness by namespacing
        // the content hash with the evaluation_run_id. This prevents DB-level
        // dedup collisions when --force is used.
        try {
            $eid = (string) ($src->metadata['evaluation_run_id'] ?? '');
        } catch (\Throwable) { $eid = ''; }
        if (($src->origin ?? '') === 'eval_harness' && $eid !== '') {
            $hash = hash('sha256', $this->normalizeForHash($raw) . '::eval:' . $eid);
        }
        $canonical = KnowledgeItem::query()
            ->where('organization_id', $src->organization_id)
            ->where('raw_text_sha256', $hash)
            ->first();
        $logger->capture('bookmark.dedup_check', [
            'hash' => $hash,
            'canonical_ki' => $canonical?->id,
            'force' => $this->force,
        ]);
        if ($canonical && !$this->force) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'knowledge_item_id')) {
                $src->knowledge_item_id = $canonical->id;
            }
            $src->status = 'completed';
            $src->dedup_reason = 'knowledge_item_duplicate';
            $src->save();
            try { IngestionSourceDeduped::dispatch($src->id, 'knowledge_item_duplicate', $canonical->id); } catch (\Throwable) {}
            Log::info('ingestion.dedup', [
                'ingestion_source_id' => $src->id,
                'canonical_knowledge_item_id' => $canonical->id,
                'dedup_reason' => 'knowledge_item_duplicate',
                'force' => false,
            ]);
            $logger->capture('bookmark.dedup_skipped', ['canonical_ki' => $canonical->id]);
            return false;
        }

        try {
        $item = KnowledgeItem::create([
            'organization_id' => $src->organization_id,
            'user_id' => $src->user_id,
            'ingestion_source_id' => $src->id,
            'type' => 'excerpt',
            'source' => 'bookmark',
            'source_id' => $bookmark->id,
            'source_platform' => $bookmark->platform ?? null,
            'title' => (string) ($bookmark->title ?: ''),
            'raw_text' => $raw,
            'raw_text_sha256' => $hash,
            'metadata' => [
                'source_url' => $bookmark->url,
                'image_url' => $bookmark->image_url,
                'favicon_url' => $bookmark->favicon_url,
            ],
            'confidence' => 0.3,
            'ingested_at' => now(),
        ]);

        if (trim((string) $item->raw_text) === '') {
            throw new \RuntimeException('InvariantViolation: KnowledgeItem created without raw_text');
        }

        // Compute and persist quality metrics on the ingestion source
        try {
            $quality = app(QualityScorer::class)->score($raw);
            $src->quality_score = (float) ($quality['overall'] ?? 0.0);
            // Persist full report if column exists
            if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'quality')) {
                $src->quality = $quality;
            }
            $src->save();
            $logger->capture('quality.scored', ['quality' => $quality]);
        } catch (\Throwable $e) {
            // Non-fatal
            $logger->capture('quality.error', ['error' => $e->getMessage()]);
        }

        if ($this->runSync) {
            dispatch_sync(new NormalizeKnowledgeItemJob($item->id));
            dispatch_sync(new ChunkKnowledgeItemJob($item->id));
            dispatch_sync(new EmbedKnowledgeChunksJob($item->id));
            dispatch_sync(new ExtractVoiceTraitsJob($item->id));
            dispatch_sync(new ExtractBusinessFactsJob($item->id));
            $logger->capture('jobs.sync_executed', ['knowledge_item_id' => $item->id]);
        } else {
            Bus::chain([
                new NormalizeKnowledgeItemJob($item->id),
                new ChunkKnowledgeItemJob($item->id),
                new EmbedKnowledgeChunksJob($item->id),
                new ExtractVoiceTraitsJob($item->id),
                new ExtractBusinessFactsJob($item->id),
            ])->dispatch();
            $logger->capture('jobs.chained', ['knowledge_item_id' => $item->id]);
        }
        } catch (\Illuminate\Database\QueryException $e) {
            $code = (string) $e->getCode();
            // Handle unique constraint violation for MySQL (23000) and Postgres (23505)
            if ($code === '23000' || $code === '23505') {
                $canonical = KnowledgeItem::where('organization_id', $src->organization_id)
                    ->where('raw_text_sha256', $hash)
                    ->first();
                if ($canonical) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'knowledge_item_id')) {
                        $src->knowledge_item_id = $canonical->id;
                    }
                    $src->status = 'completed';
                    $src->dedup_reason = 'knowledge_item_duplicate';
                    $src->save();
                    return false;
                }
            }
            throw $e;
        }
        // Processing succeeded; caller will mark as completed
        return true;
    }

    protected function processText(IngestionSource $src, ContentGenBatchLogger $logger): bool
    {
        $raw = trim((string) $src->raw_text);
        // Enforce invariant: must have content
        if ($raw === '') {
            throw new \RuntimeException('No internal content for ingestion source');
        }

        // AI folder inference (best-effort; never blocks ingestion)
        try { dispatch(new InferContextFolderJob((string) $src->id)); } catch (\Throwable) {}
        $hash = hash('sha256', $this->normalizeForHash($raw));
        // Debug: log eval harness metadata and hash inputs
        try {
            $meta = is_array($src->metadata) ? $src->metadata : [];
        } catch (\Throwable) { $meta = []; }
        \Illuminate\Support\Facades\Log::info('eval.ingestion.meta', [
            'origin' => $src->origin,
            'metadata' => $meta,
        ]);
        // For eval harness runs, isolate KnowledgeItem uniqueness by namespacing the hash.
        try {
            $eid = (string) ($src->metadata['evaluation_run_id'] ?? '');
        } catch (\Throwable) { $eid = ''; }
        if (($src->origin ?? '') === 'eval_harness' && $eid !== '') {
            $hash = hash('sha256', $this->normalizeForHash($raw) . '::eval:' . $eid);
        }
        \Illuminate\Support\Facades\Log::info('eval.ingestion.hash', [
            'namespaced' => (($src->origin ?? '') === 'eval_harness' && ($eid ?? '') !== ''),
            'hash' => $hash,
        ]);
        $canonical = KnowledgeItem::query()
            ->where('organization_id', $src->organization_id)
            ->where('raw_text_sha256', $hash)
            ->first();
        $logger->capture('text.dedup_check', ['hash' => $hash, 'canonical_ki' => $canonical?->id, 'force' => $this->force]);
        if ($canonical && !$this->force) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'knowledge_item_id')) {
                $src->knowledge_item_id = $canonical->id;
            }
            $src->status = 'completed';
            $src->dedup_reason = 'knowledge_item_duplicate';
            $src->save();
            try { IngestionSourceDeduped::dispatch($src->id, 'knowledge_item_duplicate', $canonical->id); } catch (\Throwable) {}
            Log::info('ingestion.dedup', [
                'ingestion_source_id' => $src->id,
                'canonical_knowledge_item_id' => $canonical->id,
                'dedup_reason' => 'knowledge_item_duplicate',
                'force' => false,
            ]);
            $logger->capture('text.dedup_skipped', ['canonical_ki' => $canonical->id]);
            return false;
        }

        try {
        $item = KnowledgeItem::create([
            'organization_id' => $src->organization_id,
            'user_id' => $src->user_id,
            'ingestion_source_id' => $src->id,
            'type' => 'note',
            'source' => 'manual',
            'title' => null,
            'raw_text' => $raw,
            'raw_text_sha256' => $hash,
            'metadata' => null,
            'confidence' => 0.6,
            'ingested_at' => now(),
        ]);

        if (trim((string) $item->raw_text) === '') {
            throw new \RuntimeException('InvariantViolation: KnowledgeItem created without raw_text');
        }

        // Compute and persist quality metrics on the ingestion source
        try {
            $quality = app(QualityScorer::class)->score($raw);
            $src->quality_score = (float) ($quality['overall'] ?? 0.0);
            if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'quality')) {
                $src->quality = $quality;
            }
            $src->save();
            $logger->capture('quality.scored', ['quality' => $quality]);
        } catch (\Throwable $e) {
            // Non-fatal
            $logger->capture('quality.error', ['error' => $e->getMessage()]);
        }

        // For eval harness runs, execute Phase 0 pipeline synchronously
        if (($src->origin ?? '') === 'eval_harness') {
            try {
                (new NormalizeKnowledgeItemJob($item->id))->handle(app(\App\Services\Ai\LLMClient::class));
                (new ChunkKnowledgeItemJob($item->id))->handle();
                (new EmbedKnowledgeChunksJob($item->id))->handle(app(\App\Services\Ai\EmbeddingsService::class));
                $logger->capture('phase0.sync_completed', ['knowledge_item_id' => $item->id]);
            } catch (\Throwable $e) {
                $logger->capture('phase0.sync_error', ['error' => $e->getMessage()]);
            }
            // Dispatch remaining enrichment jobs asynchronously
            Bus::chain([
                new ExtractVoiceTraitsJob($item->id),
                new ExtractBusinessFactsJob($item->id),
            ])->dispatch();
            $logger->capture('jobs.chained_enrichment', ['knowledge_item_id' => $item->id]);
        } else {
            // Default async pipeline
            Bus::chain([
                new NormalizeKnowledgeItemJob($item->id),
                new ChunkKnowledgeItemJob($item->id),
                new EmbedKnowledgeChunksJob($item->id),
                new ExtractVoiceTraitsJob($item->id),
                new ExtractBusinessFactsJob($item->id),
            ])->dispatch();
            $logger->capture('jobs.chained', ['knowledge_item_id' => $item->id]);
        }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                $canonical = KnowledgeItem::where('organization_id', $src->organization_id)
                    ->where('raw_text_sha256', $hash)
                    ->first();
                if ($canonical) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('ingestion_sources', 'knowledge_item_id')) {
                        $src->knowledge_item_id = $canonical->id;
                    }
                    $src->status = 'completed';
                    $src->dedup_reason = 'knowledge_item_duplicate';
                    $src->save();
                    return false;
                }
            }
            throw $e;
        }
        // Processing succeeded; caller will mark as completed
        return true;
    }

    private function normalizeForHash(string $text): string
    {
        $t = trim($text);
        // Collapse whitespace and normalize line endings for stable hashing
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return $t;
    }
}
