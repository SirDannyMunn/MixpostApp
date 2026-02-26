<?php

namespace App\Services\Ai\Research;

use App\Enums\ResearchStage;
use App\Services\Ai\Research\DTO\ResearchOptions;
use App\Services\Ai\Research\DTO\ResearchResult;
use App\Services\Ai\Research\Sources\SocialWatcherResearchGateway;
use App\Services\Ai\Retriever;
use App\Services\Ai\PostClassifier;
use App\Services\Ai\Generation\Steps\SnapshotPersister;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ai\LlmStageTracker;
use App\Services\Ai\GenerationContext;
use Illuminate\Support\Facades\Log;

class ResearchExecutor
{
    public function __construct(
        protected Retriever $retriever,
        protected PostClassifier $classifier,
        protected ResearchReportComposer $researchReportComposer,
        protected TrendDiscoveryService $trendDiscovery,
        protected HookGenerationService $hookGenerator,
        protected SnapshotPersister $snapshotPersister,
        protected ContentGenBatchLogger $cgLogger,
        protected ?SocialWatcherResearchGateway $canonicalGateway = null,
    ) {}

    /**
     * Execute research and return structured result.
     */
    public function run(
        string $question,
        ResearchStage $stage,
        ResearchOptions $options,
        string $platform = 'generic',
        array $additionalContext = []
    ): ResearchResult {
        $startTime = microtime(true);
        $llmStages = new LlmStageTracker();
        $runId = (string) \Illuminate\Support\Str::ulid();

        // Set logging mode
        $this->cgLogger->setMode('research', $stage->value);

        try {
            $this->cgLogger->begin($runId, [
                'org_id' => $options->organizationId,
                'user_id' => $options->userId,
                'platform' => $platform,
                'mode' => 'research',
                'research_stage' => $stage->value,
                'question' => $question,
                'options' => $options->toArray(),
            ]);
        } catch (\Throwable) {}

        // Route to specific stage handler
        $result = match ($stage) {
            ResearchStage::TREND_DISCOVERY => $this->runTrendDiscovery($question, $options, $platform, $runId, $startTime),
            ResearchStage::ANGLE_HOOKS => $this->runAngleHooks($question, $options, $platform, $runId, $llmStages, $startTime),
            ResearchStage::DEEP_RESEARCH => $this->runDeepResearch($question, $options, $platform, $runId, $llmStages, $startTime),
            ResearchStage::SATURATION_OPPORTUNITY => $this->runSaturationOpportunity($question, $options, $platform, $runId, $llmStages, $startTime),
        };

        try {
            $this->cgLogger->flush('run_end', [
                'mode' => 'research',
                'snapshot_id' => $result->snapshotId,
                'stage' => $stage->value,
            ]);
        } catch (\Throwable) {}

        return $result;
    }

