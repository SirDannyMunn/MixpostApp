<?php

namespace App\Services\ImageGeneration\Providers;

use App\Services\ImageGeneration\ImageProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleImagesProvider implements ImageProviderInterface
{
    public function generate(array $options): array
    {
        $prompt = (string) ($options['prompt'] ?? '');
        if ($prompt === '') {
            throw new \InvalidArgumentException('Missing prompt');
        }

        $apiKey = (string) config('services.google.api_key');
        $base   = rtrim((string) config('services.google.api_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        // IMPORTANT: must be an image-capable Gemini 3 model
        $model = (string) ($options['model']
            ?? config('services.google.image_model', 'gemini-3-pro-image-preview'));

        if ($apiKey === '') {
            throw new \RuntimeException('Missing GOOGLE_API_KEY');
        }

        $endpoint = "{$base}/models/{$model}:generateContent";

        // ✅ CORRECT payload for Gemini 3 image generation
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => [
                    'imageSize' => '1K',
                ],
            ],
            // Optional but supported
            // 'tools' => [
            //     ['googleSearch' => new \stdClass()],
            // ],
        ];

        try {
            $resp = Http::asJson()
                ->timeout(120)
                ->retry(3, 1500)
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::error('Google Images API transport error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'payload' => $this->debugPayload($payload, $prompt),
            ]);

            throw new \RuntimeException(
                'Failed to reach Google Images API: ' . $e->getMessage(),
                previous: $e
            );
        }

        if (!$resp->successful()) {
            Log::error('Google Images API error', [
                'endpoint' => $endpoint,
                'status'   => $resp->status(),
                'body'     => $this->clip($resp->body(), 6000),
                'payload'  => $this->debugPayload($payload, $prompt),
            ]);

            throw new \RuntimeException(
                'Failed to generate image via Google Images API (' . $resp->status() . ')'
            );
        }

        $data = $resp->json();
        $b64  = $this->extractBase64($data);

        if (!$b64) {
            Log::error('Google Images API success but no image found', [
                'endpoint' => $endpoint,
                'body'     => $this->clip($resp->body(), 6000),
                'payload'  => $this->debugPayload($payload, $prompt),
            ]);

            throw new \RuntimeException('Google Images API returned no image data');
        }

        return [
            'url'            => '',
            'b64'            => $b64,
            'revised_prompt' => $prompt,
            'model'          => 'google:' . $model,
        ];
    }

    /**
     * Extract inline base64 image from Gemini 3 response
     */
    protected function extractBase64(array $data): ?string
    {
        foreach ($data['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData']['data'])) {
                    return (string) $part['inlineData']['data'];
                }
            }
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

        if (isset($copy['contents'][0]['parts'][0]['text'])) {
            $copy['contents'][0]['parts'][0]['text'] =
                mb_strimwidth($copy['contents'][0]['parts'][0]['text'], 0, 300, '...');
        }

        $copy['_prompt_preview'] = mb_strimwidth($prompt, 0, 300, '...');
        return $copy;
    }
}
