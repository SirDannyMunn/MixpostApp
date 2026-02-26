<?php

namespace App\Services\Ai;

use App\Models\VoiceProfile;
use App\Enums\LlmStage;
use App\Services\Ai\Generation\DTO\Constraints;
use App\Services\Ai\Generation\Policy\GenerationPolicy;
use App\Services\Ai\Generation\Steps\PromptComposer;
use App\Services\Ai\Generation\Steps\GenerationRunner;
use App\Services\Ai\Generation\Steps\ValidationAndRepairService;
use App\Services\Ai\Generation\Steps\OverrideResolver;
use App\Services\Ai\Generation\Steps\BusinessProfileResolver;
use App\Services\Ai\Generation\Steps\SnapshotPersister;
use App\Services\Ai\Generation\DTO\GenerationRequest;
use App\Services\Ai\Generation\Steps\TemplateService;
use App\Services\Ai\Generation\Factories\ContextFactory;
use App\Services\Ai\Generation\DTO\PromptBuildResult;
use App\Services\Ai\Generation\Steps\ReflexionService;
use App\Services\Ai\Generation\Steps\PromptSignalExtractor;
use App\Services\Ai\Generation\Steps\CreativeIntelligenceRecommender;
use App\Services\Ai\Generation\Steps\RelevanceGate;
use App\Services\Ai\Generation\DecisionTraceCollector;
use App\Services\Ai\ChunkKindResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ai\Research\ResearchReportComposer;
use App\Services\Ai\Research\TrendDiscoveryService;
use App\Services\Ai\Research\HookGenerationService;
use App\Services\Ai\Research\ResearchExecutor;
use App\Services\Ai\Research\DTO\ResearchOptions;
use App\Services\Ai\Research\Formatters\ChatResearchFormatter;
use App\Enums\ResearchStage;

class ContentGeneratorService
{
    public function __construct(
        protected LLMClient $llm,
        protected Retriever $retriever,
        protected TemplateSelector $selector,
        protected ContextAssembler $assembler,
        protected PostValidator $validator,
        protected SchemaValidator $schemaValidator,
        protected PostClassifier $classifier,
        protected PromptComposer $composer,
        protected PromptSignalExtractor $promptSignalExtractor,
        protected FolderScopeResolver $folderScopeResolver,
        protected CreativeIntelligenceRecommender $ciRecommender,
        protected RelevanceGate $relevanceGate,
        protected GenerationRunner $runner,
        protected ValidationAndRepairService $validatorRepair,
        protected OverrideResolver $overrideResolver,
        protected BusinessProfileResolver $bpResolver,
        protected SnapshotPersister $snapshotPersister,
        protected TemplateService $templateService,
        protected ContextFactory $contextFactory,
        protected ContentGenBatchLogger $cgLogger,
        protected ReflexionService $reflexion,
        protected \App\Services\Ai\Generation\Steps\PromptInsightSelector $insightSelector,
        protected ResearchReportComposer $researchReportComposer,
        protected TrendDiscoveryService $trendDiscovery,
        protected HookGenerationService $hookGenerator,
        protected ResearchExecutor $researchExecutor,
        protected ChatResearchFormatter $researchFormatter,
    ) {}

