<?php

namespace App\Services\Ai\Research\DTO;

class ResearchOptions
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly int $limit = 40,
        public readonly bool $includeKb = false,
        public readonly array $mediaTypes = [],
        public readonly int $hooksCount = 5,
        public readonly string $industry = '',
        public readonly array $platforms = [],
        public readonly int $trendLimit = 10,
        public readonly int $trendRecentDays = 7,
        public readonly int $trendDaysBack = 30,
        public readonly int $trendMinRecent = 3,
        public readonly array $timeWindows = [],
        public readonly float $clusterSimilarity = 0.75,
        public readonly int $maxExamples = 6,
        public readonly bool $returnDebug = false,
        public readonly bool $trace = false,
        public readonly array $folderIds = [],
    ) {}

    public static function fromArray(string $organizationId, string $userId, array $options): self
    {
        // Parse time windows
        $timeWindows = [];
        if (isset($options['time_windows']) && is_array($options['time_windows'])) {
            $timeWindows = $options['time_windows'];
        } else {
            // Default time windows for saturation analysis
            $timeWindows = [
                'recent_days' => (int) ($options['recent_days'] ?? 14),
                'baseline_days' => (int) ($options['baseline_days'] ?? 90),
            ];
        }

        // Parse platforms - support both 'platforms' and 'trend_platforms'
        $platforms = [];
        if (isset($options['platforms']) && is_array($options['platforms'])) {
            $platforms = $options['platforms'];
        } elseif (isset($options['trend_platforms']) && is_array($options['trend_platforms'])) {
            $platforms = $options['trend_platforms'];
        }

        return new self(
            organizationId: $organizationId,
            userId: $userId,
            limit: (int) ($options['retrieval_limit'] ?? $options['limit'] ?? 40),
            includeKb: (bool) ($options['include_kb'] ?? false),
            mediaTypes: (array) ($options['research_media_types'] ?? []),
            hooksCount: (int) ($options['hooks_count'] ?? 5),
            industry: (string) ($options['research_industry'] ?? ''),
            platforms: $platforms,
            trendLimit: (int) ($options['trend_limit'] ?? 10),
            trendRecentDays: (int) ($options['trend_recent_days'] ?? 7),
            trendDaysBack: (int) ($options['trend_days_back'] ?? 30),
            trendMinRecent: (int) ($options['trend_min_recent'] ?? 3),
            timeWindows: $timeWindows,
            clusterSimilarity: (float) ($options['cluster_similarity'] ?? config('ai.research.cluster_similarity', 0.75)),
            maxExamples: (int) ($options['max_examples'] ?? 6),
            returnDebug: (bool) ($options['return_debug'] ?? false),
            trace: (bool) ($options['trace'] ?? false),
            folderIds: (array) ($options['folder_ids'] ?? []),
        );
    }

    /**
     * Create a copy with a different limit
     */
    public function withLimit(int $limit): self
    {
        return new self(
            organizationId: $this->organizationId,
            userId: $this->userId,
            limit: $limit,
            includeKb: $this->includeKb,
            mediaTypes: $this->mediaTypes,
            hooksCount: $this->hooksCount,
            industry: $this->industry,
            platforms: $this->platforms,
            trendLimit: $this->trendLimit,
            trendRecentDays: $this->trendRecentDays,
            trendDaysBack: $this->trendDaysBack,
            trendMinRecent: $this->trendMinRecent,
            timeWindows: $this->timeWindows,
            clusterSimilarity: $this->clusterSimilarity,
            maxExamples: $this->maxExamples,
            returnDebug: $this->returnDebug,
            trace: $this->trace,
            folderIds: $this->folderIds,
        );
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'limit' => $this->limit,
            'include_kb' => $this->includeKb,
            'media_types' => $this->mediaTypes,
            'hooks_count' => $this->hooksCount,
            'industry' => $this->industry,
            'platforms' => $this->platforms,
            'trend_limit' => $this->trendLimit,
            'trend_recent_days' => $this->trendRecentDays,
            'trend_days_back' => $this->trendDaysBack,
            'trend_min_recent' => $this->trendMinRecent,
            'time_windows' => $this->timeWindows,
            'cluster_similarity' => $this->clusterSimilarity,
            'max_examples' => $this->maxExamples,
            'return_debug' => $this->returnDebug,
            'trace' => $this->trace,
            'folder_ids' => $this->folderIds,
        ];
    }
}
