Below is a **primary design + engineering spec** you can hand directly to implementation. It is opinionated, scoped, and minimizes refactors while fixing the root issue.

---

# Prompt Build & Replay Architecture

**Primary + Engineering Specification**

## Objective (Primary)

Introduce a **prompt-only build and replay capability** that:

* Allows inspecting the *exact* `(system, user)` prompt sent to the LLM
* Prevents internal data / JSON object leakage into prompts
* Enables fast debugging, iteration, and diffing of prompt changes
* Does **not** pollute or fork the generation pipeline with debug flags

This is a **foundational control and observability feature**, not a debugging hack.

---

## High-Level Design

### Core Principle

> **Prompt construction must be a first-class, side-effect-free operation.**

Execution (LLM calls, validation, persistence) is optional and downstream.

---

## Architectural Changes (Minimal, Correct)

### New Concept: `PromptBuildResult`

A stable artifact representing the final prompt state.

```php
final class PromptBuildResult
{
    public function __construct(
        public string $system,
        public string $user,
        public array $meta,           // classification, template, constraints
        public array $contextSummary, // redacted, human-readable
    ) {}
}
```

**Rules**

* No raw DB records
* No IDs unless explicitly useful
* No JSON dumps
* Text only for `system` and `user`

---

## ContentGeneratorService Refactor

### 1. Add a prompt-only build method

```php
public function buildPrompt(
    string $orgId,
    string $userId,
    string $prompt,
    string $platform,
    array $options = []
): PromptBuildResult
```

#### Responsibilities

Runs **Steps 0–4 only**:

* VIP override resolution
* Classification
* Retrieval
* Business profile integration
* Template & voice resolution
* Context assembly
* Prompt composition

#### Explicitly does NOT:

* Call LLMs
* Validate content
* Repair drafts
* Persist snapshots
* Generate quality reports

---

### 2. Refactor `generate()` to delegate

```php
$promptBuild = $this->buildPrompt(...);

if ($options['mode'] === 'prompt_only') {
    return [
        'system' => $promptBuild->system,
        'user'   => $promptBuild->user,
        'meta'   => $promptBuild->meta,
        'context_summary' => $promptBuild->contextSummary,
    ];
}

// existing generation logic continues
```

**Important**

* No `if (debug)` checks inside pipeline steps
* One clean branch at the boundary between *build* and *execute*

---

## PromptComposer Hard Rule (Critical)

### New invariant

> **PromptComposer may accept rich domain objects, but may emit text only.**

#### Enforcement rules

PromptComposer **must never output**:

* JSON blobs
* Arrays or serialized objects
* Internal labels like `KNOWLEDGE:`, `TEMPLATE_DATA:`
* IDs, scores, recall flags, similarity metrics

#### Required behavior

* Knowledge → summarized bullet points
* Templates → structural instructions
* Swipes → compressed structural hints
* Business context → short positioning paragraph

If raw data appears in the prompt, that is a **bug**.

---

## Context Summary (for Debugging Only)

`PromptBuildResult::$contextSummary` should include:

```php
[
  'classification' => [
    'intent' => 'educational',
    'funnel_stage' => 'tof',
    'overridden' => false,
  ],
  'template' => [
    'id' => 'authority_hook_v1',
    'structure' => ['Hook', 'Context', 'Value', 'CTA'],
  ],
  'retrieval' => [
    'knowledge_chunks_used' => 3,
    'business_facts_used' => 0,
    'vip_overrides' => false,
  ],
  'constraints' => [
    'max_chars' => 2000,
    'emoji' => 'disallow',
    'tone' => 'authority',
  ],
]
```

This is **never sent to the model**.

---

## New Artisan Command

### `ai:replay-snapshot`

#### Modes

```bash
php artisan ai:replay-snapshot {snapshot_id} --prompt-only
php artisan ai:replay-snapshot {snapshot_id} --full
```

#### `--prompt-only`

* Rebuilds context via `ContextFactory::fromSnapshot`
* Calls `PromptComposer`
* Outputs:

  * system prompt
  * user prompt
  * token counts
  * context summary

No LLM calls. No persistence.

---

## Engineering Tasks Breakdown

### Phase 1 — Prompt Isolation (Required)

* [ ] Introduce `PromptBuildResult`
* [ ] Add `buildPrompt()` to `ContentGeneratorService`
* [ ] Refactor `generate()` to call it
* [ ] Ensure no prompt logic remains outside `PromptComposer`

### Phase 2 — PromptComposer Compression (Required)

* [ ] Replace all raw dumps with summaries
* [ ] Remove labeled JSON sections from prompts
* [ ] Add internal tests asserting no `{`, `[`, or `"id":` in prompts

### Phase 3 — Replay Tooling (Required)

* [ ] Implement `ai:replay-snapshot --prompt-only`
* [ ] Add `ai:show-prompt {run_id}` shortcut
* [ ] Log prompt hashes for diffing

---

## Non-Goals (Explicit)

* No partial execution modes
* No debug flags scattered through pipeline
* No “just for now” JSON passthroughs
* No dual prompt formats

---

## Why This Is the Right Design

* Aligns with your **replayable, transparent, power-user positioning**
* Makes prompt behavior testable and inspectable
* Prevents prompt entropy as features grow
* Keeps generation quality predictable and improvable
