<?php

namespace App\Services\Ai\Research\DTO;

/**
 * SocialEvidenceItem - Unified evidence DTO for research mode
 * 
 * Represents a single piece of social content (node/fragment/annotation)
 * used as evidence in research reports, regardless of underlying table structure.
 */
class SocialEvidenceItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $platform,
        public readonly string $contentType,
        public readonly ?string $url,
        public readonly ?string $title,
        public readonly string $text,
        public readonly ?string $authorUsername,
        public readonly ?\DateTimeInterface $publishedAt,
        public readonly ?array $metrics,
        public readonly array $source,
        public readonly array $debug,
    ) {}

    /**
     * Create from canonical ContentNode
     */
    public static function fromContentNode(object $node, array $debug = []): self
    {
        return new self(
            id: (string) $node->id,
            kind: 'content_node',
            platform: (string) ($node->platform ?? 'generic'),
            contentType: (string) ($node->content_type ?? 'post'),
            url: $node->url,
            title: $node->title,
            text: (string) $node->text,
            authorUsername: $node->author_username,
            publishedAt: $node->published_at,
            metrics: self::extractMetrics($node),
            source: self::extractSource($node),
            debug: $debug,
        );
    }

    /**
     * Create from canonical ContentFragment
     */
    public static function fromContentFragment(object $fragment, array $debug = []): self
    {
        $node = $fragment->contentNode ?? $fragment->node ?? null;
        
        return new self(
            id: (string) $fragment->id,
            kind: 'fragment',
            platform: (string) ($node->platform ?? 'generic'),
            contentType: (string) ($fragment->fragment_type ?? 'summary'),
            url: $node->url ?? null,
            title: $node->title ?? null,
            text: (string) $fragment->text,
            authorUsername: $node->author_username ?? null,
            publishedAt: $node->published_at ?? null,
            metrics: self::extractMetrics($node),
            source: self::extractSource($node),
            debug: array_merge(['fragment_type' => $fragment->fragment_type], $debug),
        );
    }

    /**
     * Create from canonical ContentAnnotation
     */
    public static function fromContentAnnotation(object $annotation, array $debug = []): self
    {
        $node = $annotation->contentNode ?? $annotation->node ?? null;
        
        return new self(
            id: (string) $annotation->id,
            kind: 'annotation',
            platform: (string) ($node->platform ?? 'generic'),
            contentType: (string) ($annotation->annotation_type ?? 'creative'),
            url: $node->url ?? null,
            title: $node->title ?? null,
            text: (string) ($annotation->text ?? $node->text ?? ''),
            authorUsername: $node->author_username ?? null,
            publishedAt: $node->published_at ?? null,
            metrics: self::extractMetrics($node),
            source: self::extractSource($node),
            debug: array_merge([
                'annotation_type' => $annotation->annotation_type,
                'annotation_subtype' => $annotation->subtype ?? null,
            ], $debug),
        );
    }

    /**
     * Convert to array (for legacy compatibility)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'platform' => $this->platform,
            'source' => $this->source['source_type'] ?? 'social',
            'media_type' => $this->contentType,
            'media_type_detail' => $this->debug['annotation_subtype'] ?? $this->debug['fragment_type'] ?? '',
            'text' => $this->text,
            'title' => $this->title ?? '',
            'url' => $this->url ?? '',
            'author_name' => '',
            'author_username' => $this->authorUsername ?? '',
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s'),
            'likes' => $this->metrics['likes'] ?? null,
            'comments' => $this->metrics['comments'] ?? null,
            'shares' => $this->metrics['shares'] ?? null,
            'views' => $this->metrics['views'] ?? null,
            'engagement_score' => $this->metrics['engagement_score'] ?? null,
            'raw_reference_id' => $this->source['source_id'] ?? null,
            'similarity' => $this->debug['similarity'] ?? 0.0,
            'confidence_hint' => $this->debug['confidence'] ?? null,
            'match_type' => $this->debug['match_type'] ?? 'semantic',
            'embedding' => $this->debug['embedding_id'] ?? null,
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
        ];
    }

    /**
     * Extract metrics from node
     * 
     * Note: Metrics are stored directly on sw_content_nodes, not in a separate table.
     * We use the explicit column names (like_count, view_count) as per the schema.
     */
    protected static function extractMetrics(?object $node): ?array
    {
        if (!$node) {
            return null;
        }
        
        // Use explicit column names from sw_content_nodes schema
        return [
            'likes' => $node->like_count ?? $node->likes ?? null,
            'comments' => $node->comment_count ?? $node->comments ?? null,
            'shares' => $node->share_count ?? $node->shares ?? null,
            'views' => $node->view_count ?? $node->views ?? null,
            'engagement_score' => null, // Computed metric, not stored
        ];
    }

    /**
     * Extract source metadata from node
     */
    protected static function extractSource(?object $node): array
    {
        if (!$node) {
            return ['source_type' => 'unknown'];
        }

        return [
            'source_id' => $node->source_id ?? null,
            'source_type' => 'social',
            'keyword_set_id' => null, // Can be enriched if available
            'target_id' => null,
        ];
    }
}
