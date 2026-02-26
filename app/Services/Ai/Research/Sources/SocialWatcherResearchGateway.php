<?php

namespace App\Services\Ai\Research\Sources;

use App\Services\Ai\Research\DTO\ResearchOptions;
use App\Services\Ai\Research\DTO\SocialEvidenceItem;
use App\Services\Ai\Research\DTO\SocialClusterEvidence;
use App\Services\Ai\Research\Embeddings\ResearchQueryEmbeddingService;
use App\Services\Ai\Research\Mappers\SocialWatcherEvidenceMapper;
use LaundryOS\SocialWatcher\Models\ContentNode;
use LaundryOS\SocialWatcher\Models\ContentFragment;
use LaundryOS\SocialWatcher\Models\ContentAnnotation;
use LaundryOS\SocialWatcher\Models\AnnotationCluster;
use LaundryOS\SocialWatcher\Models\Embedding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SocialWatcherResearchGateway - Canonical Social Watcher integration for research mode
 * 
 * Single gateway for all reads from canonical Social Watcher tables.
 * Replaces legacy Social Watcher repository/model usage in research mode.
 * 
 * Responsibilities:
 * - Semantic retrieval (query vector → evidence items)
 * - Cluster retrieval (hooks/angles/formats)
 * - Conversation retrieval (cluster → linked content)
 * - Filtering (platform, time window, similarity threshold)
 */
class SocialWatcherResearchGateway
{
    public function __construct(
        protected ResearchQueryEmbeddingService $embeddingService,
        protected SocialWatcherEvidenceMapper $mapper,
    ) {}

    /**
     * Search for evidence using semantic similarity
     * 
     * @param string $orgId Organization ID
     * @param string $query Search query
     * @param ResearchOptions $opts Research options with filters
     * @return SocialEvidenceItem[]
     */
    public function searchEvidence(string $orgId, string $query, ResearchOptions $opts): array
    {
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        
        if (!$useCanonical) {
            Log::debug('SocialWatcherResearchGateway: canonical reader disabled, returning empty');
            return [];
        }

        try {
            // Generate query embedding
            $queryVector = $this->embeddingService->embed($orgId, $query);
            
            // Determine what to search: prefer fragments, fall back to nodes
            $mediaTypes = $opts->mediaTypes;
            $searchFragments = empty($mediaTypes) || in_array('research_fragment', $mediaTypes);
            $searchNodes = empty($mediaTypes) || in_array('post', $mediaTypes);
            
            $results = [];
            
            // Search fragments first (preferred for research)
            if ($searchFragments) {
                $fragmentResults = $this->searchFragmentsByVector(
                    $orgId,
                    $queryVector,
                    $opts->limit,
                    $opts
                );
                $results = array_merge($results, $fragmentResults);
            }
            
            // Search nodes if needed
            if ($searchNodes && count($results) < $opts->limit) {
                $remaining = $opts->limit - count($results);
                $nodeResults = $this->searchNodesByVector(
                    $orgId,
                    $queryVector,
                    $remaining,
                    $opts
                );
                $results = array_merge($results, $nodeResults);
            }
            
            // Sort by similarity and limit
            usort($results, fn($a, $b) => ($b->debug['similarity'] ?? 0) <=> ($a->debug['similarity'] ?? 0));
            
            return array_slice($results, 0, $opts->limit);
            
        } catch (\Throwable $e) {
            Log::error('SocialWatcherResearchGateway::searchEvidence failed', [
                'error' => $e->getMessage(),
                'org_id' => $orgId,
                'query_length' => strlen($query),
            ]);
            
            return [];
        }
    }

    /**
     * Get clusters for requested types (ci_hook, ci_angle, ci_format)
     * 
     * @param string $orgId Organization ID
     * @param string $clusterType Type: ci_hook, ci_angle, ci_format, topic
     * @param ResearchOptions $opts Research options
     * @return SocialClusterEvidence[]
     */
    public function getClusters(string $orgId, string $clusterType, ResearchOptions $opts): array
    {
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        
        if (!$useCanonical) {
            Log::debug('SocialWatcherResearchGateway: canonical reader disabled, returning empty clusters');
            return [];
        }

        try {
            // Query annotation clusters of requested type, scoped to organization
            $query = AnnotationCluster::where('annotation_type', $clusterType)
                ->forOrganization($orgId)
                ->active() // Only get latest/active clusters
                ->orderByDesc('member_count')
                ->limit($opts->limit);
            
            // Apply platform filter if specified
            if (!empty($opts->platforms)) {
                $query->where(function ($q) use ($opts) {
                    foreach ($opts->platforms as $platform) {
                        $q->orWhereJsonContains('metadata->platforms', $platform);
                    }
                });
            }
            
            $clusters = $query->get();
            
            // Load representative members for each cluster
            $clusterEvidence = [];
            foreach ($clusters as $cluster) {
                $members = $this->getClusterMembers($cluster, $opts->maxExamples);
                // Pass the array of models, not array-converted data
                $clusterEvidence[] = $this->mapper->mapCluster($cluster, $members->all());
            }
            
            return $clusterEvidence;
            
        } catch (\Throwable $e) {
            Log::error('SocialWatcherResearchGateway::getClusters failed', [
                'error' => $e->getMessage(),
                'org_id' => $orgId,
                'cluster_type' => $clusterType,
            ]);
            
            return [];
        }
    }

