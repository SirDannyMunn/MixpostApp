<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Social Watcher Reader Mode
    |--------------------------------------------------------------------------
    |
    | Controls which Social Watcher data source research mode uses:
    | - 'legacy': Use legacy sw_normalized_content and related tables
    | - 'canonical': Use canonical sw_content_nodes, sw_content_fragments,
    |   sw_content_annotations, sw_embeddings, and sw_annotation_clusters
    |
    | Set to 'legacy' initially, then flip to 'canonical' after verification.
    |
    */
    'social_watcher_reader' => env('RESEARCH_SOCIAL_WATCHER_READER', 'canonical'),

    /*
    |--------------------------------------------------------------------------
    | Research Cluster Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for cluster-based research features
    |
    */
    'cluster_similarity' => env('RESEARCH_CLUSTER_SIMILARITY', 0.75),
    
    /*
    |--------------------------------------------------------------------------
    | Research Query Embedding Cache
    |--------------------------------------------------------------------------
    |
    | TTL for cached query embeddings (in hours)
    |
    */
    'query_embedding_cache_ttl' => env('RESEARCH_QUERY_EMBEDDING_CACHE_TTL', 24),
];
