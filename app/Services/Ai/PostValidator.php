<?php

namespace App\Services\Ai;

class PostValidator
{
    public function checkPost(string $draft, object $context): array
    {
        $max = (int) ($context->options['max_chars'] ?? 1200);
        $emojiPolicy = (string) ($context->options['emoji'] ?? 'disallow');
        $ok = true;
        $issues = [];
        $charCount = mb_strlen($draft);
        if (trim($draft) === '') { $ok = false; $issues[] = 'empty_content'; }
        if ($charCount > $max) { $ok = false; $issues[] = 'length_exceeded'; }
        $emojiCount = 0;
        if ($emojiPolicy === 'disallow' && preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]/u', $draft, $m)) {
            $emojiCount = count($m[0] ?? []);
            $ok = false; $issues[] = 'emoji_disallowed';
        }
        // Basic required-structure hint: if template has sections, require at least the count of line groups
        $sections = (array) ($context->template?->template_data['structure']['sections'] ?? []);
        $paragraphs = preg_split('/\n\s*\n/', trim($draft)) ?: [];
        if (!empty($sections)) {
            // Heuristic: ensure draft has at least N paragraphs/line breaks roughly equal to sections
            if (count($paragraphs) < count($sections)) {
                $ok = false; $issues[] = 'missing_sections';
            }
        }

        // Detect meta/no-draft responses from models and mark as invalid
        if (preg_match('/(i\s+apologize|please\s+provide\s+(the\s+)?draft|don\'t\s+see\s+any\s+draft|do\s+not\s+see\s+any\s+draft)/i', $draft)) {
            $ok = false; $issues[] = 'meta_response';
        }
        return [
            'ok' => $ok,
            'issues' => $issues,
            'metrics' => [
                'char_count' => $charCount,
                'target_max' => $max,
                'emoji_count' => $emojiCount,
                'paragraphs' => count(array_filter($paragraphs, fn($p) => trim($p) !== '')),
            ],
        ];
    }
}
