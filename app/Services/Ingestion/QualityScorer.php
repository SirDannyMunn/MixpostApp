<?php

namespace App\Services\Ingestion;

class QualityScorer
{
    public function score(string $text): array
    {
        $text = trim($text);
        $length = mb_strlen($text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $sentences = array_values(array_filter(array_map('trim', $sentences), fn($s) => $s !== ''));
        $uniqueSentences = array_unique(array_map(fn($s) => mb_strtolower($s), $sentences));

        $charDensity = max(0.0, min(1.0, $length / 4000));
        $sentenceCount = count($sentences);
        $uniqueCount = count($uniqueSentences);
        $redundancy = $sentenceCount > 0 ? 1.0 - min(1.0, $uniqueCount / max(1, $sentenceCount)) : 0.0; // 0..1 where higher = more redundant
        $signalDensity = max(0.0, min(1.0, ($uniqueCount / max(1, $sentenceCount)) * 0.6 + $charDensity * 0.4));

        $specificity = $this->estimateSpecificity($text);
        $extractability = $this->estimateExtractability($text);
        $embeddingCoverage = $length > 0 ? min(1.0, $length / 12000) : 0.0;

        $warnings = [];
        if ($length < 200) { $warnings[] = 'too_short'; }
        if ($redundancy > 0.4) { $warnings[] = 'redundant_content'; }
        if ($specificity < 0.4) { $warnings[] = 'low_specificity'; }

        $overall = max(0.0, min(1.0, (
            0.30 * $signalDensity +
            0.20 * (1.0 - $redundancy) +
            0.20 * $specificity +
            0.20 * $extractability +
            0.10 * $embeddingCoverage
        )));

        return [
            'overall' => round($overall, 4),
            'signal_density' => round($signalDensity, 4),
            'redundancy' => round($redundancy, 4),
            'specificity' => round($specificity, 4),
            'extractability' => round($extractability, 4),
            'embedding_coverage' => round($embeddingCoverage, 4),
            'warnings' => $warnings,
            'stats' => [
                'chars' => $length,
                'sentences' => $sentenceCount,
                'unique_sentences' => $uniqueCount,
            ],
        ];
    }

    private function estimateSpecificity(string $text): float
    {
        // Basic heuristic: presence of numbers, dates, and proper nouns increases specificity
        $numbers = preg_match_all('/\b\d+(?:[.,]\d+)?\b/u', $text);
        $properNouns = preg_match_all('/\b[A-Z][a-z]{2,}\b/u', $text);
        $keywords = preg_match_all('/\b(CTA|ROI|KPI|roadmap|prototype|beta|MRR|SaaS|retention|churn)\b/i', $text);

        $score = 0.0;
        $score += min(1.0, $numbers / 10.0) * 0.4;
        $score += min(1.0, $properNouns / 15.0) * 0.4;
        $score += min(1.0, $keywords / 5.0) * 0.2;
        return max(0.0, min(1.0, $score));
    }

    private function estimateExtractability(string $text): float
    {
        // Simple structure cues: bullets, numbered lists, short paragraphs improve extractability
        $bullets = preg_match_all('/^[\-*•]/m', $text);
        $numbers = preg_match_all('/^\s*\d+\./m', $text);
        $paras = preg_split('/\n\s*\n/', $text) ?: [];
        $shortParas = array_filter($paras, fn($p) => mb_strlen(trim($p)) <= 500);

        $score = 0.0;
        $score += min(1.0, $bullets / 10.0) * 0.4;
        $score += min(1.0, $numbers / 10.0) * 0.2;
        $ratio = count($paras) > 0 ? count($shortParas) / max(1, count($paras)) : 0.0;
        $score += max(0.0, min(1.0, $ratio)) * 0.4;
        return max(0.0, min(1.0, $score));
    }
}

