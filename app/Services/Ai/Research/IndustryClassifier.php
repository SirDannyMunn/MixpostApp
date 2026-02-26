<?php

namespace App\Services\Ai\Research;

class IndustryClassifier
{
    /**
     * Infer a short industry label from a prompt.
     */
    public static function infer(string $message): string
    {
        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return '';
        }

        $map = [
            'seo' => ['seo', 'search engine', 'google search', 'keyword', 'serp'],
            'marketing' => ['marketing', 'brand', 'positioning', 'campaign'],
            'saas' => ['saas', 'subscription', 'b2b software'],
            'ecommerce' => ['ecommerce', 'e-commerce', 'shopify', 'storefront', 'checkout'],
            'ai' => ['ai', 'artificial intelligence', 'llm', 'gpt', 'machine learning'],
            'creator economy' => ['creator', 'influencer', 'newsletter', 'youtube', 'tiktok'],
            'finance' => ['finance', 'fintech', 'banking', 'payments', 'investing'],
            'health' => ['health', 'wellness', 'fitness', 'nutrition', 'supplement'],
            'real estate' => ['real estate', 'mortgage', 'property', 'housing'],
            'education' => ['education', 'edtech', 'course', 'curriculum'],
            'legal' => ['legal', 'law', 'attorney', 'compliance'],
            'retail' => ['retail', 'brick and mortar', 'in-store'],
        ];

        foreach ($map as $label => $tokens) {
            foreach ($tokens as $token) {
                if (str_contains($text, $token)) {
                    return $label;
                }
            }
        }

        $keywords = self::extractKeywords($text, 1);
        return $keywords[0] ?? '';
    }

    private static function extractKeywords(string $text, int $max): array
    {
        $text = preg_replace('~https?://\S+~i', ' ', $text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
        $parts = preg_split('/\s+/', $text) ?: [];

        $stop = [
            'the','and','for','with','that','this','from','your','you','our','are','was','were','will','can',
            'about','into','over','under','after','before','just','have','has','had','not','but','they','them',
            'their','its','what','when','where','why','how','who','which','also','use','using','make','makes',
            'made','like','more','most','less','much','many','too','very','per','via','set','get','new','best',
            'top','today','trend','trending','industry','market','latest','news','research','whether','dead',
            'soon','because','after','before','about','will','should','could','would',
        ];
        $stop = array_flip($stop);

        $counts = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset($stop[$p])) {
                continue;
            }
            $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
        if (empty($counts)) {
            return [];
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, max(1, $max));
    }
}
