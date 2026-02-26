<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\FolderEmbedding;
use App\Services\Ai\EmbeddingsService;
use App\Services\Ai\FolderEmbeddingBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RebuildFolderEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = 30;

    public function __construct(public string $folderId) {}

    public function handle(EmbeddingsService $embeddings, FolderEmbeddingBuilder $builder): void
    {
        if (!Schema::hasTable('folders') || !Schema::hasTable('folder_embeddings') || !Schema::hasColumn('folder_embeddings', 'embedding')) {
            return;
        }

        $folder = Folder::find($this->folderId);
        if (!$folder) {
            return;
        }

        $textVersion = (int) config('ai.folder_scope.text_version', 1);
        $built = $builder->buildRepresentation($folder);
        $text = trim((string) ($built['text'] ?? ''));
        if ($text === '') {
            return;
        }

        $vector = $embeddings->embedOne($text);
        if (!is_array($vector) || count($vector) === 0) {
            Log::warning('folder_embeddings.empty_vector', [
                'folder_id' => (string) $folder->id,
            ]);
            return;
        }

        $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float) $f), '0'), $vector)) . ']';
        if ($literal === '[]') {
            return;
        }

        $now = now();
        $orgId = (string) $folder->organization_id;

        DB::beginTransaction();
        try {
            $existing = FolderEmbedding::where('folder_id', (string) $folder->id)->first();
            if ($existing) {
                DB::update(
                    "UPDATE folder_embeddings
                     SET org_id = ?, text_version = ?, representation_text = ?, embedding = CAST(? AS vector), stale_at = NULL, updated_at = ?
                     WHERE folder_id = ?",
                    [$orgId, $textVersion, $text, $literal, $now, (string) $folder->id]
                );
            } else {
                DB::statement(
                    "INSERT INTO folder_embeddings (id, folder_id, org_id, text_version, representation_text, embedding, stale_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, CAST(? AS vector), NULL, ?)",
                    [
                        (string) Str::uuid(),
                        (string) $folder->id,
                        $orgId,
                        $textVersion,
                        $text,
                        $literal,
                        $now,
                    ]
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::warning('folder_embeddings.rebuild_failed', [
                'folder_id' => (string) $folder->id,
                'error' => $e->getMessage(),
            ]);
            $this->release(30);
        }
    }
}
