<?php

return [
    // AI-related tunables
    'retriever' => [
        // Retrieval ranking configuration (Eval Harness v1.3.0)
        // Use ranking-first retrieval with soft score floor.
        'top_n' => env('AI_RETRIEVER_TOP_N', 50),
        'return_k' => env('AI_RETRIEVER_RETURN_K', 5),

        // Composite score weights
        'weights' => [
            // Emphasize semantic proximity; soften non-distance penalties
            'distance' => env('AI_RETRIEVER_W_DISTANCE', 0.80),
            'authority' => env('AI_RETRIEVER_W_AUTHORITY', 0.05),
            'confidence' => env('AI_RETRIEVER_W_CONFIDENCE', 0.05),
            'time_horizon' => env('AI_RETRIEVER_W_TIME', 0.10),
        ],

        // Soft floor: drop only absurdly bad scores
        'soft_score_limit' => env('AI_RETRIEVER_SOFT_SCORE_LIMIT', 0.90),

        // Near-perfect distance dominance: bypass authority/time penalties
        // Any chunk at or below this distance should not be ranked out by heuristics
        'near_match_distance' => env('AI_RETRIEVER_NEAR_MATCH_DISTANCE', 0.10),

        // Legacy thresholds retained for backward compatibility (not used in ranking path)
        'strict_distance_threshold' => env('AI_RETRIEVER_STRICT_DISTANCE', 0.42),
        'fallback_distance_threshold' => env('AI_RETRIEVER_FALLBACK_DISTANCE', 0.50),

        // Sparse-Document Minimum Recall Guarantee (SD-MRG)
        'sparse_recall' => [
            'enabled' => env('AI_SPARSE_RECALL_ENABLED', true),
            // Treat knowledge items with chunk_count <= threshold as sparse
            'chunk_threshold' => env('AI_SPARSE_RECALL_CHUNK_THRESHOLD', 2),
            // Only allow injections if raw distance <= ceiling (lower is more similar)
            'distance_ceiling' => env('AI_SPARSE_RECALL_DISTANCE_CEILING', 0.20),
            // Cap number of injected chunks per retrieval call
            'max_injections' => env('AI_SPARSE_RECALL_MAX_INJECTIONS', 1),
        ],

        // Small-Dense Assist (anti-crowding protection)
        // For knowledge items with 3–6 chunks, allow a limited precision injection
        // separate from sparse recall to prevent cross-corpus crowding.
        'small_dense_assist' => [
            'enabled' => env('AI_SMALL_DENSE_ASSIST_ENABLED', true),
            'chunk_threshold_min' => env('AI_SMALL_DENSE_MIN', 3),
            'chunk_threshold_max' => env('AI_SMALL_DENSE_MAX', 6),
            'max_injections' => env('AI_SMALL_DENSE_MAX_INJECTIONS', 1),
            'distance_ceiling' => env('AI_SMALL_DENSE_DISTANCE_CEILING', 0.15),
        ],
    ],

    // Folder auto-scoping (embeddings)
    'folder_scope' => [
        'mode' => env('AI_FOLDER_SCOPE_MODE', 'off'), // off|auto|strict|augment
        'max_folders' => env('AI_FOLDER_SCOPE_MAX_FOLDERS', 2),
        'min_score' => env('AI_FOLDER_SCOPE_MIN_SCORE', 0.80),
        'allow_unscoped_fallback' => env('AI_FOLDER_SCOPE_ALLOW_UNSCOPED', true),
        'candidate_k' => env('AI_FOLDER_SCOPE_CANDIDATE_K', 10),
        'text_version' => env('AI_FOLDER_SCOPE_TEXT_VERSION', 1),
        'debounce_seconds' => env('AI_FOLDER_SCOPE_DEBOUNCE', 180),
        'sample_latest' => env('AI_FOLDER_SCOPE_SAMPLE_LATEST', 20),
        'sample_top' => env('AI_FOLDER_SCOPE_SAMPLE_TOP', 20),
        'evidence_max_chars' => env('AI_FOLDER_SCOPE_EVIDENCE_MAX_CHARS', 1000),
        'evidence_item_max_chars' => env('AI_FOLDER_SCOPE_EVIDENCE_ITEM_MAX_CHARS', 160),
    ],

    // Normalization gating and eligibility
    'normalization' => [
        'min_chars' => env('AI_NORMALIZATION_MIN_CHARS', 100),
        'min_quality' => env('AI_NORMALIZATION_MIN_QUALITY', 0.55),
        // ingestion_sources.source_type eligible for normalization
        'eligible_sources' => ['bookmark', 'text', 'file', 'transcript'],
    ],
    // Prompt registry for evaluation harness (optional overrides)
    'prompts' => [
        // Purpose key => relative file path
        'faithfulness_audit' => 'docs/ingestion-eval/prompts/faithfulness-audit.md',
        'synthetic_qa_min'   => 'docs/ingestion-eval/prompts/synthetic-qa-min.md',
    ],

    // Evaluation harness toggles (deterministic behavior)
    'eval' => [
        // When true, scope retrieval to the just-ingested knowledge item during eval runs
        'scope_to_knowledge_item' => env('AI_EVAL_SCOPE_TO_KI', true),
        // Grader mode for generation probe: 'contains' uses deterministic contains/normalization checks first,
        // 'llm' defers to LLM even if contains-check is inconclusive
        'grader_mode' => env('AI_EVAL_GRADER_MODE', 'contains'), // contains|llm
    ],

    // Prompt Insight Selection & Abstraction (ISA)
    'prompt_isa' => [
        'enabled' => env('AI_PROMPT_ISA_ENABLED', true),
        'max_insights' => env('AI_PROMPT_ISA_MAX_INSIGHTS', 3),
        'max_chunk_chars' => env('AI_PROMPT_ISA_MAX_CHUNK_CHARS', 600),
        'min_keyword_hits' => env('AI_PROMPT_ISA_MIN_KEYWORD_HITS', 1),
        'task_keywords_max' => env('AI_PROMPT_ISA_TASK_KEYWORDS_MAX', 12),
        'drop_if_contains' => ['###', '##', '```'],
        'strip_markdown' => true,
        'stopwords' => [
            'the','a','an','and','or','but','if','then','than','to','of','for','in','on','at','by','with','as','is','are','was','were','be','been','it','this','that','these','those','we','you','your','our','their','from','about','how','what','when','where','why','which','who','whom','can','could','should','would','will','may','might','do','does','did','not','no','yes','up','down','over','under','out','into','more','most','less','least','very','just','also','too','such'
        ],
    ],

    // Relevance gate (retrieval-time)
    'relevance_gate' => [
        'enabled' => env('AI_RELEVANCE_GATE_ENABLED', true),
        'min_confidence' => env('AI_RELEVANCE_GATE_MIN_CONF', 0.4),
        'max_chunk_tokens' => env('AI_RELEVANCE_GATE_MAX_TOKENS', 200),
        'llm' => [
            'enabled' => env('AI_RELEVANCE_GATE_LLM_ENABLED', false),
            'batch_size' => env('AI_RELEVANCE_GATE_LLM_BATCH', 6),
            'max_output_tokens' => env('AI_RELEVANCE_GATE_LLM_MAX_OUTPUT', 200),
        ],
    ],

    // Context composition policy
    'context' => [
        'max_angles' => env('AI_CONTEXT_MAX_ANGLES', 1),
        'max_examples' => env('AI_CONTEXT_MAX_EXAMPLES', 1),
        'require_fact_for_angles' => env('AI_CONTEXT_REQUIRE_FACT_FOR_ANGLES', true),
    ],

    // Creative Intelligence (CI) vector retrieval
    'ci' => [
        'vector' => [
            'enabled' => env('AI_CI_VECTOR_ENABLED', false),
            'shadow_mode' => env('AI_CI_VECTOR_SHADOW_MODE', false),
            'k' => env('AI_CI_VECTOR_K', 50),
            'min_results' => env('AI_CI_VECTOR_MIN_RESULTS', 8),
            'max_candidates' => env('AI_CI_VECTOR_MAX_CANDIDATES', 2000),
            'chunk_size' => env('AI_CI_VECTOR_CHUNK_SIZE', 200),
        ],
    ],

    // Research mode settings
    'research' => [
        'candidate_limit' => env('AI_RESEARCH_CANDIDATE_LIMIT', 800),
        'cluster_similarity' => env('AI_RESEARCH_CLUSTER_SIMILARITY', 0.82),
    ],

    // Per-stage LLM model selection
    // These are defaults; per-request overrides can still be provided via options['model'] or options['models'].
    'models' => [
        // Non-generative
        'classification' => env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku')),
        'relevance_gate' => env('AI_MODEL_RELEVANCE_GATE', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),

        // Generative pipeline
        'generation' => [
            'primary' => env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet'),
            'fallback' => env('AI_MODEL_GENERATION_FALLBACK', env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet')),
            'repair' => env('AI_MODEL_GENERATION_REPAIR', env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet')),
            'replay' => env('AI_MODEL_GENERATION_REPLAY', env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet')),
            'reflexion_critique' => env('AI_MODEL_REFLEXION_CRITIQUE', env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet')),
            'reflexion_refine' => env('AI_MODEL_REFLEXION_REFINE', env('AI_MODEL_GENERATION_PRIMARY', 'anthropic/claude-3.5-sonnet')),
        ],

        // Ingestion / parsing
        'ingestion' => [
            'normalize_knowledge_item' => env('AI_MODEL_NORMALIZE_KI', 'anthropic/claude-3.5-sonnet'),
            'classify_knowledge_chunks' => env('AI_MODEL_CLASSIFY_KNOWLEDGE_CHUNKS', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
            'template_parse' => env('AI_MODEL_TEMPLATE_PARSE', 'anthropic/claude-3.5-sonnet'),
            'infer_context_folder' => env('AI_MODEL_INFER_CONTEXT_FOLDER', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
            'score_folder_candidates' => env('AI_MODEL_SCORE_FOLDER_CANDIDATES', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
        ],

        // Evaluation harness (LLM grader is optional depending on ai.eval.grader_mode)
        'eval' => [
            'faithfulness_audit' => env('AI_MODEL_EVAL_FAITHFULNESS', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
            'synthetic_qa_min' => env('AI_MODEL_EVAL_SYNTHETIC_QA', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
            'generation_grader' => env('AI_MODEL_EVAL_GRADER', env('AI_MODEL_CLASSIFICATION', env('OPENROUTER_CLASSIFIER_MODEL', 'anthropic/claude-3.5-haiku'))),
        ],

        // Social Watcher: YouTube research creative extraction
        'youtube_research_creative' => env('AI_MODEL_YOUTUBE_RESEARCH_CREATIVE', 'x-ai/grok-4-fast'),
    ],
];
