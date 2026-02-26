<?php

namespace App\Services\Chunking;

class ContentFormatDetector
{
    public function detect(string $text): string
    {
        $text = trim($text);
        
        if ($text === '') {
            return 'unknown';
        }

        $lines = preg_split('/\r?\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

        // Check for numeric list (revenue timelines, metrics)
        if ($this->isNumericList($lines)) {
            return 'numeric_list';
        }

        // Check for bullet list
        if ($this->isBulletList($lines)) {
            return 'bullet_list';
        }

        // Check for short post
        $tokens = $this->estimateTokens($text);
        if ($tokens < 60) {
            // Check if it's a promo/CTA post
            if ($this->isPromoCTA($text)) {
                return 'promo_cta';
            }
            return 'short_post';
        }

        return 'plain_text';
    }

    private function isNumericList(array $lines): bool
    {
        if (count($lines) < 3) {
            return false;
        }

        $numericLines = 0;
        $totalLines = 0;
        
        foreach ($lines as $line) {
            // Skip very short lines
            if (strlen($line) < 5) {
                continue;
            }
            
            $totalLines++;
            
            // Match patterns like: "2014 = $450/mo", "2015 = $1500/mo", "1. Item", "10k/mo"
            // More specific patterns for numeric lists
            if (preg_match('/^\s*(\d{4}\s*=|\d+\.\s+|\d+\)\s+)/', $line)) {
                $numericLines++;
            }
        }

        // Require at least 3 numeric lines AND they should be >50% of total lines
        return $numericLines >= 3 && $totalLines > 0 && ($numericLines / $totalLines) > 0.5;
    }

    private function isBulletList(array $lines): bool
    {
        if (count($lines) < 3) {
            return false;
        }

        $bulletLines = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s*[-•*]\s+/', $line)) {
                $bulletLines++;
            }
        }

        return $bulletLines >= 3;
    }

    private function isPromoCTA(string $text): bool
    {
        $lower = strtolower($text);
        
        $ctaPatterns = [
            'comment',
            'dm me',
            'dm you',
            'link in bio',
            'guaranteed',
            'check out',
            'limited',
            'click here',
            'sign up',
            'get started',
            'free trial',
        ];

        $hasCta = false;
        foreach ($ctaPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                $hasCta = true;
                break;
            }
        }

        $hasUrl = (bool) preg_match('/https?:\/\/[^\s]+/i', $text);

        return $hasCta && $hasUrl;
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
