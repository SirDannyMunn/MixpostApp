<?php

namespace App\Services\Ai;

class PostQualityEvaluator
{
    public function evaluate(string $content, GenerationContext $context, array $classification, array $options = []): array
    {
        $scores = [];
        $scores['length_fit'] = $this->scoreLength($content, (int) ($context->options['max_chars'] ?? 1200));
        $scores['emoji_compliance'] = $this->scoreEmoji($content, (string) ($context->options['emoji'] ?? 'disallow'));
        $scores['structure_adherence'] = $this->scoreStructure($content, (array) ($context->template?->template_data['structure'] ?? []));
        $scores['relevance'] = $this->scoreRelevance($content, $context->chunks, $context->facts);
        $scores['readability'] = $this->scoreReadability($content);

        // Weighted blend; weights tuned conservatively
        $overall = (
            0.25 * $scores['relevance'] +
            0.20 * $scores['structure_adherence'] +
            0.20 * $scores['readability'] +
            0.20 * $scores['length_fit'] +
            0.15 * $scores['emoji_compliance']
        );

        return [
            'overall_score' => round($overall, 4),
            'scores' => $scores,
        ];
    }

    private function scoreLength(string $text, int $max): float
    {
        $len = mb_strlen(trim($text));
        if ($len <= 1) return 0.0;
        if ($len <= $max) return 1.0;
        // Penalize proportionally beyond limit, capped
        $over = $len - $max;
        $penalty = min(1.0, $over / max(1, $max));
        return max(0.0, 1.0 - $penalty);
    }

    private function scoreEmoji(string $text, string $policy): float
    {
        if ($policy !== 'disallow') return 1.0;
        $hasEmoji = (bool) preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]/u', $text);
        return $hasEmoji ? 0.0 : 1.0;
    }

    private function scoreStructure(string $text, array $structure): float
    {
        $sections = [];
        if (isset($structure['sections']) && is_array($structure['sections'])) {
            $sections = $structure['sections'];
        } elseif (!empty($structure)) {
            $sections = $structure;
        }
        $sectionCount = count($sections);
        if ($sectionCount === 0) return 0.7; // neutral if no target structure

        // Compare paragraph blocks to desired section count
        $paragraphs = preg_split('/\n\s*\n/', trim($text)) ?: [];
        $pCount = count(array_filter(array_map('trim', $paragraphs), fn($p) => $p !== ''));
        if ($pCount === 0) return 0.0;
        $diff = abs($pCount - $sectionCount);
        $ratio = $sectionCount > 0 ? (1.0 - min(1.0, $diff / $sectionCount)) : 0.0;
        return max(0.0, min(1.0, 0.6 + 0.4 * $ratio));
    }

    private function scoreRelevance(string $text, array $chunks, array $facts): float
    {
        $keywords = [];
        foreach ($chunks as $c) {
            $kw = $this->extractKeywords((string) ($c['chunk_text'] ?? ''));
            $keywords = array_merge($keywords, $kw);
        }
        foreach ($facts as $f) {
            $kw = $this->extractKeywords((string) ($f['text'] ?? ''));
            $keywords = array_merge($keywords, $kw);
        }
        $keywords = array_values(array_unique(array_filter($keywords)));
        if (empty($keywords)) return 0.7; // neutral if no inputs
        $hits = 0;
        $hay = mb_strtolower($text);
        foreach ($keywords as $k) {
            if ($k !== '' && str_contains($hay, $k)) $hits++;
        }
        $coverage = $hits / max(1, count($keywords));
        return max(0.0, min(1.0, 0.4 + 0.6 * $coverage));
    }

    private function scoreReadability(string $text): float
    {
        $sentences = preg_split('/[.!?]+\s+/', trim($text)) ?: [];
        $sentences = array_values(array_filter($sentences, fn($s) => $s !== ''));
        if (empty($sentences)) return 0.5;
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $wCount = count(array_filter($words, fn($w) => $w !== ''));
        $sCount = max(1, count($sentences));
        $avg = $wCount / $sCount;
        // Target 12–22 words per sentence
        if ($avg >= 12 && $avg <= 22) return 1.0;
        $dist = min(abs($avg - 17) / 17, 1.0);
        return max(0.0, 1.0 - $dist);
    }

    private function extractKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $parts = preg_split('/\s+/', (string) $text) ?: [];
        $stop = ['the','and','for','with','that','this','from','are','you','your','our','but','not','can','will','have','has','was','were','to','of','in','on','a','an'];
        $parts = array_values(array_filter($parts, fn($p) => mb_strlen($p) >= 5 && !in_array($p, $stop, true)));
        return array_slice(array_unique($parts), 0, 30);
    }
}

