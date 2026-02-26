<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected Client $http;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $chatModel;
    protected string $classifierModel;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?: new Client(['timeout' => 60]);
        $this->apiKey = (string) config('services.openrouter.api_key');
        $configured = (string) config('services.openrouter.api_url', 'https://api.openrouter.ai/v1');
        $this->baseUrl = rtrim($this->normalizeBaseUrl($configured), '/');
        // Prefer explicit chat/classifier models; fall back to envs if provided
        $this->chatModel = (string) (config('services.openrouter.chat_model')
            ?: env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'));
        $this->classifierModel = (string) (config('services.openrouter.classifier_model')
            ?: env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'));
    }

    /**
     * Call chat completions and return the assistant text content.
     */
    public function chat(array $messages, array $options = []): string
    {
        $model = $this->resolveChatModel($options);
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ] + $this->buildOptions($options, [
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ]);

        try {
            $resp = $this->send('/chat/completions', $payload);

            if (!empty($resp['json']) && is_array($resp['json'])) {
                $data = $resp['json'];
                Log::info('openrouter.chat.response', Arr::only($data, ['id','model']));
                $content = Arr::get($data, 'choices.0.message.content');
                return is_string($content) ? $content : '';
            }

            // Log diagnostics when non-JSON or empty
            Log::warning('OpenRouter chat non-json/empty response', [
                'status' => $resp['status'] ?? null,
                'content_type' => $resp['headers']['content-type'][0] ?? null,
                'body_preview' => mb_substr((string)($resp['body'] ?? ''), 0, 500),
                'base_url' => $this->baseUrl,
            ]);
            return '';
        } catch (\Throwable $e) {
            Log::error('OpenRouter chat error', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /** Return assistant text and meta (model, usage). */
    public function chatWithMeta(array $messages, array $options = []): array
    {
        $model = $this->resolveChatModel($options);
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ] + $this->buildOptions($options, [
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ]);

        try {
            $resp = $this->send('/chat/completions', $payload);
            $data = $resp['json'] ?? null;
            $content = is_array($data) ? Arr::get($data, 'choices.0.message.content') : '';
            return [
                'data' => is_string($content) ? $content : '',
                'meta' => [
                    'model' => is_array($data) ? ($data['model'] ?? null) : null,
                    'usage' => is_array($data) ? ($data['usage'] ?? []) : [],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenRouter chatWithMeta error', ['error' => $e->getMessage()]);
            return ['data' => '', 'meta' => []];
        }
    }

    /**
     * Call chat completions expecting a JSON object response. Decodes JSON.
     * Applies a strict JSON-only system instruction to reduce malformed outputs.
     */
    public function chatJSON(array $messages, array $options = []): array
    {
        $schemaHint = isset($options['json_schema_hint']) && is_string($options['json_schema_hint'])
            ? $options['json_schema_hint']
            : null;

        $messages = $this->injectJsonGuardrail($messages, $schemaHint);

        $model = $this->resolveChatModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'stream' => false,
        ] + $this->buildOptions($options, [
            'temperature' => 0.5,
        ]);

        // Do not constrain output length for JSON calls
        if (array_key_exists('max_tokens', $payload)) {
            unset($payload['max_tokens']);
        }

        try {
            $resp = $this->send('/chat/completions', $payload);
            if (!empty($resp['json']) && is_array($resp['json'])) {
                $data = $resp['json'];
                Log::info('openrouter.chatJSON.response', Arr::only($data, ['id','model']));
                $content = Arr::get($data, 'choices.0.message.content');
                $decoded = is_string($content) ? json_decode($content, true) : null;
                if (is_array($decoded)) {
                    if (empty($decoded)) {
                        Log::warning('openrouter.chatJSON.empty_decoded', Arr::only($data, ['id','model','usage']) + [
                            'content_preview' => is_string($content) ? mb_substr($content, 0, 200) : null,
                        ]);
                    }
                    return $decoded;
                }
            }

            Log::warning('OpenRouter chatJSON non-json/empty response', [
                'status' => $resp['status'] ?? null,
                'content_type' => $resp['headers']['content-type'][0] ?? null,
                'body_preview' => mb_substr((string)($resp['body'] ?? ''), 0, 500),
                'base_url' => $this->baseUrl,
            ]);
            return [];
        } catch (\Throwable $e) {
            Log::error('OpenRouter chatJSON error', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** Return decoded JSON object and meta (model, usage). */
    public function chatJSONWithMeta(array $messages, array $options = []): array
    {
        $schemaHint = isset($options['json_schema_hint']) && is_string($options['json_schema_hint'])
            ? $options['json_schema_hint']
            : null;

        $messages = $this->injectJsonGuardrail($messages, $schemaHint);

        $model = $this->resolveChatModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'stream' => false,
        ] + $this->buildOptions($options, [
            'temperature' => 0.5,
        ]);

        // Do not constrain output length for JSON calls
        if (array_key_exists('max_tokens', $payload)) {
            unset($payload['max_tokens']);
        }

        try {
            $resp = $this->send('/chat/completions', $payload);
            $data = $resp['json'] ?? null;
            $content = is_array($data) ? Arr::get($data, 'choices.0.message.content') : '';
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (is_array($decoded) && empty($decoded)) {
                Log::warning('openrouter.chatJSONWithMeta.empty_decoded', Arr::only((array) $data, ['id','model','usage']) + [
                    'content_preview' => is_string($content) ? mb_substr($content, 0, 200) : null,
                ]);
            }
            return [
                'data' => is_array($decoded) ? $decoded : [],
                'meta' => [
                    'model' => is_array($data) ? ($data['model'] ?? null) : null,
                    'usage' => is_array($data) ? ($data['usage'] ?? []) : [],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('OpenRouter chatJSONWithMeta error', ['error' => $e->getMessage()]);
            return ['data' => [], 'meta' => []];
        }
    }

    /**
     * Lightweight intent classification, expecting JSON: {intent, confidence}.
     */
    public function classify(array $messages, array $options = []): array
    {
        $model = $this->resolveClassifierModel($options);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'max_tokens' => 200,
        ];

        try {
            $resp = $this->send('/chat/completions', $payload);
            if (!empty($resp['json']) && is_array($resp['json'])) {
                $data = $resp['json'];
                Log::info('openrouter.classify.response', Arr::only($data, ['id','model']));
                $content = Arr::get($data, 'choices.0.message.content');
                $decoded = is_string($content) ? json_decode($content, true) : null;
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            Log::warning('OpenRouter classify non-json/empty response', [
                'status' => $resp['status'] ?? null,
                'content_type' => $resp['headers']['content-type'][0] ?? null,
                'body_preview' => mb_substr((string)($resp['body'] ?? ''), 0, 500),
                'base_url' => $this->baseUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenRouter classify error', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to safe default
        return ['intent' => 'chat_only', 'confidence' => 1.0];
    }

    /**
     * Same as classify() but returns meta including the actual model used.
     * Returns ['data'=>array, 'meta'=>array{model?:string,usage?:array}]
     */
    public function classifyWithMeta(array $messages, array $options = []): array
    {
        $model = $this->resolveClassifierModel($options);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'max_tokens' => 200,
        ];

        try {
            $resp = $this->send('/chat/completions', $payload);
            $data = $resp['json'] ?? null;
            if (is_array($data)) {
                Log::info('openrouter.classify.response', Arr::only($data, ['id','model']));
                $content = Arr::get($data, 'choices.0.message.content');
                $decoded = is_string($content) ? json_decode($content, true) : null;
                return [
                    'data' => is_array($decoded) ? $decoded : [],
                    'meta' => [
                        'model' => is_array($data) ? ($data['model'] ?? null) : null,
                        'usage' => is_array($data) ? ($data['usage'] ?? []) : [],
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Log::error('OpenRouter classifyWithMeta error', ['error' => $e->getMessage()]);
        }

        return ['data' => ['intent' => 'chat_only', 'confidence' => 1.0], 'meta' => ['model' => $model, 'usage' => []]];
    }

    /**
     * Resolve a per-call chat model override.
     * Supported option keys: model, openrouter_model, chat_model.
     */
    protected function resolveChatModel(array $options): string
    {
        $m = (string) ($options['model'] ?? ($options['openrouter_model'] ?? ($options['chat_model'] ?? '')));
        $m = trim($m);
        return $m !== '' ? $m : $this->chatModel;
    }

    /**
     * Resolve a per-call classifier model override.
     * Supported option keys: model, openrouter_model, classifier_model.
     */
    protected function resolveClassifierModel(array $options): string
    {
        $m = (string) ($options['model'] ?? ($options['openrouter_model'] ?? ($options['classifier_model'] ?? '')));
        $m = trim($m);
        return $m !== '' ? $m : $this->classifierModel;
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'HTTP-Referer' => (string) config('app.url'),
            'X-Title' => (string) config('app.name'),
        ];
    }

    protected function buildOptions(array $overrides, array $defaults): array
    {
        return array_merge($defaults, $overrides);
    }

    /**
     * Build request options: headers, json, and optional proxy configuration.
     */
    protected function requestOptions(array $json): array
    {
        $opts = [
            'headers' => $this->headers(),
            'json' => $json,
            'http_errors' => false,
        ];
        $proxy = $this->proxyOptions();
        if (!empty($proxy)) {
            $opts['proxy'] = $proxy;
        }
        return $opts;
    }

    /**
     * Read proxy settings from environment if present.
     */
    protected function proxyOptions(): array
    {
        $http = env('HTTP_PROXY') ?: env('http_proxy');
        $https = env('HTTPS_PROXY') ?: env('https_proxy');
        $no = env('NO_PROXY') ?: env('no_proxy');
        $out = [];
        if ($http || $https || $no) {
            if ($http) $out['http'] = $http;
            if ($https) $out['https'] = $https;
            if ($no) $out['no'] = $no;
        }
        return $out;
    }

    /**
     * Normalize OpenRouter base URLs so both openrouter.ai/v1 and api.openrouter.ai/v1 work.
     */
    protected function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = '/' . ltrim($parts['path'] ?? '/v1', '/');

        if ($host === 'openrouter.ai') {
            if (str_starts_with($path, '/v1')) {
                $path = '/api' . $path; // insert /api when missing
            }
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * Inject a strict JSON-only instruction as a system message.
     * If a schema hint/example is provided, include it for clarity.
     */
    private function injectJsonGuardrail(array $messages, ?string $schemaHint = null): array
    {
        $instruction = "You are a JSON API. Respond with ONE valid JSON object and nothing else. "
            . "Do not include code fences, markdown, or commentary before or after the JSON. ";

        if ($schemaHint) {
            $instruction .= "Follow this schema exactly: " . $schemaHint . " ";
        } else {
            $instruction .= "Follow the JSON schema or fields requested in the prompt. ";
        }

        $instruction .= "If you cannot comply, return an empty JSON object {} with required fields present but empty or null.";

        // If there are existing system prompts, merge into a single system authority.
        $systemIndexes = [];
        foreach ($messages as $i => $m) {
            if (is_array($m) && ($m['role'] ?? null) === 'system' && isset($m['content']) && is_string($m['content'])) {
                $systemIndexes[] = $i;
            }
        }

        if (!empty($systemIndexes)) {
            $combined = $instruction;
            foreach ($systemIndexes as $idx) {
                $combined .= "\n\n" . (string) $messages[$idx]['content'];
            }
            // Keep only the first system message, replace its content; remove others.
            $first = array_shift($systemIndexes);
            $messages[$first]['content'] = $combined;
            // Remove remaining system messages (from end to start to keep indexes valid)
            rsort($systemIndexes);
            foreach ($systemIndexes as $idx) {
                array_splice($messages, $idx, 1);
            }
            return $messages;
        }

        // Otherwise, prepend a single system instruction
        array_unshift($messages, [
            'role' => 'system',
            'content' => $instruction,
        ]);

        return $messages;
    }

    /**
     * Low-level POST with diagnostics. Returns array: status, headers, body, json.
     */
    protected function send(string $path, array $json): array
    {
        $res = $this->http->post($this->baseUrl . $path, $this->requestOptions($json));
        $status = $res->getStatusCode();
        $headers = $res->getHeaders();
        $body = (string) $res->getBody();

        $decoded = null;
        if (stripos($headers['Content-Type'][0] ?? '', 'application/json') !== false) {
            $decoded = json_decode($body, true);
        } else {
            // Attempt decode anyway in case server mislabels content-type
            $try = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $try;
            }
        }

        return [
            'status' => $status,
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body' => $body,
            'json' => $decoded,
        ];
    }
}
