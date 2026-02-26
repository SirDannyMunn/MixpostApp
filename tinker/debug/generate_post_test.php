<?php

// Tinker debug script to exercise the AI generate-post endpoint without an HTTP server.
// Usage:
//   php artisan tinker --execute="require 'tinker/debug/generate_post_test.php';"
// or within an interactive tinker session:
//   >>> require 'tinker/debug/generate_post_test.php';

use Illuminate\Http\Request;
use Illuminate\Support\Str;

echo "\n== Debug: AI Generate Post ==\n";

// 1) Resolve an existing user; abort with guidance if none found.
$user = \App\Models\User::query()->first();
if (!$user) {
    echo "No users found. Create a user first, then rerun.\n";
    echo "Example: php artisan tinker --execute=\"\\App\\Models\\User::factory()->create();\"\n";
    return;
}

// 2) Resolve or create an organization (middleware is bypassed here; controller uses request attribute)
$organization = \App\Models\Organization::query()->first();
if (!$organization) {
    $organization = \App\Models\Organization::create([
        'name' => 'Debug Org',
        'slug' => 'debug-' . Str::lower(Str::random(6)),
    ]);
    echo "Created organization: {$organization->id} ({$organization->slug})\n";
}

// 3) Build a valid request payload per AiController::generatePost validation rules
$payload = [
    'platform' => 'twitter',
    'prompt' => 'Draft a short, professional post announcing our new AI-assisted content generation feature with a soft CTA.',
    'context' => 'Mixpost just launched AI tools to help teams draft and refine posts faster.',
    'options' => [
        'max_chars' => 500,
        'cta' => 'soft',          // none | implicit | soft | direct
        'emoji' => 'disallow',    // allow | disallow
        'tone' => 'professional',
    ],
    'bookmark_ids' => [1,2],  // optional: include if you have bookmark IDs
];

// 4) Create a Request and inject auth + organization context
$request = Request::create('/api/v1/ai/generate-post', 'POST', $payload);
$request->headers->set('Accept', 'application/json');
$request->setUserResolver(fn () => $user);
$request->attributes->set('organization', $organization);

// 5) Invoke controller directly and print the response
/** @var \App\Http\Controllers\Api\V1\AiController $controller */
$controller = app(\App\Http\Controllers\Api\V1\AiController::class);

try {
    $response = $controller->generatePost($request, app(\App\Services\OpenRouterService::class));
    $status = $response->getStatusCode();
    $content = $response->getContent();
    echo "Status: {$status}\n";
    echo "Response: {$content}\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "== Done ==\n";

