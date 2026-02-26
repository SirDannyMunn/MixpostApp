<?php

namespace App\Console\Commands;

use App\Jobs\ConvertNormalizedContentToIngestionSourceJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaundryOS\SocialWatcher\Models\NormalizedContent;

class ConvertNormalizedContentToIngestionSource extends Command
{
    protected $signature = 'ingestion:convert:social-watcher
        {--normalized-id= : NormalizedContent UUID to convert}
        {--source-id= : Social Watcher Source ID (integer) to convert all normalized content for}
        {--org= : Organization UUID for the created ingestion source(s)}
        {--user= : User UUID to attribute ingestion source(s) to (defaults to first org member)}
        {--force : Force reprocess (purges derived items and passes force=true)}
        {--sync : Run conversion synchronously instead of queueing}
        {--queue= : Queue name for conversion jobs (only when not --sync)}';

    protected $aliases = [
        'social-watcher:convert-normalized-to-ingestion',
    ];

    protected $description = 'Convert Social Watcher normalized content into ingestion sources and queue processing.';

    public function handle(): int
    {
        $normalizedId = (string) ($this->option('normalized-id') ?: '');
        $sourceIdOpt = $this->option('source-id');
        $organizationId = (string) ($this->option('org') ?: '');
        $userId = (string) ($this->option('user') ?: '');
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $queueName = (string) ($this->option('queue') ?: 'default');

        if ($normalizedId === '' && ($sourceIdOpt === null || $sourceIdOpt === '')) {
            $this->error('Provide either --normalized-id or --source-id');
            return self::FAILURE;
        }
        if ($normalizedId !== '' && ($sourceIdOpt !== null && $sourceIdOpt !== '')) {
            $this->error('Provide only one of --normalized-id or --source-id');
            return self::FAILURE;
        }

        if ($organizationId === '' || !Str::isUuid($organizationId)) {
            $this->error('--org is required and must be a UUID');
            return self::FAILURE;
        }

        $org = Organization::query()->find($organizationId);
        if (!$org) {
            $this->error('Organization not found: ' . $organizationId);
            return self::FAILURE;
        }

        if ($userId !== '' && !Str::isUuid($userId)) {
            $this->error('--user must be a UUID when provided');
            return self::FAILURE;
        }

        if ($userId === '') {
            $member = $org->members()->first();
            $userId = (string) ($member?->id ?? '');
        }
        if ($userId === '') {
            $userId = (string) (User::query()->value('id') ?? '');
        }
        if ($userId === '' || !Str::isUuid($userId)) {
            $this->error('Could not infer a user id; provide --user');
            return self::FAILURE;
        }

        if ($normalizedId !== '') {
            if (!Str::isUuid($normalizedId)) {
                $this->error('--normalized-id must be a UUID');
                return self::FAILURE;
            }

            $this->info('Converting normalized content: ' . $normalizedId);

            $job = new ConvertNormalizedContentToIngestionSourceJob($normalizedId, $organizationId, $userId, $force);

            try {
                if ($sync) {
                    $result = dispatch_sync($job);
                    $this->info('Done. ingestion_source_id=' . ($result['ingestion_source_id'] ?? ''));
                    return self::SUCCESS;
                }

                dispatch($job)->onQueue($queueName);
                $this->info('Queued conversion on queue=' . $queueName);
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('Conversion failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $sourceId = (int) $sourceIdOpt;
        if ($sourceId < 1) {
            $this->error('--source-id must be a positive integer');
            return self::FAILURE;
        }

        $query = NormalizedContent::query()->where('source_id', $sourceId)->orderBy('created_at');
        $total = (int) $query->count();
        if ($total < 1) {
            $this->warn('No normalized content found for source_id=' . $sourceId);
            return self::SUCCESS;
        }

        $this->info("Converting {$total} normalized content rows for source_id={$sourceId}...");

        $processed = 0;
        $failed = 0;

        $query->chunk(200, function ($rows) use ($organizationId, $userId, $force, $sync, $queueName, &$processed, &$failed) {
            foreach ($rows as $row) {
                $job = new ConvertNormalizedContentToIngestionSourceJob((string) $row->id, $organizationId, $userId, $force);

                try {
                    if ($sync) {
                        dispatch_sync($job);
                    } else {
                        dispatch($job)->onQueue($queueName);
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn('Failed for normalized_content_id=' . $row->id . ': ' . $e->getMessage());
                }
            }
        });

        $mode = $sync ? 'sync' : ('queued(queue=' . $queueName . ')');
        $this->info("Done ({$mode}). processed={$processed} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
