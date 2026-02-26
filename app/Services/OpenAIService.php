<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;

class OpenAIService
{
    protected Client $http;
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?: new Client(['timeout' => 20]);
        $this->apiKey = env('OPENAI_API_KEY');
        $this->baseUrl = rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $this->model = env('OPENAI_MODEL', 'gpt-4o-mini');
    }

    public function generateSlideshowContent(array $params): array
    {
        $prompt = (string)($params['prompt'] ?? '');
        $slideCount = max(3, min((int)($params['slide_count'] ?? 7), 20));
        $language = (string)($params['language'] ?? 'English');
        $theme = (string)($params['theme'] ?? 'modern');

        // Try API if available; otherwise fall back
        if ($this->apiKey) {
            try {
                $payload = [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are an expert slideshow content creator. Generate engaging, structured content for a {$slideCount}-slide presentation in {$language}. For each slide, provide JSON with keys: title, subtitle, text_elements (array of {text, x, y, font_size, font_weight}). Slide 1 is a hook; middle slides deliver value; final slide has a clear CTA. Keep headings under 15 words. Respond ONLY with valid JSON: {\"title\": string, \"slides\": [{...}]}"
                        ],
                        [
                            'role' => 'user',
                            'content' => "Prompt: {$prompt}\nTheme: {$theme}\nSlide count: {$slideCount}\nLanguage: {$language}"
                        ],
                    ],
                    'temperature' => 0.7,
                    'response_format' => ['type' => 'json_object'],
                ];

                $res = $this->http->post($this->baseUrl . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $data = json_decode((string)$res->getBody(), true);
                \Log::info('openai.generateSlideshowContent.response', ['trace' => Arr::only($data ?? [], ['id','model'])]);
                $content = Arr::get($data, 'choices.0.message.content');
                if ($content) {
                    $json = json_decode($content, true);
                    if (is_array($json) && isset($json['slides']) && is_array($json['slides'])) {
                        return $json;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to deterministic fallback
            }
        }

        // Fallback deterministic content
        $title = $this->extractTitleFromPrompt($prompt) ?: 'AI-Generated Slideshow';
        $slides = [];
        for ($i = 1; $i <= $slideCount; $i++) {
            $slides[] = [
                'title' => $i === 1 ? "{$title}: The Hook" : ($i === $slideCount ? 'Final Thoughts & CTA' : "Key Idea #{$i}"),
                'subtitle' => $i === 1 ? 'A quick look at the problem' : ($i === $slideCount ? 'Take action today' : 'Actionable insight'),
                'text_elements' => [
                    [
                        'text' => $i === 1 ? 'Attention-grabbing opener' : ($i === $slideCount ? 'Follow for more, and share!' : "Tip {$i}: Keep it simple"),
                        'x' => 100,
                        'y' => 220,
                        'font_size' => $i === 1 ? 64 : 48,
                        'font_weight' => $i === 1 ? '700' : '600',
                    ],
                ],
            ];
        }

        return [
            'title' => $title,
            'slides' => $slides,
        ];
    }

    public function assistPrompt(array $params): array
    {
        $context = (string)($params['context'] ?? '');
        $language = (string)($params['language'] ?? 'English');
        $suggested = [
            'prompt' => "Create a 7-slide slideshow about {$context}. Start with a compelling hook, include 3-4 actionable insights, and end with a clear CTA.",
            'slide_count' => 7,
            'suggested_theme' => 'modern',
            'language' => $language,
        ];

        if (! $this->apiKey) {
            return $suggested;
        }

        try {
            $payload = [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You help users craft concise slideshow prompts. Respond as JSON with keys: prompt, slide_count, suggested_theme.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Context: {$context}\nLanguage: {$language}"
                    ],
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
            ];

            $res = $this->http->post($this->baseUrl . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode((string)$res->getBody(), true);
            \Log::info('openai.assistPrompt.response', ['trace' => Arr::only($data ?? [], ['id','model'])]);
            $content = Arr::get($data, 'choices.0.message.content');
            $json = $content ? json_decode($content, true) : null;
            if (is_array($json) && isset($json['prompt'])) {
                return array_merge($suggested, $json);
            }
        } catch (\Throwable $e) {
            // ignore and return suggested
        }

        return $suggested;
    }

    protected function extractTitleFromPrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }
        // Heuristic: take first 6 words, title case
        $words = preg_split('/\s+/', $prompt);
        $first = array_slice($words, 0, 6);
        $candidate = implode(' ', $first);
        return ucwords(rtrim($candidate, '.:;!?, '));
    }
}
