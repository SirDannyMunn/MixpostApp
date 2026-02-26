<?php

namespace App\Services\Ai\Research;

use App\Services\Ai\Generation\DTO\Prompt;

class ResearchPromptComposer
{
    public function composeReport(string $question, array $clusters, array $items, array $options = []): Prompt
    {
        $system = "You are a neutral research analyst. Return STRICT JSON only.\n";
        $system .= "Do not write publishable content, offers, CTAs, or advice.\n";
        $system .= "Be analytical and evidence-grounded. Do not invent sources.\n";
        $system .= "Output schema:\n";
        $system .= "{\n";
        $system .= "  \"question\": \"string\",\n";
        $system .= "  \"dominant_claims\": [\"string\"],\n";
        $system .= "  \"points_of_disagreement\": [\"string\"],\n";
        $system .= "  \"saturated_angles\": [\"string\"],\n";
        $system .= "  \"emerging_angles\": [\"string\"],\n";
        $system .= "  \"example_excerpts\": [\n";
        $system .= "    {\"text\":\"string\",\"source\":\"youtube|x|linkedin|instagram|other\",\"confidence\":0.0}\n";
        $system .= "  ]\n";
        $system .= "}\n";
        $system .= "All fields are required. If evidence is weak, return empty arrays.\n";
        $system .= "Use excerpts verbatim from the provided items.\n";

        $user = "QUESTION:\n" . $question . "\n\n";
        $user .= "CLUSTERS (semantic groups):\n";
        foreach ($clusters as $cluster) {
            $user .= "- Cluster " . ($cluster['id'] ?? 'unknown') . " (size=" . ($cluster['size'] ?? 0) . ")\n";
            if (!empty($cluster['dominant_angles'])) {
                $user .= "  angles: " . implode('; ', (array) $cluster['dominant_angles']) . "\n";
            }
            if (!empty($cluster['dominant_hooks'])) {
                $user .= "  hooks: " . implode('; ', (array) $cluster['dominant_hooks']) . "\n";
            }
            if (!empty($cluster['representative_excerpts'])) {
                $user .= "  excerpts:\n";
                foreach ((array) $cluster['representative_excerpts'] as $ex) {
                    $text = (string) ($ex['text'] ?? '');
                    $source = (string) ($ex['source'] ?? 'other');
                    $id = (string) ($ex['id'] ?? '');
                    $user .= "    - [{$source}] {$text} (id={$id})\n";
                }
            }
        }

        $user .= "\nALL ITEMS (for selection):\n";
        foreach ($items as $item) {
            $text = (string) ($item['text'] ?? '');
            $source = (string) ($item['source'] ?? 'other');
            $id = (string) ($item['id'] ?? '');
            $mediaType = (string) ($item['media_type'] ?? '');
            $clusterId = (string) ($item['cluster_id'] ?? '');
            $confidence = isset($item['confidence_hint']) ? (float) $item['confidence_hint'] : null;
            $angle = (string) ($item['creative']['angle'] ?? '');
            $hook = (string) ($item['creative']['hook_text'] ?? '');

            $line = "- id={$id} source={$source} media_type={$mediaType}";
            if ($clusterId !== '') { $line .= " cluster={$clusterId}"; }
            if ($confidence !== null) { $line .= " confidence_hint=" . number_format($confidence, 2); }
            $user .= $line . "\n";
            if ($angle !== '') { $user .= "  angle: {$angle}\n"; }
            if ($hook !== '') { $user .= "  hook: {$hook}\n"; }
            $user .= "  text: " . $this->truncate($text, 360) . "\n";
        }

        $user .= "\nTASK:\n";
        $user .= "Produce a structured research report. Emphasize dominant claims as points of agreement.\n";
        $user .= "List disagreements when statements conflict. Mark saturated vs emerging angles by frequency and novelty.\n";
        $user .= "Return JSON only.";

        $llmParams = [
            'json_schema_hint' => '{"question":"...","dominant_claims":["..."],"points_of_disagreement":["..."],"saturated_angles":["..."],"emerging_angles":["..."],"example_excerpts":[{"text":"...","source":"youtube|x|linkedin|instagram|other","confidence":0.0}]}',
        ];
        $this->injectModelOverrides($options, $llmParams);

        return new Prompt(system: $system, user: $user, schemaName: 'research_report', llmParams: $llmParams);
    }

    private function injectModelOverrides(array $options, array &$llmParams): void
    {
        try {
            $defaultModel = isset($options['model']) ? trim((string) $options['model']) : '';
            $models = is_array($options['models'] ?? null) ? (array) $options['models'] : [];
            if ($defaultModel !== '') {
                $llmParams['model'] = $defaultModel;
            }
            if (!empty($models)) {
                $llmParams['models'] = $models;
            }
        } catch (\Throwable) {
            // Keep llmParams unchanged on error.
        }
    }

    private function truncate(string $text, int $limit): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($t) <= $limit) {
            return $t;
        }
        $slice = mb_substr($t, 0, $limit + 1);
        $cut = mb_strrpos($slice, ' ');
        if ($cut === false || $cut < 20) {
            $cut = $limit;
        }
        return rtrim(mb_substr($slice, 0, $cut), " \t\n\r\0\x0B.,;:") . '...';
    }
}
