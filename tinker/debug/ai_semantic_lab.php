<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Semantic Lab: seeds purpose-built knowledge, runs pipeline, probes retrieval at different thresholds and intents.
 *
 * Run:
 *   php artisan tinker --execute="require 'tinker/debug/ai_semantic_lab.php';"
 */

echo "\n== Semantic Lab ==\n";

// 0) Seed the lab dataset
try {
    Artisan::call('db:seed', ['--class' => Database\\Seeders\\KnowledgeLabSeeder::class, '--force' => true]);
    echo "Seeded KnowledgeLabSeeder\n";
} catch (Throwable $e) {
    echo "Seeding failed: {$e->getMessage()}\n";
}

// 1) Resolve user/org
$user = \App\Models\User::query()->first();
$org = \App\Models\Organization::query()->first();
if (!$user || !$org) {
    echo "Missing user or organization. Seed failed?\n";
    return;
}

// 2) Run pipeline on all seeded items (chunk + embed)
$items = \App\Models\KnowledgeItem::query()
    ->where('organization_id', $org->id)
    ->where('user_id', $user->id)
    ->orderBy('created_at')
    ->get();

echo "Processing " . $items->count() . " knowledge items...\n";
foreach ($items as $it) {
    (new \App\Jobs\ChunkKnowledgeItemJob($it->id))->handle();
    (new \App\Jobs\EmbedKnowledgeChunksJob($it->id))->handle(app(\App\Services\Ai\EmbeddingsService::class));
}

$totalChunks = \App\Models\KnowledgeChunk::query()
    ->where('organization_id', $org->id)
    ->where('user_id', $user->id)
    ->count();
$embeddedChunks = \App\Models\KnowledgeChunk::query()
    ->where('organization_id', $org->id)
    ->where('user_id', $user->id)
    ->whereNotNull('embedding_vec')
    ->count();
echo "Chunks: {$embeddedChunks}/{$totalChunks} embedded\n";

// 3) Retrieval probes at multiple thresholds
$prompts = [
    'why startup content fails and how to build trust',
    'founders should stop copying competitors and publish opinions',
];

$intents = ['educational', 'persuasive', 'story'];
$thresholds = [0.25, 0.35, 0.45];

$retriever = app(\App\Services\Ai\Retriever::class);

foreach ($prompts as $prompt) {
    echo "\n-- Prompt: {$prompt} --\n";
    foreach ($intents as $intent) {
        foreach ($thresholds as $th) {
            config(['vector.similarity.threshold' => $th]);
            $results = $retriever->knowledgeChunks($org->id, $user->id, $prompt, $intent, 6);

            echo sprintf("intent=%s threshold=%.2f -> %d results\n", $intent, $th, count($results));

            // Also print distances for the first 5 via raw query
            $embed = app(\App\Services\Ai\EmbeddingsService::class)->embedOne($prompt);
            if (!empty($embed)) {
                $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float)$f), '0'), $embed)) . ']';
                $rows = DB::select(
                    "SELECT id, (embedding_vec <=> CAST(? AS vector)) AS distance FROM knowledge_chunks WHERE organization_id = ? AND user_id = ? AND embedding_vec IS NOT NULL ORDER BY embedding_vec <=> CAST(? AS vector) LIMIT 5",
                    [$literal, $org->id, $user->id, $literal]
                );
                $distances = array_map(fn($r) => round((float) ($r->distance ?? 0), 4), $rows);
                echo "top5 distances: [" . implode(', ', $distances) . "]\n";
            }

            // Print first 2 previews
            foreach (array_slice($results, 0, 2) as $i => $r) {
                $preview = mb_substr((string) ($r['chunk_text'] ?? ''), 0, 100);
                echo "  [" . ($i+1) . "] " . $preview . (mb_strlen($preview) >= 100 ? '...' : '') . "\n";
            }
        }
    }
}

echo "\n== Done ==\n";

