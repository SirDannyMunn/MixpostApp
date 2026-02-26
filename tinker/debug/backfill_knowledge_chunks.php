<?php

use Illuminate\Support\Facades\DB;

/**
 * Backfill chunks (and optionally embeddings) for all knowledge items.
 *
 * Run:
 *   php artisan tinker --execute="require 'tinker/debug/backfill_knowledge_chunks.php';"
 */

echo "\n== Backfill Knowledge Chunks ==\n";

$items = \App\Models\KnowledgeItem::query()->orderBy('created_at')->get();
echo "Items found: " . $items->count() . "\n";

$createdChunks = 0;
foreach ($items as $it) {
    (new \App\Jobs\ChunkKnowledgeItemJob($it->id))->handle();
    $createdChunks++;
}

$totalChunks = \App\Models\KnowledgeChunk::query()->count();
echo "Chunking complete for {$createdChunks} items. Total chunks: {$totalChunks}\n";

$doEmbed = (bool) env('BACKFILL_EMBED', false);
if ($doEmbed) {
    echo "Embedding chunks (BACKFILL_EMBED=1)...\n";
    $itemIds = $items->pluck('id')->all();
    foreach ($itemIds as $id) {
        (new \App\Jobs\EmbedKnowledgeChunksJob($id))->handle(app(\App\Services\Ai\EmbeddingsService::class));
    }
    $embedded = \App\Models\KnowledgeChunk::query()->whereNotNull('embedding_vec')->count();
    echo "Embedded chunks: {$embedded}\n";
}

echo "== Done ==\n";

