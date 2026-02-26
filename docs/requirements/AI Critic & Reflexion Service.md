Here is the backend engineering specification to implement the **Critic Loop (Reflexion Pattern)**.

This creates a self-correcting pipeline where the AI critiques its own work and rewrites it if necessary, controlled by a simple boolean flag in the request options.

# Engineering Spec: AI Critic & Reflexion Service

**Status:** Ready for Implementation
**Target System:** `App\Services\Ai\ContentGeneratorService`
**New Component:** `App\Services\Ai\Generation\Steps\ReflexionService`

## 1. Architecture Overview

We are moving from a linear pipeline:
`Prompt -> Generate -> Validate -> Output`

To a feedback-driven pipeline (conditional):
`Prompt -> Generate -> [Critic -> (If Score < 9) -> Refiner] -> Validate -> Output`

This ensures that "Supermode" or advanced generations run through a quality filter before the user ever sees the result.

---

## 2. New Service: `ReflexionService`

Create a dedicated service to handle the critique and refinement logic. This keeps the main generator clean and allows you to potentially reuse this logic later (e.g., for a "Fix this post" button).

**File Path:** `app/Services/Ai/Generation/Steps/ReflexionService.php`

```php
<?php

namespace App\Services\Ai\Generation\Steps;

use App\Services\Ai\LLMClient;
use App\Services\Ai\Generation\DTO\Context;
use App\Services\Ai\Generation\DTO\Constraints;

class ReflexionService
{
    public function __construct(
        protected LLMClient $llm
    ) {}

    /**
     * Step 1: The Critic
     * Acts as a Senior Editor. Returns structured feedback and a quality score.
     */
    public function critique(string $draft, string $rawPrompt, Context $context): array
    {
        $system = <<<EOT
You are a Senior Chief Editor. You despise generic AI fluff.
Your job is to ruthlessly critique the provided content draft based on the user's original intent.
Identify weak positioning, fake authority, generic bullet points, and lack of "lived experience."

Return a STRICT JSON object with this structure:
{
    "verdict": "string (short summary)",
    "score": number (1-10),
    "strengths": ["string"],
    "weaknesses": [{"issue": "string", "detail": "string"}],
    "recommended_fixes": ["string"]
}
EOT;
        // Truncate context for the critic to save tokens/focus attention
        $contextPreview = mb_substr($context->user_context ?? '', 0, 800);
        
        $user = <<<EOT
USER PROMPT: $rawPrompt
CONTEXT SUMMARY: $contextPreview

DRAFT TO REVIEW:
$draft
EOT;

        // Use 'json_object' mode to ensure the score is machine-readable
        $response = $this->llm->call('reflexion_critique', $system, $user, 'json_object');
        
        return is_array($response) ? $response : json_decode($response, true);
    }

    /**
     * Step 2: The Refiner
     * Acts as a Ghostwriter. Rewrites the draft to address specific criticism.
     */
    public function refine(string $draft, array $critique, string $rawPrompt): string
    {
        $fixes = implode("\n- ", $critique['recommended_fixes'] ?? []);
        
        $system = "You are an expert Ghostwriter. You have received specific feedback from your Chief Editor. Rewrite the content to address the feedback perfectly. Keep the original structure but sharpen the tone, remove fluff, and add specificity. Do not apologize or explain changes.";
        
        $user = <<<EOT
ORIGINAL PROMPT: $rawPrompt

CURRENT DRAFT:
$draft

EDITOR FEEDBACK (Implement these fixes):
- $fixes

TASK: Rewrite the post. Return ONLY JSON with field 'content'.
EOT;

        $response = $this->llm->call('reflexion_refine', $system, $user, 'post_generation'); // Reuses your existing post_generation schema/mode
        
        return $response['content'] ?? $draft;
    }
}

```

---

## 3. Integration into `ContentGeneratorService`

We will modify the main `generate` method to conditionally trigger this loop.

**Modifications required:**

1. **Dependency Injection:** Add `ReflexionService` to the `__construct`.
2. **Logic Insertion:** Add the loop after the initial draft generation and before the final validation.

