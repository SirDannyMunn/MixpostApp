# Research Mode v2 – Debug & Fix Spec (Empty Results + Wrong Prompt)

This spec explains **why Research Mode now returns the correct API shape but empty results**, and **why the wrong system prompt is being used**, then defines the fixes.

---

## Current Observed Behavior

### What’s Working

* `options.mode = research` is now respected by `/ai/chat`
* API response shape is correct
* `command = null`
* `metadata.mode = research`
* `research_stage = trend_discovery`

### What’s Broken

1. **Wrong system prompt**

   * Using normal content-generation system prompt
   * Not using `"You are a neutral research analyst"`

2. **Empty research results**

   * `trends: []`
   * `items_considered: 0`
   * `platforms: []`
   * Snapshot ID missing

3. **CLI and Chat diverge**

   * CLI → populated research
   * Chat → empty research

---

## Root Cause Analysis

### Root Cause 1 – Research Prompt Composer Not Selected

Your logs show:

* Final user prompt is correct: `Research whether SEO will be dead because of AI soon`
* **System prompt is wrong**

This means:

* `ResearchPromptComposer` is **not being selected**
* The pipeline is still using the **generation prompt composer**

#### Why this happens

Somewhere in the pipeline:

```php
$composer = PromptComposerFactory::for($request);
```

is resolving to:

* `GenerationPromptComposer`

instead of:

* `ResearchPromptComposer`

Likely causes:

* `research_stage` not propagated into the composer factory
* Factory switching only on intent, not on `options.mode`

---

### Root Cause 2 – Trend Discovery Retrieval Is Never Invoked

From the response:

```json
"trend_meta": {
  "items_considered": 0,
  "platforms": []
}
```

This proves:

* No retrieval happened
* No fallback search happened

That means:

* `TrendDiscoveryService` was never called
* Or it early-returned due to missing inputs

Common causes:

* `industry` not inferred or passed
* `platforms` defaulting to empty array
* Guard clause like:

```php
if (empty($platforms)) return [];
```

---

### Root Cause 3 – Chat Path Skips CLI Defaults

CLI sets **implicit defaults**:

* `days_back = 30`
* `platforms = ['youtube', 'x']`
* `limit = 40`

Chat path currently sends:

```json
"options": {
  "mode": "research",
  "research_stage": "trend_discovery"
}
```

So:

* No platforms
* No industry
* No limits

Trend discovery runs with empty config → empty output.

---

## Required Fixes

---

## Fix 1 – Hard-Switch Prompt Composer on `mode=research`

### Change

In `PromptComposerFactory`:

```php
if ($request->options->mode === 'research') {
    return new ResearchPromptComposer($request);
}
```

**Ignore intent, template, or document context**.

Research Mode must always use:

* Neutral system prompt
* Research-only instructions

---

## Fix 2 – Call the Correct Research Stage Service

Add explicit routing:

```php
switch ($request->options->research_stage) {
  case 'trend_discovery':
    return TrendDiscoveryService::run($request);
  case 'deep_research':
    return DeepResearchService::run($request);
  case 'angle_hooks':
    return HookResearchService::run($request);
}
```

Do not reuse generation entrypoints.

---

## Fix 3 – Apply Defaults in Chat Path (Match CLI)

In `GenerationRequest::normalize()`:

```php
if ($mode === 'research' && $stage === 'trend_discovery') {
    $options->platforms ??= ['x', 'youtube'];
    $options->days_back ??= 30;
    $options->recent_days ??= 7;
    $options->limit ??= 40;
}
```

This guarantees non-empty discovery.

---

## Fix 4 – Industry Inference (Required)

If `industry` missing:

```php
$options->industry = IndustryClassifier::infer($request->message);
```

Trend discovery without industry is undefined behavior.

---

## Fix 5 – Snapshot Persistence Consistency

Chat research must call:

```php
SnapshotPersister::storeResearchSnapshot(...)
```

Not the generation snapshot path.

Snapshot ID must be returned.

---

## Fix 6 – Add Guardrail Logging

Add a single structured log per research request:

```json
{
  "mode": "research",
  "stage": "trend_discovery",
  "composer": "ResearchPromptComposer",
  "platforms": ["x", "youtube"],
  "items_considered": 42
}
```

If `items_considered === 0`, log at WARN.

---

## Expected Behavior After Fix

For the same request:

```json
{
  "message": "research whether seo will be dead because of ai soon",
  "options": {
    "mode": "research",
    "research_stage": "trend_discovery"
  }
}
```

You should see:

* Neutral research system prompt
* Non-empty `trends[]`
* `items_considered > 0`
* Snapshot ID populated
* Identical output (conceptually) to CLI

---

## Why This Happened (One Sentence)

You fixed **execution mode**, but not **research-specific routing and defaults** — so the request entered Research Mode, but never actually *did* any research.

---

## Next Optional Hardening

* Assert: `mode=research` cannot touch generation code paths
* Add unit tests per research stage
* Add a dev-only banner in chat: “Research Mode Active”

---

## Bottom Line

Your architecture is still correct.

This is a classic partial-wiring issue:

* Mode flag ✔
* Read-only ✔
* Stage routing ❌
* Defaults ❌
* Prompt composer ❌

Fix those five points and Research Mode v2 will be fully functional.
