<?php

namespace App\Observers;

use App\Models\Folder;
use App\Services\Ai\FolderEmbeddingScheduler;

class FolderObserver
{
    public function created(Folder $folder): void
    {
        $this->schedule($folder);
    }

    public function updated(Folder $folder): void
    {
        if ($this->shouldRebuild($folder)) {
            $this->schedule($folder);
        }
    }

    protected function schedule(Folder $folder): void
    {
        try {
            app(FolderEmbeddingScheduler::class)->scheduleRebuild(
                (string) $folder->id,
                true,
                (string) ($folder->organization_id ?? '')
            );
        } catch (\Throwable) {
            // non-fatal
        }
    }

    protected function shouldRebuild(Folder $folder): bool
    {
        if ($folder->isDirty('system_name')) {
            return true;
        }

        if ($folder->isDirty('metadata')) {
            $before = is_array($folder->getOriginal('metadata') ?? null) ? $folder->getOriginal('metadata') : [];
            $after = is_array($folder->metadata ?? null) ? $folder->metadata : [];

            $keys = ['context_type', 'primary_entity', 'description'];
            foreach ($keys as $key) {
                $b = trim((string) ($before[$key] ?? ''));
                $a = trim((string) ($after[$key] ?? ''));
                if ($a !== $b) {
                    return true;
                }
            }
        }

        return false;
    }
}
