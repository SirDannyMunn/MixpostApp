<?php

namespace App\Services\Chunking;

use App\Models\KnowledgeItem;

class ChunkingPreflight
{
    public function check(KnowledgeItem $item, array $config): array
    {
        $cleanText = trim((string) $item->raw_text);
        
        $metrics = [
            'raw_chars' => strlen((string) $item->raw_text),
            'clean_chars' => strlen($cleanText),
            'contains_url' => false,
            'is_url_only' => false,
        ];

        if ($cleanText === '') {
            return [
                'eligible' => false,
                'skip_reason' => 'empty_after_clean',
                'metrics' => $metrics,
            ];
        }

        // Check if URL-only
        $urlPattern = '/^https?:\/\/[^\s]+$/i';
        if (preg_match($urlPattern, $cleanText)) {
            $metrics['is_url_only'] = true;
            return [
                'eligible' => false,
                'skip_reason' => 'url_only',
                'metrics' => $metrics,
            ];
        }

        // Check if contains URLs
        if (preg_match('/https?:\/\/[^\s]+/i', $cleanText)) {
            $metrics['contains_url'] = true;
        }

        // Estimate tokens (rough word count)
        $tokens = $this->estimateTokens($cleanText);
        $metrics['raw_tokens_est'] = $tokens;
        $metrics['clean_tokens_est'] = $tokens;

        $minChars = (int) ($config['min_clean_chars'] ?? 80);
        $minTokens = (int) ($config['min_clean_tokens_est'] ?? 20);

        if ($metrics['clean_chars'] < $minChars) {
            return [
                'eligible' => false,
                'skip_reason' => 'below_min_chars',
                'metrics' => $metrics,
            ];
        }

        if ($metrics['clean_tokens_est'] < $minTokens) {
            return [
                'eligible' => false,
                'skip_reason' => 'below_min_tokens',
                'metrics' => $metrics,
            ];
        }

        return [
            'eligible' => true,
            'skip_reason' => null,
            'metrics' => $metrics,
        ];
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
