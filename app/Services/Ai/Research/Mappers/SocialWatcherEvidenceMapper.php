<?php

namespace App\Services\Ai\Research\Mappers;

use App\Services\Ai\Research\DTO\SocialEvidenceItem;
use App\Services\Ai\Research\DTO\SocialClusterEvidence;
use LaundryOS\SocialWatcher\Models\ContentNode;
use LaundryOS\SocialWatcher\Models\ContentFragment;
use LaundryOS\SocialWatcher\Models\ContentAnnotation;
use LaundryOS\SocialWatcher\Models\AnnotationCluster;

/**
 * SocialWatcherEvidenceMapper - Convert canonical SW models to evidence DTOs
 * 
 * Centralizes mapping logic from canonical Social Watcher models to
 * research-friendly evidence DTOs.
 */
class SocialWatcherEvidenceMapper
{
    /**
     * Map ContentNode to SocialEvidenceItem
     */
    public function mapNode(ContentNode $node, array $debug = []): SocialEvidenceItem
    {
        return SocialEvidenceItem::fromContentNode($node, $debug);
    }

    /**
     * Map ContentFragment to SocialEvidenceItem
     */
    public function mapFragment(ContentFragment $fragment, array $debug = []): SocialEvidenceItem
    {
        return SocialEvidenceItem::fromContentFragment($fragment, $debug);
    }

    /**
     * Map ContentAnnotation to SocialEvidenceItem
     */
    public function mapAnnotation(ContentAnnotation $annotation, array $debug = []): SocialEvidenceItem
    {
        return SocialEvidenceItem::fromContentAnnotation($annotation, $debug);
    }

    /**
     * Map AnnotationCluster to SocialClusterEvidence
     * 
     * @param AnnotationCluster $cluster
     * @param array $memberAnnotations Array of ContentAnnotation models
     * @param array $debug
     * @return SocialClusterEvidence
     */
    public function mapCluster(
        AnnotationCluster $cluster,
        array $memberAnnotations = [],
        array $debug = []
    ): SocialClusterEvidence {
        // Map member annotations to evidence items
        $exampleItems = array_map(
            fn($annotation) => $this->mapAnnotation($annotation, [
                'cluster_member' => true,
                'cluster_id' => $cluster->id,
            ]),
            $memberAnnotations
        );

        return SocialClusterEvidence::fromAnnotationCluster($cluster, $exampleItems, $debug);
    }

    /**
     * Batch map nodes
     * 
     * @param iterable $nodes
     * @param array $debugMap Map of node_id => debug data
     * @return SocialEvidenceItem[]
     */
    public function mapNodes(iterable $nodes, array $debugMap = []): array
    {
        $items = [];
        foreach ($nodes as $node) {
            $debug = $debugMap[$node->id] ?? [];
            $items[] = $this->mapNode($node, $debug);
        }
        return $items;
    }

    /**
     * Batch map fragments
     * 
     * @param iterable $fragments
     * @param array $debugMap Map of fragment_id => debug data
     * @return SocialEvidenceItem[]
     */
    public function mapFragments(iterable $fragments, array $debugMap = []): array
    {
        $items = [];
        foreach ($fragments as $fragment) {
            $debug = $debugMap[$fragment->id] ?? [];
            $items[] = $this->mapFragment($fragment, $debug);
        }
        return $items;
    }

    /**
     * Batch map annotations
     * 
     * @param iterable $annotations
     * @param array $debugMap Map of annotation_id => debug data
     * @return SocialEvidenceItem[]
     */
    public function mapAnnotations(iterable $annotations, array $debugMap = []): array
    {
        $items = [];
        foreach ($annotations as $annotation) {
            $debug = $debugMap[$annotation->id] ?? [];
            $items[] = $this->mapAnnotation($annotation, $debug);
        }
        return $items;
    }

    /**
     * Batch map clusters
     * 
     * @param iterable $clusters
     * @param array $memberMap Map of cluster_id => array of member annotations
     * @param array $debugMap Map of cluster_id => debug data
     * @return SocialClusterEvidence[]
     */
    public function mapClusters(
        iterable $clusters,
        array $memberMap = [],
        array $debugMap = []
    ): array {
        $items = [];
        foreach ($clusters as $cluster) {
            $members = $memberMap[$cluster->id] ?? [];
            $debug = $debugMap[$cluster->id] ?? [];
            $items[] = $this->mapCluster($cluster, $members, $debug);
        }
        return $items;
    }

    /**
     * Map mixed embedding results to evidence items
     * 
     * Handles results from semantic search that may contain nodes, fragments, or annotations.
     * 
     * @param array $embeddingResults Results from embedding search with embeddable_type/embeddable_id
     * @return SocialEvidenceItem[]
     */
    public function mapEmbeddingResults(array $embeddingResults): array
    {
        $items = [];
        
        foreach ($embeddingResults as $result) {
            $embeddable = $result['embeddable'] ?? null;
            $similarity = $result['similarity'] ?? 0.0;
            $embeddingId = $result['embedding_id'] ?? null;
            
            if (!$embeddable) {
                continue;
            }

            $debug = [
                'similarity' => $similarity,
                'embedding_id' => $embeddingId,
                'match_type' => 'semantic',
            ];

            $item = match (true) {
                $embeddable instanceof ContentNode => $this->mapNode($embeddable, $debug),
                $embeddable instanceof ContentFragment => $this->mapFragment($embeddable, $debug),
                $embeddable instanceof ContentAnnotation => $this->mapAnnotation($embeddable, $debug),
                default => null,
            };

            if ($item) {
                $items[] = $item;
            }
        }
        
        return $items;
    }
}
