<?php

namespace App\Services\Ai\SwipeStructures;

use App\Services\OpenRouterService;

class EphemeralStructureGenerator
{
    public function __construct(
        protected OpenRouterService $llm,
    ) {}

    /**
     * @return array{structure: array<int, array{section:string,purpose:string}>, meta: array{model?:string,usage?:array,source:string}}
     */
    public function generate(string $prompt, ?string $requestedIntent = null, ?string $requestedLengthBand = null, ?string $requestedShapeHint = null): array
    {
        $prompt = trim($prompt);

        $schemaHint = '{"structure":[{"section":"Hook","purpose":"..."}],"confidence":0,"origin":"ephemeral"}';

        $messages = [
            [
                'role' => 'system',
                'content' => "You generate a minimal writing structure only. Return STRICT JSON only.\n" .
                    "Hard constraints:\n" .
                    "- Output 3–6 sections only.\n" .
                    "- Each section MUST be an object with keys: section, purpose (both strings).\n" .
                    "- No content wording, no examples, no platform labels.\n" .
                    "- Return top-level JSON with keys: structure (array), confidence (0-100), origin ('ephemeral').",
            ],
            [
                'role' => 'user',
                'content' => $this->buildUserPrompt($prompt, $requestedIntent, $requestedLengthBand, $requestedShapeHint),
            ],
        ];

        $first = $this->llm->chatJSONWithMeta($messages, [
            'temperature' => 0.2,
            'json_schema_hint' => $schemaHint,
        ]);

        $data = (array) ($first['data'] ?? []);
        $meta = (array) ($first['meta'] ?? []);

        $structure = $this->normalizeStructure($data['structure'] ?? null);
        if ($this->isValidStructure($structure)) {
            return [
                'structure' => $structure,
                'meta' => [
                    'model' => isset($meta['model']) ? (string) $meta['model'] : null,
                    'usage' => isset($meta['usage']) && is_array($meta['usage']) ? $meta['usage'] : [],
                    'source' => 'llm',
                ],
            ];
        }

        // Retry once with a stricter prompt
        $messages[0]['content'] .= "\n\nIMPORTANT: Your previous response was invalid. Return ONLY JSON with the required shape.";
        $retry = $this->llm->chatJSONWithMeta($messages, [
            'temperature' => 0.0,
            'json_schema_hint' => $schemaHint,
        ]);

        $data2 = (array) ($retry['data'] ?? []);
        $meta2 = (array) ($retry['meta'] ?? []);
        $structure2 = $this->normalizeStructure($data2['structure'] ?? null);

        if ($this->isValidStructure($structure2)) {
            return [
                'structure' => $structure2,
                'meta' => [
                    'model' => isset($meta2['model']) ? (string) $meta2['model'] : null,
                    'usage' => isset($meta2['usage']) && is_array($meta2['usage']) ? $meta2['usage'] : [],
                    'source' => 'llm_retry',
                ],
            ];
        }

        // Hardcoded skeleton fallback
        return [
            'structure' => [
                ['section' => 'Hook', 'purpose' => 'Create tension or interest'],
                ['section' => 'Context', 'purpose' => 'Clarify what this is about'],
                ['section' => 'Core Point', 'purpose' => 'State the main idea clearly'],
                ['section' => 'Takeaways', 'purpose' => 'Explain or list the key points'],
                ['section' => 'CTA', 'purpose' => 'Invite the reader to take an optional next step'],
            ],
            'meta' => [
                'model' => null,
                'usage' => [],
                'source' => 'hardcoded',
            ],
        ];
    }

    private function buildUserPrompt(string $prompt, ?string $intent, ?string $lengthBand, ?string $shapeHint): string
    {
        $lines = [];
        if ($intent) { $lines[] = "Requested intent (optional): {$intent}"; }
        if ($lengthBand) { $lines[] = "Requested length band (optional): {$lengthBand}"; }
        if ($shapeHint) { $lines[] = "Requested shape hint (optional): {$shapeHint}"; }
        $lines[] = "Prompt: {$prompt}";
        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{section:string,purpose:string}>
     */
    private function normalizeStructure($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $section = trim((string) ($row['section'] ?? ''));
            $purpose = trim((string) ($row['purpose'] ?? ''));
            if ($section === '' || $purpose === '') {
                continue;
            }
            $out[] = [
                'section' => mb_substr($section, 0, 60),
                'purpose' => mb_substr($purpose, 0, 200),
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{section:string,purpose:string}> $structure
     */
    private function isValidStructure(array $structure): bool
    {
        $n = count($structure);
        if ($n < 3 || $n > 6) {
            return false;
        }
        foreach ($structure as $s) {
            if (!isset($s['section'], $s['purpose'])) {
                return false;
            }
            if (trim((string) $s['section']) === '' || trim((string) $s['purpose']) === '') {
                return false;
            }
        }
        return true;
    }
}
