<?php

namespace App\Services\Ingestion;

class KnowledgeCompiler
{
    /**
     * Semantic gating thresholds.
     *
     * NOTE: "tokens" here means roughly whitespace-delimited words, not model BPE tokens.
     */
    public const MIN_GATE_NON_URL_TOKENS = 12;

    /**
     * Suggested domains (used for examples/prompts).
     *
     * Domain is an OPEN vocabulary; do not treat this as an enum.
     */
    public const SUGGESTED_DOMAINS = [
        'seo',
        'content marketing',
        'saas',
        'monetization',
        'growth',
        'business strategy',
    ];

    /**
     * Extract candidate text blocks from a raw document.
     */
    public function extractCandidates(string $rawText, int $maxBlocks = 20): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        // Prefer paragraph blocks; fall back to sentence-ish splitting.
        $blocks = preg_split('/\n\s*\n/', $rawText) ?: [$rawText];
        $blocks = array_values(array_filter(array_map(fn($b) => trim((string) $b), $blocks), fn($b) => $b !== ''));

        // If paragraphs are huge, split them to keep blocks LLM-manageable.
        $out = [];
        foreach ($blocks as $b) {
            if (mb_strlen($b) <= 2400) {
                $out[] = $b;
                continue;
            }
            // Split long paragraphs into roughly 1200-1600 char chunks on sentence boundaries.
            $sentences = preg_split('/(?<=[\.\!\?])\s+/', $b) ?: [$b];
            $buf = '';
            foreach ($sentences as $s) {
                $s = trim((string) $s);
                if ($s === '') continue;
                $candidate = $buf === '' ? $s : ($buf . ' ' . $s);
                if (mb_strlen($candidate) > 1600 && $buf !== '') {
                    $out[] = $buf;
                    $buf = $s;
                } else {
                    $buf = $candidate;
                }
            }
            if ($buf !== '') {
                $out[] = $buf;
            }
        }

