<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LaundryOS\SocialWatcher\Jobs\AnnotateCreative;
use LaundryOS\SocialWatcher\Jobs\AnnotateTopic;
use LaundryOS\SocialWatcher\Jobs\ClusterAnnotations;
use LaundryOS\SocialWatcher\Jobs\GenerateEmbeddingJob;
use LaundryOS\SocialWatcher\Jobs\GenerateTranscriptSummaryFragmentsJob;
use LaundryOS\SocialWatcher\Jobs\GeneratePostSummaryFragmentsJob;
use LaundryOS\SocialWatcher\Jobs\NormalizeContentNodeJob;
use LaundryOS\SocialWatcher\Models\ContentNode;

/**
 * ReprocessContentNodes
 * 
 * Reprocesses existing content_nodes to regenerate intelligence layers
 * without re-running Apify actors or mutating canonical content.
 * 
 * Purpose:
 * - Validate legacy parity after refactors
 * - Fix logic bugs without re-scraping
 * - Rebuild intelligence layers safely in production
 * - Unblock CI/TI creation when scrapers are broken
 */
class ReprocessContentNodes
{
    /**
     * Reprocess existing content nodes
     * 
     * @param array $options
     *   - content_scope: 'all'|'posts'|'comments'|'transcripts'
     *   - since: Carbon|string date filter
     *   - source_id: optional source filter
     *   - test_run_id: optional test run filter
     *   - skip_annotations: bool
     *   - skip_clustering: bool
    *   - normalize: bool
     *   - sync: bool (force synchronous job execution)
     * @return ReprocessResult
     */
    public function reprocess(array $options = []): ReprocessResult
    {
        $startTime = microtime(true);

        // Build content selection query
        $contentNodes = $this->selectContentNodes($options);

        if ($contentNodes->isEmpty()) {
            Log::info('ReprocessContentNodes: No content nodes found to reprocess', $options);
            
            return new ReprocessResult(
                contentNodesProcessed: 0,
                jobsDispatched: 0,
                durationSeconds: microtime(true) - $startTime,
                warnings: ['No content nodes found matching criteria'],
            );
        }

        Log::info('ReprocessContentNodes: Starting reprocessing', [
            'content_nodes_count' => $contentNodes->count(),
            'options' => $options,
        ]);

        $jobsDispatched = 0;
        $warnings = [];
        $errors = [];

        $skipAnnotations = $options['skip_annotations'] ?? false;
        $skipClustering = $options['skip_clustering'] ?? true;
        $shouldNormalize = (bool) ($options['normalize'] ?? false);
        $sync = $options['sync'] ?? config('social-watcher.force_sync_jobs', false);

        // Group nodes by content type for efficient processing
        $postNodes = $contentNodes->where('content_type', 'post');
        $commentNodes = $contentNodes->where('content_type', 'comment');
        $transcriptNodes = $contentNodes->where('content_type', 'transcript');

        // Stage 0: Normalize content nodes (optional)
        if ($shouldNormalize) {
            foreach ($contentNodes as $node) {
                $job = new NormalizeContentNodeJob($node->id, false);

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job)->onQueue(config('social-watcher.ingestion.queue', 'default'));
                }

                $jobsDispatched++;
            }

