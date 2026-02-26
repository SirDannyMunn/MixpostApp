<?php

namespace App\Services\ImageGeneration\Providers;

use App\Services\ImageGeneration\ImageProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterImagesProvider implements ImageProviderInterface
{
    public function generate(array $options): array
    {
        $prompt = (string) ($options['prompt'] ?? '');
        if ($prompt === '') {
            throw new \InvalidArgumentException('Missing prompt');
        }

        $aspectRatio = (string) ($options['aspect_ratio'] ?? '1:1');
        $size = $this->mapAspectRatioToSize($aspectRatio);

        $apiKey = (string) config('services.openrouter.api_key');
        $base = rtrim((string) config('services.openrouter.api_url', 'https://api.openrouter.ai/v1'), '/');
        $model = (string) ($options['model'] ?? config('services.openrouter.default_model', 'openai/dall-e-3'));

        if ($apiKey === '') {
            throw new \RuntimeException('Missing OPENROUTER_API_KEY');
        }

        $endpoint = $base . '/images/generations';

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'response_format' => 'b64_json',
        ];

        try {
            $resp = Http::asJson()->timeout(180)->retry(3, 1500)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => (string) config('app.name', 'MixpostApp'),
                    'Accept' => 'application/json',
                ])
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::error('OpenRouter Images transport error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'payload' => $this->debugPayload($payload, $prompt),
            ]);
            throw new \RuntimeException('Failed to reach OpenRouter Images API: ' . $e->getMessage(), previous: $e);
        }

        if ($resp->successful()) {
            $data = $resp->json();
            $b64 = $this->extractBase64($data);
            if ($b64) {
                return [
                    'url' => '',
                    'b64' => $b64,
                    'revised_prompt' => $prompt,
                    'model' => 'openrouter:' . $model,
                ];
            }
            try {
                Log::error('OpenRouter Images success but no image found', [
                    'endpoint' => $endpoint,
                    'status' => $resp->status(),
                    'headers' => method_exists($resp, 'headers') ? $resp->headers() : [],
                    'body' => $this->clip($resp->body(), 6000),
                    'parsed_keys' => array_keys((array) $data),
                    'payload' => $this->debugPayload($payload, $prompt),
                ]);
            } catch (\Throwable $logEx) {}
            throw new \RuntimeException('OpenRouter API returned no image data');
        }

        try {
            Log::error('OpenRouter Images API error', [
                'endpoint' => $endpoint,
                'status' => $resp->status(),
                'reason' => method_exists($resp, 'reason') ? $resp->reason() : '',
                'headers' => method_exists($resp, 'headers') ? $resp->headers() : [],
                'body' => $this->clip($resp->body(), 6000),
                'payload' => $this->debugPayload($payload, $prompt),
            ]);
        } catch (\Throwable $logEx) {}
        throw new \RuntimeException('Failed to generate image via OpenRouter (' . $resp->status() . ')');
    }

    protected function mapAspectRatioToSize(string $aspectRatio): string
    {
        return match ($aspectRatio) {
            '1:1' => '1024x1024',
            '16:9' => '1792x1024',
            '9:16' => '1024x1792',
            '4:3' => '1024x768',
            default => '1024x1024',
        };
    }

    protected function extractBase64($data): ?string
    {
        // OpenAI-compatible images API
        if (isset($data['data'][0]['b64_json'])) {
            return (string) $data['data'][0]['b64_json'];
        }
        // Some providers return 'images: [ { base64 } ]'
        if (isset($data['images'][0]['base64'])) {
            return (string) $data['images'][0]['base64'];
        }
        if (isset($data['images'][0]['b64_json'])) {
            return (string) $data['images'][0]['b64_json'];
        }
        return null;
    }

    protected function clip(?string $text, int $limit = 4000): string
    {
        $text = (string) ($text ?? '');
        if (strlen($text) <= $limit) return $text;
        return substr($text, 0, $limit) . '...';
    }

    protected function debugPayload(array $payload, string $prompt): array
    {
        $copy = $payload;
        if (isset($copy['prompt'])) {
            $copy['prompt'] = mb_strimwidth((string) $copy['prompt'], 0, 300, '...');
        }
        $copy['_prompt_preview'] = mb_strimwidth($prompt, 0, 300, '...');
        return $copy;
    }
}
