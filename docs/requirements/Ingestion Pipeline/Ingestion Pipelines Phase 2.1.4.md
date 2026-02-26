Supreme leader,

Below is a **clean, implementation-ready requirements document** to fix the current regression and lock the pipeline correctly. This is written as an internal engineering spec, not commentary.

---

# Requirements: Fix Chunking Regression & Finalize Variant-Safe Pipeline

**Phase:** 2.1.4
**Status:** Required
**Owner:** Ingestion / Knowledge Pipeline

---

## 1. Problem Statement

After implementing idempotent chunking and variant-scoped classification (Phase 2.1.3), the ingestion pipeline now **produces no output** for certain items.

### Observed Behavior

* `ChunkKnowledgeItemJob` exits early with:

  ```
  status: skipped
  reason: no_chunkable_input
  ```
* This occurs even when `KnowledgeItem.raw_text` is present and valid.

### Root Cause

Chunking eligibility is incorrectly **gated by variant state or existing chunks**, rather than by **text availability**.

Specifically:

* Existing chunks are deleted *before* chunking
* The job then checks for variant-scoped chunk presence
* This results in a false “no input” condition

This violates the core pipeline invariant.

---

## 2. Non-Negotiable Invariants

### 2.1 Chunking Invariant (Primary Fix)

> **Chunking MUST be driven by text presence, never by variant state or existing chunks.**

Formal rule:

```
IF KnowledgeItem.raw_text exists
→ Chunking MUST run
→ Variant affects HOW chunking happens, not WHETHER
```

Variant selection must never suppress chunk creation.

---

### 2.2 Variant Responsibility Boundaries

| Concern              | Variant Allowed to Affect | Variant NOT Allowed to Affect |
| -------------------- | ------------------------- | ----------------------------- |
| Chunk deletion       | ✅ yes                     |                               |
| Chunk creation logic | ✅ yes                     |                               |
| Classification scope | ✅ yes                     |                               |
| Chunking eligibility |                           | ❌ no                          |
| Input validation     |                           | ❌ no                          |

---

## 3. Required Functional Changes

### 3.1 ChunkKnowledgeItemJob (Critical)

#### Current (Incorrect)

* Determines “chunkable input” by inspecting:

  * existing chunks
  * variant-scoped state
* Skips after deleting chunks

#### Required Behavior

Chunking eligibility must be **text-based only**.

#### Required Logic (Authoritative)

```php
if (blank($knowledgeItem->raw_text)) {
    skip('no_chunkable_input');
}

$variant = resolveVariant($knowledgeItem);

// idempotency
deleteExistingChunks($knowledgeItem->id, $variant);

// creation
createChunksFromText(
    text: $knowledgeItem->raw_text,
    variant: $variant
);
```

#### Explicitly Forbidden

* Checking for existing chunks to decide eligibility
* Checking for normalized claims to decide eligibility
* Skipping because a variant has “no input”

---

### 3.2 Variant Resolution Rules (Clarified)

Variant selection determines **chunking mode**, not input.

#### Resolution Order

1. If normalized claims exist → `variant = normalized`
2. Else → `variant = raw`

Regardless of variant:

* Input source = `KnowledgeItem.raw_text`

---

## 4. Classification Requirements (Already Mostly Correct)

### 4.1 Variant-Scoped Classification (Confirmed)

Classification must:

* Select chunks by `(knowledge_item_id, source_variant)`
* Never mix raw + normalized in a single run
* Operate only on chunks created by the current chunking pass

### 4.2 Debug Invariant (Keep)

In debug mode:

```
expected_chunk_count == classified_chunk_count
```

Mismatch MUST throw `PipelineInvariantViolation`.

This behavior is correct and should remain.

---

## 5. Pipeline End-to-End Expected Behavior

### 5.1 Low-Quality Bookmark (No Normalization)

1. Bookmark resolved internally
2. `KnowledgeItem.raw_text` populated
3. Normalization skipped (low quality)
4. Chunking runs in **raw mode**
5. Raw chunks created
6. Classification scoped to raw
7. Pipeline completes successfully

### 5.2 High-Quality Bookmark (With Normalization)

1. Bookmark resolved internally
2. `KnowledgeItem.raw_text` populated
3. Normalization produces claims
4. Chunking runs in **normalized mode**
5. Normalized chunks created
6. Classification scoped to normalized
7. Pipeline completes successfully

---

## 6. Acceptance Criteria

The fix is complete when:

* [ ] `ChunkKnowledgeItemJob` never skips if `raw_text` exists
* [ ] Deleting chunks does not suppress chunk creation
* [ ] Variant affects mode, not eligibility
* [ ] `expected == got` in classification logs
* [ ] `ingestion:backfill --debug` produces deterministic output
* [ ] No stale or duplicate chunks remain after reprocessing

---

## 7. Out of Scope (Explicit)

* Changing normalization thresholds
* Changing quality scoring
* Changing retrieval logic
* Introducing new variants
* Altering business facts or voice extraction

---

## 8. Summary (One-Line Fix)

> **Replace variant-based chunking gates with a single rule:
> “If raw_text exists, chunk.”**

Once applied, Phase 2.1.x is complete and stable.

---

If you want, next step I can:

* sanity-check the exact code diff, or
* help you write a one-off cleanup command to purge stale chunks safely.

---

# Implementation Notes (2026-01-02)

- `app/Jobs/ChunkKnowledgeItemJob.php`
  - Eligibility now uses only `KnowledgeItem.raw_text` presence. If empty → skip; otherwise always chunk.
  - Variant resolution remains: prefers `normalized` when claims exist, else `raw`.
  - Idempotency preserved: deletes existing chunks for the selected `source_variant` before creating new chunks.
- `app/Jobs/ClassifyKnowledgeChunksJob.php`
  - Continues variant-scoped selection and debug invariant `expected == got`.

These changes fix the regression where chunking was incorrectly suppressed by variant state and ensure stable, deterministic runs.
