<?php

use GuzzleHttp\Client;

/**
 * Tinker script: Ping OpenRouter /chat/completions directly to debug DNS/host/response.
 *
 * Run:
 *   php artisan tinker:debug openrouter_ping
 */

$apiKey = (string) config('services.openrouter.api_key');
$configured = (string) config('services.openrouter.api_url', 'https://api.openrouter.ai/v1');
// Normalize: if using openrouter.ai/v1 add /api path
$normBase = (function (string $url): string {
    $url = rtrim($url, '/');
    $p = parse_url($url) ?: [];
    $scheme = $p['scheme'] ?? 'https';
    $host = $p['host'] ?? '';
    $path = '/' . ltrim($p['path'] ?? '/v1', '/');
    if ($host === 'openrouter.ai' && str_starts_with($path, '/v1')) {
        $path = '/api' . $path;
    }
    return $scheme . '://' . $host . rtrim($path, '/');
})($configured);
$base = rtrim($normBase, '/');
$model = (string) (config('services.openrouter.classifier_model') ?: env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'));

$client = new Client(['timeout' => 60]);

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => 'Return compact JSON {"pong":true}'],
        ['role' => 'user', 'content' => 'reply only with {"pong":true}'],
    ],
    'response_format' => ['type' => 'json_object'],
    'max_tokens' => 50,
    'temperature' => 0,
];

$opts = [
    'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'HTTP-Referer' => (string) config('app.url'),
        'X-Title' => (string) config('app.name'),
    ],
    'json' => $payload,
    'http_errors' => false,
];

// Optional proxy support
$proxy = array_filter([
    'http' => env('HTTP_PROXY') ?: env('http_proxy'),
    'https' => env('HTTPS_PROXY') ?: env('https_proxy'),
    'no' => env('NO_PROXY') ?: env('no_proxy'),
]);
if (!empty($proxy)) {
    $opts['proxy'] = $proxy;
}

try {
    $res = $client->post($base . '/chat/completions', $opts);
    $status = $res->getStatusCode();
    $headers = $res->getHeaders();
    $body = (string) $res->getBody();

    dump('base=' . $base);
    dump('status=' . $status);
    dump('content-type=' . (($headers['Content-Type'][0] ?? $headers['content-type'][0] ?? '') ?: ''));
    dump('body_preview=' . mb_substr($body, 0, 400));

    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        dump(['json' => $decoded]);
    }
} catch (\Throwable $e) {
    dump('EXCEPTION: ' . get_class($e) . ' - ' . $e->getMessage());
}
