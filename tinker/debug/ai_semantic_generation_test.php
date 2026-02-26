<?php

use Illuminate\Support\Str;

/**
 * Tinker debug: End-to-end semantic generation smoke test.
 * - Ingests/uses a knowledge item
 * - Chunks and embeds (pgvector)
 * - Runs semantic retrieval
 * - Optionally runs full generation (if OPENROUTER_API_KEY is set)
 *
 * Run:
 *   php artisan tinker --execute="require 'tinker/debug/ai_semantic_generation_test.php';"
 */

echo "\n== Semantic Generation Smoke Test ==\n";

// Resolve a user
$user = \App\Models\User::query()->first();
if (!$user) {
    echo "No users found. Create a user first (e.g., factory), then rerun.\n";
    return;
}

// Resolve or create an organization
$org = \App\Models\Organization::query()->first();
if (!$org) {
    $org = \App\Models\Organization::create([
        'name' => 'Debug Org',
        'slug' => 'debug-' . Str::lower(Str::random(6)),
    ]);
    echo "Created organization: {$org->id} ({$org->slug})\n";
}

// 1) Ensure there is a knowledge item to retrieve from
$sampleTitle = 'Semantic Retrieval Test Note';
$item = \App\Models\KnowledgeItem::query()
    ->where('organization_id', $org->id)
    ->where('user_id', $user->id)
    ->where('title', $sampleTitle)
    ->first();

if (!$item) {
    $raw = "Most SaaS founders fail at content because they treat it like a growth hack instead of reputation building. The fix is to focus on consistent, opinionated writing that compounds trust. Start by publishing one strong thread weekly, and avoid hype.";
    $item = \App\Models\KnowledgeItem::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'note',
        'source' => 'manual',
        'title' => $sampleTitle,
        'raw_text' => $raw,
        'raw_text_sha256' => hash('sha256', $raw),
        'ingested_at' => now(),
    ]);
    echo "Created knowledge item: {$item->id}\n";
}

// 2) Chunk then embed synchronously for this test
echo "Chunking knowledge item...\n";
(new \App\Jobs\ChunkKnowledgeItemJob($item->id))->handle();
echo "Embedding chunks (pgvector)...\n";
(new \App\Jobs\EmbedKnowledgeChunksJob($item->id))->handle(app(\App\Services\Ai\EmbeddingsService::class));

// Verify embeddings exist
$embeddedCount = \App\Models\KnowledgeChunk::query()
    ->where('knowledge_item_id', $item->id)
    ->whereNotNull('embedding_vec')
    ->count();
echo "Embedded chunks: {$embeddedCount}\n";
if ($embeddedCount === 0) {
    echo "No embeddings were created. Check OPENROUTER_API_KEY and pgvector migration.\n";
}

// 3) Semantic retrieval demo
$retriever = app(\App\Services\Ai\Retriever::class);
$query = 'founders fail at content; focus on trust';
$chunks = $retriever->knowledgeChunks($org->id, $user->id, $query, 'educational', 5);
echo "Semantic retrieval results (top):\n";
foreach (array_slice($chunks, 0, 3) as $i => $c) {
    $preview = mb_substr((string) ($c['chunk_text'] ?? ''), 0, 120);
    echo "  [" . ($i+1) . "] " . $preview . (mb_strlen($preview) >= 120 ? '...' : '') . "\n";
}

// 4) Full generation (optional; requires OpenRouter key)
$hasApiKey = (bool) config('services.openrouter.api_key');
if (!$hasApiKey) {
    echo "\nOpenRouter API key not set; skipping full generation. Set OPENROUTER_API_KEY to test generation.\n";
    return;
}

echo "\nClassifying + generating post via job...\n";
$gen = \App\Models\GeneratedPost::create([
    'organization_id' => $org->id,
    'user_id' => $user->id,
    'platform' => 'twitter',
    'request' => [
        'prompt' => 'Write a short, contrarian post about why most startup content fails and how to build trust instead.',
        'context' => 'Audience: SaaS founders; Tone: direct, no fluff',
        'options' => [
            'max_chars' => 500,
            'cta' => 'implicit',
            'emoji' => 'disallow',
            'tone' => 'direct',
        ],
        'reference_ids' => [],
    ],
    'status' => 'queued',
]);

$job = new \App\Jobs\GeneratePostJob($gen->id);
$job->handle(
    app(\App\Services\Ai\LLMClient::class),
    app(\App\Services\Ai\Retriever::class),
    app(\App\Services\Ai\TemplateSelector::class),
    app(\App\Services\Ai\ContextAssembler::class),
    app(\App\Services\Ai\PostValidator::class),
    app(\App\Services\Ai\SchemaValidator::class),
    app(\App\Services\Ai\PostClassifier::class),
);

$reloaded = \App\Models\GeneratedPost::find($gen->id);
echo "Generation status: {$reloaded->status}\n";
echo "Intent/Funnel: {$reloaded->intent} / {$reloaded->funnel_stage}\n";
echo "Content preview:\n";
echo mb_substr((string) $reloaded->content, 0, 500) . (mb_strlen((string) $reloaded->content) > 500 ? '...' : '') . "\n";

echo "\n== Done ==\n";

