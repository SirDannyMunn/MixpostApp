<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vector-Searchable Chunk Roles
    |--------------------------------------------------------------------------
    |
    | Roles eligible for similarity retrieval. Only chunks with these roles
    | will be returned by primary vector search operations.
    |
    */
    'vector_searchable_roles' => [
        'strategic_claim',
        'heuristic',
        'causal_claim',
        'definition',
        // 'instruction', // Optionally include if needed for task-specific retrieval
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Chunk Roles
    |--------------------------------------------------------------------------
    |
    | Roles that should never be returned by similarity search. These may be
    | used for enrichment or grouping but are not suitable as primary
    | retrieval candidates.
    |
    */
    'excluded_roles' => [
        'metric',
        'example',
        'quote',
        'raw',
        'meta',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Boosts
    |--------------------------------------------------------------------------
    |
    | Optional weighting/boosting applied after vector similarity scoring.
    | Higher values increase the rank of chunks with that role.
    |
    */
    'role_boosts' => [
        'strategic_claim' => 1.10,
        'causal_claim'    => 1.05,
        'heuristic'       => 1.00,
        'definition'      => 0.95,
        // 'instruction'  => 0.90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum Thresholds
    |--------------------------------------------------------------------------
    |
    | Minimum token count and character count to avoid tiny fragments
    | polluting retrieval results.
    |
    */
    'min_token_count' => env('AI_CHUNK_MIN_TOKEN_COUNT', 12),
    'min_char_count'  => env('AI_CHUNK_MIN_CHAR_COUNT', 60),

    /*
    |--------------------------------------------------------------------------
    | Maximum Chunks
    |--------------------------------------------------------------------------
    |
    | Maximum number of chunks to return from retrieval
    |
    */
    'max_chunks' => env('AI_CHUNK_MAX_CHUNKS', 12),

    /*
    |--------------------------------------------------------------------------
    | Enrichment Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for optional enrichment retrieval (metrics, examples, etc.)
    |
    */
    'enrichment' => [
        'enabled' => env('AI_CHUNK_ENRICHMENT_ENABLED', false),
        'roles' => ['metric', 'instruction'],
        'max_per_item' => 3,
        'max_total' => 10,
    ],
];
