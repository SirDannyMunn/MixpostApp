<?php

namespace App\Services\Chunking\Strategies;

use App\Models\KnowledgeItem;
use Illuminate\Support\Str;

class ShortPostClaimStrategy implements ChunkingStrategy
{
    public function generateChunks(KnowledgeItem $item, string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '' && !$this->isUrlLine($l)));

        $chunks = [];

        // Extract up to 2 meaningful sentences
        $sentences = [];
        foreach ($lines as $line) {
            // Split line into sentences
            $lineSentences = preg_split('/(?<=[.!?])\s+/', $line);
            foreach ($lineSentences as $sent) {
                $sent = trim($sent);
                if (strlen($sent) > 20 && $this->estimateTokens($sent) >= 8) {
                    $sentences[] = $sent;
                    if (count($sentences) >= 2) {
                        break 2;
                    }
                }
            }
        }

        // Generate chunks from sentences
        foreach ($sentences as $idx => $sentence) {
            $role = $idx === 0 ? 'strategic_claim' : 'instruction';
            
            $chunks[] = [
                'text' => $sentence,
                'role' => $role,
                'authority' => 'medium',
                'confidence' => 0.6,
                'token_count' => $this->estimateTokens($sentence),
                'source_text' => $sentence,
                'source_spans' => null,
                'transformation_type' => 'extractive',
            ];
        }

        return $chunks;
    }

    private function isUrlLine(string $line): bool
    {
        $line = trim($line);
        return preg_match('/^https?:\/\//i', $line) || strlen($line) < 10;
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
