<?php

namespace App\Services\Chunking\Strategies;

use App\Models\KnowledgeItem;
use Illuminate\Support\Str;

class FallbackSentenceStrategy implements ChunkingStrategy
{
    public function generateChunks(KnowledgeItem $item, string $text): array
    {
        $sentences = $this->extractSentences($text);
        
        // Score and rank sentences
        $scored = [];
        foreach ($sentences as $sent) {
            $score = $this->scoreSentence($sent);
            if ($score > 0) {
                $scored[] = [
                    'text' => $sent,
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Take top 3
        $chunks = [];
        foreach (array_slice($scored, 0, 3) as $item) {
            $chunks[] = [
                'text' => $item['text'],
                'role' => 'heuristic',
                'authority' => 'low',
                'confidence' => 0.5,
                'token_count' => $this->estimateTokens($item['text']),
                'source_text' => $item['text'],
                'source_spans' => null,
                'transformation_type' => 'extractive',
            ];
        }

        return $chunks;
    }

    private function extractSentences(string $text): array
    {
        // Split on sentence boundaries
        $parts = preg_split('/(?<=[.!?])\s+/', $text);
        $sentences = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            // Filter out very short or URL-only fragments
            if (strlen($part) > 30 && !preg_match('/^https?:\/\//i', $part)) {
                $sentences[] = $part;
            }
        }
        
        return $sentences;
    }

    private function scoreSentence(string $sentence): float
    {
        $score = 0.0;
        $lower = strtolower($sentence);

        // Contains numbers or comparatives
        if (preg_match('/\d+/', $sentence)) {
            $score += 2.0;
        }

        // Contains causal language
        $causalWords = ['because', 'therefore', 'thus', 'so', 'leads to', 'results in', 'causes'];
        foreach ($causalWords as $word) {
            if (str_contains($lower, $word)) {
                $score += 1.5;
                break;
            }
        }

        // Contains instruction verbs
        $instructionVerbs = ['do', 'use', 'seed', 'add', 'include', 'ensure', 'avoid', 'create', 'build'];
        foreach ($instructionVerbs as $verb) {
            if (str_contains($lower, ' ' . $verb . ' ')) {
                $score += 1.0;
                break;
            }
        }

        // Length bonus (prefer medium-length sentences)
        $tokens = $this->estimateTokens($sentence);
        if ($tokens >= 12 && $tokens <= 40) {
            $score += 1.0;
        }

        return $score;
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
