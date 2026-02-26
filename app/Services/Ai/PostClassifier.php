<?php

namespace App\Services\Ai;

use App\Enums\LlmStage;
use App\Services\OpenRouterService;

class PostClassifier
{
    public function __construct(protected OpenRouterService $openRouter) {}

    /**
     * Classify a prompt into { intent, funnel_stage }.
     * Fallback: ['intent' => 'educational', 'funnel_stage' => 'tof']
     */
    public function classify(string $prompt, array $options = [], ?LlmStageTracker $tracker = null): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['intent' => 'educational', 'funnel_stage' => 'tof'];
        }

        $schema = 'Return ONLY JSON with keys: intent, funnel_stage. '
            . 'intent one of [educational,persuasive,emotional,contrarian,story]; '
            . 'funnel_stage one of [tof,mof,bof].';

        $messages = [
            ['role' => 'system', 'content' => 'You are a concise classifier for social media post generation. ' . $schema],
            ['role' => 'user', 'content' => 'PROMPT: ' . $prompt],
        ];

        $wrapped = $this->openRouter->classifyWithMeta($messages, $options);
        $res = is_array($wrapped['data'] ?? null) ? (array) $wrapped['data'] : [];
        $modelUsed = trim((string) (($wrapped['meta']['model'] ?? '') ?: ($options['model'] ?? '')));
        if ($tracker && $modelUsed !== '') {
            $tracker->record(LlmStage::CLASSIFY, $modelUsed);
        }
        $intent = $res['intent'] ?? null;
        $funnel = $res['funnel_stage'] ?? null;
        if (!is_string($intent) || !in_array($intent, ['educational','persuasive','emotional','contrarian','story'], true)) {
            $intent = 'educational';
        }
        if (!is_string($funnel) || !in_array($funnel, ['tof','mof','bof'], true)) {
            $funnel = 'tof';
        }
        return ['intent' => $intent, 'funnel_stage' => $funnel];
    }
}

