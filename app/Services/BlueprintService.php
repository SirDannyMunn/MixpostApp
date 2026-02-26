<?php

namespace App\Services;

use App\Models\ContentPlan;
use App\Models\ContentPlanStage;
use Illuminate\Support\Facades\DB;

class BlueprintService
{
    /**
     * Generate stages for a content plan based on its plan_type and duration_days.
     * This is deterministic and template-driven (no LLM).
     */
    public function generateStages(ContentPlan $plan): void
    {
        // Only regenerate if plan is not confirmed
        if ($plan->status === 'confirmed' || $plan->status === 'ready') {
            throw new \InvalidArgumentException('Cannot regenerate stages for confirmed or ready plans');
        }

        DB::transaction(function () use ($plan) {
            // Delete existing stages
            $plan->stages()->delete();

            // Generate stages based on plan_type
            $stages = $this->getBlueprint($plan->plan_type, $plan->duration_days);

            foreach ($stages as $stage) {
                ContentPlanStage::create([
                    'content_plan_id' => $plan->id,
                    'day_index' => $stage['day_index'],
                    'stage_type' => $stage['stage_type'],
                    'intent' => $stage['intent'],
                    'prompt_seed' => $stage['prompt_seed'],
                ]);
            }
        });
    }

    /**
     * Get blueprint stages for a given plan type and duration.
     */
    private function getBlueprint(string $planType, int $durationDays): array
    {
        return match ($planType) {
            'build_in_public' => $this->getBuildInPublicBlueprint($durationDays),
            default => throw new \InvalidArgumentException("Unknown plan type: {$planType}"),
        };
    }

    /**
     * Build in Public blueprint stages.
     */
    private function getBuildInPublicBlueprint(int $days): array
    {
        if (!in_array($days, [7, 14])) {
            throw new \InvalidArgumentException("Build in Public supports 7 or 14 days, got {$days}");
        }

        if ($days === 7) {
            return [
                [
                    'day_index' => 1,
                    'stage_type' => 'announce',
                    'intent' => 'Announce the project and why you\'re building it',
                    'prompt_seed' => 'Share your motivation for starting this project and what problem it will solve.',
                ],
                [
                    'day_index' => 2,
                    'stage_type' => 'progress',
                    'intent' => 'Share initial progress and early wins',
                    'prompt_seed' => 'Highlight the first steps you\'ve taken and any early breakthroughs or challenges.',
                ],
                [
                    'day_index' => 3,
                    'stage_type' => 'challenge',
                    'intent' => 'Discuss a challenge you\'re facing',
                    'prompt_seed' => 'Talk about a specific obstacle or decision you\'re working through right now.',
                ],
                [
                    'day_index' => 4,
                    'stage_type' => 'progress',
                    'intent' => 'Show tangible progress or a demo',
                    'prompt_seed' => 'Share something visual or concrete—screenshots, code snippets, or a working prototype.',
                ],
                [
                    'day_index' => 5,
                    'stage_type' => 'insight',
                    'intent' => 'Share a key insight or learning',
                    'prompt_seed' => 'Reflect on something important you\'ve learned while building this week.',
                ],
                [
                    'day_index' => 6,
                    'stage_type' => 'community',
                    'intent' => 'Ask for feedback or engage the community',
                    'prompt_seed' => 'Invite your audience to weigh in on a decision or feature you\'re considering.',
                ],
                [
                    'day_index' => 7,
                    'stage_type' => 'reflect',
                    'intent' => 'Reflect on the week and tease next steps',
                    'prompt_seed' => 'Summarize what you\'ve accomplished this week and hint at what\'s coming next.',
                ],
            ];
        }

        // 14-day blueprint
        return [
            [
                'day_index' => 1,
                'stage_type' => 'announce',
                'intent' => 'Announce the project and your mission',
                'prompt_seed' => 'Introduce your project, the problem it solves, and why you\'re passionate about building it.',
            ],
            [
                'day_index' => 2,
                'stage_type' => 'progress',
                'intent' => 'Share your initial setup and approach',
                'prompt_seed' => 'Explain your technical choices, tools, or frameworks and why you chose them.',
            ],
            [
                'day_index' => 3,
                'stage_type' => 'challenge',
                'intent' => 'Discuss an early challenge or technical decision',
                'prompt_seed' => 'Dive into a specific technical or design problem you\'re solving.',
            ],
            [
                'day_index' => 4,
                'stage_type' => 'progress',
                'intent' => 'Show early progress—screenshots or code',
                'prompt_seed' => 'Share something visual or tangible that shows the project taking shape.',
            ],
            [
                'day_index' => 5,
                'stage_type' => 'insight',
                'intent' => 'Share a key insight or principle guiding your build',
                'prompt_seed' => 'Reflect on a principle, pattern, or realization that\'s shaping your work.',
            ],
            [
                'day_index' => 6,
                'stage_type' => 'community',
                'intent' => 'Engage your audience—ask a question or request feedback',
                'prompt_seed' => 'Invite your community to help you decide on a feature, name, or direction.',
            ],
            [
                'day_index' => 7,
                'stage_type' => 'reflect',
                'intent' => 'Reflect on the first week',
                'prompt_seed' => 'Summarize the first week of building and what you\'ve learned so far.',
            ],
            [
                'day_index' => 8,
                'stage_type' => 'progress',
                'intent' => 'Show deeper progress—features coming together',
                'prompt_seed' => 'Highlight how core features are starting to connect and work together.',
            ],
            [
                'day_index' => 9,
                'stage_type' => 'challenge',
                'intent' => 'Discuss a setback or pivot',
                'prompt_seed' => 'Share something that didn\'t go as planned and how you\'re adapting.',
            ],
            [
                'day_index' => 10,
                'stage_type' => 'progress',
                'intent' => 'Demo something working',
                'prompt_seed' => 'Show a working feature or interaction—make it tangible and real.',
            ],
            [
                'day_index' => 11,
                'stage_type' => 'insight',
                'intent' => 'Share a technical or strategic insight',
                'prompt_seed' => 'Discuss a technique, optimization, or strategic choice that\'s paying off.',
            ],
            [
                'day_index' => 12,
                'stage_type' => 'community',
                'intent' => 'Invite beta testers or early users',
                'prompt_seed' => 'Ask your audience if they want early access or to be beta testers.',
            ],
            [
                'day_index' => 13,
                'stage_type' => 'progress',
                'intent' => 'Show polishing and final touches',
                'prompt_seed' => 'Highlight the refinements and finishing touches you\'re adding.',
            ],
            [
                'day_index' => 14,
                'stage_type' => 'launch',
                'intent' => 'Celebrate launch or milestone',
                'prompt_seed' => 'Announce that your project is ready, reflect on the journey, and share next steps.',
            ],
        ];
    }
}