**Updated `ContentGeneratorService.php` Flow:**

```php
// ... imports
use App\Services\Ai\Generation\Steps\ReflexionService;

class ContentGeneratorService
{
    public function __construct(
        // ... existing dependencies
        protected ReflexionService $reflexion // <--- Inject New Service
    ) {}

    public function generate(...): array
    {
        // ... [Standard Steps 1-4: Classification, Retrieval, Context Assembly] ...

        // 5) Compose Prompts & Initial Generation
        $promptObj = $this->composer->composePostGeneration($context, $constraints, $prompt);
        $gen = $this->runner->runJsonContentCall('generate', $promptObj);
        $draft = (string) ($gen['content'] ?? '');

        // --- NEW: CRITIC LOOP (REFLEXION) ---
        // Configurable via options (default false for safety/cost)
        $useReflexion = $options['use_reflexion'] ?? false;

        // Only run if requested and we actually have a draft to critique
        if ($useReflexion && !empty($draft)) {
            try {
                // 1. CRITIQUE
                $critique = $this->reflexion->critique($draft, $prompt, $context);
                
                // Log the critique (Visible in your debug logs)
                $this->cgLogger->capture('reflexion_critique', $critique);

                // 2. EVALUATE THRESHOLD
                // If the score is 9 or 10, we trust the first draft and skip the rewrite cost.
                $score = $critique['score'] ?? 0;
                
                if ($score < 9.0) {
                    // 3. REFINE
                    $improvedDraft = $this->reflexion->refine($draft, $critique, $prompt);
                    
                    // Safety check: Ensure we didn't get an empty response
                    if (!empty($improvedDraft)) {
                        $draft = $improvedDraft; // Replace the main draft variable
                        
                        $this->cgLogger->capture('reflexion_result', [
                            'status' => 'refined',
                            'original_score' => $score,
                            'new_content_len' => mb_strlen($draft)
                        ]);
                    }
                } else {
                    $this->cgLogger->capture('reflexion_skipped', ['reason' => 'high_quality_score', 'score' => $score]);
                }

            } catch (\Throwable $e) {
                // FAIL-SAFE: If the critic errors out, we simply log it and 
                // proceed with the original draft. The user flow is not interrupted.
                Log::warning('Reflexion loop failed', [
                    'run_id' => $runId, 
                    'error' => $e->getMessage()
                ]);
            }
        }
        // --- END CRITIC LOOP ---

        // 6) Validate + Repair (Run validation on the *new* draft)
        $res = $this->validatorRepair->validateAndRepair($draft, $context, $constraints);
        
        // ... [Remainder of function: Persistence and Return] ...
    }
}

```

---

## 4. Data & Logging Updates

To make this feature observable (so you can see *why* a post was rewritten), we will ensure the snapshot data includes the critique.

**In `ContentGeneratorService` -> `persistGeneration` logic:**

Update the `$optionsForSnapshot` array to include the new metadata:

```php
$optionsForSnapshot = array_merge($options, [
    // ... existing options ...
    'reflexion_run' => $useReflexion,
    'reflexion_score' => $critique['score'] ?? null,
    'reflexion_verdict' => $critique['verdict'] ?? null,
]);

```

## 5. Implementation Steps

1. **Create Service:** Copy the code in Section 2 to `app/Services/Ai/Generation/Steps/ReflexionService.php`.
2. **Register Dependency:** Ensure your Service Provider (e.g., `AppServiceProvider`) can auto-wire `ReflexionService`. (Laravel usually does this automatically).
3. **Update Generator:** Modify `ContentGeneratorService.php` constructor and `generate` method as shown in Section 3.
4. **Test:**
* Call `generate` with `options['use_reflexion'] => false`. Confirm strictly 1 LLM call is made (Draft).
* Call `generate` with `options['use_reflexion'] => true`. Confirm 3 LLM calls are made (Draft -> Critique -> Refine).
* Check logs to verify the `reflexion_critique` event contains the JSON feedback.