    private function estimateCost(int $promptTokens, int $completionTokens, string $model): ?float
    {
        try {
            // Optional pricing config (per 1K tokens). Supports either
            // a flat shape ['in'=>..,'out'=>..] or a per-model map [model=>['in'=>..,'out'=>..]].
            $pricing = config('services.openrouter.pricing');
            if (!is_array($pricing)) { return null; }

            $in = null; $out = null;
            if (array_key_exists('in', $pricing) || array_key_exists('out', $pricing)) {
                $in = isset($pricing['in']) ? (float) $pricing['in'] : null;
                $out = isset($pricing['out']) ? (float) $pricing['out'] : null;
            } elseif (isset($pricing[$model]) && is_array($pricing[$model])) {
                $in = isset($pricing[$model]['in']) ? (float) $pricing[$model]['in'] : null;
                $out = isset($pricing[$model]['out']) ? (float) $pricing[$model]['out'] : null;
            }
            if ($in === null && $out === null) { return null; }
            $costIn = $in !== null ? ($promptTokens / 1000.0) * $in : 0.0;
            $costOut = $out !== null ? ($completionTokens / 1000.0) * $out : 0.0;
            return round($costIn + $costOut, 6);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unified generation entry point used by both Chat and Async Job.
     *
     * @param string $orgId
     * @param string $userId
     * @param string $prompt
     * @param string $platform
     * @param array $options Options include: max_chars, emoji, tone, retrieval_limit, user_context, reference_ids
     * @return array{content:string, context_used:array, validation_result:bool, validation:array, metadata:array}
     */
    public function generate(string $orgId, string $userId, string $prompt, string $platform, array $options = []): array
    {
        $t0 = microtime(true);
        $llmStages = new LlmStageTracker();
        $req = new GenerationRequest($orgId, $userId, $prompt, $platform, $options);
        $this->cgLogger->setMode($req->mode, $req->researchStage !== '' ? $req->researchStage : null);
        $runId = $req->runId;
        $decisionTrace = new DecisionTraceCollector();
        $promptMutations = [];
        $ciRejections = [];
        $options = $req->normalizedOptions;
        $options['run_id'] = $runId;
        $options['mode'] = $req->mode;
        // Canonicalize folder-scoped retrieval boundary (knowledge retrieval only)
        $options['folder_ids'] = $req->folderIds;
        // Begin batched logging for this generation run
        try {
            $this->cgLogger->startRun($runId, [
                'entry' => 'generate',
                'org_id' => $orgId,
                'user_id' => $userId,
                'platform' => $platform,
                'mode' => [
                    'type' => $req->mode,
                    'subtype' => $req->mode === 'research' ? $req->researchStage : null,
                ],
                'options' => $options,
                'raw_request' => [
                    'retrievalPolicy' => $req->retrievalPolicy,
                    'contextInputs' => $req->contextInputs,
                    'vipOverrides' => $req->vipOverrides,
                    'swipePolicy' => $req->swipePolicy,
                    'templatePolicy' => $req->templatePolicy,
                    'folder_ids' => $req->folderIds,
                    'folder_scope' => $req->folderScopePolicy,
                    'constraints' => [
                        'maxChars' => $req->constraints->maxChars,
                        'emojiPolicy' => $req->constraints->emojiPolicy,
                        'tone' => $req->constraints->tone,
                    ],
                    'classificationOverrides' => $req->classificationOverrides,
                    'voiceOverrides' => $req->voiceOverrides,
                ],
                'prompt' => $prompt,
            ]);
        } catch (\Throwable) {}
        $prompt = $req->prompt;
        if ($req->mode === 'research') {
            return $this->generateResearchReport($req, $options, $llmStages, $t0);
        }
        if ($req->mode === 'comment') {
            return $this->generateComment($req, $options, $llmStages, $t0);
        }
        $retrievalLimit = (int) $req->retrievalPolicy['retrievalLimit'];
        $userContext = (string) $req->contextInputs['userContext'];
        $businessContext = (string) $req->contextInputs['businessContext'];
        $referenceIds = (array) $req->contextInputs['referenceIds'];
        $overrides = (array) $req->vipOverrides;
        $swipeMode = (string) $req->swipePolicy['mode'];
        $swipeIds = (array) $req->swipePolicy['swipeIds'];
        $templateOverrideOpt = $req->templatePolicy['templateId'];

        // Constraint options (defaults); may be refined by business profile later
        $maxChars = (int) $req->constraints->maxChars;
        $emojiPolicy = (string) $req->constraints->emojiPolicy;
        $tone = (string) $req->constraints->tone;

        // Classification overrides
        $intentOverride = $req->classificationOverrides['intent'] ?? null;
        $funnelOverride = $req->classificationOverrides['funnel_stage'] ?? null;

        // Voice overrides
        $voiceProfileId = $req->voiceOverrides['voiceProfileId'] ?? null;
        $voiceInline = $req->voiceOverrides['voiceInline'] ?? null;

        // Parse explicit overrides (VIP): template/swipes/facts/knowledge
        $vipChunks = [];
        $vipFacts = [];
        $vipSwipes = [];
        $overrideTemplateId = $overrides['template_id'] ?? null;
        // Resolve VIP knowledge
        $k = $this->overrideResolver->resolveVipKnowledge((array) ($overrides['knowledge'] ?? []));
        $vipChunks = $k['vipChunks'];
        $referenceIds = array_merge($referenceIds, (array) $k['referenceIds']);
        // Resolve VIP facts
        $f = $this->overrideResolver->resolveVipFacts($orgId, (array) ($overrides['facts'] ?? []));
        $vipFacts = $f['vipFacts'];
        $referenceIds = array_merge($referenceIds, (array) $f['referenceIds']);
        // Resolve VIP swipes
        $s = $this->overrideResolver->resolveVipSwipes($orgId, (array) ($overrides['swipes'] ?? []));
        $vipSwipes = $s['vipSwipes'];
        $referenceIds = array_merge($referenceIds, (array) $s['referenceIds']);
        $referenceIds = array_values(array_unique(array_filter($referenceIds, fn($v) => (string) $v !== '')));
        try { $this->cgLogger->capture('resolve_overrides', [
            'overrides' => $overrides,
            'vip_chunks' => $vipChunks,
            'vip_facts' => $vipFacts,
            'vip_swipes' => $vipSwipes,
            'override_template_id' => $overrideTemplateId,
            'reference_ids' => $referenceIds,
        ]);} catch (\Throwable) {}

        // 1) Classify prompt intent/funnel with overrides logic
        $classificationOriginal = null;
        $classification = ['intent' => null, 'funnel_stage' => null];
        if ($intentOverride && $funnelOverride) {
            $classification = ['intent' => $intentOverride, 'funnel_stage' => $funnelOverride];
        } else {
            // We need the classifier to fill missing pieces or provide defaults
            $classificationOriginal = $this->classifier->classify($prompt, $this->stageModelOptions($options, 'classify'), $llmStages);
            $classification['intent'] = $intentOverride ?: ($classificationOriginal['intent'] ?? 'educational');
            $classification['funnel_stage'] = $funnelOverride ?: ($classificationOriginal['funnel_stage'] ?? 'tof');
        }
        $classificationOverridden = (bool) ($intentOverride || $funnelOverride);
        try { $this->cgLogger->capture('classification', [
            'classification_original' => $classificationOriginal,
            'classification_final' => $classification,
            'classification_overridden' => $classificationOverridden,
        ]);} catch (\Throwable) {}

        // 1.5) Prompt signal extraction (hook/emotion/audience/format)
        $ciPolicy = (array) $req->ciPolicy;
        $signals = null;
        $ci = null;
        try {
            $signals = $this->promptSignalExtractor->extract($prompt, $platform, $options, $decisionTrace, $llmStages);
            $this->cgLogger->capture('ci_signals', ['signals' => $signals->toArray()]);
        } catch (\Throwable $e) {
            $decisionTrace->record('prompt_signals', PromptSignalExtractor::class, 'error', 'exception');
            try { $this->cgLogger->capture('ci_signals_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
        }

        // 1.6) Creative Intelligence recommendation (optional)
        if (($ciPolicy['mode'] ?? 'auto') !== 'none') {
            try {
                $ci = $this->ciRecommender->recommend(
                    $orgId,
                    $userId,
                    $prompt,
                    $platform,
                    $classification,
                    $signals ?? new \App\Services\Ai\Generation\DTO\PromptSignals(),
                    $ciPolicy,
                    $decisionTrace,
                    $ciRejections
                );
                $this->cgLogger->capture('ci_recommendation', ['ci' => $ci->toArray()]);
            } catch (\Throwable $e) {
                $decisionTrace->record('ci_recommendation', CreativeIntelligenceRecommender::class, 'error', 'exception');
                try { $this->cgLogger->capture('ci_recommendation_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
                $ci = null;
            }
        }

        // 1.7) Folder auto-scope resolution (optional; before retrieval)
        $folderScopePolicy = (array) $req->folderScopePolicy;
        $resolvedFolderIds = $req->folderIds;
        $folderScopeResult = null;
        $folderScopeDiagnostics = [
            'folder_scope_used' => false,
            'folder_scope_mode' => $folderScopePolicy['mode'] ?? 'off',
            'folder_scope_method' => null,
            'folder_scope_selected_ids' => [],
            'folder_scope_candidates' => [],
            'folder_scope_minScore' => $folderScopePolicy['minScore'] ?? null,
        ];
        $folderScopeBlockRetrieval = false;
        $explicitFolderIds = !empty($req->folderIds);

        if (($folderScopePolicy['mode'] ?? 'off') !== 'off' && (!$explicitFolderIds || ($folderScopePolicy['mode'] ?? '') === 'augment')) {
            try {
                $folderScopeResult = $this->folderScopeResolver->resolve(
                    $orgId,
                    $userId,
                    $prompt,
                    $classification,
                    $signals ?? null,
                    $folderScopePolicy
                );
                $folderScopeDiagnostics['folder_scope_used'] = (bool) ($folderScopeResult['used'] ?? false);
                $folderScopeDiagnostics['folder_scope_selected_ids'] = (array) ($folderScopeResult['folder_ids'] ?? []);
                $folderScopeDiagnostics['folder_scope_candidates'] = (array) ($folderScopeResult['candidates'] ?? []);
                $folderScopeDiagnostics['folder_scope_minScore'] = $folderScopeResult['min_score'] ?? $folderScopeDiagnostics['folder_scope_minScore'];
                $folderScopeDiagnostics['folder_scope_method'] = $folderScopeResult['method'] ?? $folderScopeDiagnostics['folder_scope_method'];
                $folderScopeBlockRetrieval = (bool) ($folderScopeResult['block_retrieval'] ?? false);

                $selected = array_values(array_filter(array_map('strval', (array) ($folderScopeResult['folder_ids'] ?? [])), fn($v) => $v !== ''));
                if (!empty($selected)) {
                    if ($explicitFolderIds && ($folderScopePolicy['mode'] ?? '') === 'augment') {
                        $resolvedFolderIds = array_values(array_unique(array_merge($resolvedFolderIds, $selected)));
                    } else {
                        $resolvedFolderIds = $selected;
                    }
                }
            } catch (\Throwable $e) {
                try { $this->cgLogger->capture('folder_scope_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
            }
        }

        $options['folder_ids'] = $resolvedFolderIds;
        foreach ($folderScopeDiagnostics as $k => $v) {
            $options[$k] = $v;
        }
        try { $this->cgLogger->capture('folder_scope', $folderScopeDiagnostics); } catch (\Throwable) {}

        // 2) Retrieve knowledge/facts/swipes
        $useRetrieval = (bool) ($req->retrievalPolicy['useRetrieval'] ?? true);
        if ($folderScopeBlockRetrieval) {
            $useRetrieval = false;
        }
        $chunks = [];
        $enrichmentChunks = [];
        if ($useRetrieval) {
            $retrievalFilters = is_array($options['retrieval_filters'] ?? null) ? (array) $options['retrieval_filters'] : [];
            if (!empty($resolvedFolderIds)) {
                $retrievalFilters['folder_ids'] = $resolvedFolderIds;
            }
            // Apply retrieval policy overrides
            if ($req->retrievalPolicy['vectorRoles'] !== null) {
                $retrievalFilters['vectorRoles'] = $req->retrievalPolicy['vectorRoles'];
            }
            // Include instructions if requested
            if ($req->retrievalPolicy['includeInstructions'] === true) {
                $currentRoles = (array) ($retrievalFilters['vectorRoles'] ?? config('ai_chunk_roles.vector_searchable_roles', []));
                if (!in_array('instruction', $currentRoles, true)) {
                    $currentRoles[] = 'instruction';
                    $retrievalFilters['vectorRoles'] = $currentRoles;
                }
            }
            
            $chunks = $this->retriever->knowledgeChunks($orgId, $userId, $prompt, $classification['intent'], $retrievalLimit, $retrievalFilters);
            
            // Optional enrichment
            if (($req->retrievalPolicy['useEnrichment'] ?? false) && !empty($chunks)) {
                $enrichmentChunks = $this->retriever->enrichForChunks($chunks, 10);
            }
        }
        $useBusinessFacts = ($req->retrievalPolicy['useBusinessFacts'] !== null)
            ? (bool) $req->retrievalPolicy['useBusinessFacts']
            : (in_array($classification['funnel_stage'], ['mof','bof'], true) || ($classification['intent'] === 'persuasive'));
        $facts = $useBusinessFacts ? $this->retriever->businessFacts($orgId, $userId, 8) : [];

        $relevanceMeta = [
            'candidates' => count($chunks),
            'accepted' => count($chunks),
            'rejected' => [],
        ];
        if ($useRetrieval && !empty($chunks)) {
            $gate = $this->relevanceGate->filter($prompt, $classification, $chunks, $options);
            $chunks = (array) ($gate['accepted'] ?? $chunks);
            $relevanceMeta = (array) ($gate['meta'] ?? $relevanceMeta);
        }
        $options['relevance_gate'] = $relevanceMeta;
        $options['knowledge_gate'] = $relevanceMeta;

        [$chunks, $contextBreakdown] = $this->applyChunkKindPolicy($chunks);
        $options['context_breakdown'] = $contextBreakdown;
        $options['knowledge_context_breakdown'] = $contextBreakdown;
        $options['knowledge_disabled_count'] = 0;
        $options['knowledge_user_overrides_applied'] = !empty($vipChunks);
        try { $this->cgLogger->capture('retrieval', [
            'use_retrieval' => $useRetrieval,
            'retrieval_limit' => $retrievalLimit,
            'chunks' => $chunks,
            'enrichment_chunks' => $enrichmentChunks,
            'use_business_facts' => $useBusinessFacts,
            'facts' => $facts,
            'retrieval_policy' => [
                'vectorRoles' => $retrievalFilters['vectorRoles'] ?? null,
                'useEnrichment' => $req->retrievalPolicy['useEnrichment'] ?? false,
            ],
            'relevance_gate' => $relevanceMeta,
            'context_breakdown' => $contextBreakdown,
        ]);} catch (\Throwable) {}

        // 2.5) Business Profile Integration (VIP, never pruned)
        $bp = [ 'snapshot' => [], 'version' => null, 'used' => false, 'retrieval_level' => 'shallow', 'context' => '' ];
        $bpRes = $this->bpResolver->resolveForOrg($orgId, $businessContext, $options);
        $businessContext = (string) ($bpRes['businessContext'] ?? $businessContext);
        $emojiPolicy = GenerationPolicy::resolveEmojiPolicy(
            array_key_exists('emoji', $options) ? (string) $options['emoji'] : null,
            $bpRes['emojiPolicyDerived'] ?? null,
            $emojiPolicy
        );
        $options['emoji'] = $emojiPolicy;
        $bp['snapshot'] = (array) ($bpRes['snapshot'] ?? []);
        $bp['version'] = (string) ($bpRes['version'] ?? '');
        $bp['used'] = (bool) ($bpRes['used'] ?? false);
        $bp['retrieval_level'] = (string) ($bpRes['retrieval_level'] ?? 'shallow');
        try { $this->cgLogger->capture('business_profile', [
            'bp_resolve' => $bpRes,
            'business_context' => $businessContext,
            'emoji_policy' => $emojiPolicy,
        ]);} catch (\Throwable) {}
        // Resolve template deterministically; never continue without a structure
        [$templateResolved, $tplRefIds, $resolverDebug] = $this->templateService->resolveFinal(
            $orgId,
            $platform,
            $classification['intent'],
            $classification['funnel_stage'],
            $overrideTemplateId,
            $templateOverrideOpt,
            $decisionTrace
        );
        if (!empty($resolverDebug['fallback_used'])) {
            try {
                Log::warning('content.generator.template_fallback_used', [
                    'run_id' => $runId,
                    'org_id' => $orgId,
                    'intent' => $classification['intent'] ?? null,
                    'funnel_stage' => $classification['funnel_stage'] ?? null,
                    'platform' => $platform,
                ]);
            } catch (\Throwable) {}
        }
        $referenceIds = array_values(array_unique(array_merge($referenceIds, $tplRefIds)));
        $structureSignature = $templateResolved?->template_data['structure'] ?? null;
        $swipeDiagnostics = ['scores' => [], 'rejected' => []];
        $swipes = [];
        try { $this->cgLogger->capture('template_resolution', [
            'resolver_debug' => $resolverDebug,
            'template_resolved' => $templateResolved,
            'template_reference_ids' => $tplRefIds,
        ]);} catch (\Throwable) {}
        if ($swipeMode === 'none') {
            $swipes = [];
        } elseif ($swipeMode === 'strict') {
            // Only use provided swipe_ids; if invalid/empty -> fallback to none (no error)
            $ids = array_values(array_filter(array_map(fn($v) => (string) $v, $swipeIds)));
            if (!empty($ids)) {
                try {
                    $swipes = \App\Models\SwipeStructure::query()
                        ->whereHas('swipeItem', fn($q) => $q->where('organization_id', $orgId))
                        ->whereIn('id', $ids)
                        ->orderByDesc('confidence')
                        ->get(['id','intent','funnel_stage','cta_type','structure','confidence'])
                        ->toArray();
                    $swipes = array_map(function ($s) {
                        if (!is_array($s)) return $s;
                        $s['structure_resolution'] = $s['structure_resolution'] ?? 'user_selected';
                        return $s;
                    }, $swipes);
                } catch (\Throwable) { $swipes = []; }
            } else {
                $swipes = [];
            }
        } else { // auto
            $sig = is_array($structureSignature) ? $structureSignature : null;
            $bundle = $this->retriever->swipeStructures($orgId, $classification['intent'], $classification['funnel_stage'], $platform, $prompt, 1, $sig);
            $swipes = (array) ($bundle['selected'] ?? []);
            $swipeDiagnostics['scores'] = (array) ($bundle['scores'] ?? []);
            $swipeDiagnostics['rejected'] = (array) ($bundle['rejected'] ?? []);
            if (!empty($bundle['ephemeral_meta'])) {
                $swipeDiagnostics['ephemeral_meta'] = (array) $bundle['ephemeral_meta'];
            }
        }
        if (!empty($vipSwipes)) {
            $swipes = GenerationPolicy::resolveSwipeList($swipeMode, $swipes, $vipSwipes, $swipeIds);
        }

        // Telemetry: record deterministic matching and any fallback generation model
        try {
            $llmStages->record(\App\Enums\LlmStage::STRUCTURE_MATCH, 'deterministic');
            $firstSwipe = $swipes[0] ?? null;
            $sr = is_array($firstSwipe) ? (string) ($firstSwipe['structure_resolution'] ?? '') : '';
            if ($sr === 'ephemeral_fallback') {
                $m = trim((string) (($swipeDiagnostics['ephemeral_meta']['model'] ?? '') ?: ''));
                if ($m !== '') {
                    $llmStages->record(\App\Enums\LlmStage::STRUCTURE_FALLBACK_GENERATE, $m);
                }
            }
        } catch (\Throwable) {}
        try { $this->cgLogger->capture('swipes_resolved', [
            'swipe_mode' => $swipeMode,
            'swipe_ids' => $swipeIds,
            'swipes' => $swipes,
            'swipe_diagnostics' => $swipeDiagnostics,
            'vip_swipes' => $vipSwipes,
        ]);} catch (\Throwable) {}

        // 3) Select template and voice
        $template = $templateResolved;
        $voice = null;
        $voiceSource = 'none';
        $voiceProfileUsedId = null;
        if ($voiceProfileId) {
            $voice = VoiceProfile::where('organization_id', $orgId)->where('id', $voiceProfileId)->first();
            if ($voice) {
                $voiceSource = 'override_reference';
                $voiceProfileUsedId = (string) $voice->id;
            } else {
                $voiceSource = 'none';
                $voiceProfileUsedId = null;
            }
        } elseif ($voiceInline) {
            $voice = (object) ['id' => null, 'traits' => ['description' => $voiceInline]];
            $voiceSource = 'override_reference';
            $voiceProfileUsedId = null;
        } else {
            // No auto-apply default on backend per requirements
            $voice = null;
            $voiceSource = 'none';
            $voiceProfileUsedId = null;
        }

        // 4) Assemble context (with pruning and sanitization)
        // Include classification for downstream consumers (ISA)
        $optionsWithClassification = array_merge($options, ['classification' => $classification]);
        $ciAttempted = ($ciPolicy['mode'] ?? 'auto') !== 'none';
        $ciApplied = (bool) $ci;
        $ciMutationReason = null;
        if (!$ciAttempted) {
            $ciMutationReason = 'ci_disabled';
        } elseif (!$ciApplied) {
            $ciMutationReason = 'ci_unavailable';
        } elseif (empty($ci->recommendations)) {
            $ciMutationReason = (string) ($ci->debug['skip'] ?? 'no_recommendations');
        } else {
            $ciMutationReason = 'recommendations_available';
        }
        $templateMutationReason = !empty($resolverDebug['fallback_used'])
            ? 'fallback_template_used'
            : (($overrideTemplateId || $templateOverrideOpt) ? 'override_selected' : 'selector_match');
        $promptMutations = [
            'creative_intelligence' => [
                'attempted' => $ciAttempted,
                'applied' => $ciApplied,
                'reason' => $ciMutationReason,
            ],
            'template' => [
                'attempted' => true,
                'applied' => (bool) $templateResolved,
                'reason' => $templateMutationReason,
            ],
        ];
        $context = $this->contextFactory->fromParts([
            'voice' => $voice,
            'template' => $template,
            'chunks' => $chunks,
            'enrichment_chunks' => $enrichmentChunks,
            'facts' => $facts,
            'swipes' => $swipes,
            // VIP-first injection
            'vip_chunks' => $vipChunks,
            'vip_facts' => $vipFacts,
            'vip_swipes' => [],
            'user_context' => $userContext,
            'business_context' => $businessContext,
            'business_summary' => $bpRes['businessSummary'] ?? null,
            'options' => $optionsWithClassification,
            'creative_intelligence' => $ci ? $ci->toArray() : null,
            'reference_ids' => $referenceIds,
            'decision_trace' => $decisionTrace->all(),
            'prompt_mutations' => $promptMutations,
            'ci_rejections' => $ciRejections,
        ]);
        try {
            $this->cgLogger->capture('context_assembled', [
                'context_snapshot_ids' => $context->snapshotIds(),
                'context_debug' => $context->debug(),
                'voice' => $voice,
                'voice_source' => $voiceSource,
                'voice_profile_used_id' => $voiceProfileUsedId,
            ]);
        } catch (\Throwable) {}
        try {
            $this->cgLogger->captureDecisionTrace($decisionTrace->all(), $promptMutations, $ciRejections);
        } catch (\Throwable) {}

        $ciHookApplied = (bool) ($ci && $signals && !$signals->hookProvided && !empty($ci->recommendations['hooks'] ?? []));
        $ciEmotionApplied = (bool) ($ci && $signals && !$signals->emotionProvided && !empty($ci->resolved['emotional_target']['primary'] ?? null));
        $ciAudienceApplied = (bool) ($ci && $signals && !$signals->audienceProvided && !empty($ci->resolved['audience_persona'] ?? null));
        $ciUsed = (bool) ($ciHookApplied || $ciEmotionApplied || $ciAudienceApplied);

        $ciStrict = $this->evaluateCiStrict($ciPolicy, $ci?->toArray());
        if ($ci && is_array($ciStrict['missing'] ?? null)) {
            $ci->debug = array_merge((array) $ci->debug, [
                'ci_used' => $ciUsed,
                'ci_hook_applied' => $ciHookApplied,
                'ci_emotion_applied' => $ciEmotionApplied,
                'ci_audience_applied' => $ciAudienceApplied,
                'strict_ok' => (bool) ($ciStrict['ok'] ?? false),
                'strict_missing' => (array) ($ciStrict['missing'] ?? []),
            ]);
        }

        if (($ciPolicy['mode'] ?? 'auto') === 'strict' && !$ciStrict['ok']) {
            $draft = 'Cannot generate: creative intelligence strict mode requirements not met.';
            $validation = [
                'ok' => false,
                'issues' => ['ci_strict_failed'],
                'metrics' => [
                    'char_count' => mb_strlen($draft),
                    'target_max' => $maxChars,
                    'emoji_count' => 0,
                    'paragraphs' => max(1, substr_count($draft, "\n\n") + 1),
                ],
            ];
            $snapshot = $context->snapshotIds();
            try {
                $usage = (array) $context->calculateTokenUsage();
                $optionsForSnapshot = array_merge($options, [
                    'run_id' => $runId,
                    'folder_ids' => $options['folder_ids'] ?? [],
                    'voice_source' => $voiceSource,
                    'voice_profile_id' => $voiceProfileUsedId,
                    'swipe_mode' => $swipeMode,
                    'swipe_ids' => array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $swipes))),
                    'swipe_scores' => $swipeDiagnostics['scores'] ?? [],
                    'swipe_rejected' => $swipeDiagnostics['rejected'] ?? [],
                    'token_usage' => $usage,
                    'ci_policy' => $ciPolicy,
                    'ci_used' => $ciUsed,
                    'ci_mode' => $ciPolicy['mode'] ?? 'auto',
                    'ci_hook_applied' => $ciHookApplied,
                    'ci_emotion_applied' => $ciEmotionApplied,
                    'ci_audience_applied' => $ciAudienceApplied,
                    'ci_strict_failed' => true,
                    'ci_strict_missing' => (array) ($ciStrict['missing'] ?? []),
                ]);
                $this->snapshotPersister->persistGeneration(
                    orgId: $orgId,
                    userId: $userId,
                    platform: $platform,
                    prompt: $prompt,
                    classification: $classification,
                    context: $context,
                    options: $optionsForSnapshot,
                    content: $draft,
                    generatedPostId: (string) ($options['generated_post_id'] ?? ''),
                    conversationId: !empty($options['conversation_id']) ? (string) $options['conversation_id'] : null,
                    conversationMessageId: !empty($options['conversation_message_id']) ? (string) $options['conversation_message_id'] : null,
                    llmStages: $llmStages->all(),
                );
            } catch (\Throwable) {}

            try { $this->cgLogger->flush('run_end', ['result' => [
                'content_len' => mb_strlen($draft),
                'validation_ok' => false,
                'context_used' => $snapshot,
                'metadata' => [
                    'intent' => $classification['intent'] ?? null,
                    'funnel_stage' => $classification['funnel_stage'] ?? null,
                    'template_id' => $snapshot['template_id'] ?? null,
                    'run_id' => $runId,
                ],
            ]]); } catch (\Throwable) {}

            return [
                'content' => $draft,
                'context_used' => $snapshot,
                'validation_result' => false,
                'validation' => $validation,
                'metadata' => [
                    'intent' => $classification['intent'] ?? null,
                    'funnel_stage' => $classification['funnel_stage'] ?? null,
                    'template_id' => $snapshot['template_id'] ?? null,
                    'run_id' => $runId,
                ],
            ];
        }

        // 4.5) Minimum viable context check (fail fast)
        $hasTemplate = (bool) $template;
        $hasKnowledge = !empty($context->chunks);
        $hasFacts = !empty($context->facts);
        $hasUser = trim((string) ($context->user_context ?? '')) !== '';
        $minContextOk = $hasTemplate && ($hasKnowledge || $hasFacts || $hasUser);

        if (!$minContextOk) {
            $draft = 'Cannot generate meaningful content — missing knowledge sources';
            $validation = [
                'ok' => false,
                'issues' => ['missing_context'],
                'metrics' => [
                    'char_count' => mb_strlen($draft),
                    'target_max' => $maxChars,
                    'emoji_count' => 0,
                    'paragraphs' => max(1, substr_count($draft, "\n\n") + 1),
                ],
            ];
            $snapshot = $context->snapshotIds();

            // Persist snapshot with failure message for traceability
            try {
                $usage = (array) $context->calculateTokenUsage();
                $optionsForSnapshot = array_merge($options, [
                    'run_id' => $runId,
                    'folder_ids' => $options['folder_ids'] ?? [],
                    // include voice diagnostics even on failure for traceability
                    'voice_source' => $voiceSource,
                    'voice_profile_id' => $voiceProfileUsedId,
                    'swipe_mode' => $swipeMode,
                    'swipe_ids' => array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $swipes))),
                    'swipe_scores' => [],
                    'swipe_rejected' => [],
                    'token_usage' => $usage,
                    'ci_policy' => $ciPolicy,
                    'ci_used' => $ciUsed,
                    'ci_mode' => $ciPolicy['mode'] ?? 'auto',
                    'ci_hook_applied' => $ciHookApplied,
                    'ci_emotion_applied' => $ciEmotionApplied,
                    'ci_audience_applied' => $ciAudienceApplied,
                ]);
                $this->snapshotPersister->persistGeneration(
                    orgId: $orgId,
                    userId: $userId,
                    platform: $platform,
                    prompt: $prompt,
                    classification: $classification,
                    context: $context,
                    options: $optionsForSnapshot,
                    content: $draft,
                    generatedPostId: (string) ($options['generated_post_id'] ?? ''),
                    conversationId: !empty($options['conversation_id']) ? (string) $options['conversation_id'] : null,
                    conversationMessageId: !empty($options['conversation_message_id']) ? (string) $options['conversation_message_id'] : null,
                    llmStages: $llmStages->all(),
                );
            } catch (\Throwable) {}

            try { $this->cgLogger->flush('run_end', ['result' => [
                'content_len' => mb_strlen($draft),
                'validation_ok' => false,
                'context_used' => $snapshot,
                'metadata' => [
                    'intent' => $classification['intent'] ?? null,
                    'funnel_stage' => $classification['funnel_stage'] ?? null,
                    'template_id' => $snapshot['template_id'] ?? null,
                    'run_id' => $runId,
                ],
            ]]); } catch (\Throwable) {}

            return [
                'content' => $draft,
                'context_used' => $snapshot,
                'validation_result' => false,
                'validation' => $validation,
                'metadata' => [
                    'intent' => $classification['intent'] ?? null,
                    'funnel_stage' => $classification['funnel_stage'] ?? null,
                    'template_id' => $snapshot['template_id'] ?? null,
                    'run_id' => $runId,
                ],
            ];
        }

        // Early exit: prompt-only mode uses isolated builder and returns without executing
        if (isset($options['mode']) && (string) $options['mode'] === 'prompt_only') {
            $built = $this->buildPrompt($orgId, $userId, $prompt, $platform, array_merge($options, [
                '_ci' => $ci,
            ]));
            return [
                'system' => $built->system,
                'user' => $built->user,
                'meta' => $built->meta,
                'context_summary' => $built->contextSummary,
            ];
        }

        // 5) Compose prompts from Context and run via unified runner
        $constraints = new Constraints($maxChars, $emojiPolicy, $tone);
        $promptObj = $this->composer->composePostGeneration($context, $constraints, $prompt, $signals);
        try { $this->cgLogger->capture('prompt_composed', [
            'constraints' => [ 'max_chars' => $maxChars, 'emoji' => $emojiPolicy, 'tone' => $tone ],
            'prompt_system' => $promptObj->system ?? null,
            'prompt_user' => $promptObj->user ?? null,
            'schema' => $promptObj->schemaName ?? null,
            'llm_params' => $promptObj->llmParams ?? [],
        ]);} catch (\Throwable) {}
        try {
            Log::info('content.generator.call_debug', [
                'run_id' => $runId,
                'platform' => $platform,
                'intent' => $classification['intent'] ?? null,
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'template_id' => $template?->id ?? null,
                'system_len' => mb_strlen($promptObj->system ?? ''),
                'user_len' => mb_strlen($promptObj->user ?? ''),
                'chunks' => count((array) $context->chunks),
                'facts' => count((array) $context->facts),
                'swipes' => count((array) $context->swipes),
            ]);
        } catch (\Throwable) {}
        // Use meta-preserving call for token/latency/model metrics
        $gen = $this->runner->runJsonContentCallWithMeta('generate', $promptObj);
        $draft = (string) ($gen['content'] ?? '');
        $modelUsedGenerate = trim((string) (($gen['meta']['model'] ?? '') ?: ''));
        if ($modelUsedGenerate !== '') {
            $llmStages->record(LlmStage::GENERATE, $modelUsedGenerate);
        }
        try { $this->cgLogger->capture('llm_result', ['raw' => $gen, 'draft_preview' => mb_substr($draft, 0, 200)]);} catch (\Throwable) {}
        try { Log::info('content.generator.call_result', ['run_id' => $runId, 'content_len' => mb_strlen($draft)]); } catch (\Throwable) {}

        // Handle empty or meta non-draft responses up front
        if (trim($draft) === '' || $this->looksLikeMetaNoDraft($draft)) {
            Log::warning('content.generator.empty_or_meta', [
                'stage' => 'generate_primary',
                'reason' => trim($draft) === '' ? 'empty' : 'meta_no_draft_detected',
            ]);
            // Attempt a strict regeneration using the same context
            [$draft, $regenMeta] = $this->regenerateFromContext($promptObj->system, $promptObj->user, $options, $llmStages);
            Log::info('content.generator.regenerate_attempt', $regenMeta);
            try { $this->cgLogger->capture('regenerate_attempt', ['meta' => $regenMeta, 'draft_preview' => mb_substr($draft, 0, 200)]);} catch (\Throwable) {}
        }

        if ($draft === '') {
            Log::warning('content.generator.empty_output', [
                'stage' => 'generate',
                'org_id' => $orgId,
                'user_id' => $userId,
                'platform' => $platform,
                'intent' => $classification['intent'] ?? null,
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'system_len' => mb_strlen($promptObj->system),
                'user_len' => mb_strlen($promptObj->user),
                'model' => (string) config('services.openrouter.chat_model'),
                'run_id' => $options['run_id'] ?? null,
            ]);
        }

        // 5.5) Optional Critic -> Refiner loop (Reflexion pattern)
        $enableReflexion = (bool) ($options['enable_reflexion'] ?? false);
        if ($enableReflexion && trim($draft) !== '' && !$this->looksLikeMetaNoDraft($draft)) {
            try {
                $critique = $this->reflexion->critique($draft, $prompt, $context, $llmStages);
                $score = (float) ($critique['score'] ?? 0);
                $this->cgLogger->capture('reflexion_critique', [
                    'score' => $score,
                    'critique' => $critique,
                ]);
                if ($score < 9.0) {
                    $refined = $this->reflexion->refine($draft, $critique, $prompt, $context, $llmStages);
                    if (is_string($refined) && trim($refined) !== '') {
                        $draft = $refined;
                        $this->cgLogger->capture('reflexion_refine_applied', [
                            'new_len' => mb_strlen($draft),
                        ]);
                    } else {
                        $this->cgLogger->capture('reflexion_refine_skipped', ['reason' => 'empty_refine_output']);
                    }
                } else {
                    $this->cgLogger->capture('reflexion_refine_skipped', ['reason' => 'score_threshold_met']);
                }
            } catch (\Throwable $e) {
                try { $this->cgLogger->capture('reflexion_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
            }
        }

        // 6) Validate + one repair attempt (centralized) with Circuit Breaker for empty drafts
        // If the draft is empty at this stage, skip validateAndRepair and attempt regeneration immediately.
        if (trim($draft) === '') {
            try { Log::warning('content.generator.empty_draft_detected', ['stage' => 'pre_validation']); } catch (\Throwable) {}
            [$draft, $regenMeta] = $this->regenerateFromContext($promptObj->system, $promptObj->user, $options, $llmStages);
            try { $this->cgLogger->capture('circuit_regenerate_attempt', ['meta' => $regenMeta, 'draft_preview' => mb_substr($draft, 0, 200)]);} catch (\Throwable) {}

            if (trim($draft) !== '') {
                // Validate regenerated content directly (no repair of an empty input)
                $validation = $this->validator->checkPost($draft, $context);
                $repairInfo = [];
            } else {
                // Still empty: mark validation as failed; later emergency compose may kick in
                $validation = ['ok' => false, 'issues' => ['generation_failed']];
                $repairInfo = [];
            }
        } else {
            // Normal flow: existing draft goes through validateAndRepair
            $res = $this->validatorRepair->validateAndRepair($draft, $context, $constraints, $llmStages);
            $draft = $res['content'];
            $validation = $res['validation'];
            $repairInfo = (array) ($res['repair'] ?? []);
            try { $this->cgLogger->capture('validate_and_repair', $res);} catch (\Throwable) {}
        }

        // If repair produced a meta/non-draft message or empty text, make one last regeneration attempt
        if (trim($draft) === '' || $this->looksLikeMetaNoDraft($draft)) {
            Log::warning('content.generator.post_repair_empty_or_meta', [
                'stage' => 'generate_post_repair',
                'reason' => trim($draft) === '' ? 'empty' : 'meta_no_draft_detected',
            ]);
                [$draft2] = $this->regenerateFromContext($promptObj->system, $promptObj->user, $options, $llmStages);
            if (trim($draft2) !== '') {
                $draft = $draft2;
                $validation = $this->validator->checkPost($draft, $context);
            }
            try { $this->cgLogger->capture('post_repair_regenerate_attempt', ['draft_preview' => mb_substr($draft, 0, 200)]);} catch (\Throwable) {}
        }

        // Absolute last resort: synthesize a minimal post only if we have viable context
        if ($minContextOk && trim($draft) === '') {
            try {
                Log::warning('content.generator.emergency_compose_trigger', [
                    'org_id' => $orgId,
                    'user_id' => $userId,
                    'platform' => $platform,
                    'template_id' => $context->template?->id ?? null,
                    'chunks' => count((array) $context->chunks),
                    'facts' => count((array) $context->facts),
                    'swipes' => count((array) $context->swipes),
                ]);
            } catch (\Throwable) {}
            $draft = $this->emergencyCompose($context, $prompt, $constraints);
            $validation = $this->validator->checkPost($draft, $context);
        }

        $snapshot = $context->snapshotIds();
        // 7) Persist replayable snapshot and quality metrics
        $snapshotId = null;
        try {
            // Enrich options with diagnostics per spec for snapshot/replay
            $usage = (array) $context->calculateTokenUsage();
            $optionsForSnapshot = array_merge($options, [
                'run_id' => $runId,
                'classification_overridden' => $classificationOverridden,
                'classification_original' => $classificationOriginal,
                'folder_ids' => $options['folder_ids'] ?? [],
                'voice_source' => $voiceSource,
                'voice_profile_id' => $voiceProfileUsedId,
                'retrieval_enabled' => $useRetrieval,
                'business_facts_enabled' => $useBusinessFacts,
                'business_context' => $businessContext,
                'business_profile_snapshot' => (array) ($bp['snapshot'] ?? []),
                'business_profile_used' => (bool) ($bp['used'] ?? false),
                'business_profile_version' => (string) ($bp['version'] ?? ''),
                'business_retrieval_level' => (string) ($bp['retrieval_level'] ?? 'shallow'),
                'swipe_mode' => $swipeMode,
                'swipe_ids' => array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $swipes))),
                'swipe_scores' => $swipeDiagnostics['scores'] ?? [],
                'swipe_rejected' => $swipeDiagnostics['rejected'] ?? [],
                'token_usage' => $usage,
                'ci_policy' => $ciPolicy,
                'ci_used' => $ciUsed,
                'ci_mode' => $ciPolicy['mode'] ?? 'auto',
                'ci_hook_applied' => $ciHookApplied,
                'ci_emotion_applied' => $ciEmotionApplied,
                'ci_audience_applied' => $ciAudienceApplied,
                // Template resolver debug
                'template_selected' => (bool) ($resolverDebug['template_selected'] ?? false),
                'template_id' => (string) (($resolverDebug['template_id'] ?? '') ?: ($template?->id ?? '')),
                'template_candidates' => (array) ($resolverDebug['template_candidates'] ?? ['count' => 0, 'ids' => []]),
                'fallback_used' => (bool) ($resolverDebug['fallback_used'] ?? false),
                'resolver_score_debug' => (array) ($resolverDebug['resolver_score_debug'] ?? []),
                'template_resolution_failed' => (bool) ($resolverDebug['template_resolution_failed'] ?? false),
                'fallback_template_used' => (bool) ($resolverDebug['fallback_template_used'] ?? false),
                'fallback_template_id' => (string) ($resolverDebug['fallback_template_id'] ?? ''),
            ]);
            // Build high‑fidelity auditing metrics
            $modelId = (string) ($gen['meta']['model'] ?? config('services.openrouter.chat_model'));
            $providerLatency = (int) ($gen['latency_ms'] ?? 0);
            $totalLatency = (int) round((microtime(true) - $t0) * 1000);
            $usageMeta = (array) ($gen['meta']['usage'] ?? []);
            $promptTokens = (int) ($usageMeta['prompt_tokens'] ?? 0);
            $completionTokens = (int) ($usageMeta['completion_tokens'] ?? 0);
            $totalTokens = $promptTokens + $completionTokens;
            $estimatedCost = $this->estimateCost($promptTokens, $completionTokens, $modelId);

            $tokenMetrics = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost' => $estimatedCost,
            ];
            $performanceMetrics = [
                'total_latency_ms' => $totalLatency,
                'provider_latency_ms' => $providerLatency,
                'model_identifier' => $modelId,
            ];
            if (empty($repairInfo)) {
                $repairInfo = [
                    'repair_count' => 0,
                    'repair_types' => [],
                    'initial_validation_score' => null,
                    'repair_log' => '',
                ];
            }

            $snapshotId = $this->snapshotPersister->persistGeneration(
                orgId: $orgId,
                userId: $userId,
                platform: $platform,
                prompt: $prompt,
                classification: $classification,
                context: $context,
                options: $optionsForSnapshot,
                content: $draft,
                finalSystemPrompt: (string) ($promptObj->system ?? ''),
                finalUserPrompt: (string) ($promptObj->user ?? ''),
                tokenUsage: $tokenMetrics,
                performance: $performanceMetrics,
                repairInfo: $repairInfo,
                generatedPostId: (string) ($options['generated_post_id'] ?? ''),
                conversationId: !empty($options['conversation_id']) ? (string) $options['conversation_id'] : null,
                conversationMessageId: !empty($options['conversation_message_id']) ? (string) $options['conversation_message_id'] : null,
                llmStages: $llmStages->all(),
            );
            try { $this->cgLogger->capture('snapshot_persisted', [
                'snapshot_id' => $snapshotId,
                'intent' => (string) ($classification['intent'] ?? ''),
                'mode' => [
                    'type' => (string) ($optionsForSnapshot['mode'] ?? 'generate'),
                    'subtype' => (string) ($optionsForSnapshot['research_stage'] ?? ''),
                ],
                'options' => $optionsForSnapshot,
            ]); } catch (\Throwable) {}
            $this->snapshotPersister->persistQuality(
                orgId: $orgId,
                userId: $userId,
                classification: $classification,
                content: $draft,
                context: $context,
                options: $options,
                snapshotId: $snapshotId,
                generatedPostId: (string) ($options['generated_post_id'] ?? ''),
            );
            try { $this->cgLogger->capture('quality_persisted', ['snapshot_id' => $snapshotId]); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            Log::warning('content.generator.post_eval_failed', ['error' => $e->getMessage()]);
        }
        try { $this->cgLogger->flush('run_end', ['result' => [
            'content_len' => mb_strlen($draft),
            'validation_ok' => (bool) ($validation['ok'] ?? false),
            'context_used' => $snapshot,
            'metadata' => [
                'intent' => $classification['intent'] ?? null,
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'template_id' => $snapshot['template_id'] ?? null,
                'run_id' => $runId,
            ],
        ]]); } catch (\Throwable) {}
        return [
            'content' => $draft,
            'context_used' => $snapshot,
            'validation_result' => (bool) ($validation['ok'] ?? false),
            'validation' => $validation,
            'metadata' => [
                'intent' => $classification['intent'] ?? null,
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'template_id' => $snapshot['template_id'] ?? null,
                'run_id' => $runId,
                'snapshot_id' => $snapshotId,
            ],
        ];
    }

    /**
     * Build prompts only (no LLM calls, no persistence). Side-effect free.
     */
    public function buildPrompt(
        string $orgId,
        string $userId,
        string $prompt,
        string $platform,
        array $options = []
    ): PromptBuildResult {
        // Reuse GenerationRequest for option normalization
        $req = new GenerationRequest($orgId, $userId, $prompt, $platform, $options);
        $prompt = $req->prompt;
        // Canonicalize folder-scoped retrieval boundary (knowledge retrieval only)
        $options['folder_ids'] = $req->folderIds;

        // Resolve explicit overrides (VIP)
        $overrides = is_array($options['overrides'] ?? null) ? (array) $options['overrides'] : [];
        $vipChunks = $this->overrideResolver->resolveVipKnowledge((array) ($overrides['knowledge'] ?? []))['vipChunks'];
        $vipFacts = $this->overrideResolver->resolveVipFacts($orgId, (array) ($overrides['facts'] ?? []))['vipFacts'];
        $vipSwipes = $this->overrideResolver->resolveVipSwipes($orgId, (array) ($overrides['swipes'] ?? []))['vipSwipes'];

        // Classification with overrides
        $intentOverride = (string) ($options['intent'] ?? '');
        $funnelOverride = (string) ($options['funnel_stage'] ?? '');
        $classificationOriginal = null;
        $classification = ['intent' => null, 'funnel_stage' => null];
        if ($intentOverride && $funnelOverride) {
            $classification = ['intent' => $intentOverride, 'funnel_stage' => $funnelOverride];
        } else {
            $classificationOriginal = $this->classifier->classify($prompt, $this->stageModelOptions($options, 'classify'));
            $classification['intent'] = $intentOverride ?: ($classificationOriginal['intent'] ?? 'educational');
            $classification['funnel_stage'] = $funnelOverride ?: ($classificationOriginal['funnel_stage'] ?? 'tof');
        }

        // Prompt signal extraction + CI recommendation
        $ciPolicy = (array) $req->ciPolicy;
        $ci = null;
        if (array_key_exists('_ci', $options) && $options['_ci'] !== null) {
            $ci = $options['_ci'];
        } elseif (($ciPolicy['mode'] ?? 'auto') !== 'none') {
            try {
                $signals = $this->promptSignalExtractor->extract($prompt, $platform, $options);
                $ci = $this->ciRecommender->recommend(
                    $orgId,
                    $userId,
                    $prompt,
                    $platform,
                    $classification,
                    $signals ?? new \App\Services\Ai\Generation\DTO\PromptSignals(),
                    $ciPolicy
                );
            } catch (\Throwable) {
                $ci = null;
            }
        }

        // Retrieval
        $retrievalLimit = (int) $req->retrievalPolicy['retrievalLimit'];
        $retrievalFilters = is_array($options['retrieval_filters'] ?? null) ? (array) $options['retrieval_filters'] : [];
        $chunks = [];
        $enrichmentChunks = [];
        if ((bool) ($req->retrievalPolicy['useRetrieval'] ?? true)) {
            if (!empty($req->folderIds)) {
                $retrievalFilters['folder_ids'] = $req->folderIds;
            }
            // Apply retrieval policy overrides
            if ($req->retrievalPolicy['vectorRoles'] !== null) {
                $retrievalFilters['vectorRoles'] = $req->retrievalPolicy['vectorRoles'];
            }
            // Include instructions if requested
            if ($req->retrievalPolicy['includeInstructions'] === true) {
                $currentRoles = (array) ($retrievalFilters['vectorRoles'] ?? config('ai_chunk_roles.vector_searchable_roles', []));
                if (!in_array('instruction', $currentRoles, true)) {
                    $currentRoles[] = 'instruction';
                    $retrievalFilters['vectorRoles'] = $currentRoles;
                }
            }
            
            $chunks = $this->retriever->knowledgeChunks($orgId, $userId, $prompt, $classification['intent'], $retrievalLimit, $retrievalFilters);
            
            // Optional enrichment
            if (($req->retrievalPolicy['useEnrichment'] ?? false) && !empty($chunks)) {
                $enrichmentChunks = $this->retriever->enrichForChunks($chunks, 10);
            }
        }
        $useBusinessFacts = ($req->retrievalPolicy['useBusinessFacts'] !== null)
            ? (bool) $req->retrievalPolicy['useBusinessFacts']
            : (in_array($classification['funnel_stage'], ['mof','bof'], true) || ($classification['intent'] === 'persuasive'));
        $facts = $useBusinessFacts ? $this->retriever->businessFacts($orgId, $userId, 8) : [];

        // Business profile integration
        $businessContext = (string) ($options['business_context'] ?? '');
        $emojiPolicy = (string) ($options['emoji'] ?? 'disallow');
        $bpRes = $this->bpResolver->resolveForOrg($orgId, $businessContext, $options);
        $businessContext = (string) ($bpRes['businessContext'] ?? $businessContext);
        $emojiPolicy = GenerationPolicy::resolveEmojiPolicy(
            array_key_exists('emoji', $options) ? (string) $options['emoji'] : null,
            $bpRes['emojiPolicyDerived'] ?? null,
            $emojiPolicy
        );

        // Template resolution and swipes
        $overrideTemplateId = $overrides['template_id'] ?? ($options['template_id'] ?? null);
        [$templateResolved, $tplRefIds, $resolverDebug] = $this->templateService->resolveFinal(
            $orgId,
            $platform,
            $classification['intent'],
            $classification['funnel_stage'],
            $overrideTemplateId,
            $options['template_override'] ?? null
        );
        $structureSignature = $templateResolved?->template_data['structure'] ?? null;
        $swipeMode = (string) ($options['swipe_mode'] ?? 'auto');
        $swipeIds = (array) ($options['swipe_ids'] ?? []);
        if (!empty($vipSwipes)) {
            // Will be reconciled after auto selection
        }
        if ($swipeMode === 'none') {
            $swipes = [];
        } elseif ($swipeMode === 'strict') {
            $ids = array_values(array_filter(array_map(fn($v) => (string) $v, $swipeIds)));
            if (!empty($ids)) {
                try {
                    $swipes = \App\Models\SwipeStructure::query()
                        ->whereHas('swipeItem', fn($q) => $q->where('organization_id', $orgId))
                        ->whereIn('id', $ids)
                        ->orderByDesc('confidence')
                        ->get(['id','intent','funnel_stage','cta_type','structure','confidence'])
                        ->toArray();
                    $swipes = array_map(function ($s) {
                        if (!is_array($s)) return $s;
                        $s['structure_resolution'] = $s['structure_resolution'] ?? 'user_selected';
                        return $s;
                    }, $swipes);
                } catch (\Throwable) { $swipes = []; }
            } else { $swipes = []; }
        } else {
            $sig = is_array($structureSignature) ? $structureSignature : null;
            $bundle = $this->retriever->swipeStructures($orgId, $classification['intent'], $classification['funnel_stage'], $platform, $prompt, 1, $sig);
            $swipes = (array) ($bundle['selected'] ?? []);
        }
        if (!empty($vipSwipes)) {
            $swipes = GenerationPolicy::resolveSwipeList($swipeMode, $swipes, $vipSwipes, $swipeIds);
        }

        // Voice selection (no auto default beyond inline/override)
        $voice = null;
        $voiceProfileId = $options['voice_profile_id'] ?? null;
        $voiceInline = $options['voiceInline'] ?? ($options['voice_inline'] ?? null);
        if ($voiceProfileId) {
            $voice = \App\Models\VoiceProfile::where('organization_id', $orgId)->where('id', $voiceProfileId)->first();
        } elseif ($voiceInline) {
            $voice = (object) ['id' => null, 'traits' => ['description' => (string) $voiceInline]];
        }

        $optionsSansInternal = $options;
        if (array_key_exists('_ci', $optionsSansInternal)) {
            unset($optionsSansInternal['_ci']);
        }

        // Assemble context (include classification for ISA downstream)
        $optionsWithClassification = array_merge($optionsSansInternal, ['classification' => $classification]);
        $userContext = (string) ($options['user_context'] ?? '');
        $ciPayload = null;
        if ($ci instanceof \App\Services\Ai\Generation\DTO\CiRecommendation) {
            $ciPayload = $ci->toArray();
        } elseif (is_array($ci)) {
            $ciPayload = $ci;
        }

        $context = $this->contextFactory->fromParts([
            'voice' => $voice,
            'template' => $templateResolved,
            'chunks' => $chunks,
            'enrichment_chunks' => $enrichmentChunks,
            'facts' => $facts,
            'swipes' => $swipes,
            'vip_chunks' => $vipChunks,
            'vip_facts' => $vipFacts,
            'vip_swipes' => [],
            'user_context' => $userContext,
            'business_context' => $businessContext,
            'business_summary' => $bpRes['businessSummary'] ?? null,
            'options' => $optionsWithClassification,
            'creative_intelligence' => $ciPayload,
            'reference_ids' => array_values(array_filter(array_merge(
                array_map(fn($c) => $c['id'] ?? null, $chunks),
                array_map(fn($f) => $f['id'] ?? null, $facts)
            ))),
        ]);

        $ciStrict = $this->evaluateCiStrict($ciPolicy, $ciPayload);
        if (($ciPolicy['mode'] ?? 'auto') === 'strict' && !$ciStrict['ok']) {
            return new PromptBuildResult(
                '',
                '',
                ['error' => 'ci_strict_failed', 'ci_missing' => (array) ($ciStrict['missing'] ?? [])],
                ['ci_strict_failed' => true]
            );
        }

        // Compose prompts
        $maxChars = (int) ($options['max_chars'] ?? 1200);
        $tone = (string) ($options['tone'] ?? 'professional');
        $constraints = new \App\Services\Ai\Generation\DTO\Constraints($maxChars, $emojiPolicy, $tone);
        $promptObj = $this->composer->composePostGeneration($context, $constraints, $prompt, $signals ?? null);

        // Build meta and context summary (include ISA debug)
        $structure = is_array($templateResolved?->template_data) ? ($templateResolved->template_data['structure'] ?? []) : [];
        $factChunks = array_values(array_filter((array) $context->chunks, fn($c) => ChunkKindResolver::resolveKind($c) === 'fact'));
        $vipFactChunks = array_values(array_filter((array) $vipChunks, fn($c) => ChunkKindResolver::resolveKind($c) === 'fact'));
        $isa = $this->insightSelector->buildInsights($prompt, (string) ($classification['intent'] ?? ''), $factChunks, $vipFactChunks);
        $meta = [
            'classification' => $classification,
            'template' => [
                'id' => $templateResolved?->id ?? null,
                'structure' => $structure,
            ],
            'constraints' => [
                'max_chars' => $maxChars,
                'emoji' => $emojiPolicy,
                'tone' => $tone,
            ],
            'isa' => [
                'input_chunks' => (int) ($isa['debug']['input_chunks'] ?? 0),
                'kept' => (int) ($isa['debug']['kept'] ?? 0),
                'dropped' => (array) ($isa['debug']['dropped'] ?? []),
                'task_keywords' => (array) ($isa['debug']['task_keywords'] ?? []),
            ],
        ];
        $contextSummary = [
            'classification' => [
                'intent' => $classification['intent'] ?? 'educational',
                'funnel_stage' => $classification['funnel_stage'] ?? 'tof',
                'overridden' => (bool) ($intentOverride || $funnelOverride),
            ],
            'template' => [
                'id' => $templateResolved?->id ?? null,
                'structure' => $structure,
            ],
            'retrieval' => [
                'knowledge_chunks_used' => count((array) $context->chunks),
                'business_facts_used' => count((array) $context->facts),
                'vip_overrides' => !empty($vipChunks) || !empty($vipFacts) || !empty($vipSwipes),
            ],
            'constraints' => [
                'max_chars' => $maxChars,
                'emoji' => $emojiPolicy,
                'tone' => $tone,
            ],
        ];

        return new PromptBuildResult(
            (string) ($promptObj->system ?? ''),
            (string) ($promptObj->user ?? ''),
            $meta,
            $contextSummary,
        );
    }

    private function looksLikeMetaNoDraft(string $text): bool
    {
        $t = mb_strtolower($text);
        $patterns = [
            'i apologize',
            "i'm sorry",
            "i am sorry",
            "don't see any draft",
            'do not see any draft',
            'please provide the draft',
            'please provide draft',
            'provide the draft',
            'draft appears to be empty',
        ];
        foreach ($patterns as $p) {
            if (mb_strpos($t, $p) !== false) return true;
        }
        return false;
    }

    private function regenerateFromContext(string $system, string $user, array $options = [], ?LlmStageTracker $tracker = null): array
    {
        $system2 = $system . "\nIf no draft is provided, compose the full post from the prompt and context. Do not ask for more input. Never apologize. Return STRICT JSON only.";
        $user2 = $user . "\n\nACTION: Write the complete post now based on the above. Return JSON with field 'content'.";
        $llmOpts = [ 'temperature' => 0.2, 'json_schema_hint' => '{"content":"<final post text>"}' ];
        $llmOpts = array_merge($llmOpts, $this->stageModelOptions($options, 'generate_fallback'));
        $res = $this->llm->callWithMeta('generate_fallback', $system2, $user2, 'post_generation', $llmOpts);
        $obj = $res['data'] ?? [];
        $modelUsed = trim((string) (($res['meta']['model'] ?? '') ?: ''));
        if ($tracker && $modelUsed !== '') {
            $tracker->record(LlmStage::GENERATE_FALLBACK, $modelUsed);
        }
        $draft2 = is_array($obj) ? (string) ($obj['content'] ?? '') : (string) $obj;
        return [ $draft2, [ 'used' => 'generate_fallback', 'preview' => mb_substr($draft2, 0, 120), 'model' => $modelUsed ] ];
    }

    /**
     * Resolve per-stage model overrides.
     * Supported:
     * - $options['model'] : string (default model for all stages)
     * - $options['models'] : array{stage => model}
     */
    private function stageModelOptions(array $options, string $stage): array
    {
        try {
            $out = [];
            $models = is_array($options['models'] ?? null) ? (array) $options['models'] : [];
            $default = isset($options['model']) ? trim((string) $options['model']) : '';

            if (!empty($models)) {
                if (isset($models[$stage]) && trim((string) $models[$stage]) !== '') {
            // Config defaults (only if caller didn't specify anything)
            $cfg = '';
            switch ($stage) {
                case 'classify':
                    $cfg = (string) config('ai.models.classification', '');
                    break;
                case 'generate_fallback':
                    $cfg = (string) config('ai.models.generation.fallback', '');
                    break;
            }
            $cfg = trim($cfg);
            if ($cfg !== '') {
                $out['model'] = $cfg;
            }
                    $out['model'] = trim((string) $models[$stage]);
                    return $out;
                }
                if (isset($models['default']) && trim((string) $models['default']) !== '') {
                    $out['model'] = trim((string) $models['default']);
                    return $out;
                }
                if (isset($models['*']) && trim((string) $models['*']) !== '') {
                    $out['model'] = trim((string) $models['*']);
                    return $out;
                }
            }

            if ($default !== '') {
                $out['model'] = $default;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function evaluateCiStrict(array $ciPolicy, ?array $ci): array
    {
        $mode = (string) ($ciPolicy['mode'] ?? 'auto');
        if ($mode !== 'strict') {
            return ['ok' => true, 'missing' => []];
        }
        if (empty($ci) || !is_array($ci)) {
            return ['ok' => false, 'missing' => ['ci']];
        }

        $signals = is_array($ci['signals'] ?? null) ? $ci['signals'] : [];
        $recs = is_array($ci['recommendations'] ?? null) ? $ci['recommendations'] : [];
        $resolved = is_array($ci['resolved'] ?? null) ? $ci['resolved'] : [];

        $hasHook = (bool) ($signals['hook_provided'] ?? false) || !empty($recs['hooks'] ?? []);
        $hasAngle = !empty($recs['angles'] ?? []);
        $hasEmotion = (bool) ($signals['emotion_provided'] ?? false) || !empty($resolved['emotional_target']['primary'] ?? null);

        $missing = [];
        if (!$hasHook) { $missing[] = 'hook'; }
        if (!$hasAngle) { $missing[] = 'angle'; }
        if (!$hasEmotion) { $missing[] = 'emotion'; }

        return ['ok' => empty($missing), 'missing' => $missing];
    }

    private function emergencyCompose(object $context, string $prompt, Constraints $constraints): string
    {
        $tpl = $context->template;
        $structure = [];
        if ($tpl && is_array($tpl->template_data ?? null)) {
            $structure = (array) (($tpl->template_data['structure'] ?? []) ?: []);
        }
        if (empty($structure)) {
            $structure = [
                ['section' => 'Hook'],
                ['section' => 'Context'],
                ['section' => 'Value'],
                ['section' => 'CTA'],
            ];
        }

        $textChunks = array_values(array_filter(array_map(function ($c) {
            if (is_array($c)) return (string) ($c['chunk_text'] ?? $c['text'] ?? '');
            if (is_object($c)) return (string) ($c->chunk_text ?? $c->text ?? '');
            return '';
        }, (array) $context->chunks)));
        $facts = array_values(array_filter(array_map(function ($f) {
            if (is_array($f)) return (string) ($f['text'] ?? '');
            if (is_object($f)) return (string) ($f->text ?? '');
            return '';
        }, (array) $context->facts)));

        $hook = 'Quick insight: ' . trim(mb_substr($prompt, 0, 120));
        $ctx = trim(mb_substr(implode(' ', array_slice($textChunks, 0, 2)), 0, 280));
        $vals = array_filter(array_map(fn($t) => '- ' . trim(mb_substr($t, 0, 160)), array_slice(array_merge($facts, $textChunks), 0, 5)));
        if (empty($vals)) { $vals = ['- Key takeaway 1', '- Key takeaway 2']; }
        $cta = 'What do you think? Reply with your take.';

        $sections = [];
        foreach ($structure as $s) {
            $name = strtolower((string) ($s['section'] ?? ''));
            if ($name === 'hook') { $sections[] = $hook; }
            elseif ($name === 'context') { if ($ctx !== '') { $sections[] = $ctx; } }
            elseif ($name === 'value') { $sections[] = implode("\n", $vals); }
            elseif ($name === 'cta') { $sections[] = $cta; }
        }
        if (empty($sections)) {
            $sections = [$hook, $ctx, implode("\n", $vals), $cta];
        }
        $out = trim(implode("\n\n", array_filter($sections)));

        // Enforce constraints quickly
        if (strtolower($constraints->emojiPolicy ?? '') === 'disallow') {
            $out = $this->stripEmojis($out);
        }
        $max = (int) ($constraints->maxChars ?? 1200);
        if ($max > 0 && mb_strlen($out) > $max) {
            $out = mb_substr($out, 0, $max - 1);
        }
        try { Log::info('content.generator.emergency_compose_success', ['preview' => mb_substr($out, 0, 120)]); } catch (\Throwable) {}
        return $out !== '' ? $out : 'Here are a couple of quick insights on this topic.';
    }

    private function stripEmojis(string $text): string
    {
        return \App\Services\Ai\Generation\Steps\EmojiSanitizer::strip($text);
    }

    /**
     * Comment mode pipeline: generates engagement comments for social posts.
     * Uses voice profiles and templates for personalized, on-brand comments.
     */
    private function generateComment(GenerationRequest $req, array $options, LlmStageTracker $llmStages, float $t0): array
    {
        $runId = $req->runId;
        $orgId = $req->orgId;
        $userId = $req->userId;
        $platform = $req->platform;

        // Comment context
        $parentPost = $req->commentContext['parent_post'];
        $authorName = $req->commentContext['author_name'];
        $authorTitle = $req->commentContext['author_title'];
        $commentIntent = $req->commentContext['comment_intent'];
        $platformUrl = $req->commentContext['platform_url'];

        // Voice profile resolution
        $voiceProfileId = $req->voiceOverrides['voiceProfileId'] ?? null;
        $voice = null;
        if ($voiceProfileId) {
            $voiceProfile = VoiceProfile::find($voiceProfileId);
            if ($voiceProfile && ($voiceProfile->isCommenter() || $voiceProfile->isDesigned() || $voiceProfile->isInferred())) {
                $voice = $voiceProfile->traits ?? [];
            }
        }

        // Template selection for comments
        $template = null;
        $templateId = $req->templatePolicy['templateId'] ?? null;
        if ($templateId) {
            $template = \App\Models\Template::where('id', $templateId)
                ->where('template_type', 'comment')
                ->first();
        }
        if (!$template) {
            $template = $this->selector->select(
                $orgId,
                $commentIntent, // Use comment intent as the classification intent
                null, // No funnel stage for comments
                $platform,
                TemplateSelector::TYPE_COMMENT
            );
        }

        // Build prompt using composer
        $prompt = $this->composer->composeCommentGeneration(
            $parentPost,
            $authorName,
            $authorTitle,
            $commentIntent,
            $voice,
            $template,
            $req->constraints,
            $req->prompt // Additional user instructions
        );

        try {
            $this->cgLogger->capture('comment_prompt', [
                'parent_post_length' => strlen($parentPost),
                'author_name' => $authorName,
                'comment_intent' => $commentIntent,
                'voice_profile_id' => $voiceProfileId,
                'template_id' => $template?->id,
                'constraints' => [
                    'max_chars' => $req->constraints->maxChars,
                    'emoji' => $req->constraints->emojiPolicy,
                    'tone' => $req->constraints->tone,
                ],
            ]);
        } catch (\Throwable) {}

        // Call LLM
        $model = $options['model'] ?? config('ai.model', 'gpt-4o-mini');

        $stageT0 = microtime(true);
        $raw = $this->llm->call(
            'comment_generation',
            $prompt->system,
            $prompt->user,
            null, // no schema
            [
                'max_tokens' => 600,
                'temperature' => 0.7,
                'model' => $model,
            ]
        );
        $stageMs = (int) round((microtime(true) - $stageT0) * 1000);
        $llmStages->record(LlmStage::GENERATE, $stageMs, [
            ['role' => 'system', 'content' => $prompt->system],
            ['role' => 'user', 'content' => $prompt->user],
        ], is_array($raw) ? json_encode($raw) : $raw);

        // Extract comment from response
        $comment = $this->extractCommentFromResponse(is_array($raw) ? json_encode($raw) : (string) $raw);

        // Validate constraints
        $maxChars = $req->constraints->maxChars;
        if (mb_strlen($comment, 'UTF-8') > $maxChars) {
            $comment = mb_substr($comment, 0, $maxChars, 'UTF-8');
        }

        // Handle emoji policy
        if ($req->constraints->emojiPolicy === 'disallow') {
            $comment = $this->stripEmojis($comment);
        }

        // Persist snapshot using the correct method signature
        $snapshotId = null;
        try {
            // Build a proper GenerationContext for the persister
            $contextObj = new GenerationContext(
                voice: $voice ? (object) [
                    'id' => $voice->id,
                    'name' => $voice->name,
                    'type' => $voice->type,
                ] : null,
                template: $template ? (object) [
                    'id' => $template->id,
                    'name' => $template->name,
                    'template_type' => $template->template_type,
                ] : null,
                chunks: [],
                vip_chunks: [],
                enrichment_chunks: [],
                facts: [],
                swipes: [],
                user_context: null,
                businessSummary: null,
                options: [
                    'mode' => ['type' => 'comment', 'subtype' => $commentIntent],
                    'parent_post_preview' => mb_substr($parentPost, 0, 200, 'UTF-8'),
                    'author_name' => $authorName,
                    'platform' => $platform,
                    'platform_url' => $platformUrl,
                ],
            );

            $snapshotId = $this->snapshotPersister->persistGeneration(
                orgId: $orgId,
                userId: $userId,
                platform: $platform,
                prompt: $req->prompt ?: "Generate {$commentIntent} comment",
                classification: ['intent' => $commentIntent, 'funnel_stage' => null],
                context: $contextObj,
                options: $req->normalizedOptions,
                content: $comment,
                finalSystemPrompt: $prompt->system,
                finalUserPrompt: $prompt->user,
                tokenUsage: [],
                performance: ['total_ms' => (int) round((microtime(true) - $t0) * 1000)],
                repairInfo: [],
                llmStages: $llmStages->all(),
                generatedPostId: $options['generated_post_id'] ?? null,
                conversationId: $options['conversation_id'] ?? null,
                conversationMessageId: $options['conversation_message_id'] ?? null
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to persist comment snapshot', ['error' => $e->getMessage()]);
        }

        try {
            $this->cgLogger->capture('comment_result', [
                'comment_length' => strlen($comment),
                'snapshot_id' => $snapshotId,
            ]);
            $this->cgLogger->flush();
        } catch (\Throwable) {}

        return [
            'content' => $comment,
            'context_used' => [
                'template_id' => $template?->id,
                'voice_profile_id' => $voiceProfileId,
            ],
            'validation_result' => true,
            'validation' => [],
            'metadata' => [
                'intent' => $commentIntent,
                'funnel_stage' => null,
                'template_id' => $template?->id,
                'run_id' => $runId,
                'snapshot_id' => $snapshotId,
                'mode' => [
                    'type' => 'comment',
                    'subtype' => $commentIntent,
                ],
            ],
        ];
    }

    /**
     * Extract comment text from LLM response.
     */
    private function extractCommentFromResponse(string $raw): string
    {
        $raw = trim($raw);

        // Try to parse as JSON first
        if (str_starts_with($raw, '{')) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (isset($decoded['content'])) {
                    return trim((string) $decoded['content']);
                }
                if (isset($decoded['comment'])) {
                    return trim((string) $decoded['comment']);
                }
            } catch (\JsonException) {
                // Not valid JSON, use raw text
            }
        }

        // Remove markdown code blocks if present
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/\s*```$/m', '', $raw);

        return trim($raw);
    }

    /**
     * Research mode pipeline: delegated to ResearchExecutor for unified execution.
     */
    private function generateResearchReport(GenerationRequest $req, array $options, LlmStageTracker $llmStages, float $t0): array
    {
        $stageRaw = trim((string) ($options['research_stage'] ?? 'deep_research'));
        $stageRaw = $stageRaw !== '' ? $stageRaw : 'deep_research';
        $stage = ResearchStage::fromString($stageRaw);

        if ($stage === null) {
            $stage = ResearchStage::DEEP_RESEARCH;
        }

        // Build research options from request
        $researchOptions = ResearchOptions::fromArray($req->orgId, $req->userId, $options);

        // Execute research via shared executor
        $result = $this->researchExecutor->run(
            $req->prompt,
            $stage,
            $researchOptions,
            $req->platform,
            [
                'conversation_id' => $options['conversation_id'] ?? null,
                'conversation_message_id' => $options['conversation_message_id'] ?? null,
                'generated_post_id' => $options['generated_post_id'] ?? null,
            ]
        );

        // Build chat response using formatter
        $chatResponse = $this->researchFormatter->buildChatResponse($result);

        // Construct return format matching existing contract
        $report = $chatResponse['report'];
        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode([]);
        }

        $out = [
            'content' => $content,
            'report' => $report,
            'context_used' => [],
            'validation_result' => true,
            'validation' => [],
            'metadata' => [
                'intent' => $result->metadata['intent'] ?? null,
                'funnel_stage' => $result->metadata['funnel_stage'] ?? null,
                'template_id' => null,
                'run_id' => $result->metadata['run_id'] ?? $req->runId,
                'snapshot_id' => $result->snapshotId,
                'mode' => [
                    'type' => 'research',
                    'subtype' => $stage->value,
                ],
                // Keep flat keys for backward compatibility
                'research_stage' => $stage->value,
            ],
        ];

        // Include debug info if requested
        if (!empty($result->debug)) {
            $out['debug'] = $result->debug;
        }

        return $out;
    }

    private function handleTrendDiscovery(
        string $orgId,
        string $userId,
        string $platform,
        string $prompt,
        array $options,
        string $runId
    ): array {
        $industry = trim((string) ($options['research_industry'] ?? ''));
        $platforms = is_array($options['trend_platforms'] ?? null) ? (array) ($options['trend_platforms'] ?? []) : [];
        $limit = (int) ($options['trend_limit'] ?? 10);
        $trends = $this->trendDiscovery->discover($orgId, $prompt, $industry, $platforms, [
            'limit' => $limit,
            'recent_days' => $options['trend_recent_days'] ?? null,
            'days_back' => $options['trend_days_back'] ?? null,
        ]);

        $report = [
            'query' => $prompt,
            'industry' => $industry,
            'trends' => (array) ($trends['trends'] ?? []),
        ];

        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode([
                'query' => $prompt,
                'industry' => $industry,
                'trends' => [],
            ]);
        }

        $options['mode'] = 'research';
        $options['research_stage'] = 'trend_discovery';
        $options['research_intent'] = $this->mapResearchIntent('trend_discovery');
        $trendMeta = (array) ($trends['meta'] ?? []);
        $itemsConsidered = (int) ($trendMeta['items_considered'] ?? 0);

        $snapshotId = '';
        try {
            $context = $this->buildResearchSnapshotContext($options, [
                'trend_meta' => $trendMeta,
            ]);
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $orgId,
                userId: $userId,
                platform: $platform,
                prompt: $prompt,
                classification: ['intent' => $this->mapResearchIntent('trend_discovery'), 'funnel_stage' => null],
                context: $context,
                options: $options,
                content: $content,
                finalSystemPrompt: '',
                finalUserPrompt: '',
                tokenUsage: [],
                performance: [],
                repairInfo: [],
                llmStages: [],
                generatedPostId: $options['generated_post_id'] ?? null,
                conversationId: $options['conversation_id'] ?? null,
                conversationMessageId: $options['conversation_message_id'] ?? null,
            );
        } catch (\Throwable $e) {
            try { $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
        }

        try { $this->cgLogger->capture('snapshot_persisted', [
            'snapshot_id' => $snapshotId,
            'intent' => $this->mapResearchIntent('trend_discovery'),
            'mode' => [
                'type' => 'research',
                'subtype' => 'trend_discovery',
            ],
            'options' => $options,
        ]); } catch (\Throwable) {}

        $this->logResearchGuardrail('trend_discovery', 'TrendDiscoveryService', $platforms, $itemsConsidered);
        try { $this->cgLogger->flush('run_end', [
            'mode' => 'research',
            'snapshot_id' => $snapshotId,
            'report_preview' => mb_substr($content, 0, 240),
        ]);} catch (\Throwable) {}

        return [
            'content' => $content,
            'report' => $report,
            'context_used' => [],
            'validation_result' => true,
            'validation' => [],
            'metadata' => [
                'intent' => $this->mapResearchIntent('trend_discovery'),
                'funnel_stage' => null,
                'template_id' => null,
                'run_id' => $runId,
                'snapshot_id' => $snapshotId,
                'mode' => [
                    'type' => 'research',
                    'subtype' => 'trend_discovery',
                ],
                'research_stage' => 'trend_discovery',
                'trend_meta' => $trendMeta,
            ],
        ];
    }

    private function handleAngleHooks(
        string $orgId,
        string $userId,
        string $prompt,
        string $platform,
        array $classification,
        array $options,
        string $runId
    ): array {
        $result = $this->hookGenerator->generate($orgId, $userId, $prompt, $platform, $classification, $options);
        $report = (array) ($result['report'] ?? []);
        $meta = (array) ($result['meta'] ?? []);

        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode(['hooks' => []]);
        }

        $options['mode'] = 'research';
        $options['research_stage'] = 'angle_hooks';
        $options['research_intent'] = $this->mapResearchIntent('angle_hooks');

        $snapshotId = '';
        try {
            $context = $this->buildResearchSnapshotContext($options, [
                'hook_count' => count((array) ($report['hooks'] ?? [])),
            ]);
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $orgId,
                userId: $userId,
                platform: $platform,
                prompt: $prompt,
                classification: $classification,
                context: $context,
                options: $options,
                content: $content,
                finalSystemPrompt: '',
                finalUserPrompt: '',
                tokenUsage: (array) ($meta['usage'] ?? []),
                performance: ['latency_ms' => (int) ($meta['latency_ms'] ?? 0)],
                repairInfo: [],
                llmStages: [],
                generatedPostId: $options['generated_post_id'] ?? null,
                conversationId: $options['conversation_id'] ?? null,
                conversationMessageId: $options['conversation_message_id'] ?? null,
            );
        } catch (\Throwable $e) {
            try { $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
        }

        try { $this->cgLogger->capture('snapshot_persisted', [
            'snapshot_id' => $snapshotId,
            'intent' => (string) ($classification['intent'] ?? ''),
            'mode' => [
                'type' => 'research',
                'subtype' => 'angle_hooks',
            ],
            'options' => $options,
        ]); } catch (\Throwable) {}

        $this->logResearchGuardrail('angle_hooks', 'HookGenerationService', [], count((array) ($report['hooks'] ?? [])));
        try { $this->cgLogger->flush('run_end', [
            'mode' => 'research',
            'snapshot_id' => $snapshotId,
            'report_preview' => mb_substr($content, 0, 240),
        ]);} catch (\Throwable) {}

        return [
            'content' => $content,
            'report' => $report,
            'context_used' => [],
            'validation_result' => true,
            'validation' => [],
            'metadata' => [
                'intent' => $this->mapResearchIntent('angle_hooks'),
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'template_id' => null,
                'run_id' => $runId,
                'snapshot_id' => $snapshotId,
                'mode' => [
                    'type' => 'research',
                    'subtype' => 'angle_hooks',
                ],
                'research_stage' => 'angle_hooks',
                'hooks_meta' => $meta,
            ],
        ];
    }

    private function mapResearchIntent(string $stage): string
    {
        return match ($stage) {
            'trend_discovery' => 'research.trends',
            'angle_hooks' => 'research.ideation',
            default => 'research.analysis',
        };
    }

    private function buildResearchSnapshotContext(array $options, array $debug = []): GenerationContext
    {
        $creative = is_array($options['creative_intelligence'] ?? null) ? $options['creative_intelligence'] : null;

        return new GenerationContext(
            voice: null,
            template: null,
            chunks: [],
            vip_chunks: [],
            enrichment_chunks: [],
            facts: [],
            swipes: [],
            user_context: null,
            businessSummary: null,
            options: $options,
            creative_intelligence: $creative,
            snapshot: [
                'template_id' => null,
                'voice_profile_id' => null,
                'chunk_ids' => [],
                'fact_ids' => [],
                'swipe_ids' => [],
                'reference_ids' => [],
                'creative_intelligence' => $creative,
            ],
            debug: $debug,
            decision_trace: [],
            prompt_mutations: [],
            ci_rejections: [],
        );
    }

    private function logResearchGuardrail(string $stage, string $composer, array $platforms, int $itemsConsidered): void
    {
        $payload = [
            'mode' => 'research',
            'stage' => $stage,
            'composer' => $composer,
            'platforms' => array_values(array_filter(array_map('strval', $platforms), fn($v) => $v !== '')),
            'items_considered' => $itemsConsidered,
        ];

        if ($itemsConsidered === 0) {
            Log::warning('ai.research.guardrail', $payload);
            return;
        }
        Log::info('ai.research.guardrail', $payload);
    }

    private function summarizeCreativeSignals(array $items): array
    {
        $hooks = [];
        $angles = [];
        $archetypes = [];
        $personas = [];
        $unitIds = [];

        foreach ($items as $item) {
            $creative = is_array($item['creative'] ?? null) ? $item['creative'] : [];
            $unitId = isset($creative['creative_unit_id']) ? (string) $creative['creative_unit_id'] : '';
            if ($unitId !== '') {
                $unitIds[$unitId] = true;
            }
            $hook = trim((string) ($creative['hook_text'] ?? ''));
            $angle = trim((string) ($creative['angle'] ?? ''));
            $arch = trim((string) ($creative['hook_archetype'] ?? ''));
            $persona = trim((string) ($creative['audience_persona'] ?? ''));

            if ($hook !== '') { $hooks[$hook] = ($hooks[$hook] ?? 0) + 1; }
            if ($angle !== '') { $angles[$angle] = ($angles[$angle] ?? 0) + 1; }
            if ($arch !== '') { $archetypes[$arch] = ($archetypes[$arch] ?? 0) + 1; }
            if ($persona !== '') { $personas[$persona] = ($personas[$persona] ?? 0) + 1; }
        }

        arsort($hooks);
        arsort($angles);
        arsort($archetypes);
        arsort($personas);

        return [
            'unit_ids' => array_keys($unitIds),
            'top_hooks' => array_slice(array_keys($hooks), 0, 5),
            'top_angles' => array_slice(array_keys($angles), 0, 5),
            'top_hook_archetypes' => array_slice(array_keys($archetypes), 0, 5),
            'top_personas' => array_slice(array_keys($personas), 0, 5),
        ];
    }

    /**
     * Apply fact/angle policy: facts first, cap angles, and require facts for angles.
     *
     * @return array{0: array, 1: array}
     */
    private function applyChunkKindPolicy(array $chunks): array
    {
        $maxAngles = (int) config('ai.context.max_angles', 1);
        $maxExamples = (int) config('ai.context.max_examples', 1);
        $requireFact = (bool) config('ai.context.require_fact_for_angles', true);

        $facts = [];
        $angles = [];
        $examples = [];
        $quotes = [];
        $other = [];

        foreach ($chunks as $chunk) {
            $kind = ChunkKindResolver::resolveKind($chunk);
            $usagePolicy = is_array($chunk)
                ? (string) ($chunk['usage_policy'] ?? '')
                : (is_object($chunk) ? (string) ($chunk->usage_policy ?? '') : '');
            if ($usagePolicy === 'inspiration_only') {
                $kind = 'angle';
            }
            if (is_array($chunk)) {
                $chunk['chunk_kind'] = $kind;
            }
            switch ($kind) {
                case 'angle':
                    $angles[] = $chunk;
                    break;
                case 'example':
                    $examples[] = $chunk;
                    break;
                case 'quote':
                    $quotes[] = $chunk;
                    break;
                default:
                    $facts[] = $chunk;
                    break;
            }
        }

        if ($requireFact && empty($facts)) {
            $angles = [];
        }
        if ($maxAngles >= 0) {
            $angles = array_slice($angles, 0, $maxAngles);
        }
        if ($maxExamples >= 0) {
            $examples = array_slice($examples, 0, $maxExamples);
        }

        $ordered = array_merge($facts, $angles, $examples, $quotes, $other);
        $breakdown = [
            'facts' => count($facts),
            'angles' => count($angles),
            'examples' => count($examples),
            'quotes' => count($quotes),
        ];

        return [$ordered, $breakdown];
    }

    /**
     * Enforce constraints (length, emoji policy, tone hint) on an existing draft.
     * Returns [content, validation]. Performs a single repair attempt if needed.
     */
    public function enforce(string $orgId, string $userId, string $draft, string $platform, array $options = []): array
    {
        $runId = (string) (Str::uuid());
        try { $this->cgLogger->startRun($runId, [
            'entry' => 'enforce',
            'org_id' => $orgId,
            'user_id' => $userId,
            'platform' => $platform,
            'options' => $options,
            'draft_preview' => mb_substr($draft, 0, 200),
        ]);} catch (\Throwable) {}
        // Build minimal context and use centralized validation+repair flow
        $context = $this->contextFactory->fromParts([
            'voice' => null,
            'template' => null,
            'chunks' => [],
            'facts' => [],
            'swipes' => [],
            'user_context' => null,
            'business_context' => '',
            'business_summary' => null,
            'options' => $options,
            'reference_ids' => [],
        ]);
        try { $this->cgLogger->capture('enforce_context_built', ['context_debug' => $context->debug()]); } catch (\Throwable) {}

        $constraints = new \App\Services\Ai\Generation\DTO\Constraints(
            (int) ($options['max_chars'] ?? 1200),
            (string) ($options['emoji'] ?? 'disallow'),
            (string) ($options['tone'] ?? 'professional')
        );

        $res = $this->validatorRepair->validateAndRepair($draft, $context, $constraints);
        try { $this->cgLogger->flush('run_end', ['validate_and_repair' => $res]); } catch (\Throwable) {}
        return ['content' => $res['content'], 'validation' => $res['validation']];
    }

    /**
     * Replay generation from a stored GenerationSnapshot, optionally overriding options.
     * Returns [content, validation, quality, metadata].
     */
    public function replayFromSnapshot(\App\Models\GenerationSnapshot $snap, array $overrides = []): array
    {
        $orgId = (string) $snap->organization_id;
        $userId = (string) $snap->user_id;
        $platform = (string) ($overrides['platform'] ?? $snap->platform ?? 'generic');
        $prompt = (string) $snap->prompt;
        $llmStages = new LlmStageTracker();
        $runId = (string) (Str::uuid());
        try { $this->cgLogger->startRun($runId, [
            'entry' => 'replay',
            'snapshot_id' => (string) $snap->id,
            'org_id' => $orgId,
            'user_id' => $userId,
            'platform' => $platform,
        ]);} catch (\Throwable) {}

        // Determine template source
        $template = null;
        if (!empty($snap->template_id)) {
            try { $template = \App\Models\Template::find($snap->template_id); } catch (\Throwable) {}
        }
        if (!$template && is_array($snap->template_data)) {
            $template = (object) ['id' => null, 'template_data' => $snap->template_data];
        }

        // Build context using snapshot data and optional overrides
        $options = array_merge((array) $snap->options, (array) ($overrides['options'] ?? []));
        $context = $this->contextFactory->fromSnapshot($snap, $overrides);

        // Compose prompts from context and run via runner with meta
        $constraints = new \App\Services\Ai\Generation\DTO\Constraints(
            (int) ($options['max_chars'] ?? 1200),
            (string) ($options['emoji'] ?? 'disallow'),
            (string) ($options['tone'] ?? 'professional')
        );
        $promptObj = $this->composer->composePostGeneration($context, $constraints, $prompt, null);
        $res = $this->runner->runJsonContentCallWithMeta('replay_generate', $promptObj);
        $draft = (string) ($res['content'] ?? '');
        $modelUsedReplay = trim((string) (($res['meta']['model'] ?? '') ?: ''));
        if ($modelUsedReplay !== '') {
            $llmStages->record(LlmStage::REPLAY, $modelUsedReplay);
        }
        try { $this->cgLogger->capture('replay_llm_result', ['raw' => $res, 'draft_preview' => mb_substr($draft, 0, 200)]);} catch (\Throwable) {}

        // Optional Critic -> Refiner loop during replay when requested
        $enableReflexionReplay = (bool) (($options['enable_reflexion'] ?? false));
        if ($enableReflexionReplay && trim($draft) !== '' && !$this->looksLikeMetaNoDraft($draft)) {
            try {
                $critique = $this->reflexion->critique($draft, $prompt, $context, $llmStages);
                $score = (float) ($critique['score'] ?? 0);
                $this->cgLogger->capture('replay_reflexion_critique', [
                    'score' => $score,
                    'critique' => $critique,
                ]);
                if ($score < 9.0) {
                    $refined = $this->reflexion->refine($draft, $critique, $prompt, $context, $llmStages);
                    if (is_string($refined) && trim($refined) !== '') {
                        $draft = $refined;
                        $this->cgLogger->capture('replay_reflexion_refine_applied', [
                            'new_len' => mb_strlen($draft),
                        ]);
                    } else {
                        $this->cgLogger->capture('replay_reflexion_refine_skipped', ['reason' => 'empty_refine_output']);
                    }
                } else {
                    $this->cgLogger->capture('replay_reflexion_refine_skipped', ['reason' => 'score_threshold_met']);
                }
            } catch (\Throwable $e) {
                try { $this->cgLogger->capture('replay_reflexion_error', ['error' => $e->getMessage()]); } catch (\Throwable) {}
            }
        }

        if ($draft === '') {
            try {
                Log::warning('content.generator.replay_empty_output', [
                    'stage' => 'replay',
                    'snapshot_id' => (string) $snap->id,
                    'org_id' => $orgId,
                    'user_id' => $userId,
                    'platform' => $platform,
                    'system_len' => mb_strlen($promptObj->system),
                    'user_len' => mb_strlen($promptObj->user),
                    'model' => (string) (($res['meta']['model'] ?? null) ?: config('services.openrouter.chat_model')),
                    'usage' => (array) ($res['meta']['usage'] ?? []),
                ]);
            } catch (\Throwable $e) {
                // ignore logging failure
            }
        }

        // Validate and evaluate quality for A/B debugging
        $validation = $this->validator->checkPost($draft, $context);
        $classification = (array) $snap->classification;
        $quality = app(\App\Services\Ai\PostQualityEvaluator::class)->evaluate($draft, $context, $classification, $options);
        try { $this->cgLogger->flush('run_end', [
            'validation' => $validation,
            'quality' => $quality,
            'context_debug' => $context->debug(),
        ]);} catch (\Throwable) {}

        // Optionally persist a report
        if (!empty($overrides['store_report'])) {
            try {
                app(\App\Services\Ai\QualityReportService::class)->store(
                    orgId: $orgId,
                    userId: $userId,
                    intent: (string) ($classification['intent'] ?? ''),
                    overall: (float) ($quality['overall_score'] ?? 0.0),
                    scores: (array) ($quality['scores'] ?? []),
                    snapshotId: (string) $snap->id,
                    generatedPostId: null
                );
            } catch (\Throwable) {}
        }

        $debug = $context->debug();
        $contextSourceIds = [
            'chunk_ids' => array_values(array_filter(array_map(fn($c) => $c['id'] ?? null, $context->chunks))),
            'fact_ids' => array_values(array_filter(array_map(fn($f) => $f['id'] ?? null, $context->facts))),
            'swipe_ids' => array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $context->swipes))),
        ];
        $provided = (array) ($debug['provided_counts'] ?? []);
        $used = (array) ($debug['used_counts'] ?? []);
        $pruned = (array) ($debug['pruned_counts'] ?? []);
        $usage = (array) ($debug['usage'] ?? []);
        $meta = (array) ($res['meta'] ?? []);
        $latency = (int) ($res['latency_ms'] ?? 0);

        return [
            'content' => $draft,
            'validation' => $validation,
            'quality' => $quality,
            'metadata' => [
                'snapshot_id' => (string) $snap->id,
                'platform' => $platform,
                'intent' => $classification['intent'] ?? null,
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'model_used' => (string) ($meta['model'] ?? config('services.openrouter.chat_model')),
                'llm_stages_run' => $llmStages->all(),
                'total_tokens' => (int) ($meta['usage']['total_tokens'] ?? ($usage['total'] ?? 0)),
                'processing_time_ms' => $latency,
            ],
            'input_snapshot' => [
                'template_id' => $context->snapshotIds()['template_id'] ?? null,
                'provided_context_items' => ($provided['chunks'] ?? 0) + ($provided['facts'] ?? 0) + ($provided['user'] ?? 0),
                'pruned_items' => ($pruned['chunks'] ?? 0) + ($pruned['facts'] ?? 0) + ($pruned['user'] ?? 0),
            ],
            'context' => [
                'context_source_ids' => $contextSourceIds,
                'token_usage' => [
                    'chunks' => (int) ($usage['chunks_tokens'] ?? 0),
                    'facts' => (int) ($usage['facts_tokens'] ?? 0),
                    'user_context' => (int) ($usage['user_tokens'] ?? 0),
                    'template' => (int) ($usage['template_tokens'] ?? 0),
                    'swipes' => (int) ($usage['swipe_tokens'] ?? 0),
                    'total' => (int) ($usage['total'] ?? 0),
                ],
                'raw_prompt_sent' => $promptObj->user,
                'system_instruction' => $promptObj->system,
            ],
            'debug_links' => [
                'view_full_prompt' => 'php artisan ai:show-prompt ' . (string) $snap->id,
            ],
        ];
    }
}
