<?php

namespace App\Services\Ingestion;

use App\Models\Bookmark;
use App\Models\IngestionSource;

class IngestionContentResolver
{
    /**
     * Resolve raw text for an ingestion source without external fetching.
     */
    public function resolve(IngestionSource $source): ?string
    {
        switch ((string) $source->source_type) {
            case 'bookmark':
                return $this->resolveBookmark($source);
            case 'text':
                return $this->resolveText($source);
            default:
                return null;
        }
    }

    protected function resolveText(IngestionSource $source): ?string
    {
        $text = trim((string) ($source->raw_text ?? ''));
        return $text !== '' ? $text : null;
    }

    protected function resolveBookmark(IngestionSource $source): ?string
    {
        if (!$source->source_id) {
            return null;
        }

        $bookmark = Bookmark::find($source->source_id);
        if (!$bookmark) {
            return null;
        }

        // Use existing stored content only; never fetch externally
        $text = trim((string) ($bookmark->description ?? ''));

        // Optionally include title as part of text context if present
        $title = trim((string) ($bookmark->title ?? ''));
        if ($title !== '') {
            // Place title on its own line if we have body text too
            $text = $text !== '' ? ($title . "\n\n" . $text) : $title;
        }

        return $text !== '' ? $text : null;
    }
}

