<?php

namespace App\Services\Ai\Research;

use App\Services\Ai\LLMClient;
use App\Services\Ai\Generation\Steps\CreativeIntelligenceRecommender;
use App\Services\Ai\Generation\Steps\PromptSignalExtractor;
use LaundryOS\SocialWatcher\Models\CreativeUnit;

class HookGenerationService
{
    public function __construct(
        protected LLMClient $llm,
        protected CreativeIntelligenceRecommender $ciRecommender,
        protected PromptSignalExtractor $signalExtractor,
    ) {}

    /**
     * @return array{report:array<string,mixed>,meta:array<string,mixed>}
     */
    public function generate(
        string $orgId,
        string $userId,
        string $prompt,
        string $platform,
        array $classification,
        array $options = []
    ): array {
        $count = (int) ($options['hooks_count'] ?? 5);
        $count = max(1, min(10, $count));

        // Try to get canonical clusters if enabled
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        $canonicalHooks = [];
        $canonicalAngles = [];
        
        if ($useCanonical) {
            try {
                $researchOpts = ResearchOptions::fromArray($orgId, $userId, array_merge($options, [
                    'limit' => 10,
                    'max_examples' => 3,
                ]));
                
                $hookClusters = $this->swGateway->getClusters($orgId, 'ci_hook', $researchOpts);
                $angleClusters = $this->swGateway->getClusters($orgId, 'ci_angle', $researchOpts);
                
                foreach ($hookClusters as $cluster) {
                    foreach ($cluster->getExamples() as $example) {
                        $canonicalHooks[] = [
                            'hook_text' => $example->text,
                            'hook_archetype' => $cluster->label ?? '',
                            'cluster_id' => $cluster->clusterId,
                        ];
                    }
                }
                
                foreach ($angleClusters as $cluster) {
                    $canonicalAngles[] = [
                        'label' => $cluster->label ?? '',
                        'summary' => $cluster->summary ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                \Log::warning('HookGenerationService: canonical cluster retrieval failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $signals = $this->signalExtractor->extract($prompt, $platform, $options);
        $ciPolicy = [
            'mode' => 'auto',
            'hook' => 'force',
            'emotion' => 'fill',
            'audience' => 'fill',
            'max_hooks' => max(5, $count),
            'max_angles' => 4,
            'allow_verbatim_hooks' => false,
        ];

        $ci = $this->ciRecommender->recommend(
            $orgId,
            $userId,
            $prompt,
            $platform,
            $classification,
            $signals,
            $ciPolicy
        );

        $ciHooks = (array) ($ci->recommendations['hooks'] ?? []);
        $ciHooks = $this->filterHooksByQuality($ciHooks);
        
        // Merge canonical hooks with CI recommendations
        if (!empty($canonicalHooks)) {
            $ciHooks = array_merge($canonicalHooks, $ciHooks);
        }

        $hookPatterns = array_values(array_filter(array_map(function ($hook) {
            $text = trim((string) ($hook['hook_text'] ?? ''));
            if ($text === '') {
                return null;
            }
            $archetype = trim((string) ($hook['hook_archetype'] ?? ''));
            return $archetype !== '' ? "{$text} ({$archetype})" : $text;
        }, $ciHooks)));

        $angleLabels = array_values(array_filter(array_map(function ($angle) {
            return trim((string) ($angle['label'] ?? ''));
        }, (array) ($ci->recommendations['angles'] ?? []))));
        
        // Merge canonical angles
        if (!empty($canonicalAngles)) {
            $angleLabels = array_merge(
                array_map(fn($a) => $a['label'], $canonicalAngles),
                $angleLabels
            );
        }

        $resolved = (array) ($ci->resolved ?? []);
        $emotionalTarget = (array) ($resolved['emotional_target'] ?? []);
        $audiencePersona = (string) ($resolved['audience_persona'] ?? '');
        $sophistication = (string) ($resolved['sophistication_level'] ?? '');

        $system = implode("\n", [
            'You are generating short, punchy hooks for a research analyst.',
            'Return ONLY valid JSON: {"hooks":[{"text":"...","archetype":"..."}]}.',
            'Do not include commentary, markdown, or extra keys.',
            'Do not reuse example hooks verbatim; use them as pattern inspiration.',
        ]);

        $userLines = [
            'Topic/angle: ' . trim($prompt),
            'Hooks to generate: ' . $count,
        ];

        if (!empty($angleLabels)) {
            $userLines[] = 'Related angles: ' . implode('; ', array_slice($angleLabels, 0, 4));
        }
        if (!empty($hookPatterns)) {
            $userLines[] = 'Hook patterns (inspiration only): ' . implode(' | ', array_slice($hookPatterns, 0, 6));
        }
        if (!empty($audiencePersona)) {
            $userLines[] = 'Audience persona: ' . $audiencePersona;
        }
        if (!empty($sophistication)) {
            $userLines[] = 'Audience sophistication: ' . $sophistication;
        }
        if (!empty($emotionalTarget)) {
            $userLines[] = 'Emotional target: ' . json_encode($emotionalTarget);
        }

        $user = implode("\n", $userLines);

        $res = $this->llm->callWithMeta('research_hooks', $system, $user, 'research_hooks_v1');
        $data = $res['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $hooks = is_array($data['hooks'] ?? null) ? $data['hooks'] : [];
        $hooks = array_values(array_filter(array_map(function ($hook) {
            if (!is_array($hook)) {
                return null;
            }
            $text = trim((string) ($hook['text'] ?? ''));
            if ($text === '') {
                return null;
            }
            return [
                'text' => $text,
                'archetype' => trim((string) ($hook['archetype'] ?? '')),
            ];
        }, $hooks)));

        if (empty($hooks)) {
            $hooks = array_slice(array_map(function ($hook) {
                return [
                    'text' => (string) ($hook['hook_text'] ?? ''),
                    'archetype' => (string) ($hook['hook_archetype'] ?? ''),
                ];
            }, $ciHooks), 0, $count);
        }

        if (empty($hooks)) {
            $hooks = array_slice(array_map(function ($hook) {
                return [
                    'text' => (string) ($hook['hook_text'] ?? ''),
                    'archetype' => (string) ($hook['hook_archetype'] ?? ''),
                ];
            }, (array) ($ci->recommendations['hooks'] ?? [])), 0, $count);
        }

        return [
            'report' => [
                'hooks' => array_slice($hooks, 0, $count),
                'signals' => [
                    'audience_persona' => $audiencePersona,
                    'sophistication_level' => $sophistication,
                    'emotional_target' => $emotionalTarget,
                ],
            ],
            'meta' => [
                'model' => (string) ($res['meta']['model'] ?? ''),
                'usage' => (array) ($res['meta']['usage'] ?? []),
                'latency_ms' => (int) ($res['latency_ms'] ?? 0),
            ],
        ];
    }

    private function filterHooksByQuality(array $hooks): array
    {
        $ids = array_values(array_filter(array_map(fn($h) => (int) ($h['id'] ?? 0), $hooks)));
        if (empty($ids)) {
            return $hooks;
        }

        $allowed = CreativeUnit::query()
            ->whereIn('id', $ids)
            ->where('is_business_relevant', true)
            ->where(function ($q) {
                $q->whereNull('noise_risk')->orWhere('noise_risk', '<=', 0.3);
            })
            ->where(function ($q) {
                $q->whereNull('buyer_quality_score')->orWhere('buyer_quality_score', '>=', 0.6);
            })
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($allowed)) {
            return $hooks;
        }

        $allowedIds = array_flip($allowed);
        return array_values(array_filter($hooks, function ($hook) use ($allowedIds) {
            $id = (int) ($hook['id'] ?? 0);
            return $id !== 0 && isset($allowedIds[$id]);
        }));
    }
}
