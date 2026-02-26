<?php

namespace App\Services\Ai;

use App\Models\GenerationSnapshot;
use Illuminate\Support\Str;

class SnapshotService
{
    /**
     * Persist a replayable snapshot of the generation state.
     * Returns the snapshot id.
     */
    public function storeSnapshot(
        string $orgId,
        string $userId,
        string $platform,
        string $prompt,
        array $classification,
        GenerationContext $context,
        array $options,
        string $content,
        string $finalSystemPrompt = '',
        string $finalUserPrompt = '',
        array $tokenUsage = [],
        array $performance = [],
        array $repairInfo = [],
        array $llmStages = [],
        ?string $generatedPostId = null,
        ?string $conversationId = null,
        ?string $conversationMessageId = null
    ): string
    {
        $tpl = $context->template;
        $voiceProfileId = $options['voice_profile_id'] ?? null;
        $voiceSource = $options['voice_source'] ?? ($voiceProfileId ? 'explicit' : 'none');
        $intent = (string) ($classification['intent'] ?? '');
        $modeType = (string) ($options['mode'] ?? 'generate');
        $modeType = in_array($modeType, ['generate', 'research'], true) ? $modeType : 'generate';
        $modeSubtype = $modeType === 'research' ? (string) ($options['research_stage'] ?? '') : null;

        $structureResolution = null;
        $structureFitScore = null;
        $resolvedStructurePayload = null;
        try {
            $swipes = is_array($context->swipes) ? $context->swipes : [];
            $first = $swipes[0] ?? null;
            if (is_array($first)) {
                $structureResolution = isset($first['structure_resolution']) ? (string) $first['structure_resolution'] : null;
                $structureFitScore = isset($first['fit_score']) ? (int) $first['fit_score'] : (isset($first['structure_fit_score']) ? (int) $first['structure_fit_score'] : null);
                $resolvedStructurePayload = [
                    'id' => $first['id'] ?? null,
                    'is_ephemeral' => (bool) ($first['is_ephemeral'] ?? false),
                    'origin' => $first['origin'] ?? null,
                    'title' => $first['title'] ?? null,
                    'intent' => $first['intent'] ?? null,
                    'funnel_stage' => $first['funnel_stage'] ?? null,
                    'cta_type' => $first['cta_type'] ?? null,
                    'structure' => $first['structure'] ?? null,
                ];
            }
        } catch (\Throwable) {
            // Non-fatal: snapshot still stores successfully without these extra fields.
        }

        $llmStages = $this->validateLlmStages($llmStages);
        $snapshot = GenerationSnapshot::create([
            'organization_id' => $orgId,
            'user_id' => $userId,
            'generated_post_id' => $generatedPostId ?: null,
            'conversation_id' => $conversationId ?: null,
            'conversation_message_id' => $conversationMessageId ?: null,
            'platform' => 'not_applicable',
            'prompt' => $prompt,
            'classification' => $classification,
            'intent' => $intent !== '' ? $intent : null,
            'mode' => [
                'type' => $modeType,
                'subtype' => $modeSubtype,
            ],
            // Only store template_id if it's a valid UUID (fallback templates use string identifiers)
            'template_id' => ($tpl?->id && Str::isUuid($tpl->id)) ? $tpl->id : null,
            'template_data' => $tpl?->template_data ?? null,
            'voice_profile_id' => $voiceProfileId ?: null,
            'voice_source' => $voiceSource ?: null,
            'chunks' => array_map(fn($c) => [
                'id' => $c['id'] ?? null,
                'text' => $c['chunk_text'] ?? '',
                'chunk_kind' => $c['chunk_kind'] ?? null,
                'chunk_role' => $c['chunk_role'] ?? null,
            ], $context->chunks),
            'facts' => array_map(fn($f) => ['id' => $f['id'] ?? null, 'text' => $f['text'] ?? '', 'confidence' => $f['confidence'] ?? null], $context->facts),
            'swipes' => $context->swipes,
            'structure_resolution' => $structureResolution,
            'structure_fit_score' => $structureFitScore,
            'resolved_structure_payload' => $resolvedStructurePayload,
            'user_context' => $context->user_context,
            'creative_intelligence' => $context->creative_intelligence ?? null,
            'decision_trace' => !empty($context->decisionTrace()) ? $context->decisionTrace() : null,
            'prompt_mutations' => !empty($context->promptMutations()) ? $context->promptMutations() : null,
            'ci_rejections' => !empty($context->ciRejections()) ? $context->ciRejections() : null,
            // Persist enriched options passed by caller (includes voice_source, voice_profile_id, diagnostics)
            'options' => $options,
            'output_content' => $content,
            // High‑fidelity auditing fields
            'final_system_prompt' => $finalSystemPrompt !== '' ? $finalSystemPrompt : null,
            'final_user_prompt' => $finalUserPrompt !== '' ? $finalUserPrompt : null,
            'token_metrics' => !empty($tokenUsage) ? $tokenUsage : null,
            'performance_metrics' => !empty($performance) ? $performance : null,
            'repair_metrics' => !empty($repairInfo) ? $repairInfo : null,
            'llm_stages' => !empty($llmStages) ? $llmStages : null,
            'created_at' => now(),
        ]);

        // If this snapshot is for a chat conversation, update its active context
        if (!empty($conversationId)) {
            try {
                $activeSwipeIds = array_values(array_filter(array_map(function ($s) {
                    return is_array($s) ? ($s['id'] ?? null) : (is_object($s) ? ($s->id ?? null) : null);
                }, (array) $context->swipes)));
                $activeFactIds = array_values(array_filter(array_map(function ($f) {
                    return is_array($f) ? ($f['id'] ?? null) : (is_object($f) ? ($f->id ?? null) : null);
                }, (array) $context->facts)));
                $snapshotIds = $context->snapshotIds();
                $activeReferenceIds = array_values(array_filter((array) ($snapshotIds['reference_ids'] ?? [])));

                \App\Models\AiCanvasConversation::where('id', $conversationId)->update([
                    'last_snapshot_id' => (string) $snapshot->id,
                    'active_voice_profile_id' => $options['voice_profile_id'] ?? null,
                    // Only store template_id if it's a valid UUID (fallback templates use string identifiers)
                    'active_template_id' => ($tpl?->id && Str::isUuid($tpl->id)) ? $tpl->id : null,
                    'active_swipe_ids' => $activeSwipeIds,
                    'active_fact_ids' => $activeFactIds,
                    'active_reference_ids' => $activeReferenceIds,
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: snapshot remains stored even if conversation update fails
            }
        }
        return (string) $snapshot->id;
    }

    /**
     * Validate and sanitize stage-centric LLM tracking payload.
     * - Unknown stage keys are dropped.
     * - Each stage must be an object with non-empty string 'model'.
     * - Empty result returns [] (caller persists null).
     *
     * @param array $llmStages
     * @return array<string, array{model: string}>
     */
    private function validateLlmStages(array $llmStages): array
    {
        try {
            $allowed = [
                'classify',
                'structure_match',
                'structure_fallback_generate',
                'structure_promote',
                'generate',
                'generate_fallback',
                'repair',
                'reflection',
                'critique',
                'reflection_refine',
                'replay',
            ];

            $out = [];
            foreach ($llmStages as $stage => $payload) {
                if (!is_string($stage) || !in_array($stage, $allowed, true)) {
                    continue;
                }
                if (!is_array($payload)) {
                    continue;
                }
                $model = trim((string) ($payload['model'] ?? ''));
                if ($model === '') {
                    continue;
                }
                $out[$stage] = ['model' => $model];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
