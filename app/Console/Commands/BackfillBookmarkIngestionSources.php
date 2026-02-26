<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Bookmark;
use App\Models\IngestionSource;
use App\Services\Ai\Generation\ContentGenBatchLogger;

class BackfillBookmarkIngestionSources extends Command
{
    protected $signature = 'backfill:ingestion:bookmarks
        {--org= : Limit to a specific organization UUID}
        {--limit=0 : Max bookmarks to process (0 = all)}
        {--dry-run : Show what would be created without writing}';

    protected $aliases = [
        'ingestion:backfill-bookmarks',
    ];

    protected $description = 'Create ingestion_sources for existing bookmarks without converting to KnowledgeItems.';

    public function handle(): int
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('BackfillBookmarkIngestionSources', [
            'options' => [
                'org' => $this->option('org'),
                'limit' => $this->option('limit'),
                'dry-run' => $this->option('dry-run'),
            ]
        ]);
        $org = $this->option('org');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        $this->info('Backfilling ingestion_sources for bookmarks');
        if ($org) $this->line("- Organization: $org");
        if ($limit > 0) $this->line("- Limit: $limit");
        if ($dry) $this->line('- Dry run: enabled');

        $query = Bookmark::query()->whereNull('deleted_at');
        if ($org) $query->where('organization_id', $org);

        $total = ($limit > 0) ? min($limit, $query->count()) : $query->count();
        $this->line("- Candidates: $total");
        if ($total === 0) return self::SUCCESS;

        $created = 0; $skipped = 0;
        $processed = 0;
        $shouldStop = false;

        $logger->capture('candidates', ['total' => $total]);
        $query->orderBy('created_at')->chunk(500, function ($chunk) use (&$created, &$skipped, &$processed, $limit, $dry, &$shouldStop, $logger) {
            foreach ($chunk as $b) {
                if ($limit > 0 && $processed >= $limit) { $shouldStop = true; break; }
                $processed++;
                $dedup = IngestionSource::dedupHashFromUrl($b->url);

                $exists = IngestionSource::query()
                    ->where('source_type', 'bookmark')
                    ->where('source_id', $b->id)
                    ->exists();
                if ($exists) { $skipped++; $logger->capture('skip_existing', ['bookmark_id' => $b->id]); continue; }

                $payload = [
                    'organization_id' => $b->organization_id,
                    'user_id' => $b->created_by,
                    'origin' => 'browser',
                    'platform' => $b->platform,
                    'raw_url' => $b->url,
                    'dedup_hash' => $dedup,
                    'status' => 'pending',
                ];

                if ($dry) {
                    $this->line("DRY: would create ingestion_source for bookmark {$b->id} ({$b->url})");
                    $logger->capture('dry_create', ['bookmark_id' => $b->id, 'url' => $b->url]);
                } else {
                    IngestionSource::create(array_merge([
                        'source_type' => 'bookmark',
                        'source_id' => $b->id,
                    ], $payload));
                    $created++;
                    $logger->capture('created', ['bookmark_id' => $b->id]);
                }
            }
            if ($shouldStop) return false; // stop further chunking
        });

        $this->info("Done. Created: $created, Skipped(existing): $skipped");
        $logger->flush('completed', ['created' => $created, 'skipped' => $skipped, 'processed' => $processed]);
        return self::SUCCESS;
    }
}
