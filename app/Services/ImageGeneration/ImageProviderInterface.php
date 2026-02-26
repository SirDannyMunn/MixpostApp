<?php

namespace App\Services\ImageGeneration;

interface ImageProviderInterface
{
    /**
     * Generate an image.
     *
     * Expected options keys:
     * - prompt (string, required)
     * - aspect_ratio (string, optional: 1:1, 16:9, 9:16, 4:3)
     * - model (string|null, provider-specific identifier)
     *
     * Returns array with at least one of:
     * - 'b64' => base64 image data
     * - 'url' => remote URL to download
     * Plus metadata such as 'model'.
     */
    public function generate(array $options): array;
}

