<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Bookmark;
use App\Models\IngestionSource;

class LibraryItemResource extends JsonResource
{
    public function toArray($request)
    {
        /** @var IngestionSource $src */
        $src = $this->resource;

        /** @var Bookmark|null $bookmark */
        $bookmark = $src->relationLoaded('bookmark') ? $src->getRelation('bookmark') : null;

        $type = (string) ($src->source_type ?? '');
        $origin = (string) ($src->origin ?? '');
        $source = $origin !== '' ? $origin : ($type === 'bookmark' ? 'browser' : 'manual');

        $title = (string) ($src->title ?? '');
        if ($title === '' && $type === 'bookmark' && $bookmark) {
            $title = (string) ($bookmark->title ?? '');
        }

        $preview = '';
        if ($type === 'bookmark' && $bookmark) {
            $preview = (string) ($bookmark->description ?? '');
        } else {
            $preview = (string) ($src->raw_text ?? '');
            $preview = trim(preg_replace('/\s+/', ' ', $preview) ?? $preview);
            if (mb_strlen($preview) > 220) {
                $preview = mb_substr($preview, 0, 220) . '…';
            }
        }

        $thumbnailUrl = null;
        if ($type === 'bookmark' && $bookmark) {
            $thumbnailUrl = $bookmark->image_url;
        }

        $ingestionStatus = (string) ($src->status ?? 'pending');

        return [
            'id' => 'lib_'.$src->id,
            'type' => $type,
            'source' => $source,
            'title' => $title,
            'preview' => $preview,
            'thumbnail_url' => $thumbnailUrl,
            'created_at' => optional($src->created_at)->toIso8601String(),
            'updated_at' => optional($src->updated_at)->toIso8601String(),

            'ingestion' => [
                'status' => $ingestionStatus,
                'ingested_at' => ($ingestionStatus === 'completed') ? optional($src->updated_at)->toIso8601String() : null,
                'confidence' => $src->confidence_score,
                'error_message' => ($ingestionStatus === 'failed') ? (string) ($src->error ?? '') : null,
            ],

            'bookmark' => ($type === 'bookmark' && $bookmark) ? [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'platform' => $bookmark->platform,
                'image_url' => $bookmark->image_url,
                'favicon_url' => $bookmark->favicon_url,
                    'folder' => $bookmark->relationLoaded('folder') && $bookmark->folder ? [
                        'id' => $bookmark->folder->id,
                        'name' => $bookmark->folder->effective_name,
                        'system_name' => $bookmark->folder->system_name ?? null,
                        'display_name' => $bookmark->folder->display_name ?? null,
                        'effective_name' => $bookmark->folder->effective_name,
                    'parent_id' => $bookmark->folder->parent_id,
                ] : null,
                'folder_id' => $bookmark->folder_id,
                'tags' => $bookmark->relationLoaded('tags') ? $bookmark->tags->map(fn($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'color' => $t->color,
                ]) : [],
            ] : null,
        ];
    }
}
