<?php

/**
 * Tinker debug: Unified ContentGeneratorService smoke test (simplified)
 *
 * - Uses the new ContentGeneratorService end-to-end
 * - Minimal setup: resolves a user + org; optional creation
 * - Generates content synchronously with constraints and retrieval
 *
 * Run:
 *   php artisan tinker --execute="require 'tinker/debug/ai_generation_service_smoke.php';"
 */

use Illuminate\Support\Str;

echo "\n== ContentGeneratorService Smoke Test ==\n";

// Require OpenRouter API key
if (!config('services.openrouter.api_key')) {
    echo "OPENROUTER_API_KEY not set; set it to run this test.\n";
    return;
}

// Resolve a user
$user = \App\Models\User::query()->first();
if (!$user) {
    echo "No users found. Create a user first (factory/seed), then rerun.\n";
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

// Build a simple prompt and options
$prompt = 'Write a short, contrarian post about why most startup content fails and how to build trust instead.';
$platform = 'twitter';
$options = [
    'max_chars' => 500,
    'emoji' => 'disallow',
    'tone' => 'direct',
    // Keep retrieval light for snappy tests
    'retrieval_limit' => 3,
    // Optional additional context to nudge persona/audience
    'user_context' => 'Audience: SaaS founders; Persona: experienced operator; Avoid hype.',
];

/** @var \App\Services\Ai\ContentGeneratorService $generator */
$generator = app(\App\Services\Ai\ContentGeneratorService::class);

$result = $generator->generate(
    orgId: (string) $org->id,
    userId: (string) $user->id,
    prompt: $prompt,
    platform: $platform,
    options: $options,
);

echo "\n-- Result --\n";
echo "Validation: " . (($result['validation_result'] ?? false) ? 'ok' : 'failed') . "\n";
echo "Intent/Funnel: " . (($result['metadata']['intent'] ?? 'n/a') . ' / ' . ($result['metadata']['funnel_stage'] ?? 'n/a')) . "\n";

$content = (string) ($result['content'] ?? '');
echo "\nContent (preview):\n";
echo mb_substr($content, 0, 500) . (mb_strlen($content) > 500 ? '...' : '') . "\n";

// Show a brief snapshot of context used (IDs only)
$snap = (array) ($result['context_used'] ?? []);
$tmplId = $snap['template_id'] ?? null;
$chunks = (array) ($snap['chunk_ids'] ?? []);
$facts  = (array) ($snap['fact_ids'] ?? []);
$swipes = (array) ($snap['swipe_ids'] ?? []);
echo "\nContext Used: template=" . ($tmplId ?: 'none')
    . ", chunks=" . count($chunks)
    . ", facts=" . count($facts)
    . ", swipes=" . count($swipes) . "\n";

echo "\n== Done ==\n";

