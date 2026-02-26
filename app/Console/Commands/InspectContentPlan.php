<?php

namespace App\Console\Commands;

use App\Models\ContentPlan;
use App\Models\GenerationSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class InspectContentPlan extends Command
{
    protected $signature = 'content-plan:inspect {plan_id}
        {--output= : Output file path (defaults to storage/content-plan-reports/plan-{id}.md)}
        {--json : Output as JSON instead of markdown}
        {--stdout : Output to console instead of file}
        {--full-prompts : Include full system and user prompts from snapshots}';

    protected $description = 'Inspect a content plan and output a detailed markdown report with all prompts and snapshots';

    public function handle(): int
    {
        $id = (string) $this->argument('plan_id');
        
        if (!Str::isUuid($id)) {
            $this->error('Invalid plan_id. Provide a full UUID.');
            $this->line('Tip: run php artisan tinker and query ContentPlan::latest()->first() to find IDs.');
            return 1;
        }

        $plan = ContentPlan::with([
            'stages' => fn($q) => $q->orderBy('day_index'),
            'stages.posts.generationSnapshot',
            'voiceProfile',
            'user',
            'organization',
        ])->find($id);

        if (!$plan) {
            $this->error("Content plan not found: {$id}");
            return 1;
        }

        if ($this->option('json')) {
            $content = json_encode($this->buildInspectionData($plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $extension = 'json';
        } else {
            $content = $this->buildMarkdownReport($plan);
            $extension = 'md';
        }

        if ($this->option('stdout')) {
            $this->line($content);
            return 0;
        }

        // Determine output path
        $outputPath = $this->option('output');
        if (!$outputPath) {
            $reportsDir = storage_path('content-plan-reports');
            if (!File::isDirectory($reportsDir)) {
                File::makeDirectory($reportsDir, 0755, true);
            }
            $outputPath = "{$reportsDir}/plan-{$plan->id}.{$extension}";
        }

        File::put($outputPath, $content);
        $this->info("Report saved to: {$outputPath}");

        return 0;
    }

    protected function buildMarkdownReport(ContentPlan $plan): string
    {
        $showFullPrompts = $this->option('full-prompts');
        $md = [];

        // Header
        $md[] = "# Content Plan Inspection Report";
        $md[] = "";
        $md[] = "Generated: " . now()->format('Y-m-d H:i:s');
        $md[] = "";

        // Plan Details
        $md[] = "## Plan Details";
        $md[] = "";
        $md[] = "| Field | Value |";
        $md[] = "|-------|-------|";
        $md[] = "| **Plan ID** | `{$plan->id}` |";
        $md[] = "| **Status** | `{$plan->status}` |";
        $md[] = "| **Plan Type** | `{$plan->plan_type}` |";
        $md[] = "| **Platform** | `{$plan->platform}` |";
        $md[] = "| **Duration** | {$plan->duration_days} days |";
        $md[] = "| **Goal** | " . ($plan->goal ?: '_(not set)_') . " |";
        $md[] = "| **Audience** | " . ($plan->audience ?: '_(not set)_') . " |";
        $md[] = "| **Voice Profile** | " . ($plan->voiceProfile?->name ?? '_(none)_') . " |";
        $md[] = "| **Organization** | " . ($plan->organization?->name ?? $plan->organization_id) . " |";
        $md[] = "| **User** | " . ($plan->user?->email ?? $plan->user_id) . " |";
        $md[] = "| **Created** | " . $plan->created_at->format('Y-m-d H:i:s') . " |";
        $md[] = "";

        // Continuity State
        if ($plan->continuity_state) {
            $md[] = "### Continuity State";
            $md[] = "";
            
            if (!empty($plan->continuity_state['summary'])) {
                $md[] = "**Summary:**";
                $md[] = "```";
                $md[] = $plan->continuity_state['summary'];
                $md[] = "```";
                $md[] = "";
            }
            
            if (!empty($plan->continuity_state['do_not_repeat'])) {
                $md[] = "**Do Not Repeat:**";
                foreach ($plan->continuity_state['do_not_repeat'] as $item) {
                    $md[] = "- {$item}";
                }
                $md[] = "";
            }
        }

        // Stages & Posts
        $md[] = "---";
        $md[] = "";
        $md[] = "## Stages & Generated Posts";
        $md[] = "";

        foreach ($plan->stages as $stage) {
            $md[] = "### Day {$stage->day_index}: {$stage->stage_type}";
            $md[] = "";
            $md[] = "| Field | Value |";
            $md[] = "|-------|-------|";
            $md[] = "| **Stage ID** | `{$stage->id}` |";
            $md[] = "| **Intent** | {$stage->intent} |";
            if ($stage->prompt_seed) {
                $md[] = "| **Prompt Seed** | {$stage->prompt_seed} |";
            }
            $md[] = "";

            // Generated Prompt
            $md[] = "#### Generated Prompt";
            $md[] = "";
            $md[] = "```";
            $md[] = trim($this->buildStagePrompt($plan, $stage));
            $md[] = "```";
            $md[] = "";

            // Plan Context
            $md[] = "#### Plan Context (user_context)";
            $md[] = "";
            $md[] = "```";
            $md[] = trim($this->buildPlanContext($plan, $stage, $plan->continuity_state ?? []));
            $md[] = "```";
            $md[] = "";

            // Post
            $post = $stage->posts->first();
            if ($post) {
                $md[] = "#### Generated Post";
                $md[] = "";
                $md[] = "| Field | Value |";
                $md[] = "|-------|-------|";
                $md[] = "| **Post ID** | `{$post->id}` |";
                $md[] = "| **Status** | `{$post->status}` |";
                $md[] = "| **Snapshot ID** | `" . ($post->generation_snapshot_id ?? 'none') . "` |";
                $md[] = "";

                if ($post->draft_text) {
                    $md[] = "**Content:**";
                    $md[] = "";
                    $md[] = "> " . str_replace("\n", "\n> ", $post->draft_text);
                    $md[] = "";
                }
            } else {
                $md[] = "_(No post generated for this stage)_";
                $md[] = "";
            }
        }

        // Snapshots
        $md[] = "---";
        $md[] = "";
        $md[] = "## Generation Snapshots";
        $md[] = "";

        // Try to find snapshots by stored IDs first
        $stageSnapshots = $this->findSnapshotsForPlan($plan);

        if (empty($stageSnapshots)) {
            $md[] = "_No generation snapshots found for this plan._";
            $md[] = "";
        } else {
            foreach ($stageSnapshots as $stageInfo) {
                $snapshot = $stageInfo['snapshot'];
                
                $md[] = "### Snapshot: Day {$stageInfo['day']} ({$stageInfo['stage_type']})";
                $md[] = "";
                if ($stageInfo['matched_by'] !== 'id') {
                    $md[] = "> ⚠️ _Snapshot matched by {$stageInfo['matched_by']} (stored ID not found)_";
                    $md[] = "";
                }
                $md[] = "| Field | Value |";
                $md[] = "|-------|-------|";
                $md[] = "| **Snapshot ID** | `{$snapshot->id}` |";
                $md[] = "| **Platform** | `" . ($snapshot->platform ?? 'N/A') . "` |";
                $md[] = "| **Created** | " . ($snapshot->created_at ? $snapshot->created_at->format('Y-m-d H:i:s') : 'N/A') . " |";
                if ($snapshot->voice_profile_id) {
                    $md[] = "| **Voice Profile ID** | `{$snapshot->voice_profile_id}` |";
                }
                if ($snapshot->voice_source) {
                    $md[] = "| **Voice Source** | `{$snapshot->voice_source}` |";
                }
                $md[] = "";

                // Classification
                if ($snapshot->classification) {
                    $md[] = "#### Classification";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->classification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $md[] = "```";
                    $md[] = "";
                }

                // Intent
                if ($snapshot->intent) {
                    $md[] = "**Intent:** {$snapshot->intent}";
                    $md[] = "";
                }

                // Mode
                if ($snapshot->mode) {
                    $md[] = "**Mode:** `" . json_encode($snapshot->mode) . "`";
                    $md[] = "";
                }

                // User context (PLAN_CONTEXT)
                if ($snapshot->user_context) {
                    $md[] = "#### User Context (PLAN_CONTEXT)";
                    $md[] = "";
                    $md[] = "```";
                    $md[] = $snapshot->user_context;
                    $md[] = "```";
                    $md[] = "";
                }

                // Prompt
                if ($snapshot->prompt) {
                    $md[] = "#### Prompt";
                    $md[] = "";
                    $md[] = "```";
                    $md[] = $snapshot->prompt;
                    $md[] = "```";
                    $md[] = "";
                }

                // Options
                if ($snapshot->options) {
                    $md[] = "#### Options";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->options, JSON_PRETTY_PRINT);
                    $md[] = "```";
                    $md[] = "";
                }

                // Chunks
                if ($snapshot->chunks && count($snapshot->chunks) > 0) {
                    $md[] = "#### Chunks Used (" . count($snapshot->chunks) . ")";
                    $md[] = "";
                    foreach ($snapshot->chunks as $i => $chunk) {
                        $content = $chunk['content'] ?? $chunk['text'] ?? json_encode($chunk);
                        $preview = Str::limit($content, 200);
                        $md[] = "**Chunk {$i}:**";
                        $md[] = "```";
                        $md[] = $preview;
                        $md[] = "```";
                        $md[] = "";
                    }
                }

                // Facts
                if ($snapshot->facts && count($snapshot->facts) > 0) {
                    $md[] = "#### Facts Used (" . count($snapshot->facts) . ")";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $md[] = "```";
                    $md[] = "";
                }

                // Swipes
                if ($snapshot->swipes && count($snapshot->swipes) > 0) {
                    $md[] = "#### Swipes Used (" . count($snapshot->swipes) . ")";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->swipes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $md[] = "```";
                    $md[] = "";
                }

                // Token metrics
                if ($snapshot->token_metrics) {
                    $md[] = "#### Token Metrics";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->token_metrics, JSON_PRETTY_PRINT);
                    $md[] = "```";
                    $md[] = "";
                }

                // Performance metrics
                if ($snapshot->performance_metrics) {
                    $md[] = "#### Performance Metrics";
                    $md[] = "";
                    $md[] = "```json";
                    $md[] = json_encode($snapshot->performance_metrics, JSON_PRETTY_PRINT);
                    $md[] = "```";
                    $md[] = "";
                }

                // Full prompts (with --full-prompts flag)
                if ($showFullPrompts) {
                    if ($snapshot->final_system_prompt) {
                        $md[] = "#### Final System Prompt";
                        $md[] = "";
                        $md[] = "```";
                        $md[] = $snapshot->final_system_prompt;
                        $md[] = "```";
                        $md[] = "";
                    }

                    if ($snapshot->final_user_prompt) {
                        $md[] = "#### Final User Prompt";
                        $md[] = "";
                        $md[] = "```";
                        $md[] = $snapshot->final_user_prompt;
                        $md[] = "```";
                        $md[] = "";
                    }

                    if ($snapshot->llm_stages) {
                        $md[] = "#### LLM Stages";
                        $md[] = "";
                        $md[] = "```json";
                        $md[] = json_encode($snapshot->llm_stages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $md[] = "```";
                        $md[] = "";
                    }
                }

                // Output content
                if ($snapshot->output_content) {
                    $md[] = "#### Output Content";
                    $md[] = "";
                    $md[] = "> " . str_replace("\n", "\n> ", $snapshot->output_content);
                    $md[] = "";
                }
            }
        }

        return implode("\n", $md);
    }

    protected function buildInspectionData(ContentPlan $plan): array
    {
        $stages = [];

        foreach ($plan->stages as $stage) {
            $post = $stage->posts->first();
            
            $stageData = [
                'id' => $stage->id,
                'day_index' => $stage->day_index,
                'stage_type' => $stage->stage_type,
                'intent' => $stage->intent,
                'prompt_seed' => $stage->prompt_seed,
                'generated_prompt' => $this->buildStagePrompt($plan, $stage),
                'plan_context' => $this->buildPlanContext($plan, $stage, $plan->continuity_state ?? []),
                'post' => null,
            ];

            if ($post) {
                $stageData['post'] = [
                    'id' => $post->id,
                    'status' => $post->status,
                    'draft_text' => $post->draft_text,
                    'generation_snapshot_id' => $post->generation_snapshot_id,
                ];
            }

            $stages[] = $stageData;
        }

        // Load snapshots using the same logic as markdown output
        $stageSnapshots = $this->findSnapshotsForPlan($plan);
        $snapshots = [];
        foreach ($stageSnapshots as $stageInfo) {
            $snap = $stageInfo['snapshot'];
            $snapshots[] = [
                'id' => $snap->id,
                'day_index' => $stageInfo['day'],
                'stage_type' => $stageInfo['stage_type'],
                'matched_by' => $stageInfo['matched_by'],
                'platform' => $snap->platform,
                'prompt' => $snap->prompt,
                'user_context' => $snap->user_context,
                'classification' => $snap->classification,
                'intent' => $snap->intent,
                'mode' => $snap->mode,
                'voice_profile_id' => $snap->voice_profile_id,
                'voice_source' => $snap->voice_source,
                'options' => $snap->options,
                'chunks' => $snap->chunks,
                'facts' => $snap->facts,
                'swipes' => $snap->swipes,
                'output_content' => $snap->output_content,
                'final_system_prompt' => $snap->final_system_prompt,
                'final_user_prompt' => $snap->final_user_prompt,
                'token_metrics' => $snap->token_metrics,
                'performance_metrics' => $snap->performance_metrics,
                'llm_stages' => $snap->llm_stages,
                'created_at' => $snap->created_at?->toIso8601String(),
            ];
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'status' => $plan->status,
                'plan_type' => $plan->plan_type,
                'platform' => $plan->platform,
                'duration_days' => $plan->duration_days,
                'goal' => $plan->goal,
                'audience' => $plan->audience,
                'voice_profile_id' => $plan->voice_profile_id,
                'voice_profile_name' => $plan->voiceProfile?->name,
                'organization_id' => $plan->organization_id,
                'user_id' => $plan->user_id,
                'continuity_state' => $plan->continuity_state,
                'created_at' => $plan->created_at->toIso8601String(),
            ],
            'stages' => $stages,
            'snapshots' => $snapshots,
        ];
    }

    /**
     * Find snapshots for the plan stages.
     * First tries stored IDs, then falls back to matching by user_context pattern.
     */
    protected function findSnapshotsForPlan(ContentPlan $plan): array
    {
        $results = [];

        // Collect stored snapshot IDs
        $storedIds = [];
        foreach ($plan->stages as $stage) {
            foreach ($stage->posts as $post) {
                if ($post->generation_snapshot_id) {
                    $storedIds[$post->generation_snapshot_id] = [
                        'day' => $stage->day_index,
                        'stage_type' => $stage->stage_type,
                        'post' => $post,
                    ];
                }
            }
        }

        // Try to find by stored IDs
        if (!empty($storedIds)) {
            $foundSnapshots = GenerationSnapshot::whereIn('id', array_keys($storedIds))->get()->keyBy('id');
            
            foreach ($storedIds as $id => $stageInfo) {
                if ($foundSnapshots->has($id)) {
                    $results[] = [
                        'day' => $stageInfo['day'],
                        'stage_type' => $stageInfo['stage_type'],
                        'snapshot' => $foundSnapshots->get($id),
                        'matched_by' => 'id',
                    ];
                }
            }
        }

        // If no snapshots found by ID, try to match by user_context pattern
        if (empty($results)) {
            // Find snapshots with PLAN_CONTEXT that match this plan's characteristics
            $planCreatedAt = $plan->created_at;
            $searchPattern = "Current Day: %";
            
            // Get snapshots created around the plan time with PLAN_CONTEXT
            $candidateSnapshots = GenerationSnapshot::where('user_context', 'like', '%PLAN_CONTEXT%')
                ->where('user_context', 'like', "%Plan Type: {$plan->plan_type}%")
                ->where('user_context', 'like', "%Platform: {$plan->platform}%")
                ->where('user_context', 'like', "%Duration: {$plan->duration_days} days%")
                ->where('created_at', '>=', $planCreatedAt)
                ->orderBy('created_at')
                ->limit($plan->duration_days + 5) // Get a few extra in case of retries
                ->get();

            // Match each stage to a snapshot by day number in user_context
            foreach ($plan->stages as $stage) {
                $dayPattern = "Current Day: {$stage->day_index} of {$plan->duration_days}";
                
                foreach ($candidateSnapshots as $snapshot) {
                    if (str_contains($snapshot->user_context ?? '', $dayPattern)) {
                        $results[] = [
                            'day' => $stage->day_index,
                            'stage_type' => $stage->stage_type,
                            'snapshot' => $snapshot,
                            'matched_by' => 'user_context pattern',
                        ];
                        break; // Found match for this stage
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Build the main prompt for stage generation (mirrors GenerateContentPlanJob).
     */
    protected function buildStagePrompt(ContentPlan $plan, $stage): string
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
     * Build the plan context to inject as user_context (mirrors GenerateContentPlanJob).
     */
    protected function buildPlanContext(ContentPlan $plan, $stage, array $continuityState): string
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
}
