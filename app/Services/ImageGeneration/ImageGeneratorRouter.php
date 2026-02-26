<?php

namespace App\Services\ImageGeneration;

use App\Services\ImageGeneration\Providers\GoogleImagesProvider;
use App\Services\ImageGeneration\Providers\OpenRouterImagesProvider;

class ImageGeneratorRouter
{
    public function __construct(
        protected GoogleImagesProvider $google,
        protected OpenRouterImagesProvider $openrouter,
    ) {}

    /**
     * Route the request to a concrete provider based on the 'model' string.
     * For now defaults to Google Images API.
     */
    public function generate(array $options): array
    {
        $model = (string) ($options['model'] ?? 'google');
        $m = strtolower($model);

        // Explicit routing
        if ($m === '' || str_starts_with($m, 'google')) {
            try {
                return $this->google->generate($options);
            } catch (\Throwable $e) {
                // If Google fails and OpenRouter is configured, try it before bubbling up
                if ((string) config('services.openrouter.api_key') !== '') {
                    return $this->openrouter->generate([
                        ...$options,
                        'model' => config('services.openrouter.default_model', 'openai/dall-e-3'),
                    ]);
                }
                throw $e;
            }
        }
        if (str_starts_with($m, 'openrouter') || str_starts_with($m, 'openai')) {
            return $this->openrouter->generate($options);
        }

        // Default fallback: Google then OpenRouter
        try {
            return $this->google->generate($options);
        } catch (\Throwable $e) {
            if ((string) config('services.openrouter.api_key') !== '') {
                return $this->openrouter->generate([
                    ...$options,
                    'model' => config('services.openrouter.default_model', 'openai/dall-e-3'),
                ]);
            }
            throw $e;
        }
    }
}
