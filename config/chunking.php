<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for knowledge item chunking pipeline
    |
    */

    // Preflight gating thresholds
    'min_clean_chars' => env('CHUNKING_MIN_CLEAN_CHARS', 80),
    'min_clean_tokens_est' => env('CHUNKING_MIN_CLEAN_TOKENS_EST', 20),
    
    // Format detection
    'short_post_max_tokens' => env('CHUNKING_SHORT_POST_MAX_TOKENS', 60),
    
    // Chunk validation
    'max_chunk_tokens' => env('CHUNKING_MAX_CHUNK_TOKENS', 120),
    'max_chunks_per_item' => env('CHUNKING_MAX_CHUNKS_PER_ITEM', 20),
    
    // Duplicate detection (optional)
    'enable_duplicate_skip' => env('CHUNKING_ENABLE_DUPLICATE_SKIP', false),
];
