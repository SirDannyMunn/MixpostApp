<?php

namespace App\Console\Commands;

use App\Jobs\RebuildFolderEmbeddingJob;
use App\Models\Folder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackfillFolderEmbeddings extends Command
{
    protected $signature = 'backfill:folders:embeddings
        {--org= : Limit to a specific organization UUID}
        {--only-missing : Only embed folders without an embedding row}
        {--rebuild-stale : Rebuild rows marked as stale}';

    protected $aliases = [
        'backfill:folders:embed',
    ];

    protected $description = 'Backfill folder embeddings for auto-scoped retrieval.';

    public function handle(): int
    {
        if (!Schema::hasTable('folders')) {
            $this->error('folders table not found.');
            return self::FAILURE;
        }

        if (!Schema::hasTable('folder_embeddings')) {
            $this->error('folder_embeddings table not found. Run migrations first.');
            return self::FAILURE;
        }

        $orgId = (string) ($this->option('org') ?: '');
        $onlyMissing = (bool) $this->option('only-missing');
        $rebuildStale = (bool) $this->option('rebuild-stale');

        if ($orgId !== '' && !Str::isUuid($orgId)) {
            $this->error('--org must be a UUID');
            return self::FAILURE;
        }

        $q = Folder::query()->whereNull('deleted_at');
        if ($orgId !== '') {
            $q->where('organization_id', $orgId);
        }

        if ($onlyMissing || $rebuildStale) {
            $q->leftJoin('folder_embeddings as fe', 'fe.folder_id', '=', 'folders.id')
                ->select('folders.id as id');
            if ($onlyMissing && $rebuildStale) {
                $q->where(function ($w) {
                    $w->whereNull('fe.folder_id')->orWhereNotNull('fe.stale_at');
                });
            } elseif ($onlyMissing) {
                $q->whereNull('fe.folder_id');
            } elseif ($rebuildStale) {
                $q->whereNotNull('fe.stale_at');
            }
        } else {
            $q->select('folders.id as id');
        }

        $queued = 0;
        $q->orderBy('folders.id')->chunkById(200, function ($rows) use (&$queued) {
            foreach ($rows as $row) {
                dispatch(new RebuildFolderEmbeddingJob((string) $row->id));
                $queued++;
            }
        }, 'id');

        $this->info("Queued {$queued} folder embeddings.");
        return self::SUCCESS;
    }
}
