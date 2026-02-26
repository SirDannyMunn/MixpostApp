<?php

namespace App\Services;

use App\Models\MediaImage;
use App\Models\Organization;
use App\Services\ImageGeneration\ImageGeneratorRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class AIImageGenerationService
{
    public function __construct(private readonly ImageGeneratorRouter $router)
    {
    }

    /**
    * Generate an image via OpenRouter and persist to storage and DB.
    */
    public function generateAndSaveImage(
        Organization $organization,
        int $uploadedByUserId,
        string $prompt,
        string $aspectRatio = '1:1',
        ?string $filename = null,
        ?int $packId = null,
        ?string $model = null,
    ): MediaImage {
        $useFallback = (bool) config('services.openrouter.fallback_placeholder', true);
        $bytes = null; $width = 0; $height = 0; $usedModel = $model;
        try {
            $generated = $this->router->generate([
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                'model' => $model,
                'quality' => 'standard',
            ]);

            if (!empty($generated['url'])) {
                $bytes = $this->downloadImage($generated['url']);
            } elseif (!empty($generated['b64'])) {
                $bytes = base64_decode($generated['b64']);
            } else {
                throw new \RuntimeException('Provider did not return image data');
            }
            $usedModel = $generated['model'] ?? ($model ?? 'unknown');
        } catch (\Throwable $e) {
            if (!$useFallback) {
                throw $e;
            }
            // Generate a local placeholder image instead of failing
            [$bytes, $width, $height] = $this->generatePlaceholder($prompt, $aspectRatio);
            $usedModel = 'placeholder';
        }

        if (!$filename) {
            $slugPrompt = Str::slug(Str::limit($prompt, 30));
            $filename = 'ai-' . ($slugPrompt ?: 'image') . '.png';
        }
        $filename = strtolower($filename);
        if (!str_ends_with($filename, '.png') && !str_ends_with($filename, '.jpg') && !str_ends_with($filename, '.jpeg')) {
            $filename .= '.png';
        }

        $unique = $this->uniqueFilename($filename);

        $image = Image::make($bytes);
        if ($width === 0 || $height === 0) {
            $width = $image->width();
            $height = $image->height();
        }

        $disk = (string) config('filesystems.default');
        $dir = "media/{$organization->id}/images";
        $path = $dir . "/{$unique}";
        Storage::disk($disk)->put($path, (string) $image->encode(), 'public');

        $thumbPath = $this->makeThumb($organization, $image, $unique, $disk);

        return MediaImage::create([
            'organization_id'   => $organization->id,
            'pack_id'           => $packId,
            'uploaded_by'       => $uploadedByUserId,
            'filename'          => $unique,
            'original_filename' => $unique,
            'file_path'         => $path,
            'thumbnail_path'    => $thumbPath,
            'file_size'         => strlen($bytes),
            'mime_type'         => 'image/png',
            'width'             => $width,
            'height'            => $height,
            'generation_type'   => 'ai_generated',
            'ai_prompt'         => $prompt,
        ]);
    }

    protected function downloadImage(string $url): string
    {
        $response = \Illuminate\Support\Facades\Http::timeout(60)->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException('Failed to download generated image');
        }
        return (string) $response->body();
    }

    protected function makeThumb(Organization $organization, $image, string $unique, string $disk): string
    {
        $thumb = (clone $image)->fit(400, 400, function ($c) { $c->upsize(); });
        $thumbDir = "media/{$organization->id}/images/thumbnails";
        $thumbPath = $thumbDir . "/{$unique}";
        Storage::disk($disk)->put($thumbPath, (string)$thumb->encode('png'), 'public');
        return $thumbPath;
    }

    protected function uniqueFilename(string $filename): string
    {
        $info = pathinfo($filename);
        $base = $info['filename'] ?? 'image';
        $ext = $info['extension'] ?? 'png';
        $ts = now()->format('YmdHis');
        $rand = Str::random(8);
        return "{$base}_{$ts}_{$rand}.{$ext}";
    }

    /**
     * Generate a simple local placeholder PNG and return bytes and dimensions.
     */
    protected function generatePlaceholder(string $prompt, string $aspectRatio): array
    {
        // Map aspect ratio
        $map = [
            '1:1' => [1024, 1024],
            '16:9' => [1792, 1024],
            '9:16' => [1024, 1792],
            '4:3' => [1024, 768],
        ];
        [$w, $h] = $map[$aspectRatio] ?? [1024, 1024];

        $img = Image::canvas($w, $h, '#F0F2F5');
        // Draw a subtle border to indicate placeholder
        $img->rectangle(1, 1, $w-2, $h-2, function ($draw) {
            $draw->border(2, '#D1D5DB');
        });
        $bytes = (string) $img->encode('png');
        return [$bytes, $w, $h];
    }
}