    /**
     * Deep research: cluster analysis over existing content.
     */
    protected function runDeepResearch(
        string $question,
        ResearchOptions $options,
        string $platform,
        string $runId,
        LlmStageTracker $llmStages,
        float $startTime
    ): ResearchResult {
        // Classify intent
        $classification = $this->classifyQuestion($question, $options, $llmStages);

        // Determine retrieval limits
        $retrievalLimit = $options->limit;
        $kbLimit = 0;
        $socialLimit = $retrievalLimit;

        if ($options->includeKb) {
            $kbLimit = (int) floor($retrievalLimit / 4);
            $kbLimit = max(0, min(10, $kbLimit));
            $kbLimit = max(0, min($kbLimit, max(0, $retrievalLimit - 1)));
            $socialLimit = max(1, $retrievalLimit - $kbLimit);
        }

        // Retrieve items
        $retrievalStart = microtime(true);
        $items = [];
        $kbItems = [];

        try {
            // Use canonical gateway when enabled, fall back to legacy retriever
            $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical'
                && $this->canonicalGateway !== null;
            
            if ($useCanonical) {
                $evidenceItems = $this->canonicalGateway->searchEvidence(
                    $options->organizationId,
                    $question,
                    $options->withLimit($socialLimit)
                );
                
                // Convert to legacy array format
                $items = array_map(fn($item) => $item->toArray(), $evidenceItems);
            } else {
                $items = $this->retriever->researchItems(
                    $options->organizationId,
                    $question,
                    $socialLimit,
                    $options->mediaTypes ?: ['post', 'research_fragment'],
                    ['candidateLimit' => config('ai.research.candidate_limit', 800)]
                );
            }
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_retrieval_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        // Optionally include knowledge base
        if ($options->includeKb && $kbLimit > 0) {
            try {
                $kbChunks = $this->retriever->knowledgeChunks(
                    $options->organizationId,
                    $options->userId,
                    $question,
                    $classification['intent'] ?? 'educational',
                    $kbLimit
                );

                foreach ($kbChunks as $chunk) {
                    $kbItems[] = [
                        'id' => (string) ($chunk['id'] ?? ''),
                        'platform' => 'knowledge_base',
                        'source' => 'other',
                        'media_type' => 'knowledge',
                        'media_type_detail' => '',
                        'text' => (string) ($chunk['chunk_text'] ?? ''),
                        'title' => '',
                        'url' => '',
                        'author_name' => '',
                        'author_username' => '',
                        'published_at' => null,
                        'likes' => null,
                        'comments' => null,
                        'shares' => null,
                        'views' => null,
                        'engagement_score' => null,
                        'raw_reference_id' => null,
                        'creative' => [
                            'creative_unit_id' => null,
                            'hook_text' => '',
                            'angle' => '',
                            'value_promises' => [],
                            'proof_elements' => [],
                            'offer' => null,
                            'cta' => null,
                            'hook_archetype' => '',
                            'hook_novelty' => null,
                            'emotional_drivers' => [],
                            'audience_persona' => '',
                            'sophistication_level' => '',
                        ],
                        'embedding' => null,
                        'similarity' => 0.0,
                        'confidence_hint' => null,
                        'match_type' => 'knowledge',
                    ];
                }
            } catch (\Throwable $e) {
                try {
                    $this->cgLogger->capture('research_kb_error', ['error' => $e->getMessage()]);
                } catch (\Throwable) {}
            }
        }

        if (!empty($kbItems)) {
            $items = array_merge($items, $kbItems);
        }

        if (count($items) > $retrievalLimit) {
            $items = array_slice($items, 0, $retrievalLimit);
        }

        $retrievalMs = (int) round((microtime(true) - $retrievalStart) * 1000);

        try {
            $this->cgLogger->capture('research_retrieval', [
                'retrieval_limit' => $retrievalLimit,
                'social_limit' => $socialLimit,
                'kb_limit' => $kbLimit,
                'include_kb' => $options->includeKb,
                'media_types' => $options->mediaTypes,
                'item_count' => count($items),
                'kb_item_count' => count($kbItems),
                'retrieval_ms' => $retrievalMs,
            ]);
        } catch (\Throwable) {}

        // Cluster items (evidence assembly)
        $threshold = (float) config('ai.research.cluster_similarity', 0.82);
        $clustered = $this->clusterItems($items, $threshold);
        $items = $clustered['items'];
        $clusters = $clustered['clusters'];

        try {
            $this->cgLogger->capture('research_clustering', [
                'cluster_count' => count($clusters),
                'cluster_threshold' => $threshold,
            ]);
        } catch (\Throwable) {}

        // Compose research report with pre-clustered evidence
        $composeStart = microtime(true);
        $reportResult = $this->researchReportComposer->composeFromClusters(
            $question,
            $clusters,
            $items,
            $options->toArray(),
            $llmStages
        );
        $composeMs = (int) round((microtime(true) - $composeStart) * 1000);

        $report = (array) ($reportResult['report'] ?? []);
        $promptPayload = (array) ($reportResult['prompt'] ?? []);
        $reportMeta = (array) ($reportResult['meta'] ?? []);

        // Build context for snapshot
        $creativeMeta = $this->summarizeCreativeSignals($items);
        $chunkRows = array_map(function ($item) {
            return [
                'id' => (string) ($item['id'] ?? ''),
                'chunk_text' => (string) ($item['text'] ?? ''),
                'source' => (string) ($item['source'] ?? ''),
                'media_type' => (string) ($item['media_type'] ?? ''),
                'platform' => (string) ($item['platform'] ?? ''),
                'cluster_id' => (string) ($item['cluster_id'] ?? ''),
            ];
        }, $items);

        $contextOptions = [
            'research' => [
                'retrieval_policy' => [
                    'useRetrieval' => true,
                    'retrievalLimit' => $retrievalLimit,
                    'includeMediaTypes' => $options->mediaTypes ?: ['post', 'research_fragment'],
                    'useKnowledgeBase' => $options->includeKb,
                ],
                'retrieved_item_ids' => array_values(array_filter(array_map(fn($i) => $i['id'] ?? null, $items))),
                'cluster_summaries' => $clusters,
                'kb_item_count' => count($kbItems),
            ],
            'creative_intelligence' => $creativeMeta,
            'classification' => $classification,
            'mode' => 'research',
            'research_stage' => 'deep_research',
            'research_intent' => $this->mapResearchIntent('deep_research'),
        ];

        $context = new GenerationContext(
            voice: null,
            template: null,
            chunks: $chunkRows,
            vip_chunks: [],
            enrichment_chunks: [],
            facts: [],
            swipes: [],
            user_context: null,
            businessSummary: null,
            options: $contextOptions,
            creative_intelligence: $creativeMeta,
            snapshot: [
                'template_id' => null,
                'voice_profile_id' => null,
                'chunk_ids' => array_values(array_filter(array_map(fn($c) => $c['id'] ?? null, $chunkRows))),
                'fact_ids' => [],
                'swipe_ids' => [],
                'reference_ids' => [],
                'creative_intelligence' => $creativeMeta,
            ],
            debug: [
                'research' => [
                    'retrieval_limit' => $retrievalLimit,
                    'media_types' => $options->mediaTypes,
                    'item_count' => count($items),
                    'cluster_count' => count($clusters),
                ],
            ],
            decision_trace: [],
            prompt_mutations: [],
            ci_rejections: [],
        );

        // Persist snapshot
        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode([
                'question' => $question,
                'dominant_claims' => [],
                'points_of_disagreement' => [],
                'saturated_angles' => [],
                'emerging_angles' => [],
                'example_excerpts' => [],
            ]);
        }

        $tokenUsage = is_array($reportMeta['usage'] ?? null) ? (array) $reportMeta['usage'] : [];
        $latency = (int) ($reportMeta['latency_ms'] ?? 0);
        $perf = [
            'latency_ms' => $latency,
            'total_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
        ];

        $snapshotId = '';
        try {
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $options->organizationId,
                userId: $options->userId,
                platform: $platform,
                prompt: $question,
                classification: $classification,
                context: $context,
                options: $contextOptions,
                content: $content,
                finalSystemPrompt: (string) ($promptPayload['system'] ?? ''),
                finalUserPrompt: (string) ($promptPayload['user'] ?? ''),
                tokenUsage: $tokenUsage,
                performance: $perf,
                repairInfo: [],
                llmStages: $llmStages->all(),
                generatedPostId: null,
                conversationId: null,
                conversationMessageId: null,
            );
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        // Log research guardrail
        $platformsLogged = array_values(array_filter(array_unique(array_map(
            fn($item) => (string) ($item['platform'] ?? ''),
            $items
        )), fn($v) => $v !== ''));
        $this->logResearchGuardrail('deep_research', 'ResearchPromptComposer', $platformsLogged, count($items));

        // Build debug info
        $debug = [];
        if ($options->returnDebug || $options->trace) {
            $debug = [
                'items' => $items,
                'clusters' => $clusters,
                'prompt' => $promptPayload,
                'model' => (string) ($reportMeta['model'] ?? ''),
                'timings' => [
                    'retrieval_ms' => $retrievalMs,
                    'compose_ms' => $composeMs,
                ],
                'counts' => [
                    'items' => count($items),
                    'clusters' => count($clusters),
                    'kb_items' => count($kbItems),
                    'media_types' => $options->mediaTypes,
                ],
            ];
        }

        return new ResearchResult(
            stage: ResearchStage::DEEP_RESEARCH,
            question: $question,
            dominantClaims: (array) ($report['dominant_claims'] ?? []),
            pointsOfDisagreement: (array) ($report['points_of_disagreement'] ?? []),
            emergingAngles: (array) ($report['emerging_angles'] ?? []),
            saturatedAngles: (array) ($report['saturated_angles'] ?? []),
            sampleExcerpts: (array) ($report['example_excerpts'] ?? []),
            snapshotId: $snapshotId,
            metadata: [
                'intent' => $this->mapResearchIntent('deep_research'),
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'run_id' => $runId,
                'classification' => $classification,
            ],
            debug: $debug,
        );
    }

    /**
     * Angle & hooks generation.
     */
    protected function runAngleHooks(
        string $question,
        ResearchOptions $options,
        string $platform,
        string $runId,
        LlmStageTracker $llmStages,
        float $startTime
    ): ResearchResult {
        $classification = $this->classifyQuestion($question, $options, $llmStages);

        $result = $this->hookGenerator->generate(
            $options->organizationId,
            $options->userId,
            $question,
            $platform,
            $classification,
            $options->toArray()
        );

        $report = (array) ($result['report'] ?? []);
        $meta = (array) ($result['meta'] ?? []);

        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode(['hooks' => []]);
        }

