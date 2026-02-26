<?php

namespace App\Services\Ai;

use App\Jobs\RebuildFolderEmbeddingJob;
use App\Models\FolderEmbedding;
use Illuminate\Support\Facades\Schema;

class FolderEmbeddingScheduler
{
    /**
     * @param array<int,string> $folderIds
     */
    public function markStaleAndSchedule(array $folderIds, ?string $orgId = null): void
    {
        if (!Schema::hasTable('folder_embeddings')) {
            return;
        }

        $delay = (int) config('ai.folder_scope.debounce_seconds', 180);
        $when = now()->addSeconds(max(0, $delay));

        foreach (array_unique(array_filter($folderIds)) as $folderId) {
            $this->markStale($folderId, $orgId);
            dispatch(new RebuildFolderEmbeddingJob((string) $folderId))->delay($when);
        }
    }

    public function scheduleRebuild(string $folderId, bool $markStale = true, ?string $orgId = null): void
    {
        if (!Schema::hasTable('folder_embeddings')) {
            return;
        }

        if ($markStale) {
            $this->markStale($folderId, $orgId);
        }

        $delay = (int) config('ai.folder_scope.debounce_seconds', 180);
        dispatch(new RebuildFolderEmbeddingJob((string) $folderId))
            ->delay(now()->addSeconds(max(0, $delay)));
    }

    protected function markStale(string $folderId, ?string $orgId = null): void
    {
        try {
            $row = FolderEmbedding::where('folder_id', $folderId)->first();
            if ($row) {
                $row->stale_at = now();
                if ($orgId !== null && $orgId !== '') {
                    $row->org_id = $orgId;
                }
                $row->updated_at = now();
                $row->save();
            }
        } catch (\Throwable) {
            // ignore
        }
    }
}