    /**
     * Get conversation/context for a cluster (linked nodes/fragments)
     * 
     * @param string $orgId Organization ID
     * @param string $clusterId Cluster ID
     * @param ResearchOptions $opts Research options
     * @return SocialEvidenceItem[]
     */
    public function getClusterConversation(string $orgId, string $clusterId, ResearchOptions $opts): array
    {
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        
        if (!$useCanonical) {
            return [];
        }

        try {
            // Ensure cluster belongs to the organization
            $cluster = AnnotationCluster::forOrganization($orgId)->find($clusterId);
            
            if (!$cluster) {
                return [];
            }
            
            // Get members (annotations or fragments depending on cluster_type)
            $members = $cluster->members();
            
            // Get associated nodes
            if ($cluster->isFragmentCluster()) {
                $nodeIds = $members->pluck('content_node_id')->unique()->toArray();
            } else {
                $nodeIds = $members->pluck('content_node_id')->unique()->toArray();
            }
            
            $nodes = ContentNode::whereIn('id', $nodeIds)
                ->limit($opts->limit)
                ->get();
            
            return $this->mapper->mapNodes($nodes);
            
        } catch (\Throwable $e) {
            Log::error('SocialWatcherResearchGateway::getClusterConversation failed', [
                'error' => $e->getMessage(),
                'cluster_id' => $clusterId,
            ]);
            
            return [];
        }
    }

    /**
     * Search fragments by vector similarity
     * 
     * Note: Searches CI embeddings (ci_hook, ci_angle, ci_format, ci_topic)
     * which are linked directly to content nodes.
     */
    protected function searchFragmentsByVector(
        string $orgId,
        array $queryVector,
        int $limit,
        ResearchOptions $opts
    ): array {
        // Build SQL for cosine similarity using pgvector
        $vectorSql = '[' . implode(',', $queryVector) . ']';
        
        // CI embeddings are linked directly to content_node_id
        $query = DB::table('sw_embeddings as e')
            ->select([
                'e.id as embedding_id',
                'e.purpose',
                'n.id as node_id',
                DB::raw("1 - (e.vector <=> '{$vectorSql}'::vector) as similarity"),
            ])
            ->join('sw_content_nodes as n', 'e.content_node_id', '=', 'n.id')
            // Search across all CI embedding types for best semantic match
            ->whereIn('e.purpose', ['ci_hook', 'ci_angle', 'ci_format', 'ci_topic', 'search'])
            ->whereNotNull('e.content_node_id');
        
        // Apply platform filter
        if (!empty($opts->platforms)) {
            $query->whereIn('n.platform', $opts->platforms);
        }
        
        // Apply time window filter
        if (!empty($opts->timeWindows)) {
            $recentDays = $opts->timeWindows['recent_days'] ?? null;
            if ($recentDays) {
                $query->where('n.published_at', '>=', now()->subDays($recentDays));
            }
        }
        
        // Order by similarity and limit
        $query->orderByDesc('similarity')
            ->limit($limit * 3); // Fetch more to handle deduplication
        
        $rows = $query->get();
        
        // Load full node models
        // Note: Don't load 'parsed' - all data is directly on sw_content_nodes
        // The relation is called 'contentAnnotations' not 'annotations'
        $nodeIds = $rows->pluck('node_id')->unique()->toArray();
        $nodes = ContentNode::whereIn('id', $nodeIds)
            ->get()
            ->keyBy('id');
        
        // Map to evidence items with similarity scores
        // Deduplicate by node_id (same node may have multiple matching embeddings)
        $items = [];
        $seenNodes = [];
        foreach ($rows as $row) {
            if (isset($seenNodes[$row->node_id])) {
                continue; // Skip duplicate nodes, keep highest similarity match
            }
            $seenNodes[$row->node_id] = true;
            
            $node = $nodes[$row->node_id] ?? null;
            if ($node) {
                $items[] = $this->mapper->mapNode($node, [
                    'similarity' => (float) $row->similarity,
                    'embedding_id' => $row->embedding_id,
                    'match_purpose' => $row->purpose,
                    'match_type' => 'semantic',
                ]);
            }
            
            if (count($items) >= $limit) {
                break;
            }
        }
        
        return $items;
    }

