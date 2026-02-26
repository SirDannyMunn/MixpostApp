<?php

namespace App\Console\Commands;

use App\Jobs\InferContextFolderJob;
use App\Jobs\ScoreFolderCandidatesJob;
use App\Models\IngestionSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Services\Ai\LLMClient;
use App\Services\Ingestion\IngestionContentResolver;

class BackfillIngestionSourceFolders extends Command
{
    protected $signature = 'backfill:ingestion:source-folders
        {--org= : Limit to a specific organization UUID}
        {--limit=0 : Max sources to process (0 = all)}
        {--dry-run : Show what would be created without writing}';

    protected $aliases = [
        'ingestion:backfill-source-folders',
    ];

    protected $description = 'Backfill ingestion_source_folders by running AI folder inference per ingestion source (no legacy folder copying).';

    public function handle(): int
    {
        if (!Schema::hasTable('ingestion_source_folders')) {
            $this->error('ingestion_source_folders table not found. Run migrations first.');
            return self::FAILURE;
        }

        if (!Schema::hasTable('folders')) {
            $this->error('folders table not found.');
            return self::FAILURE;
        }

        $orgId = (string) ($this->option('org') ?: '');
        $limit = (int) ($this->option('limit') ?: 0);
        $dry = (bool) $this->option('dry-run');

        if ($orgId !== '' && !Str::isUuid($orgId)) {
            $this->error('--org must be a UUID');
            return self::FAILURE;
        }

        $q = IngestionSource::query()
            ->whereNull('deleted_at')
            ->orderByDesc('created_at');
        if ($orgId !== '') {
            $q->where('organization_id', $orgId);
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        $sources = $q->get(['id', 'organization_id', 'user_id', 'source_type']);
        if ($sources->isEmpty()) {
            $this->info('No ingestion sources found.');
            return self::SUCCESS;
        }

        $ran = 0;
        $attached = 0;
        $createdFolders = 0;
        $reusedFolders = 0;
        $skipped = 0;
        $errored = 0;
        $declined = 0;

        /** @var LLMClient $llm */
        $llm = app(LLMClient::class);
        /** @var IngestionContentResolver $resolver */
        $resolver = app(IngestionContentResolver::class);

        foreach ($sources as $src) {
            try {
                // Only attempt inference for supported source types (job will also guard)
                $t = (string) ($src->source_type ?? '');
                if (!in_array($t, ['bookmark', 'text', 'social'], true)) {
                    $skipped++;
                    continue;
                }

                if ($dry) {
                    $this->line("DRY: would run folder pipeline for ingestion_source={$src->id} type={$t}");
                    // Execute full pipeline, but do not persist (transaction rollback).
                    DB::beginTransaction();
                    $infer = (new InferContextFolderJob((string) $src->id))->run($llm, $resolver);
                    $ran++;
                    if (($infer['should_create_folder'] ?? false) !== true) {
                        $declined++;
                        $this->line('  - inference: declined');
                        DB::rollBack();
                        continue;
                    }

                    $score = (new ScoreFolderCandidatesJob((string) $src->id, $infer))->run($llm);
                    DB::rollBack();
                    $this->line('  - inference: proposed folder=' . (string) ($infer['folder_name'] ?? '') . ' confidence=' . (string) ($infer['confidence'] ?? ''));
                    $this->line('  - decision: ' . (string) ($score['decision'] ?? ($score['status'] ?? 'unknown'))
                        . ' folder_id=' . (string) ($score['folder_id'] ?? ''));
                    continue;
                }

                $ran++;
                $infer = (new InferContextFolderJob((string) $src->id))->run($llm, $resolver);
                if (($infer['should_create_folder'] ?? false) !== true) {
                    $declined++;
                    continue;
                }

                $res = (new ScoreFolderCandidatesJob((string) $src->id, $infer))->run($llm);
                if (($res['status'] ?? '') === 'attached') {
                    $attached++;
                    if (!empty($res['created'])) { $createdFolders++; }
                    if (!empty($res['reused'])) { $reusedFolders++; }
                }
            } catch (\Throwable $e) {
                $errored++;
                $this->warn("Error for ingestion_source={$src->id}: {$e->getMessage()}");
            }
        }

        $this->info('Backfill complete.');
        $this->line("- Sources scanned: {$sources->count()}");
        $this->line("- Inference executed: {$ran}");
        $this->line("- Inference declined: {$declined}");
        $this->line("- Attached: {$attached} (created={$createdFolders}, reused={$reusedFolders})");
        $this->line("- Skipped(unsupported): {$skipped}");
        $this->line("- Errors: {$errored}");

        return self::SUCCESS;
    }
}
