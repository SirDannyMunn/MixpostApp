## Engineering Spec: Canonicalize Business Summary for Prompt Composition

### Objective

Ensure prompt composition uses **only** `business_profile_snapshot.summary` as the **single, authoritative, information-dense** “Product context” sentence, and **never** concatenates other business-profile fields into the prompt.

This eliminates:

* Incomplete/truncated “Product context” lines
* Redundant/verbose context blobs
* Accidental leakage of other profile sections into prompt text
* Future regressions caused by profile schema growth

---

## Scope

**In scope**

* `BusinessProfileResolver` (or wherever the profile is resolved/assembled)
* `ContextFactory::fromParts()` and/or the `Context` object shape
* `PromptComposer` (use the canonical summary, stop merging)

**Out of scope**

* Retrieval / classification logic
* Knowledge relevance filtering
* Validation/repair
* LLM execution

---

## Current Problem

Even though `business_profile_snapshot.summary` exists and is complete, the pipeline is still:

1. mixing multiple business context fields (summary + positioning + offer + beliefs etc.)
2. applying length caps
3. producing truncated sentences in `Product context: ... that.`

This is a data-contract issue: prompt composition must consume **one canonical field**, not a merged blob.

---

## Requirements

### R1. Canonical source of “Product context”

`business_profile_snapshot.summary` is the only allowed source for the “Product context” line.

No other fields may be used for that purpose:

* `core_business_context.business_description`
* `offer_summary`
* `positioning[]`
* `why_we_win`
* `proof_points`, `facts`, `beliefs`
  …all must be excluded from “Product context”.

### R2. PromptComposer must not distill/trim product summary

PromptComposer must treat the canonical summary as **pre-normalized**:

* no truncation
* no sentence slicing
* no concatenation
* no punctuation munging beyond whitespace normalization

### R3. Summary quality guard

The canonical summary must be:

* non-empty
* ends with terminal punctuation (`.`, `!`, `?`, `…`)
* does not end with a dangling connector (`that`, `which`, `and`, `to`, `with`, `for`)

If it fails, fallback behavior is deterministic (see R4).

### R4. Deterministic fallback

If `business_profile_snapshot.summary` is missing or invalid:

* fallback to `core_business_context.business_description`
* else omit the product context line entirely (do not substitute with other profile fields)

### R5. Replay-driven iteration

Engineering must use the snapshot replay command to validate the prompt output after each change.

---

## Success Criteria (Hard Tests)

Run:

```bash
php artisan ai:replay-snapshot 019b86aa-c0b5-7130-b5f1-c496a83a0121 --prompt-only
```

### Pass conditions

1. Prompt contains a single line:

   * `Product context: <exact summary sentence>`
2. That line matches **exactly** the stored `business_profile_snapshot.summary` (allowing only whitespace normalization).
3. No truncated product context (no ending in `that.` or dangling punctuation).
4. Prompt contains **none** of:

   * `OFFER:`, `POSITIONING:`, `BELIEFS:`, `PROOF:`, `FACTS:`
5. Prompt does not contain long concatenated blobs of business profile text.

---

## Implementation Plan

### Step 1 — Add canonical summary field to Context

In `Context` (or equivalent typed context object), add:

* `public ?string $businessSummary = null;`

Populate this only from the canonical sources, in priority order.

### Step 2 — Compute canonical summary in BusinessProfileResolver (preferred)

In `BusinessProfileResolver->resolveForOrg(...)` (or wherever business context is assembled), extract:

```php
$summary = data_get($profile, 'business_profile_snapshot.summary');

if (! $this->isValidSummary($summary)) {
    $summary = data_get($profile, 'core_business_context.business_description');
}

$summary = $this->normalizeSummary($summary);
```

**Normalization** (safe only):

* trim
* collapse whitespace
* do NOT shorten or rephrase

**Validation helper**

```php
private function isValidSummary(?string $s): bool
```

Rules:

* non-empty
* length >= 40 chars (configurable)
* ends with terminal punctuation
* does not end with dangling connector words

### Step 3 — Ensure ContextFactory passes summary through

In `ContextFactory::fromParts([...])`, set:

* `$context->businessSummary = $resolvedSummary;`

Stop passing the entire business profile blob as “business_context” if PromptComposer currently consumes it for product context. Keep `business_context` only if still needed elsewhere, but PromptComposer must not use it for product context.

### Step 4 — Update PromptComposer to use canonical summary

Where PromptComposer currently renders:

* `Product context: <distilled businessContext>`

Replace with:

* `Product context: {$context->businessSummary}`

And ensure this is the **only** product-context line.

### Step 5 — Remove legacy business context merging logic

Delete/disable any code that:

* concatenates `summary + offer + positioning + beliefs`
* maps `AUDIENCE:` or `SUMMARY:` etc. into the prompt

Keep the “Audience/Positioning/Goal” lines if you want, but they must be derived from dedicated fields, not raw blob dumps. (This spec is only enforcing product context.)

---

## Iteration Workflow (Required)

Repeat until success criteria pass:

1. Run:

```bash
php artisan ai:replay-snapshot 019b86aa-c0b5-7130-b5f1-c496a83a0121 --prompt-only
```

2. Inspect “Product context” line:

* complete sentence
* matches stored summary
* no truncation

3. If failing:

* adjust `isValidSummary()` and selection order
* ensure PromptComposer is not post-processing the string

4. Re-run replay and compare.

---

## Regression Tests (Must Add)

### Test A — Canonical summary is used

Given a Context with:

* `business_profile_snapshot.summary = "SENTENCE A."`
* `core_business_context.business_description = "SENTENCE B."`

Assert prompt contains:

* `Product context: SENTENCE A.`

### Test B — Fallback works

If summary missing/invalid, assert it uses description.

### Test C — No concatenation

Assert prompt does **not** contain other known fields (offer_summary/positioning snippets) when summary is present.

---

## Definition of Done

* Snapshot replay shows `Product context` is exactly the canonical summary sentence (complete, not truncated).
* PromptComposer no longer uses merged business context blobs for product context.
* Tests prevent regression.

---
