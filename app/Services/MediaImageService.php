<?php

namespace App\Services;

use App\Models\MediaImage;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MediaImageService
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('filesystems.default');
    }

    /**
     * Delete an image and its thumbnail from storage and database.
     */
    public function deleteImage(MediaImage $image): void
    {
        $disk = $this->disk;
        if (!empty($image->file_path)) {
            Storage::disk($disk)->delete($image->file_path);
        }
        if (!empty($image->thumbnail_path)) {
            Storage::disk($disk)->delete($image->thumbnail_path);
        }
        $image->delete();
    }

    /**
     * Upload and process an image to the configured filesystem (e.g., S3).
     */
    public function uploadImage(
        Organization $organization,
        UploadedFile $file,
        int $uploadedByUserId,
        ?int $packId = null
    ): MediaImage {
        $this->validateImage($file);

        $originalFilename = $file->getClientOriginalName();
        $sanitized = $this->sanitizeFilename($originalFilename);
        $uniqueFilename = $this->generateUniqueFilename($sanitized);

        $image = Image::make($file);
        $width = $image->width();
        $height = $image->height();

        $dir = "media/{$organization->id}/images";
        $path = $dir . "/{$uniqueFilename}";

        Storage::disk($this->disk)->put($path, file_get_contents($file->getRealPath()), 'public');

        $thumbnailPath = $this->generateThumbnail($organization, $image, $uniqueFilename);

        return MediaImage::create([
            'organization_id'   => $organization->id,
            'pack_id'           => $packId,
            'uploaded_by'       => $uploadedByUserId,
            'filename'          => $uniqueFilename,
            'original_filename' => $originalFilename,
            'file_path'         => $path,
            'thumbnail_path'    => $thumbnailPath,
            'file_size'         => $file->getSize(),
            'mime_type'         => $file->getMimeType(),
            'width'             => $width,
            'height'            => $height,
            'generation_type'   => 'upload',
            'ai_prompt'         => null,
        ]);
    }

    protected function generateThumbnail(Organization $organization, $image, string $uniqueFilename): string
    {
        $thumb = (clone $image)->fit(400, 400, function ($constraint) {
            $constraint->upsize();
        });

        $thumbDir = "media/{$organization->id}/images/thumbnails";
        $thumbPath = $thumbDir . "/{$uniqueFilename}";

        Storage::disk($this->disk)->put($thumbPath, (string)$thumb->encode(), 'public');
        return $thumbPath;
    }

    protected function validateImage(UploadedFile $file): void
    {
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Image size must not exceed 10MB');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowed, true)) {
            throw new \InvalidArgumentException('Invalid image format. Allowed: JPG, PNG, GIF, WebP');
        }

        try {
            Image::make($file);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('File is not a valid image');
        }
    }

    protected function sanitizeFilename(string $filename): string
    {
        $info = pathinfo($filename);
        $base = preg_replace('/[^a-zA-Z0-9-_]/', '_', $info['filename'] ?? 'image');
        $ext = strtolower($info['extension'] ?? 'jpg');
        return $base . '.' . $ext;
    }

    protected function generateUniqueFilename(string $filename): string
    {
        $info = pathinfo($filename);
        $base = $info['filename'] ?? 'image';
        $ext = $info['extension'] ?? 'jpg';
        $timestamp = now()->format('YmdHis');
        $rand = Str::random(8);
        return "{$base}_{$timestamp}_{$rand}.{$ext}";
    }
}
