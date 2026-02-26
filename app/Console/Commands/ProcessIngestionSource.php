<?php

namespace App\Console\Commands;

use App\Jobs\ChunkKnowledgeItemJob;
use App\Jobs\ExtractBusinessFactsJob;
use App\Jobs\NormalizeKnowledgeItemJob;
use App\Jobs\ProcessIngestionSourceJob;
use App\Models\BusinessFact;
use App\Models\IngestionSource;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;

class ProcessIngestionSource extends Command
{
    protected $signature = 'ingestion:process
        {--id= : IngestionSource UUID to process}
        {--ids= : Comma-separated list of IngestionSource UUIDs to process}
        {--source-type= : Process all ingestion sources with this source_type (e.g. text, bookmark)}
        {--origin= : Optional filter by ingestion_sources.origin (e.g. social_watcher, manual, browser)}
        {--org= : Optional filter by organization UUID}
        {--force : Delete derived chunks/facts first and re-run extraction}
        {--sync : Run work synchronously (dangerous; may be slow if LLM-backed)}
        {--queue= : Queue name for jobs when not using --sync}
        {--limit= : Optional max number of ingestion sources to process in bulk mode}
        {--debug : Output detailed report of created knowledge items and chunks}';

    protected $aliases = [
        'ingestion:process-ingestion-source',
    ];

    protected $description = 'Extract knowledge chunks and business facts for one or many ingestion sources.';

