<?php

namespace App\Services\Ai\Research\DTO;

/**
 * SocialClusterEvidence - Cluster-based evidence for research mode
 * 
 * Represents a cluster of related content (hooks/angles/formats/topics)
 * with representative examples attached.
 */
class SocialClusterEvidence
{
    public function __construct(
        public readonly string $clusterId,
        public readonly string $clusterType,
        public readonly ?string $label,
        public readonly ?string $summary,
        public readonly ?float $score,
        public readonly array $exampleItems,
        public readonly array $debug,
    ) {}

    /**
     * Convenience accessor - alias for label
     */
    public function getName(): ?string
    {
        return $this->label;
    }

    /**
     * Convenience accessor - get member count from debug data
     */
    public function getMemberCount(): int
    {
        return $this->debug['member_count'] ?? count($this->exampleItems);
    }

    /**
     * Magic getter for name and memberCount (for backwards compatibility)
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'name' => $this->getName(),
            'memberCount' => $this->getMemberCount(),
            default => null,
        };
    }

    /**
     * Create from canonical AnnotationCluster
     */
    public static function fromAnnotationCluster(
        object $cluster,
        array $exampleItems = [],
        array $debug = []
    ): self {
        return new self(
            clusterId: (string) $cluster->id,
            // Use annotation_type as clusterType (canonical model uses annotation_type)
            clusterType: (string) ($cluster->annotation_type ?? $cluster->cluster_type ?? 'unknown'),
            label: $cluster->label,
            summary: $cluster->summary ?? null,
            score: $cluster->score ?? null,
            exampleItems: $exampleItems,
            debug: array_merge([
                'member_count' => $cluster->member_count ?? count($exampleItems),
                'centroid_embedding_id' => $cluster->centroid_embedding_id ?? null,
            ], $debug),
        );
    }

    /**
     * Convert to array (for legacy compatibility)
     */
    public function toArray(): array
    {
        return [
            'cluster_id' => $this->clusterId,
            'cluster_type' => $this->clusterType,
            'label' => $this->label ?? '',
            'summary' => $this->summary ?? '',
            'score' => $this->score,
            'member_count' => $this->debug['member_count'] ?? count($this->exampleItems),
            'examples' => array_map(fn($item) => $item->toArray(), $this->exampleItems),
            'debug' => $this->debug,
        ];
    }

    /**
     * Get example items as SocialEvidenceItem instances
     * 
     * @return SocialEvidenceItem[]
     */
    public function getExamples(): array
    {
        return $this->exampleItems;
    }

    /**
     * Get representative text from examples
     */
    public function getRepresentativeTexts(int $limit = 3): array
    {
        return array_slice(
            array_map(fn($item) => $item->text, $this->exampleItems),
            0,
            $limit
        );
    }
}
