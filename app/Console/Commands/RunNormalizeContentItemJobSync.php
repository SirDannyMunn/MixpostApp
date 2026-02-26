<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use LaundryOS\SocialWatcher\Jobs\NormalizeContentItem;

class RunNormalizeContentItemJobSync extends Command
{
    protected $signature = 'social-watcher:content:normalize
        {content_item_id : Social Watcher ContentItem ID}
        {--sync : Run immediately (no queue) instead of dispatching a job}
        {--queue= : Queue name (defaults to config social-watcher.ingestion.queue)}';

    protected $aliases = [
        'social-watcher:normalize-content-item',
    ];

    protected $description = 'Run the NormalizeContentItem job for a specific content item ID (sync or queued).';

    public function handle(): int
    {
        $contentItemId = (int) $this->argument('content_item_id');
        if ($contentItemId < 1) {
            $this->error('content_item_id must be a positive integer');
            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');

        if ($sync) {
            $this->info("Running NormalizeContentItem synchronously for content_item_id={$contentItemId}...");

            try {
                NormalizeContentItem::dispatchSync($contentItemId);
            } catch (\Throwable $e) {
                $this->error('NormalizeContentItem failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            $this->info('Done.');
            return self::SUCCESS;
        }

        $queueName = (string) ($this->option('queue') ?: config('social-watcher.ingestion.queue', 'default'));

        NormalizeContentItem::dispatch($contentItemId)->onQueue($queueName);

        $this->info("Queued NormalizeContentItem for content_item_id={$contentItemId} on queue={$queueName}.");
        return self::SUCCESS;
    }
}
