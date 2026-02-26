<?php

namespace App\Services;

use App\Models\Bookmark;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

class ReferenceResolverService
{
    /**
     * Resolve structured references to textual context blocks for the model.
     * Currently supports: bookmark
     *
     * @param array $references Array of ['type','id','name']
     * @param Organization $organization Scope and authorization context
     * @return array Array of ['label','type','content']
     */
    public function resolve(array $references, Organization $organization): array
    {
        $resolved = [];

        // Enforce max references limit
        $references = array_slice($references, 0, 10);

        foreach ($references as $ref) {
            $type = (string) ($ref['type'] ?? '');
            $id = (string) ($ref['id'] ?? '');
            $label = (string) ($ref['name'] ?? $ref['label'] ?? 'reference');

            $content = null;
            try {
                switch ($type) {
                    case 'bookmark':
                        $content = $this->resolveBookmark($id, $organization);
                        break;
                    // Future: file, snippet, url, etc.
                    default:
                        $content = null;
                        break;
                }
            } catch (\Throwable $e) {
                Log::warning('reference.resolve.error', [
                    'type' => $type,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $content = null;
            }

            if (is_string($content) && $content !== '') {
                // Cap size per reference (~10KB)
                $content = mb_substr($content, 0, 10 * 1024);
                $resolved[] = [
                    'label' => $label,
                    'type' => $type,
                    'content' => $content,
                ];
            }
        }

        // Cap total context budget (~50KB)
        $total = 0;
        $out = [];
        foreach ($resolved as $item) {
            $len = strlen($item['content']);
            if ($total + $len > 50 * 1024) {
                break;
            }
            $out[] = $item;
            $total += $len;
        }

        return $out;
    }

    /**
     * Resolve a bookmark into a readable Markdown context block.
     */
    protected function resolveBookmark(string $bookmarkId, Organization $organization): ?string
    {
        if ($bookmarkId === '') {
            return null;
        }

        $bookmark = Bookmark::with(['tags:id,name', 'folder:id,display_name'])
            ->where('id', $bookmarkId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$bookmark) {
            return null; // not found or unauthorized
        }

        $title = (string) ($bookmark->title ?? 'Untitled');
        $desc = trim((string) ($bookmark->description ?? ''));
        $url = (string) ($bookmark->url ?? '');
        $platform = (string) ($bookmark->platform ?? 'other');
        $tags = $bookmark->tags ? $bookmark->tags->pluck('name')->implode(', ') : '';

        $lines = [];
        $lines[] = '# ' . $this->sanitize($title);
        if ($desc !== '') {
            $lines[] = '';
            $lines[] = $this->sanitize($desc);
        }
        if ($url !== '') {
            $lines[] = '';
            $lines[] = '**URL:** ' . $this->sanitize($url);
        }
        if ($platform !== '') {
            $lines[] = '**Platform:** ' . $this->sanitize($platform);
        }
        if ($tags !== '') {
            $lines[] = '**Tags:** ' . $this->sanitize($tags);
        }

        return implode("\n", $lines);
    }

    /**
     * Basic sanitization to reduce prompt injection or formatting issues.
     */
    protected function sanitize(string $text): string
    {
        // Strip NULL bytes and normalize line endings
        $text = str_replace("\0", '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }
}

