<?php

namespace App\Services\Ai\Research\Embeddings;

use Illuminate\Support\Facades\Cache;
use App\Services\Ai\EmbeddingsService;

/**
 * ResearchQueryEmbeddingService - Generate embeddings for research queries
 * 
 * Handles query embedding generation with caching to reduce costs.
 * Research mode should never generate embeddings itself; it should always
 * use this service.
 */
class ResearchQueryEmbeddingService
{
    public function __construct(
        protected EmbeddingsService $embeddings,
    ) {}

    /**
     * Generate embedding for a research query
     * 
     * @param string $orgId Organization ID for caching scope
     * @param string $query Query text to embed
     * @param string $model Model to use (default: text-embedding-3-small)
     * @return array Vector array
     */
    public function embed(string $orgId, string $query, string $model = 'text-embedding-3-small'): array
    {
        $cacheKey = $this->getCacheKey($orgId, $query, $model);
        
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($query) {
            return $this->generateEmbedding($query);
        });
    }

    /**
     * Generate embedding without caching (for testing)
     */
    public function embedFresh(string $query, string $model = 'text-embedding-3-small'): array
    {
        return $this->generateEmbedding($query);
    }

    /**
     * Clear cached embedding for a query
     */
    public function clearCache(string $orgId, string $query, string $model = 'text-embedding-3-small'): void
    {
        $cacheKey = $this->getCacheKey($orgId, $query, $model);
        Cache::forget($cacheKey);
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $orgId, string $query, string $model): string
    {
        $hash = hash('sha256', $query);
        return "research:embedding:{$orgId}:{$model}:{$hash}";
    }

    /**
     * Actually generate the embedding via EmbeddingsService
     */
    protected function generateEmbedding(string $query): array
    {
        try {
            $vector = $this->embeddings->embedOne($query);
            
            if (!is_array($vector) || empty($vector)) {
                throw new \RuntimeException('Empty embedding returned');
            }
            
            return $vector;
        } catch (\Throwable $e) {
            \Log::error('Research query embedding generation failed', [
                'error' => $e->getMessage(),
                'query_length' => strlen($query),
            ]);
            
            throw $e;
        }
    }

    /**
     * Batch embed multiple queries (for efficiency)
     * 
     * @param string $orgId
     * @param array $queries
     * @param string $model
     * @return array Map of query => vector
     */
    public function embedBatch(string $orgId, array $queries, string $model = 'text-embedding-3-small'): array
    {
        $results = [];
        $toEmbed = [];
        
        // Check cache first
        foreach ($queries as $query) {
            $cacheKey = $this->getCacheKey($orgId, $query, $model);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                $results[$query] = $cached;
            } else {
                $toEmbed[] = $query;
            }
        }
        
        // Generate missing embeddings
        foreach ($toEmbed as $query) {
            $embedding = $this->embed($orgId, $query, $model);
            $results[$query] = $embedding;
        }
        
        return $results;
    }
}