            Log::info('ReprocessContentNodes: Dispatched normalization jobs', [
                'count' => $contentNodes->count(),
            ]);
        }

        // Stage 1: Creative Intelligence (CI) - Posts only
        if (!$skipAnnotations && $postNodes->isNotEmpty()) {
            foreach ($postNodes as $node) {
                $job = new AnnotateCreative(
                    contentNodeId: $node->id,
                    metadata: ['reprocessed' => true],
                );

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                }

                $jobsDispatched++;
            }

            Log::info('ReprocessContentNodes: Dispatched Creative Intelligence jobs', [
                'count' => $postNodes->count(),
            ]);
        }

        // Stage 1.5: Derived Fragments for Posts (summary/bullets/claims)
        if ($postNodes->isNotEmpty()) {
            foreach ($postNodes as $node) {
                $job = new GeneratePostSummaryFragmentsJob(
                    contentNodeId: $node->id,
                    metadata: ['reprocessed' => true],
                );

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                }

                $jobsDispatched++;
            }

            Log::info('ReprocessContentNodes: Dispatched post summary fragment jobs', [
                'count' => $postNodes->count(),
            ]);
        }

        // Stage 2: Topic Intelligence (TI) - Comments only
        if (!$skipAnnotations && $commentNodes->isNotEmpty()) {
            foreach ($commentNodes as $node) {
                $job = new AnnotateTopic(
                    contentNodeId: $node->id,
                    metadata: ['reprocessed' => true],
                );

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                }

                $jobsDispatched++;
            }

            Log::info('ReprocessContentNodes: Dispatched Topic Intelligence jobs', [
                'count' => $commentNodes->count(),
            ]);
        }

        // Stage 3: Derived Fragments - Transcripts only
        // Generate AI-derived fragments (summary, key_points) for transcripts
        // Note: This does NOT generate sentence/paragraph fragments (those are forbidden)
        if ($transcriptNodes->isNotEmpty()) {
            foreach ($transcriptNodes as $node) {
                $job = new GenerateTranscriptSummaryFragmentsJob(
                    contentNodeId: $node->id,
                    metadata: ['reprocessed' => true],
                );

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                }

                $jobsDispatched++;
            }

            Log::info('ReprocessContentNodes: Dispatched transcript summary fragment jobs', [
                'count' => $transcriptNodes->count(),
            ]);
        }

        // Stage 4: Embeddings
        // Embeddings are typically auto-dispatched by annotation jobs
        // or we can explicitly dispatch them here if needed
        Log::info('ReprocessContentNodes: Embeddings will be generated by annotation jobs');

        // Stage 5: Clustering (optional)
        if (!$skipClustering && !$skipAnnotations) {
            // Only cluster if we have annotations
            $annotationTypes = [];
            if ($postNodes->isNotEmpty()) {
                $annotationTypes[] = 'creative';
            }
            if ($commentNodes->isNotEmpty()) {
                $annotationTypes[] = 'topic';
            }

            if (!empty($annotationTypes)) {
                foreach ($annotationTypes as $annotationType) {
                    $job = new ClusterAnnotations(
                        annotationType: $annotationType,
                    );

                    if ($sync) {
                        dispatch_sync($job);
                    } else {
                        dispatch($job);
                    }

                    $jobsDispatched++;
                }

                Log::info('ReprocessContentNodes: Dispatched clustering jobs', [
                    'types' => $annotationTypes,
                ]);
            } else {
                Log::info('ReprocessContentNodes: Skipping clustering - no annotation types to cluster');
            }
        }

        $duration = microtime(true) - $startTime;

        Log::info('ReprocessContentNodes: Completed', [
            'content_nodes_processed' => $contentNodes->count(),
            'jobs_dispatched' => $jobsDispatched,
            'duration_seconds' => $duration,
        ]);

        return new ReprocessResult(
            contentNodesProcessed: $contentNodes->count(),
            jobsDispatched: $jobsDispatched,
            durationSeconds: $duration,
            warnings: $warnings,
            errors: $errors,
        );
    }

    /**
     * Select content nodes based on options
     * 
     * @param array $options
     * @return Collection<ContentNode>
     */
    protected function selectContentNodes(array $options): Collection
    {
        $query = ContentNode::query();

        // Apply content scope filter
        $scope = $options['content_scope'] ?? 'all';
        if ($scope !== 'all') {
            $contentType = match($scope) {
                'posts' => 'post',
                'comments' => 'comment',
                'transcripts' => 'transcript',
                default => null,
            };

            if ($contentType) {
                $query->where('content_type', $contentType);
            }
        }

        // Apply date filter
        if (isset($options['since'])) {
            $since = is_string($options['since']) 
                ? \Carbon\Carbon::parse($options['since']) 
                : $options['since'];
            
            $query->where('created_at', '>=', $since);
        }

        // Apply source filter
        if (isset($options['source_id'])) {
            $query->where('source_id', $options['source_id']);
        }

        // Apply test run filter
        if (isset($options['test_run_id'])) {
            $query->where('metadata->test_run_id', $options['test_run_id']);
        }

        // Order by created_at for deterministic processing
        $query->orderBy('created_at', 'asc');

        return $query->get();
    }
}

/**
 * ReprocessResult DTO
 */
class ReprocessResult
{
    public function __construct(
        public int $contentNodesProcessed,
        public int $jobsDispatched,
        public float $durationSeconds,
        public array $warnings = [],
        public array $errors = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'content_nodes_processed' => $this->contentNodesProcessed,
            'jobs_dispatched' => $this->jobsDispatched,
            'duration_seconds' => $this->durationSeconds,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    public function succeeded(): bool
    {
        return empty($this->errors);
    }
}
