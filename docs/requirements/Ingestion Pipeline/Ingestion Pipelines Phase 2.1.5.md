Supreme leader,

Below is a **clean, final engineering spec** to resolve the remaining raw-mode leak and permanently stabilize Phase 2.1.x. This is written to be copy-pasted into your repo as the authoritative fix.

---

# Engineering Spec

## Phase 2.1.5 — Deterministic Chunk Classification (Raw Mode Fix)

**Status:** Required
**Priority:** P0 (pipeline correctness)
**Scope:** Ingestion / Knowledge Pipeline
**Related Phases:** 2.1.3, 2.1.4

---

## 1. Problem Summary

After implementing idempotent chunking and variant-scoped classification, the pipeline still produces **classification count mismatches in raw mode**.

### Symptom

```
ChunkKnowledgeItemJob:
  created_chunks: 1
ClassifyKnowledgeChunksJob:
  expected: 1
  got: 3
```

### Impact

* Pipeline aborts in debug mode
* Non-deterministic classification input
* Knowledge graph pollution risk
* Prevents safe backfills and reprocessing

---

## 2. Root Cause (Confirmed)

### Key Insight

**Variant scoping alone is insufficient.**

Classification currently operates on:

```
(knowledge_item_id, source_variant)
```

But this still allows **stale chunks from previous runs** to be selected.

### Why normalized mode works

* Normalized claims are always regenerated
* Normalized chunks are always fully deleted and recreated
* No historical leftovers exist

### Why raw mode fails

* Raw chunks may pre-exist from:

  * earlier pipeline versions
  * failed runs
  * runs before `source_variant` existed
* Current deletion logic does **not fully clear raw chunks**
* Classification sees leftovers

---

## 3. Required Invariant (Authoritative)

> **Classification must operate on chunks created by the current run — not merely chunks that exist for a variant.**

Expressed formally:

```
Classification input = chunks(created_in_this_run)
NOT chunks(existing_for_variant)
```

Until run-scoping exists, raw-mode deletion must be total.

---

## 4. Solution Overview

### Phase 2.1.5 Strategy (Minimal & Safe)

**Make raw-mode chunking fully destructive per run.**

That guarantees:

* No stale chunks
* Deterministic classification
* Correct `expected == got`

This mirrors normalized mode behavior.

---

## 5. Detailed Requirements

### 5.1 ChunkKnowledgeItemJob — Raw Mode Cleanup (Critical)

#### Current (Insufficient)

* Deletes raw chunks conditionally
* Leaves historical raw chunks behind

#### Required Behavior

When chunking in **raw mode**, delete **all raw chunks** for the KnowledgeItem.

#### Required Logic

```php
if ($variant === 'raw') {
    KnowledgeChunk::where('knowledge_item_id', $knowledgeItem->id)
        ->where('source_variant', 'raw')
        ->delete();
}
```

No additional conditions.
No quality checks.
No “if exists” logic.

Raw chunks are **always ephemeral**.

---

### 5.2 Normalized Mode (No Change)

Normalized mode already behaves correctly:

* Normalized claims are regenerated
* Normalized chunks are deleted and recreated
* Classification is deterministic

No changes required.

---

## 6. Classification Job Requirements (Confirm / Lock)

### 6.1 Selection Scope (Already Implemented)

Classification must:

* Resolve active variant:

  * `normalized` if claims exist
  * else `raw`
* Select chunks by:

  ```
  knowledge_item_id
  AND source_variant = active_variant
  ```
* Order deterministically (`created_at asc`)

### 6.2 Debug Invariant (Must Remain)

In debug mode:

```php
if ($expected !== $actual) {
    throw new PipelineInvariantViolation();
}
```

This behavior is correct and must not be relaxed.

---

## 7. End-to-End Expected Behavior

### 7.1 Raw-Mode Item (Low Quality)

1. Bookmark resolved internally
2. `KnowledgeItem.raw_text` populated
3. Normalization skipped
4. **All raw chunks deleted**
5. Raw chunks recreated
6. Classification sees exactly N chunks
7. `expected == got`
8. Pipeline completes

### 7.2 Normalized-Mode Item (High Quality)

1. Bookmark resolved internally
2. Normalization produces claims
3. Normalized chunks deleted
4. Normalized chunks recreated
5. Classification scoped to normalized
6. `expected == got`
7. Pipeline completes

---

## 8. Acceptance Criteria

The fix is complete when:

* [ ] Raw-mode items no longer trigger mismatch errors
* [ ] `ingestion:backfill --debug` runs cleanly
* [ ] Re-running backfill produces identical results
* [ ] No stale raw chunks persist between runs
* [ ] Debug invariants remain strict
* [ ] Normalized mode remains unchanged

---

## 9. Explicit Non-Goals

* Adding run IDs to chunks (future improvement)
* Changing normalization thresholds
* Altering quality scoring
* Relaxing debug invariants
* Introducing new variants

---

## 10. Future Improvement (Deferred)

**Phase 2.2 (Optional): Run-Scoped Chunking**

Add `run_id` to `knowledge_chunks` and classify strictly by run.
This removes the need for destructive deletion and enables parallel runs.

Not required for Phase 2.1 stability.

---

## 11. One-Line Fix Summary

> **Raw chunks must be treated like normalized chunks: delete them all, every run.**

Once this is implemented, **Phase 2.1 is complete and stable**.

If you want, next step I can:

* sanity-check the exact diff before you commit, or
* draft the Phase 2.2 run-scoping spec cleanly.