    public function handle(): int
    {
        $id = (string) ($this->option('id') ?: '');
        $ids = (string) ($this->option('ids') ?: '');
        $sourceType = (string) ($this->option('source-type') ?: '');
        $origin = (string) ($this->option('origin') ?: '');
        $orgId = (string) ($this->option('org') ?: '');
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $queue = (string) ($this->option('queue') ?: 'default');
        $limit = $this->option('limit');
        $limit = $limit !== null ? (int) $limit : null;
        $debug = (bool) $this->option('debug');

        if ($id === '' && $ids === '' && $sourceType === '' && $orgId === '') {
            $this->error('Provide --id, --ids, --source-type (bulk), or --org (bulk).');
            return self::FAILURE;
        }
        if ($id !== '' && !Str::isUuid($id)) {
            $this->error('--id must be a UUID');
            return self::FAILURE;
        }
        if ($orgId !== '' && !Str::isUuid($orgId)) {
            $this->error('--org must be a UUID');
            return self::FAILURE;
        }

        $query = IngestionSource::query()->orderByDesc('created_at');

        if ($id !== '') {
            $query->where('id', $id);
        } elseif ($ids !== '') {
            $idArray = array_map('trim', explode(',', $ids));
            $validIds = [];
            foreach ($idArray as $uuid) {
                if (!Str::isUuid($uuid)) {
                    $this->error("Invalid UUID in --ids: {$uuid}");
                    return self::FAILURE;
                }
                $validIds[] = $uuid;
            }
            $query->whereIn('id', $validIds);
        } else {
            if ($sourceType !== '') {
                $query->where('source_type', $sourceType);
            }
            if ($origin !== '') {
                $query->where('origin', $origin);
            }
            if ($orgId !== '') {
                $query->where('organization_id', $orgId);
            }
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $sources = $query->get(['id', 'organization_id', 'source_type', 'origin', 'status']);

        if ($sources->isEmpty()) {
            $this->warn('No ingestion sources matched.');
            return self::SUCCESS;
        }

        $this->info('Matched ingestion sources: ' . $sources->count());

        $processed = 0;
        $queued = 0;
        $failed = 0;

        foreach ($sources as $src) {
            try {
                $this->line('Processing ingestion_source_id=' . $src->id . ' type=' . $src->source_type . ' origin=' . ($src->origin ?? '')); 

                $knowledgeItems = KnowledgeItem::query()
                    ->where('ingestion_source_id', $src->id)
                    ->pluck('id');

                // If nothing has been ingested yet, fall back to the full ingestion processor.
                if ($knowledgeItems->isEmpty()) {
                    if ($sync) {
                        ProcessIngestionSourceJob::dispatchSync((string) $src->id, $force, null, null, true);
                        $processed++;
                        continue;
                    }

                    ProcessIngestionSourceJob::dispatch((string) $src->id, $force)->onQueue($queue);
                    $queued++;
                    continue;
                }

                if ($force) {
                    KnowledgeChunk::query()->whereIn('knowledge_item_id', $knowledgeItems)->delete();
                    BusinessFact::query()->whereIn('source_knowledge_item_id', $knowledgeItems)->delete();
                }

                foreach ($knowledgeItems as $knowledgeItemId) {
                    if ($sync) {
                        dispatch_sync(new NormalizeKnowledgeItemJob((string) $knowledgeItemId));
                        dispatch_sync(new ChunkKnowledgeItemJob((string) $knowledgeItemId));
                        dispatch_sync(new ExtractBusinessFactsJob((string) $knowledgeItemId));
                        $processed++;
                        continue;
                    }

                    Bus::chain([
                        (new NormalizeKnowledgeItemJob((string) $knowledgeItemId)),
                        (new ChunkKnowledgeItemJob((string) $knowledgeItemId)),
                        (new ExtractBusinessFactsJob((string) $knowledgeItemId)),
                    ])->onQueue($queue)->dispatch();

                    $queued++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Failed for ingestion_source_id=' . $src->id . ': ' . $e->getMessage());
            }
        }

        $mode = $sync ? 'sync' : ('queued(queue=' . $queue . ')');
        $this->info("Done ({$mode}). processed={$processed} queued={$queued} failed={$failed}");

        if ($debug) {
            $this->outputDebugReport($sources);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function outputDebugReport($sources): void
    {
        $this->newLine();
        $this->info('=== DEBUG REPORT ===');
        $this->newLine();

        foreach ($sources as $src) {
            $this->line("Ingestion Source: {$src->id}");
            $this->line("  Type: {$src->source_type}, Origin: " . ($src->origin ?? 'N/A'));
            $this->newLine();

            $knowledgeItems = KnowledgeItem::query()
                ->where('ingestion_source_id', $src->id)
                ->with(['chunks', 'businessFacts'])
                ->get();

            if ($knowledgeItems->isEmpty()) {
                $this->warn('  No knowledge items found.');
                $this->newLine();
                continue;
            }

            $this->line("  Knowledge Items: {$knowledgeItems->count()}");
            $this->newLine();

            foreach ($knowledgeItems as $item) {
                $this->line("  [KnowledgeItem] {$item->id}");
                $this->line("    Title: " . ($item->title ?: '(no title)'));
                $this->line("    Type: {$item->type}");
                $this->line("    Source: {$item->source}");
                $this->line("    Raw Text Length: " . strlen($item->raw_text ?? ''));
                $this->line("    Raw Text Preview: " . Str::limit($item->raw_text ?? '', 200));
                $this->line("    Confidence: " . ($item->confidence ?? 'N/A'));
                $this->line("    Ingested At: " . ($item->ingested_at ? $item->ingested_at->toDateTimeString() : 'N/A'));
                
                // Chunking diagnostics
                if ($item->chunking_status) {
                    $this->newLine();
                    $this->line("    Chunking: {$item->chunking_status}");
                    
                    if ($item->chunking_status === 'skipped' && $item->chunking_skip_reason) {
                        $this->line("      Reason: {$item->chunking_skip_reason}");
                    }
                    
                    if ($item->chunking_status === 'failed') {
                        if ($item->chunking_error_code) {
                            $this->line("      Error Code: {$item->chunking_error_code}");
                        }
                        if ($item->chunking_error_message) {
                            $this->line("      Error Message: " . Str::limit($item->chunking_error_message, 100));
                        }
                    }
                    
                    if ($item->chunking_metrics) {
                        $metrics = is_array($item->chunking_metrics) ? $item->chunking_metrics : [];
                        $this->line("      Format: " . ($metrics['detected_format'] ?? 'N/A'));
                        $this->line("      Strategy: " . ($metrics['strategy_used'] ?? 'N/A'));
                        $this->line("      Metrics: raw_chars=" . ($metrics['raw_chars'] ?? 0) . 
                                   " clean_chars=" . ($metrics['clean_chars'] ?? 0) . 
                                   " tokens_est=" . ($metrics['clean_tokens_est'] ?? 0) . 
                                   " duration_ms=" . ($metrics['duration_ms'] ?? 0));
                    }
                }
                
                $this->line("    Chunks: {$item->chunks->count()}");
                $this->line("    Business Facts: {$item->businessFacts->count()}");
                
                if ($item->chunks->isNotEmpty()) {
                    $this->newLine();
                    $this->line("    --- Chunks ---");
                    foreach ($item->chunks as $chunk) {
                        $this->line("    [Chunk] {$chunk->id}");
                        $this->line("      Type: {$chunk->chunk_type}, Role: {$chunk->chunk_role}");
                        $this->line("      Authority: " . ($chunk->authority ?? 'N/A'));
                        $this->line("      Confidence: " . ($chunk->confidence ?? 'N/A'));
                        $this->line("      Domain: " . ($chunk->domain ?? 'N/A'));
                        $this->line("      Actor: " . ($chunk->actor ?? 'N/A'));
                        $this->line("      Token Count: " . ($chunk->token_count ?? 'N/A'));
                        $this->line("      Text Length: " . strlen($chunk->chunk_text ?? ''));
                        $this->line("      Text: " . ($chunk->chunk_text ?? ''));
                        $this->line("      Has Embedding: " . ($chunk->embedding ? 'Yes' : 'No'));
                        if ($chunk->embedding) {
                            $this->line("      Embedding Model: " . ($chunk->embedding_model ?? 'N/A'));
                        }
                        $this->newLine();
                    }
                }
                
                $this->newLine();
            }

            $this->line(str_repeat('-', 80));
            $this->newLine();
        }

        $this->info('=== END DEBUG REPORT ===');
    }
}
