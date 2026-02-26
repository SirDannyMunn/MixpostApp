<?php

namespace App\Jobs;

use App\Models\ContentPlan;
use App\Models\ContentPlanPost;
use App\Models\ContentPlanStage;
use App\Services\Ai\ContentGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates draft content for all stages in a content plan.
 * 
 * Per US-006 requirements:
 * - Generation runs via background job
 * - Each stage produces one content_plan_post
 * - PLAN_CONTEXT is injected into prompt composition
 * - voice_profile_id is respected during generation
 * - Generation snapshots are linked to posts
 * - Plan status transitions to ready on success
 */
class GenerateContentPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for generating all stages

    public function __construct(
        public string $planId
    ) {}

    public function handle(ContentGeneratorService $generator): void
    {
        $plan = ContentPlan::with(['stages' => fn($q) => $q->orderBy('day_index')])->find($this->planId);
        
        if (!$plan) {
            Log::warning('content_plan.generate.not_found', ['plan_id' => $this->planId]);
            return;
        }

        if ($plan->status !== 'confirmed') {
            Log::warning('content_plan.generate.invalid_status', [
                'plan_id' => $this->planId,
                'status' => $plan->status,
            ]);
            return;
        }

        Log::info('content_plan.generate.started', [
            'plan_id' => $plan->id,
            'plan_type' => $plan->plan_type,
            'duration_days' => $plan->duration_days,
            'platform' => $plan->platform,
            'stages_count' => $plan->stages->count(),
        ]);

        // Update status to generating
        $plan->update(['status' => 'generating']);

        $successCount = 0;
        $failureCount = 0;
        $continuityState = $plan->continuity_state ?? [
            'summary' => '',
            'do_not_repeat' => [],
        ];

        foreach ($plan->stages as $stage) {
            try {
                $post = $this->generateStagePost($generator, $plan, $stage, $continuityState);
                
                if ($post && $post->status === 'draft') {
                    $successCount++;
                    
                    // Update continuity state after successful generation (US-007)
                    $continuityState = $this->updateContinuityState($continuityState, $stage, $post);
                    $plan->update(['continuity_state' => $continuityState]);
                } else {
                    $failureCount++;
                }
            } catch (\Throwable $e) {
                $failureCount++;
                Log::error('content_plan.generate.stage_error', [
                    'plan_id' => $plan->id,
                    'stage_id' => $stage->id,
                    'day_index' => $stage->day_index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update plan status based on results
        $finalStatus = $failureCount === 0 ? 'ready' : ($successCount > 0 ? 'partial' : 'failed');
        $plan->update(['status' => $finalStatus]);

        Log::info('content_plan.generate.completed', [
            'plan_id' => $plan->id,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'final_status' => $finalStatus,
        ]);
    }

    /**
     * Generate content for a single stage.
     */
    protected function generateStagePost(
        ContentGeneratorService $generator,
        ContentPlan $plan,
        ContentPlanStage $stage,
        array $continuityState
    ): ?ContentPlanPost {
        
        // Build the generation prompt with plan context
        $prompt = $this->buildStagePrompt($plan, $stage, $continuityState);

        Log::info('content_plan.generate.stage_started', [
            'plan_id' => $plan->id,
            'stage_id' => $stage->id,
            'day_index' => $stage->day_index,
            'stage_type' => $stage->stage_type,
        ]);

        // Build options for generation
        $options = [
            'mode' => 'generate',
            'max_chars' => $this->getMaxCharsForPlatform($plan->platform),
            'emoji' => 'auto',
            'tone' => 'professional',
            'retrieval_limit' => 3,
            'user_context' => $this->buildPlanContext($plan, $stage, $continuityState),
        ];

        // Add voice profile if specified
        if ($plan->voice_profile_id) {
            $options['voice_profile_id'] = $plan->voice_profile_id;
        }

        // Call the generator
        $result = $generator->generate(
            orgId: (string) $plan->organization_id,
            userId: (string) $plan->user_id,
            prompt: $prompt,
            platform: $plan->platform,
            options: $options,
        );

        $content = (string) ($result['content'] ?? '');
        $isValid = (bool) ($result['validation_result'] ?? false);
        $metadata = (array) ($result['metadata'] ?? []);

        // Create the post
        $post = ContentPlanPost::create([
            'content_plan_id' => $plan->id,
            'content_plan_stage_id' => $stage->id,
            'platform' => $plan->platform,
            'draft_text' => $content,
            'status' => $isValid ? 'draft' : 'failed',
            'generation_snapshot_id' => $metadata['run_id'] ?? null,
        ]);

        Log::info('content_plan.generate.stage_completed', [
            'plan_id' => $plan->id,
            'stage_id' => $stage->id,
            'post_id' => $post->id,
            'status' => $post->status,
            'content_length' => strlen($content),
        ]);

        return $post;
    }

    /**
     * Build the main prompt for stage generation.
     */
    protected function buildStagePrompt(ContentPlan $plan, ContentPlanStage $stage, array $continuityState): string
    {
        $prompt = "Write a {$plan->platform} post for Day {$stage->day_index} of a {$plan->duration_days}-day content plan.\n\n";
        $prompt .= "Post Type: {$stage->stage_type}\n";
        $prompt .= "Intent: {$stage->intent}\n\n";
        
        if ($stage->prompt_seed) {
            $prompt .= "Guidance: {$stage->prompt_seed}\n\n";
        }

        return $prompt;
    }

    /**
     * Build the plan context to inject as user_context.
     */
    protected function buildPlanContext(ContentPlan $plan, ContentPlanStage $stage, array $continuityState): string
    {
        $context = "PLAN_CONTEXT:\n";
        $context .= "Plan Type: {$plan->plan_type}\n";
        $context .= "Platform: {$plan->platform}\n";
        $context .= "Duration: {$plan->duration_days} days\n";
        $context .= "Current Day: {$stage->day_index} of {$plan->duration_days}\n";
        
        if ($plan->goal) {
            $context .= "Goal: {$plan->goal}\n";
        }
        
        if ($plan->audience) {
            $context .= "Target Audience: {$plan->audience}\n";
        }

        // Add continuity context (US-007)
        if (!empty($continuityState['summary'])) {
            $context .= "\nPREVIOUS_CONTENT_SUMMARY:\n{$continuityState['summary']}\n";
        }

        if (!empty($continuityState['do_not_repeat'])) {
            $context .= "\nDO_NOT_REPEAT:\n";
            foreach ($continuityState['do_not_repeat'] as $item) {
                $context .= "- {$item}\n";
            }
        }

        return $context;
    }

    /**
     * Update continuity state after generating a post.
     * Per US-007: Maintain a rolling summary and do_not_repeat list.
     */
    protected function updateContinuityState(array $state, ContentPlanStage $stage, ContentPlanPost $post): array
    {
        // Add a brief summary of what was covered
        $daySummary = "Day {$stage->day_index} ({$stage->stage_type}): {$stage->intent}";
        
        $existingSummary = $state['summary'] ?? '';
        if ($existingSummary) {
            $state['summary'] = $existingSummary . "\n" . $daySummary;
        } else {
            $state['summary'] = $daySummary;
        }

        // Add key themes to do_not_repeat (keep it bounded)
        $doNotRepeat = $state['do_not_repeat'] ?? [];
        $doNotRepeat[] = $stage->intent;
        
        // Keep only the last 10 items to bound token usage
        if (count($doNotRepeat) > 10) {
            $doNotRepeat = array_slice($doNotRepeat, -10);
        }
        
        $state['do_not_repeat'] = $doNotRepeat;

        return $state;
    }

    /**
     * Get platform-specific max character limits.
     */
    protected function getMaxCharsForPlatform(string $platform): int
    {
        return match ($platform) {
            'twitter' => 280,
            'linkedin' => 3000,
            'facebook' => 2000,
            'instagram' => 2200,
            default => 1500,
        };
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('content_plan.generate.job_failed', [
            'plan_id' => $this->planId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Update plan status to failed
        ContentPlan::where('id', $this->planId)->update(['status' => 'failed']);
    }
}