        return array_slice($out, 0, max(1, $maxBlocks));
    }

    /**
     * Semantic gating (hard stop).
     *
     * Rules:
     * - token count < 12 (excluding URLs)
     * - > 50% URL or emoji content
     * - no verb detected
     * - no domain noun detected
     */
    public function gate(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['accepted' => false, 'reason' => 'empty'];
        }

        $tokens = preg_split('/\s+/u', $text) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
        if (empty($tokens)) {
            return ['accepted' => false, 'reason' => 'no_tokens'];
        }

        $urlTokens = 0;
        $emojiTokens = 0;
        $nonUrlTokens = 0;

        foreach ($tokens as $t) {
            if ($this->looksLikeUrlToken($t)) {
                $urlTokens++;
                continue;
            }
            $nonUrlTokens++;
            if ($this->containsEmoji($t)) {
                $emojiTokens++;
            }
        }

        if ($nonUrlTokens < self::MIN_GATE_NON_URL_TOKENS) {
            return ['accepted' => false, 'reason' => 'too_short_tokens', 'stats' => ['non_url_tokens' => $nonUrlTokens]];
        }

        $total = max(1, count($tokens));
        $urlEmojiRatio = ($urlTokens + $emojiTokens) / $total;
        if ($urlEmojiRatio > 0.50) {
            return ['accepted' => false, 'reason' => 'too_much_url_or_emoji', 'stats' => ['ratio' => $urlEmojiRatio]];
        }

        if (!$this->hasVerb($text)) {
            return ['accepted' => false, 'reason' => 'no_verb'];
        }

        // Domain-agnostic semantic sanity check (open-world).
        // IMPORTANT: be permissive enough to avoid starving the pipeline.
        // Accept if the text looks like a definition, a metric, a factual update, or a coherent claim.
        if (!$this->passesSemanticSanity($text)) {
            return ['accepted' => false, 'reason' => 'semantic_incoherence'];
        }

        return ['accepted' => true];
    }

    public function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        // Collapse whitespace.
        $domain = preg_replace('/\s+/u', ' ', $domain) ?? $domain;

        $d = strtolower($domain);
        $map = [
            'seo' => 'seo',
            'search' => 'seo',
            'content' => 'content marketing',
            'content marketing' => 'content marketing',
            'saas' => 'saas',
            'monetization' => 'monetization',
            'growth' => 'growth',
            'business' => 'business strategy',
            'business strategy' => 'business strategy',
            'strategy' => 'business strategy',
        ];
        if (isset($map[$d])) {
            return $map[$d];
        }

        // Open vocabulary: store verbatim (trimmed) for unknown domains.
        // Keep it lowercased for stable equality comparisons.
        return $d;
    }

    private function looksLikeUrlToken(string $t): bool
    {
        $t = trim($t);
        if ($t === '') return false;
        if (preg_match('/^https?:\/\//i', $t)) return true;
        if (preg_match('/^www\./i', $t)) return true;
        if (preg_match('/\.[a-z]{2,}(\/|$)/i', $t) && preg_match('/[a-z]/i', $t)) return true;
        return false;
    }

    private function containsEmoji(string $t): bool
    {
        // Basic emoji detection using Unicode pictographic property.
        return (bool) preg_match('/\p{Extended_Pictographic}/u', $t);
    }

    private function hasVerb(string $text): bool
    {
        $t = strtolower($text);
        if (preg_match('/\b(is|are|was|were|be|been|being|have|has|had|do|does|did|will|would|should|can|could|may|might|must)\b/', $t)) {
            return true;
        }
        if (preg_match('/\b(increase|decrease|improve|hurt|punish|rank|convert|drive|generate|reported|report|announced|announce|launched|launch|released|release|grew|grow|boost|boosts|reduced|reduce|raised|raise|lowered|lower)\b/', $t)) {
            return true;
        }
        // Heuristic: common verb suffixes
        if (preg_match('/\b\w+(ed|ing)\b/', $t)) {
            return true;
        }
        return false;
    }

    /**
     * Domain-agnostic semantic sanity heuristic.
     *
     * Goal: reject obvious noise (reactions/links/memes) while keeping legitimate short factual updates,
     * definitions, and product statements.
     */
    public function passesSemanticSanity(string $text): bool
    {
        $raw = trim($text);
        if ($raw === '') {
            return false;
        }

        // If it looks like a definition, accept.
        if ($this->looksLikeDefinition($raw)) {
            return true;
        }

        // If it contains numeric/metric signal (dates, %, $, counts), accept.
        if ($this->hasNumericSignal($raw)) {
            return true;
        }

        $t = strtolower($raw);

        // If it has causal structure, accept.
        if ($this->hasCausalConnector($t)) {
            return true;
        }

        // If it has abstract noun signal, accept.
        if ($this->hasAbstractNoun($t)) {
            return true;
        }

        // If it mentions a likely proper noun/product/company (cheap heuristic), accept.
        if ($this->hasProperNounSignal($raw)) {
            return true;
        }

        // Conditional structure can be meaningful (if...then)
        if (preg_match('/\bif\b.+\bthen\b/u', $t)) {
            return true;
        }

        return false;
    }

    private function looksLikeDefinition(string $text): bool
    {
        // e.g. "Churn is revenue lost over a period." "X refers to..."
        $t = strtolower($text);
        if (preg_match('/\b\w[\w\-\s]{2,40}\s+(is|are|means|refers to|defined as)\b/u', $t)) {
            return true;
        }
        return false;
    }

    private function hasNumericSignal(string $text): bool
    {
        // Dates/years, percentages, currency, counts
        if (preg_match('/\b(19\d{2}|20\d{2})\b/u', $text)) return true;
        if (preg_match('/\b\d+(\.\d+)?\s*(%|percent)\b/iu', $text)) return true;
        if (preg_match('/(\$|€|£)\s*\d[\d,]*(\.\d+)?/u', $text)) return true;
        if (preg_match('/\b\d{1,3}(,\d{3})+(\.\d+)?\b/u', $text)) return true;
        if (preg_match('/\b\d+\b/u', $text) && preg_match('/\b(users|days|weeks|months|years|minutes|hours|requests|sessions)\b/iu', $text)) return true;
        return false;
    }

    private function hasProperNounSignal(string $text): bool
    {
        // Heuristic: any capitalized token not at the start of the sentence, or CamelCase, or ALLCAPS acronym.
        if (preg_match('/\b[A-Z][a-z]{2,}\b/u', $text)) return true;
        if (preg_match('/\b[A-Z]{2,}\b/u', $text)) return true;
        if (preg_match('/\b[A-Z][a-z]+[A-Z][A-Za-z]+\b/u', $text)) return true;
        return false;
    }

    private function hasCausalConnector(string $t): bool
    {
        return (bool) preg_match('/\b(because|therefore|thus|so that|so|leads to|results in|causes|drives|increases|decreases|improves|reduces)\b/u', $t);
    }

    private function hasAbstractNoun(string $t): bool
    {
        $keywords = [
            'trust','credibility','reputation','brand','attention','engagement','retention','growth','risk','impact','value','quality','clarity',
            'strategy','tactics','principle','heuristic','tradeoff','constraints','incentives','alignment','friction','momentum','leverage',
            'community','culture','belief','motivation','behavior','psychology','learning','education',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($t, $kw)) {
                return true;
            }
        }
        return false;
    }
}
