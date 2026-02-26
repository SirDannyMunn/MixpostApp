<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class EmbeddingsService
{
    protected Client $http;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected int $dimensions;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?: new Client(['timeout' => 60]);
        // Reuse OpenRouter credentials by default
        $this->apiKey = (string) config('services.openrouter.api_key');
        $configured = (string) config('services.openrouter.api_url', 'https://api.openrouter.ai/v1');
        $this->baseUrl = rtrim($this->normalizeBaseUrl($configured), '/');
        // Align with pgvector column size (1536) — use text-embedding-3-small
        $this->model = (string) (env('OPENROUTER_EMBED_MODEL') ?: 'openai/text-embedding-3-small');
        $this->dimensions = 1536;
    }

    /**
     * Embed a single text input.
     * Returns float[] length == $this->dimensions or empty array on failure.
     */
    public function embedOne(string $text): array
    {
        $out = $this->embedMany([$text]);
        return $out[0] ?? [];
    }

    /**
     * Embed many inputs (batch).
     * Returns array of float[] in the same order as inputs.
     */
    public function embedMany(array $inputs): array
    {
        $payload = [
            'model' => $this->model,
            'input' => array_values(array_map(fn($t) => (string) $t, $inputs)),
        ];

        try {
            $res = $this->http->post($this->baseUrl . '/embeddings', [
                'headers' => $this->headers(),
                'json' => $payload,
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $data = json_decode((string) $res->getBody(), true);
            if ($status >= 200 && $status < 300 && is_array($data)) {
                $vectors = [];
                foreach ((array) ($data['data'] ?? []) as $row) {
                    $vec = $row['embedding'] ?? [];
                    if (is_array($vec) && count($vec) > 0) {
                        // Normalize length if provider returns different dims
                        $vectors[] = $this->normalizeVector($vec, $this->dimensions);
                    } else {
                        $vectors[] = [];
                    }
                }
                Log::info('embeddings.batch.ok', ['count' => count($vectors)]);
                return $vectors;
            }
            Log::warning('embeddings.batch.failed', [
                'status' => $status,
                'body_preview' => mb_substr((string) $res->getBody(), 0, 300),
            ]);
        } catch (\Throwable $e) {
            Log::error('embeddings.batch.error', ['error' => $e->getMessage()]);
        }

        // Deterministic local fallback (for eval harness and offline runs)
        $fallback = array_map(fn($t) => $this->deterministicVector((string) $t), $inputs);
        Log::info('embeddings.batch.fallback_deterministic', ['count' => count($fallback), 'dim' => $this->dimensions]);
        return $fallback;
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = '/' . ltrim($parts['path'] ?? '/v1', '/');
        if ($host === 'openrouter.ai' && str_starts_with($path, '/v1')) {
            $path = '/api' . $path;
        }
        return $scheme . '://' . $host . $path;
    }

    /**
     * Ensure vector has required dimension: pad with zeros or truncate.
     */
    protected function normalizeVector(array $vec, int $dim): array
    {
        $v = array_map('floatval', $vec);
        $n = count($v);
        if ($n === $dim) return $v;
        if ($n > $dim) return array_slice($v, 0, $dim);
        // Pad
        return array_pad($v, $dim, 0.0);
    }

    /**
     * Build a deterministic pseudo-embedding for a string.
     * Uses CRC32 per-dimension hashing mapped to [-1, 1]. Stable across runs.
     */
    private function deterministicVector(string $text): array
    {
        $dim = $this->dimensions;
        $vec = [];
        for ($i = 0; $i < $dim; $i++) {
            $h = crc32($text . '|' . $i);
            // Map to [0,1], then to [-1,1]
            $u = (($h & 0xFFFFFFFF) / 4294967295);
            $val = (float) ($u * 2.0 - 1.0);
            $vec[] = $val;
        }
        return $vec;
    }
}
