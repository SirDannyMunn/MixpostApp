<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Tinker script: POST classify-intent to a remote endpoint with custom headers.
 *
 * Run:
 *   php artisan tinker:debug ai_intent_classify_request
 *
 * Optional .env overrides:
 *   TINKER_CLASSIFY_URL=https://example.com/api/v1/ai/classify-intent
 *   TINKER_DISABLE_SSL_VERIFY=true
 *   HTTP_PROXY=... / HTTPS_PROXY=... / NO_PROXY=...
 *   TINKER_AI_MESSAGE="..."  TINKER_AI_DOC="..."
 */

$url = env('TINKER_CLASSIFY_URL', 'https://social-scheduler-dev.usewebmania.com/api/v1/ai/classify-intent');

$payload = [
    'message' => env('TINKER_AI_MESSAGE', 'update the document to be a nice poem by Dante'),
    'document_context' => env('TINKER_AI_DOC', "# Welcome to AI Canvas\n\nStart chatting to create and edit your document."),
];

$headers = [
    'sec-ch-ua-platform' => '"Windows"',
    'X-Organization-Id' => '4',
    'Referer' => 'https://f16099a3-f99f-4a71-81f2-1c974ee072a9-v2-figmaiframepreview.figma.site/',
    'sec-ch-ua' => '"Chromium";v="142", "Google Chrome";v="142", "Not_A Brand";v="99"',
    'sec-ch-ua-mobile' => '?0',
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
];

$client = Http::withHeaders($headers)->timeout(60);

// Optional TLS verify toggle for local debugging only
if (filter_var(env('TINKER_DISABLE_SSL_VERIFY', false), FILTER_VALIDATE_BOOLEAN)) {
    $client = $client->withoutVerifying();
}

// Optional proxy support via env
$proxy = array_filter([
    'http' => env('HTTP_PROXY') ?: env('http_proxy'),
    'https' => env('HTTPS_PROXY') ?: env('https_proxy'),
    'no' => env('NO_PROXY') ?: env('no_proxy'),
]);
if (!empty($proxy)) {
    $client = $client->withOptions(['proxy' => $proxy]);
}

try {
    $response = $client->post($url, $payload);

    dump('URL: ' . $url);
    dump('Status: ' . $response->status());
    dump('OK: ' . ($response->successful() ? 'true' : 'false'));
    dump('Response headers (subset):', Arr::only($response->headers(), ['content-type','date','server']));

    $body = (string) $response->body();
    $json = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        dump('JSON body:', $json);
    } else {
        dump('Raw body:', $body);
    }
} catch (\Throwable $e) {
    dump('Request failed with exception: ' . get_class($e));
    dump('Message: ' . $e->getMessage());
    // In case of connection issues, show proxy/dns hints
    dump('Hints: check DNS/proxy/SSL. You can set TINKER_DISABLE_SSL_VERIFY=true to ignore TLS for testing.');
}

