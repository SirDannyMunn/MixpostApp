<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIngestionSourceJob;
use App\Models\IngestionSource;
use Illuminate\Console\Command;
use App\Services\Ai\Generation\ContentGenBatchLogger;

class BackfillIngestionSources extends Command
{
    protected $signature = 'backfill:ingestion:sources {--limit=100 : Max number of pending sources to enqueue} {--type= : Optional source_type filter (e.g., text, bookmark)} {--force : Force reprocess, bypassing dedup checks} {--debug : Run synchronously and print a knowledge-chunk report}';

    protected $aliases = [
        'ingestion:backfill',
    ];
    protected $description = 'Dispatch ingestion jobs for pending ingestion_sources records';

    public function handle(): int
    {
        $debug = (bool) $this->option('debug');
        $startedAt = now();

        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('BackfillIngestionSources', [
            'options' => [
                'limit' => $this->option('limit'),
                'type' => $this->option('type'),
                'force' => $this->option('force'),
                'debug' => $debug,
            ]
        ]);
        $limit = max(1, (int) $this->option('limit'));
        $type = $this->option('type');

        $q = IngestionSource::query()
            ->where('status', 'pending')
            ->orderBy('created_at');
        if ($type) {
            $q->where('source_type', $type);
        }
        $sources = $q->limit($limit)->get(['id','source_type','status']);

        $count = $sources->count();
        $this->info("Dispatching {$count} ingestion job(s)");
        $force = (bool) $this->option('force');
        $logger->capture('dispatching', ['count' => $count, 'force' => $force, 'ids' => $sources->pluck('id')->all()]);

        if ($debug) {
            // Force the queue to run synchronously so all chained jobs execute inline.
            config(['queue.default' => 'sync']);
            $this->line('Debug mode enabled: using sync queue driver.');
        }

        $processedIds = [];
        foreach ($sources as $src) {
            $processedIds[] = $src->id;
            dispatch(new ProcessIngestionSourceJob($src->id, $force));
        }

        if ($count === 0) {
            $this->line('No pending sources found.');
        } else {
            $this->line('Use `php artisan queue:work` to process if your queue driver is not sync.');
        }
        $logger->flush('completed');

        // When debugging, produce a knowledge-chunk style report of created records, excluding embeddings.
        if ($debug && $count > 0) {
            try {
                // Collect knowledge items linked to these sources
                $itemIds = \App\Models\KnowledgeItem::query()
                    ->whereIn('ingestion_source_id', $processedIds)
                    ->pluck('id');

                // Gather chunks created during this run for those items
                $chunks = \App\Models\KnowledgeChunk::query()
                    ->whereIn('knowledge_item_id', $itemIds)
                    ->where('created_at', '>=', $startedAt)
                    ->orderBy('created_at')
                    ->get();

                $report = $chunks->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'knowledge_item_id' => $c->knowledge_item_id,
                        'organization_id' => $c->organization_id,
                        'user_id' => $c->user_id,
                        'chunk_text' => $c->chunk_text,
                        'chunk_type' => $c->chunk_type,
                        'chunk_role' => $c->chunk_role,
                        'authority' => $c->authority,
                        'confidence' => (float) $c->confidence,
                        'time_horizon' => $c->time_horizon,
                        'source_type' => $c->source_type,
                        'source_ref' => $c->source_ref,
                        'tags' => $c->tags,
                        'token_count' => (int) $c->token_count,
                        // Intentionally omit 'embedding' because it is very large
                        'embedding_model' => $c->embedding_model,
                        'created_at' => optional($c->created_at)->toIso8601String(),
                    ];
                })->values();

                $this->line('--- Knowledge Chunk Report (debug) ---');
                if ($report->isEmpty()) {
                    // Hotfix 2.1.1: Explain empty output explicitly
                    $this->line(json_encode([
                        'status' => 'skipped',
                        'reason' => 'no_chunkable_input',
                        'stage' => 'chunking',
                        'knowledge_chunks' => [],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } else {
                    $this->line(json_encode(['knowledge_chunks' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $this->line('----- End Report -----');
            } catch (\Throwable $e) {
                $this->error('Failed to build debug report: ' . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