        $contextOptions = [
            'mode' => 'research',
            'research_stage' => 'angle_hooks',
            'research_intent' => $this->mapResearchIntent('angle_hooks'),
        ];

        $context = $this->buildResearchSnapshotContext($contextOptions, [
            'hook_count' => count((array) ($report['hooks'] ?? [])),
        ]);

        $snapshotId = '';
        try {
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $options->organizationId,
                userId: $options->userId,
                platform: $platform,
                prompt: $question,
                classification: $classification,
                context: $context,
                options: $contextOptions,
                content: $content,
                finalSystemPrompt: '',
                finalUserPrompt: '',
                tokenUsage: (array) ($meta['usage'] ?? []),
                performance: ['latency_ms' => (int) ($meta['latency_ms'] ?? 0)],
                repairInfo: [],
                llmStages: [],
                generatedPostId: null,
                conversationId: null,
                conversationMessageId: null,
            );
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        $this->logResearchGuardrail('angle_hooks', 'HookGenerationService', [], count((array) ($report['hooks'] ?? [])));

        return new ResearchResult(
            stage: ResearchStage::ANGLE_HOOKS,
            question: $question,
            hooks: (array) ($report['hooks'] ?? []),
            snapshotId: $snapshotId,
            metadata: [
                'intent' => $this->mapResearchIntent('angle_hooks'),
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'run_id' => $runId,
                'hooks_meta' => $meta,
            ],
        );
    }

    /**
     * Trend discovery.
     */
    protected function runTrendDiscovery(
        string $question,
        ResearchOptions $options,
        string $platform,
        string $runId,
        float $startTime
    ): ResearchResult {
        $trends = $this->trendDiscovery->discover(
            $options->organizationId,
            $question,
            $options->industry,
            $options->platforms,
            [
                'limit' => $options->trendLimit,
                'recent_days' => $options->trendRecentDays,
                'days_back' => $options->trendDaysBack,
                'min_recent' => $options->trendMinRecent,
            ]
        );

        $report = [
            'query' => $question,
            'industry' => $options->industry,
            'trends' => (array) ($trends['trends'] ?? []),
        ];

        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode([
                'query' => $question,
                'industry' => $options->industry,
                'trends' => [],
            ]);
        }

        $contextOptions = [
            'mode' => 'research',
            'research_stage' => 'trend_discovery',
            'research_intent' => $this->mapResearchIntent('trend_discovery'),
        ];

        $trendMeta = (array) ($trends['meta'] ?? []);
        $context = $this->buildResearchSnapshotContext($contextOptions, [
            'trend_meta' => $trendMeta,
        ]);

        $snapshotId = '';
        try {
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $options->organizationId,
                userId: $options->userId,
                platform: $platform,
                prompt: $question,
                classification: ['intent' => $this->mapResearchIntent('trend_discovery'), 'funnel_stage' => null],
                context: $context,
                options: $contextOptions,
                content: $content,
                finalSystemPrompt: '',
                finalUserPrompt: '',
                tokenUsage: [],
                performance: [],
                repairInfo: [],
                llmStages: [],
                generatedPostId: null,
                conversationId: null,
                conversationMessageId: null,
            );
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        $itemsConsidered = (int) ($trendMeta['items_considered'] ?? 0);
        $this->logResearchGuardrail('trend_discovery', 'TrendDiscoveryService', $options->platforms, $itemsConsidered);

        return new ResearchResult(
            stage: ResearchStage::TREND_DISCOVERY,
            question: $question,
            trends: (array) ($trends['trends'] ?? []),
            snapshotId: $snapshotId,
            metadata: [
                'intent' => $this->mapResearchIntent('trend_discovery'),
                'funnel_stage' => null,
                'run_id' => $runId,
                'trend_meta' => $trendMeta,
                'industry' => $options->industry,
            ],
        );
    }

    /**
     * Saturation & Opportunity Analysis.
     */
    protected function runSaturationOpportunity(
        string $question,
        ResearchOptions $options,
        string $platform,
        string $runId,
        LlmStageTracker $llmStages,
        float $startTime
    ): ResearchResult {
        // Classify intent
        $classification = $this->classifyQuestion($question, $options, $llmStages);

        // Extract topic from question (simple heuristic for now)
        $topic = $this->extractTopic($question);

        // Use higher limit for saturation analysis (default 60)
        $retrievalLimit = max(60, $options->limit);

        // Retrieve items
        $retrievalStart = microtime(true);
        $items = [];

        try {
            // Use canonical gateway when enabled, fall back to legacy retriever
            $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical'
                && $this->canonicalGateway !== null;
            
            if ($useCanonical) {
                $evidenceItems = $this->canonicalGateway->searchEvidence(
                    $options->organizationId,
                    $question,
                    $options->withLimit($retrievalLimit)
                );
                
                // Convert to legacy array format
                $items = array_map(fn($item) => $item->toArray(), $evidenceItems);
            } else {
                $items = $this->retriever->researchItems(
                    $options->organizationId,
                    $question,
                    $retrievalLimit,
                    $options->mediaTypes ?: ['post', 'research_fragment'],
                    ['candidateLimit' => config('ai.research.candidate_limit', 800)]
                );
            }
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_retrieval_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        $retrievalMs = (int) ((microtime(true) - $retrievalStart) * 1000);

        try {
            $this->cgLogger->capture('retrieval', [
                'items_count' => count($items),
                'time_ms' => $retrievalMs,
            ]);
        } catch (\Throwable) {}

        // Compute metrics and analyze patterns
        $analysisStart = microtime(true);
        $analysis = $this->analyzeSaturation($items, $options);
        $analysisMs = (int) ((microtime(true) - $analysisStart) * 1000);

        // Build report
        $report = [
            'topic' => $topic,
            'decision' => $analysis['decision'],
            'signals' => $analysis['signals'],
            'saturated_patterns' => $analysis['saturated_patterns'],
            'white_space_opportunities' => $analysis['white_space_opportunities'],
            'risks' => $analysis['risks'],
            'evidence' => $analysis['evidence'],
        ];

        $content = json_encode($report);
        if (!is_string($content)) {
            $content = json_encode([
                'topic' => $topic,
                'decision' => ['recommendation' => 'cautious_go', 'opportunity_score' => 50, 'confidence' => 0.5, 'summary' => 'Insufficient data'],
            ]);
        }

        $contextOptions = [
            'mode' => 'research',
            'research_stage' => 'saturation_opportunity',
            'research_intent' => $this->mapResearchIntent('saturation_opportunity'),
        ];

        $context = $this->buildResearchSnapshotContext($contextOptions, [
            'items_analyzed' => count($items),
            'retrieval_ms' => $retrievalMs,
            'analysis_ms' => $analysisMs,
            'metrics' => $analysis['signals'],
        ]);

        $snapshotId = '';
        try {
            $snapshotId = $this->snapshotPersister->persistResearchSnapshot(
                orgId: $options->organizationId,
                userId: $options->userId,
                platform: $platform,
                prompt: $question,
                classification: $classification,
                context: $context,
                options: $contextOptions,
                content: $content,
                finalSystemPrompt: '',
                finalUserPrompt: '',
                tokenUsage: [],
                performance: ['retrieval_ms' => $retrievalMs, 'analysis_ms' => $analysisMs],
                repairInfo: [],
                llmStages: [],
                generatedPostId: null,
                conversationId: null,
                conversationMessageId: null,
            );
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('research_snapshot_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
        }

        $this->logResearchGuardrail('saturation_opportunity', 'SaturationAnalyzer', $options->platforms, count($items));

        return new ResearchResult(
            stage: ResearchStage::SATURATION_OPPORTUNITY,
            question: $question,
            saturationReport: $report,
            snapshotId: $snapshotId,
            metadata: [
                'intent' => $this->mapResearchIntent('saturation_opportunity'),
                'funnel_stage' => $classification['funnel_stage'] ?? null,
                'run_id' => $runId,
                'items_analyzed' => count($items),
                'opportunity_score' => $analysis['decision']['opportunity_score'] ?? 50,
            ],
            debug: $options->returnDebug ? [
                'items' => $items,
                'metrics' => $analysis['signals'],
            ] : [],
        );
    }

    /**
     * Extract topic from question (simple heuristic).
     */
    protected function extractTopic(string $question): string
    {
        // Remove common question words
        $topic = preg_replace('/^(is|are|what|where|how|why|when|which|who)\s+/i', '', $question);
        $topic = preg_replace('/\s+(saturated|worth|doing|white\s+space|opportunity|overdone|played\s+out)\??$/i', '', $topic ?? '');
        return trim($topic ?? $question);
    }

    /**
     * Analyze saturation and compute opportunity metrics.
     */
    protected function analyzeSaturation(array $items, ResearchOptions $options): array
    {
        if (empty($items)) {
            return $this->emptyAnalysisResult();
        }

        // Extract time windows
        $recentDays = (int) ($options->timeWindows['recent_days'] ?? 14);
        $baselineDays = (int) ($options->timeWindows['baseline_days'] ?? 90);

        // Compute volume & velocity
        $volumeMetrics = $this->computeVolumeMetrics($items, $recentDays, $baselineDays);

        // Compute fatigue proxy
        $fatigueMetrics = $this->computeFatigueMetrics($items);

        // Compute diversity
        $diversityMetrics = $this->computeDiversityMetrics($items);

        // Compute quality
        $qualityMetrics = $this->computeQualityMetrics($items);

        // Compute persona fit
        $personaMetrics = $this->computePersonaMetrics($items);

        // Identify saturated patterns
        $saturatedPatterns = $this->identifySaturatedPatterns($items, $fatigueMetrics);

        // Identify white space opportunities
        $whiteSpaceOpportunities = $this->identifyWhiteSpaceOpportunities($items, $qualityMetrics);

        // Compute opportunity score & recommendation
        $decision = $this->computeOpportunityDecision(
            $volumeMetrics,
            $fatigueMetrics,
            $diversityMetrics,
            $qualityMetrics,
            count($items)
        );

        // Identify risks
        $risks = $this->identifyRisks($fatigueMetrics, $qualityMetrics, $diversityMetrics);

        // Select evidence excerpts
        $evidence = $this->selectEvidenceExcerpts($items, $saturatedPatterns, $whiteSpaceOpportunities, $options->maxExamples);

        return [
            'decision' => $decision,
            'signals' => [
                'volume' => $volumeMetrics,
                'fatigue' => $fatigueMetrics,
                'diversity' => $diversityMetrics,
                'quality' => $qualityMetrics,
                'persona_fit' => $personaMetrics,
            ],
            'saturated_patterns' => $saturatedPatterns,
            'white_space_opportunities' => $whiteSpaceOpportunities,
            'risks' => $risks,
            'evidence' => $evidence,
        ];
    }

    protected function emptyAnalysisResult(): array
    {
        return [
            'decision' => [
                'recommendation' => 'cautious_go',
                'opportunity_score' => 50,
                'confidence' => 0.3,
                'summary' => 'Insufficient data for analysis. Consider broadening your search or checking different platforms.',
            ],
            'signals' => [
                'volume' => ['recent_posts' => 0, 'baseline_posts' => 0, 'velocity_ratio' => 0.0],
                'fatigue' => ['avg_fatigue' => 0.0, 'repeat_rate_30d' => 0.0],
                'diversity' => ['angle_diversity' => 0.0, 'hook_diversity' => 0.0],
                'quality' => ['avg_noise_risk' => 0.0, 'avg_buyer_quality' => 0.0],
                'persona_fit' => ['top_personas' => [], 'mismatch_risk' => 0.0],
            ],
            'saturated_patterns' => [],
            'white_space_opportunities' => [],
            'risks' => [],
            'evidence' => ['representative_excerpts' => []],
        ];
    }

    protected function computeVolumeMetrics(array $items, int $recentDays, int $baselineDays): array
    {
        $now = now();
        $recentCutoff = $now->copy()->subDays($recentDays);
        $baselineCutoff = $now->copy()->subDays($baselineDays);

        $recentCount = 0;
        $baselineCount = 0;

        foreach ($items as $item) {
            $publishedAt = $item['published_at'] ?? null;
            if (!$publishedAt) {
                continue;
            }

            try {
                $publishedDate = \Carbon\Carbon::parse($publishedAt);
                if ($publishedDate->gte($recentCutoff)) {
                    $recentCount++;
                }
                if ($publishedDate->gte($baselineCutoff) && $publishedDate->lt($recentCutoff)) {
                    $baselineCount++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $recentRate = $recentDays > 0 ? ($recentCount / $recentDays) : 0.0;
        $baselinePeriod = max(1, $baselineDays - $recentDays);
        $baselineRate = $baselinePeriod > 0 ? ($baselineCount / $baselinePeriod) : 0.0;
        $velocityRatio = $baselineRate > 0 ? ($recentRate / $baselineRate) : 0.0;

        return [
            'recent_posts' => $recentCount,
            'baseline_posts' => $baselineCount,
            'velocity_ratio' => round($velocityRatio, 2),
        ];
    }

    protected function computeFatigueMetrics(array $items): array
    {
        // Use cluster fatigue if available, else approximate via pattern repetition
        $fatigueScores = [];
        $patternCounts = [];
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $fatigueScore = (float) ($creative['fatigue_score'] ?? 0.0);
            if ($fatigueScore > 0) {
                $fatigueScores[] = $fatigueScore;
            }

            // Count patterns in last 30 days
            $publishedAt = $item['published_at'] ?? null;
            $isRecent = false;
            if ($publishedAt) {
                try {
                    $isRecent = \Carbon\Carbon::parse($publishedAt)->gte($thirtyDaysAgo);
                } catch (\Throwable) {}
            }

            if ($isRecent) {
                $hookArchetype = trim((string) ($creative['hook_archetype'] ?? ''));
                $angle = trim((string) ($creative['angle'] ?? ''));
                $pattern = $hookArchetype . '|' . $angle;
                if ($pattern !== '|') {
                    $patternCounts[$pattern] = ($patternCounts[$pattern] ?? 0) + 1;
                }
            }
        }

        $avgFatigue = !empty($fatigueScores) ? (array_sum($fatigueScores) / count($fatigueScores)) : 0.0;
        $maxRepeat = !empty($patternCounts) ? max($patternCounts) : 0;
        $totalRecent = array_sum($patternCounts);
        $repeatRate = $totalRecent > 0 ? ($maxRepeat / $totalRecent) : 0.0;

        return [
            'avg_fatigue' => round($avgFatigue, 2),
            'repeat_rate_30d' => round($repeatRate, 2),
        ];
    }

    protected function computeDiversityMetrics(array $items): array
    {
        $angles = [];
        $hooks = [];

        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $angle = trim((string) ($creative['angle'] ?? ''));
            $hookArchetype = trim((string) ($creative['hook_archetype'] ?? ''));

            if ($angle !== '') {
                $angles[$angle] = ($angles[$angle] ?? 0) + 1;
            }
            if ($hookArchetype !== '') {
                $hooks[$hookArchetype] = ($hooks[$hookArchetype] ?? 0) + 1;
            }
        }

        $angleDiversity = $this->computeShannonEntropy($angles);
        $hookDiversity = $this->computeShannonEntropy($hooks);

        return [
            'angle_diversity' => round($angleDiversity, 2),
            'hook_diversity' => round($hookDiversity, 2),
        ];
    }

    protected function computeShannonEntropy(array $counts): float
    {
        if (empty($counts)) {
            return 0.0;
        }

        $total = array_sum($counts);
        if ($total === 0) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach ($counts as $count) {
            $p = $count / $total;
            if ($p > 0) {
                $entropy -= $p * log($p, 2);
            }
        }

        // Normalize to 0-1 scale (max entropy = log2(N))
        $maxEntropy = log(count($counts), 2);
        return $maxEntropy > 0 ? ($entropy / $maxEntropy) : 0.0;
    }

    protected function computeQualityMetrics(array $items): array
    {
        $noiseRisks = [];
        $buyerQualities = [];

        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $noiseRisk = (float) ($creative['noise_risk'] ?? 0.0);
            $buyerQuality = (float) ($creative['buyer_quality_score'] ?? 0.0);

            if ($noiseRisk > 0) {
                $noiseRisks[] = $noiseRisk;
            }
            if ($buyerQuality > 0) {
                $buyerQualities[] = $buyerQuality;
            }
        }

        $avgNoiseRisk = !empty($noiseRisks) ? (array_sum($noiseRisks) / count($noiseRisks)) : 0.0;
        $avgBuyerQuality = !empty($buyerQualities) ? (array_sum($buyerQualities) / count($buyerQualities)) : 0.0;

        return [
            'avg_noise_risk' => round($avgNoiseRisk, 2),
            'avg_buyer_quality' => round($avgBuyerQuality, 2),
        ];
    }

    protected function computePersonaMetrics(array $items): array
    {
        $personas = [];
        $sophisticationLevels = [];

        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $persona = trim((string) ($creative['audience_persona'] ?? ''));
            $sophistication = trim((string) ($creative['sophistication_level'] ?? ''));

            if ($persona !== '') {
                $personas[$persona] = ($personas[$persona] ?? 0) + 1;
            }
            if ($sophistication !== '') {
                $sophisticationLevels[$sophistication] = ($sophisticationLevels[$sophistication] ?? 0) + 1;
            }
        }

        arsort($personas);
        arsort($sophisticationLevels);

        $topPersonas = array_slice(array_keys($personas), 0, 3);
        $topSophistication = array_slice(array_keys($sophisticationLevels), 0, 3);

        // Mismatch risk = high spread across personas (low concentration)
        $mismatchRisk = 0.0;
        if (count($personas) > 1) {
            $total = array_sum($personas);
            $top = reset($personas);
            $concentration = $total > 0 ? ($top / $total) : 0.0;
            $mismatchRisk = 1.0 - $concentration;
        }

        return [
            'top_personas' => $topPersonas,
            'top_sophistication_levels' => $topSophistication,
            'mismatch_risk' => round($mismatchRisk, 2),
        ];
    }

    protected function identifySaturatedPatterns(array $items, array $fatigueMetrics): array
    {
        $patterns = [];
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Group by hook archetype + angle
        $hookAngleCounts = [];
        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $hookArchetype = trim((string) ($creative['hook_archetype'] ?? ''));
            $angle = trim((string) ($creative['angle'] ?? ''));

            if ($hookArchetype === '' && $angle === '') {
                continue;
            }

            $pattern = $hookArchetype . '|' . $angle;

            // Check if recent
            $publishedAt = $item['published_at'] ?? null;
            $isRecent = false;
            if ($publishedAt) {
                try {
                    $isRecent = \Carbon\Carbon::parse($publishedAt)->gte($thirtyDaysAgo);
                } catch (\Throwable) {}
            }

            if (!isset($hookAngleCounts[$pattern])) {
                $hookAngleCounts[$pattern] = [
                    'hook' => $hookArchetype,
                    'angle' => $angle,
                    'count' => 0,
                    'recent_count' => 0,
                    'item_ids' => [],
                    'fatigue_scores' => [],
                ];
            }

            $hookAngleCounts[$pattern]['count']++;
            if ($isRecent) {
                $hookAngleCounts[$pattern]['recent_count']++;
            }
            $hookAngleCounts[$pattern]['item_ids'][] = (string) ($item['id'] ?? '');
            $fatigueScore = (float) ($creative['fatigue_score'] ?? 0.0);
            if ($fatigueScore > 0) {
                $hookAngleCounts[$pattern]['fatigue_scores'][] = $fatigueScore;
            }
        }

        // Sort by recent count descending
        uasort($hookAngleCounts, fn($a, $b) => $b['recent_count'] <=> $a['recent_count']);

        // Select top 5 as saturated patterns
        $topPatterns = array_slice($hookAngleCounts, 0, 5, true);

        foreach ($topPatterns as $pattern => $data) {
            $itemCount = $data['count'];
            $recentCount = $data['recent_count'];
            $total30d = array_sum(array_column($hookAngleCounts, 'recent_count'));
            $repeatRate = $total30d > 0 ? ($recentCount / $total30d) : 0.0;
            $avgFatigue = !empty($data['fatigue_scores']) ? (array_sum($data['fatigue_scores']) / count($data['fatigue_scores'])) : 0.0;

            $label = $data['hook'];
            if ($data['angle'] !== '') {
                $label .= ': ' . $data['angle'];
            }

            $patterns[] = [
                'pattern_type' => 'hook',
                'label' => $label,
                'evidence' => [
                    'item_count' => $itemCount,
                    'repeat_rate_30d' => round($repeatRate, 2),
                    'fatigue_score' => round($avgFatigue, 2),
                ],
                'why_saturated' => $this->explainSaturation($repeatRate, $avgFatigue),
                'example_ids' => array_slice($data['item_ids'], 0, 3),
            ];
        }

        return $patterns;
    }

    protected function explainSaturation(float $repeatRate, float $avgFatigue): string
    {
        if ($repeatRate > 0.3 && $avgFatigue > 0.6) {
            return 'High repetition and declining engagement';
        }
        if ($repeatRate > 0.3) {
            return 'High repetition in recent content';
        }
        if ($avgFatigue > 0.6) {
            return 'Showing signs of audience fatigue';
        }
        return 'Moderately saturated';
    }

    protected function identifyWhiteSpaceOpportunities(array $items, array $qualityMetrics): array
    {
        $opportunities = [];

        // Group by angle
        $angleCounts = [];
        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);
            $angle = trim((string) ($creative['angle'] ?? ''));

            if ($angle === '') {
                continue;
            }

            if (!isset($angleCounts[$angle])) {
                $angleCounts[$angle] = [
                    'count' => 0,
                    'item_ids' => [],
                    'buyer_qualities' => [],
                    'engagement_scores' => [],
                    'hook_archetypes' => [],
                ];
            }

            $angleCounts[$angle]['count']++;
            $angleCounts[$angle]['item_ids'][] = (string) ($item['id'] ?? '');

            $buyerQuality = (float) ($creative['buyer_quality_score'] ?? 0.0);
            if ($buyerQuality > 0) {
                $angleCounts[$angle]['buyer_qualities'][] = $buyerQuality;
            }

            $engagementScore = (float) ($item['engagement_score'] ?? 0.0);
            if ($engagementScore > 0) {
                $angleCounts[$angle]['engagement_scores'][] = $engagementScore;
            }

            $hookArchetype = trim((string) ($creative['hook_archetype'] ?? ''));
            if ($hookArchetype !== '') {
                $angleCounts[$angle]['hook_archetypes'][$hookArchetype] = ($angleCounts[$angle]['hook_archetypes'][$hookArchetype] ?? 0) + 1;
            }
        }

        // Find low-volume, high-quality angles
        foreach ($angleCounts as $angle => $data) {
            if ($data['count'] < 5) {
                continue;
            } // Too sparse

            $avgBuyerQuality = !empty($data['buyer_qualities']) ? (array_sum($data['buyer_qualities']) / count($data['buyer_qualities'])) : 0.0;
            $avgEngagement = !empty($data['engagement_scores']) ? (array_sum($data['engagement_scores']) / count($data['engagement_scores'])) : 0.0;

            // White space = low volume + high quality
            $isLowVolume = $data['count'] < 20;
            $isHighQuality = $avgBuyerQuality > 0.7 || $avgEngagement > 0.05;

            if ($isLowVolume && $isHighQuality) {
                arsort($data['hook_archetypes']);
                $topHooks = array_slice(array_keys($data['hook_archetypes']), 0, 3);

                $opportunities[] = [
                    'angle' => $angle,
                    'why_open' => $this->explainWhiteSpace($data['count'], $avgBuyerQuality, $avgEngagement),
                    'recommended_formats' => ['thread', 'short_video'], // Default recommendations
                    'recommended_hook_archetypes' => $topHooks,
                    'risks' => ['Requires specificity; avoid generic advice'],
                    'evidence' => [
                        'item_count' => $data['count'],
                        'avg_engagement' => round($avgEngagement, 3),
                        'avg_buyer_quality' => round($avgBuyerQuality, 2),
                    ],
                    'example_ids' => array_slice($data['item_ids'], 0, 3),
                ];
            }
        }

        // Sort by buyer quality descending
        usort($opportunities, fn($a, $b) => $b['evidence']['avg_buyer_quality'] <=> $a['evidence']['avg_buyer_quality']);

        return array_slice($opportunities, 0, 7);
    }

    protected function explainWhiteSpace(int $count, float $avgBuyerQuality, float $avgEngagement): string
    {
        if ($avgBuyerQuality > 0.8) {
            return 'Low volume but very high buyer quality among posts';
        }
        if ($avgEngagement > 0.06) {
            return 'Low volume but strong engagement signals';
        }
        return 'Underexplored angle with quality potential';
    }

    protected function computeOpportunityDecision(
        array $volumeMetrics,
        array $fatigueMetrics,
        array $diversityMetrics,
        array $qualityMetrics,
        int $sampleSize
    ): array {
        $score = 50; // Start at neutral

        // Volume/velocity adjustment (+/- 25)
        $velocityRatio = (float) ($volumeMetrics['velocity_ratio'] ?? 0.0);
        if ($velocityRatio > 1.5) {
            $score += 25; // Rising demand
        } elseif ($velocityRatio > 1.0) {
            $score += 15;
        } elseif ($velocityRatio < 0.5) {
            $score -= 15; // Declining interest
        }

        // Quality adjustment (+/- 15)
        $avgBuyerQuality = (float) ($qualityMetrics['avg_buyer_quality'] ?? 0.0);
        if ($avgBuyerQuality > 0.7) {
            $score += 15;
        } elseif ($avgBuyerQuality < 0.4) {
            $score -= 10;
        }

        // Fatigue penalty (-25)
        $avgFatigue = (float) ($fatigueMetrics['avg_fatigue'] ?? 0.0);
        $repeatRate = (float) ($fatigueMetrics['repeat_rate_30d'] ?? 0.0);
        if ($avgFatigue > 0.7 || $repeatRate > 0.4) {
            $score -= 25;
        } elseif ($avgFatigue > 0.5 || $repeatRate > 0.3) {
            $score -= 15;
        }

        // Diversity bonus (+15)
        $angleDiversity = (float) ($diversityMetrics['angle_diversity'] ?? 0.0);
        if ($angleDiversity > 0.6) {
            $score += 15;
        }

        // Noise risk penalty (-10)
        $avgNoiseRisk = (float) ($qualityMetrics['avg_noise_risk'] ?? 0.0);
        if ($avgNoiseRisk > 0.3) {
            $score -= 10;
        }

        // Clamp score
        $score = max(0, min(100, $score));

        // Map to recommendation
        if ($score >= 70) {
            $recommendation = 'go';
        } elseif ($score >= 40) {
            $recommendation = 'cautious_go';
        } else {
            $recommendation = 'avoid';
        }

        // Confidence based on sample size
        $confidence = min(0.9, ($sampleSize / 100));

        // Generate summary
        $summary = $this->generateDecisionSummary($recommendation, $velocityRatio, $avgFatigue, $repeatRate, $angleDiversity);

        return [
            'recommendation' => $recommendation,
            'opportunity_score' => (int) $score,
            'confidence' => round($confidence, 2),
            'summary' => $summary,
        ];
    }

    protected function generateDecisionSummary(string $recommendation, float $velocityRatio, float $avgFatigue, float $repeatRate, float $angleDiversity): string
    {
        if ($recommendation === 'go') {
            if ($velocityRatio > 1.5 && $angleDiversity > 0.6) {
                return 'Strong opportunity with rising demand and diverse angles to explore.';
            }
            return 'Favorable opportunity with good potential for differentiation.';
        }

        if ($recommendation === 'cautious_go') {
            if ($avgFatigue > 0.6 || $repeatRate > 0.3) {
                return 'Moderate opportunity but dominant patterns are saturated. Focus on white space angles.';
            }
            return 'Moderate opportunity. Requires strategic angle selection and differentiation.';
        }

        return 'Limited opportunity. High saturation and fatigue signals. Consider alternative topics or contrarian approaches.';
    }

    protected function identifyRisks(array $fatigueMetrics, array $qualityMetrics, array $diversityMetrics): array
    {
        $risks = [];

        $avgFatigue = (float) ($fatigueMetrics['avg_fatigue'] ?? 0.0);
        $repeatRate = (float) ($fatigueMetrics['repeat_rate_30d'] ?? 0.0);
        $avgNoiseRisk = (float) ($qualityMetrics['avg_noise_risk'] ?? 0.0);
        $angleDiversity = (float) ($diversityMetrics['angle_diversity'] ?? 0.0);

        if ($avgFatigue > 0.6 || $repeatRate > 0.3) {
            $risks[] = [
                'risk' => 'algorithm fatigue',
                'severity' => 'medium',
                'mitigation' => 'Pair with new proof elements, fresh formats, and specificity',
            ];
        }

        if ($avgNoiseRisk > 0.3) {
            $risks[] = [
                'risk' => 'noise and low-quality content',
                'severity' => 'medium',
                'mitigation' => 'Focus on high-signal angles with strong buyer quality',
            ];
        }

        if ($angleDiversity < 0.3) {
            $risks[] = [
                'risk' => 'homogeneous content landscape',
                'severity' => 'low',
                'mitigation' => 'Differentiate through contrarian angles or unique POV',
            ];
        }

        return $risks;
    }

    protected function selectEvidenceExcerpts(array $items, array $saturatedPatterns, array $whiteSpaceOpportunities, int $maxExamples): array
    {
        $excerpts = [];
        $exampleIds = [];

        // Collect IDs from saturated patterns (2 max)
        $saturatedIds = [];
        foreach (array_slice($saturatedPatterns, 0, 2) as $pattern) {
            $saturatedIds = array_merge($saturatedIds, (array) ($pattern['example_ids'] ?? []));
        }
        $saturatedIds = array_slice(array_unique($saturatedIds), 0, 2);

        // Collect IDs from white space (2 max)
        $whiteSpaceIds = [];
        foreach (array_slice($whiteSpaceOpportunities, 0, 2) as $opportunity) {
            $whiteSpaceIds = array_merge($whiteSpaceIds, (array) ($opportunity['example_ids'] ?? []));
        }
        $whiteSpaceIds = array_slice(array_unique($whiteSpaceIds), 0, 2);

        // Find highest quality items (2 max)
        $itemsByQuality = $items;
        usort($itemsByQuality, function($a, $b) {
            $qualityA = (float) (($a['creative'] ?? [])['buyer_quality_score'] ?? 0.0);
            $qualityB = (float) (($b['creative'] ?? [])['buyer_quality_score'] ?? 0.0);
            return $qualityB <=> $qualityA;
        });
        $highQualityIds = array_slice(array_map(fn($i) => (string) ($i['id'] ?? ''), $itemsByQuality), 0, 2);

        // Merge all IDs and deduplicate
        $exampleIds = array_unique(array_merge($saturatedIds, $whiteSpaceIds, $highQualityIds));
        $exampleIds = array_slice($exampleIds, 0, $maxExamples);

        // Build excerpts
        foreach ($items as $item) {
            $itemId = (string) ($item['id'] ?? '');
            if (in_array($itemId, $exampleIds, true)) {
                $text = (string) ($item['text'] ?? '');
                $preview = mb_substr($text, 0, 200);
                if (mb_strlen($text) > 200) {
                    $preview .= '...';
                }

                $excerpts[] = [
                    'source' => (string) ($item['source'] ?? 'other'),
                    'confidence' => round((float) (($item['creative'] ?? [])['buyer_quality_score'] ?? 0.5), 2),
                    'text' => $preview,
                    'content_id' => $itemId,
                ];
            }
        }

        return ['representative_excerpts' => $excerpts];
    }

    protected function classifyQuestion(string $question, ResearchOptions $options, LlmStageTracker $llmStages): array
    {
        try {
            $classification = $this->classifier->classify($question, [], $llmStages);
            $this->cgLogger->capture('research_classification', [
                'classification' => $classification,
            ]);
            return $classification;
        } catch (\Throwable $e) {
            try {
                $this->cgLogger->capture('classification_error', ['error' => $e->getMessage()]);
            } catch (\Throwable) {}
            return ['intent' => 'educational', 'funnel_stage' => 'tof'];
        }
    }

    protected function mapResearchIntent(string $stage): string
    {
        return match ($stage) {
            'deep_research' => 'research_analysis',
            'angle_hooks' => 'creative_exploration',
            'trend_discovery' => 'market_intelligence',
            'saturation_opportunity' => 'opportunity_assessment',
            default => 'research_analysis',
        };
    }

    protected function buildResearchSnapshotContext(array $options, array $debug = []): GenerationContext
    {
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
            creative_intelligence: [],
            snapshot: [
                'template_id' => null,
                'voice_profile_id' => null,
                'chunk_ids' => [],
                'fact_ids' => [],
                'swipe_ids' => [],
                'reference_ids' => [],
                'creative_intelligence' => [],
            ],
            debug: $debug,
            decision_trace: [],
            prompt_mutations: [],
            ci_rejections: [],
        );
    }

    protected function logResearchGuardrail(string $stage, string $composer, array $platforms, int $itemsConsidered): void
    {
        try {
            Log::info('ai.research.guardrail', [
                'stage' => $stage,
                'composer' => $composer,
                'platforms' => $platforms,
                'items_considered' => $itemsConsidered,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Cluster items by embedding similarity.
     * Returns items with cluster_id assigned and cluster summaries.
     *
     * @return array{items:array,clusters:array}
     */
    protected function clusterItems(array $items, float $threshold): array
    {
        $clusters = [];
        $miscItems = [];

        foreach ($items as $item) {
            $vector = $item['embedding'] ?? null;
            if (!is_array($vector) || empty($vector)) {
                $miscItems[] = $item;
                continue;
            }

            $assigned = false;
            foreach ($clusters as &$cluster) {
                $centroid = $cluster['centroid'] ?? null;
                if (!is_array($centroid) || empty($centroid)) {
                    continue;
                }
                $sim = $this->cosineSimilarity($vector, $centroid);
                if ($sim >= $threshold) {
                    $cluster['items'][] = $item;
                    $cluster['centroid'] = $this->averageVectors($cluster['centroid'], $vector, count($cluster['items']));
                    $cluster['avg_similarity'] = $this->recalculateAvgSimilarity($cluster['items'], $cluster['centroid']);
                    $assigned = true;
                    break;
                }
            }
            unset($cluster);

            if (!$assigned) {
                $clusters[] = [
                    'id' => 'c' . (count($clusters) + 1),
                    'items' => [$item],
                    'centroid' => $vector,
                    'avg_similarity' => 1.0,
                ];
            }
        }

        if (!empty($miscItems)) {
            $clusters[] = [
                'id' => 'misc',
                'items' => $miscItems,
                'centroid' => null,
                'avg_similarity' => 0.0,
            ];
        }

        $itemsWithCluster = [];
        foreach ($clusters as $cluster) {
            foreach ((array) $cluster['items'] as $item) {
                $item['cluster_id'] = (string) ($cluster['id'] ?? '');
                $itemsWithCluster[] = $item;
            }
        }

        $clusterSummaries = $this->summarizeClusters($clusters);

        return ['items' => $itemsWithCluster, 'clusters' => $clusterSummaries];
    }

    /**
     * Summarize clusters for prompt composition.
     */
    protected function summarizeClusters(array $clusters): array
    {
        $summaries = [];
        foreach ($clusters as $cluster) {
            $items = (array) ($cluster['items'] ?? []);
            $centroid = $cluster['centroid'] ?? null;
            $bySimilarity = $items;

            if (is_array($centroid) && !empty($centroid)) {
                usort($bySimilarity, function ($a, $b) use ($centroid) {
                    $simA = $this->cosineSimilarity((array) ($a['embedding'] ?? []), $centroid);
                    $simB = $this->cosineSimilarity((array) ($b['embedding'] ?? []), $centroid);
                    return $simB <=> $simA;
                });
            }

            $representative = [];
            foreach (array_slice($bySimilarity, 0, 2) as $item) {
                $representative[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'source' => (string) ($item['source'] ?? 'other'),
                    'text' => (string) ($item['text'] ?? ''),
                ];
            }

            $angles = $this->topCreativeField($items, 'angle');
            $hooks = $this->topCreativeField($items, 'hook_text');

            $summaries[] = [
                'id' => (string) ($cluster['id'] ?? ''),
                'size' => count($items),
                'avg_similarity' => (float) ($cluster['avg_similarity'] ?? 0.0),
                'dominant_angles' => $angles,
                'dominant_hooks' => $hooks,
                'representative_excerpts' => $representative,
            ];
        }

        return $summaries;
    }

    protected function topCreativeField(array $items, string $field): array
    {
        $counts = [];
        foreach ($items as $item) {
            $val = (string) ($item['creative'][$field] ?? '');
            $val = trim($val);
            if ($val === '') {
                continue;
            }
            $counts[$val] = ($counts[$val] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 3);
    }

    protected function averageVectors(array $centroid, array $vector, int $count): array
    {
        $dim = min(count($centroid), count($vector));
        if ($dim === 0 || $count <= 0) {
            return $centroid;
        }
        $out = $centroid;
        for ($i = 0; $i < $dim; $i++) {
            $out[$i] = (($centroid[$i] * ($count - 1)) + $vector[$i]) / $count;
        }
        return $out;
    }

    protected function recalculateAvgSimilarity(array $items, array $centroid): float
    {
        if (empty($items) || empty($centroid)) {
            return 0.0;
        }
        $sum = 0.0;
        $count = 0;
        foreach ($items as $item) {
            $vec = $item['embedding'] ?? null;
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $sum += $this->cosineSimilarity($vec, $centroid);
            $count++;
        }
        return $count > 0 ? ($sum / $count) : 0.0;
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        for ($i = 0; $i < $len; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }
        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    protected function summarizeCreativeSignals(array $items): array
    {
        $hookArchetypes = [];
        $emotionalDrivers = [];
        $audiencePersonas = [];
        $sophisticationLevels = [];

        foreach ($items as $item) {
            $creative = (array) ($item['creative'] ?? []);

            $hookArchetype = trim((string) ($creative['hook_archetype'] ?? ''));
            if ($hookArchetype !== '') {
                $hookArchetypes[$hookArchetype] = ($hookArchetypes[$hookArchetype] ?? 0) + 1;
            }

            $emotionalDriversArr = (array) ($creative['emotional_drivers'] ?? []);
            foreach ($emotionalDriversArr as $emotion) {
                $emotion = trim((string) $emotion);
                if ($emotion !== '') {
                    $emotionalDrivers[$emotion] = ($emotionalDrivers[$emotion] ?? 0) + 1;
                }
            }

            $audiencePersona = trim((string) ($creative['audience_persona'] ?? ''));
            if ($audiencePersona !== '') {
                $audiencePersonas[$audiencePersona] = ($audiencePersonas[$audiencePersona] ?? 0) + 1;
            }

            $sophisticationLevel = trim((string) ($creative['sophistication_level'] ?? ''));
            if ($sophisticationLevel !== '') {
                $sophisticationLevels[$sophisticationLevel] = ($sophisticationLevels[$sophisticationLevel] ?? 0) + 1;
            }
        }

        arsort($hookArchetypes);
        arsort($emotionalDrivers);
        arsort($audiencePersonas);
        arsort($sophisticationLevels);

        return [
            'hook_archetypes' => array_slice(array_keys($hookArchetypes), 0, 5),
            'emotional_drivers' => array_slice(array_keys($emotionalDrivers), 0, 5),
            'audience_personas' => array_slice(array_keys($audiencePersonas), 0, 5),
            'sophistication_levels' => array_slice(array_keys($sophisticationLevels), 0, 3),
            'total_creative_items' => count(array_filter($items, fn($i) => !empty($i['creative']['creative_unit_id'] ?? null))),
        ];
    }
}