    /**
     * Search nodes by vector similarity
     * 
     * Note: Searches CI embeddings linked directly to content nodes.
     */
    protected function searchNodesByVector(
        string $orgId,
        array $queryVector,
        int $limit,
        ResearchOptions $opts
    ): array {
        // Build SQL for cosine similarity
        $vectorSql = '[' . implode(',', $queryVector) . ']';
        
        // CI embeddings are linked directly to content_node_id
        $query = DB::table('sw_embeddings as e')
            ->select([
                'e.id as embedding_id',
                'e.purpose',
                'n.id as node_id',
                DB::raw("1 - (e.vector <=> '{$vectorSql}'::vector) as similarity"),
            ])
            ->join('sw_content_nodes as n', 'e.content_node_id', '=', 'n.id')
            // Search across all CI embedding types
            ->whereIn('e.purpose', ['ci_hook', 'ci_angle', 'ci_format', 'ci_topic', 'search'])
            ->whereNotNull('e.content_node_id');
        
        // Apply platform filter
        if (!empty($opts->platforms)) {
            $query->whereIn('n.platform', $opts->platforms);
        }
        
        // Apply time window filter
        if (!empty($opts->timeWindows)) {
            $recentDays = $opts->timeWindows['recent_days'] ?? null;
            if ($recentDays) {
                $query->where('n.published_at', '>=', now()->subDays($recentDays));
            }
        }
        
        // Order by similarity and limit
        $query->orderByDesc('similarity')
            ->limit($limit * 3); // Fetch more to handle deduplication
        
        $rows = $query->get();
        
        // Load full node models without relationships
        // Note: Don't load 'parsed' - all data is directly on sw_content_nodes
        $nodeIds = $rows->pluck('node_id')->unique()->toArray();
        $nodes = ContentNode::whereIn('id', $nodeIds)
            ->get()
            ->keyBy('id');
        
        // Map to evidence items with similarity scores
        // Deduplicate by node_id
        $items = [];
        $seenNodes = [];
        foreach ($rows as $row) {
            if (isset($seenNodes[$row->node_id])) {
                continue;
            }
            $seenNodes[$row->node_id] = true;
            
            $node = $nodes[$row->node_id] ?? null;
            if ($node) {
                $items[] = $this->mapper->mapNode($node, [
                    'similarity' => (float) $row->similarity,
                    'embedding_id' => $row->embedding_id,
                    'match_purpose' => $row->purpose,
                    'match_type' => 'semantic',
                ]);
            }
            
            if (count($items) >= $limit) {
                break;
            }
        }
        
        return $items;
    }

    /**
     * Get representative members for a cluster
     */
    protected function getClusterMembers(AnnotationCluster $cluster, int $limit = 6): \Illuminate\Support\Collection
    {
        if (empty($cluster->member_ids)) {
            return collect();
        }
        
        // Get annotations with their nodes for context
        // Note: Don't load 'contentNode.parsed' - that relation doesn't exist,
        // all data is directly on sw_content_nodes columns
        return ContentAnnotation::with(['contentNode'])
            ->whereIn('id', array_slice($cluster->member_ids, 0, $limit))
            ->get();
    }

    /**
     * Get recent trending content (for trend discovery)
     * 
     * @param string $orgId
     * @param ResearchOptions $opts
     * @return SocialEvidenceItem[]
     */
    public function getRecentTrending(string $orgId, ResearchOptions $opts): array
    {
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        
        if (!$useCanonical) {
            return [];
        }

        try {
            $recentDays = $opts->trendRecentDays ?? 7;
            
            // Don't load 'parsed' - data is directly on sw_content_nodes
            $query = ContentNode::where('org_id', $orgId)
                ->where('published_at', '>=', now()->subDays($recentDays))
                ->orderByDesc('published_at');
            
            // Apply platform filter
            if (!empty($opts->platforms)) {
                $query->whereIn('platform', $opts->platforms);
            }
            
            // Optionally order by engagement if metrics are available
            $nodes = $query->limit($opts->trendLimit ?? 20)->get();
            
            return $this->mapper->mapNodes($nodes, [
                'match_type' => 'recent_trending',
            ]);
            
        } catch (\Throwable $e) {
            Log::error('SocialWatcherResearchGateway::getRecentTrending failed', [
                'error' => $e->getMessage(),
                'org_id' => $orgId,
            ]);
            
            return [];
        }
    }
}
