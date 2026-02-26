Below is a developer-ready engineering spec to fix retrieval ranking so **near-perfect semantic matches cannot be discarded**, and to make assist/injection behavior deterministic and observable.

---

# Engineering Spec: Retrieval Dominance + Assist Placement Fix (Retriever v1.3.1)

## Summary

Retrieval is failing despite near-perfect vector matches (e.g., distance ≈ 0.0937) because **post-vector ranking / filtering** (soft score, attribute penalties, trimming, injection gating) can remove the correct chunk before it reaches Top-K.

This spec introduces a **Near-Match Dominance invariant** (“protected candidates”) and reworks injection gating so assists can correct wrong selections without polluting results.

## Goals

1. **Near-match dominance:** If a chunk’s semantic distance is within `near_match_distance`, it must survive ranking and appear in the final set (or be force-injected).
2. **Assist placement:** “small_dense_assist” and sparse recall injections must run at a stage where the good candidate still exists.
3. **Deterministic behavior:** The same query + KB state yields stable output ordering.
4. **Observability:** We can explain why each candidate was kept/dropped.

## Non-Goals

* Changing ingestion (Phase 0) behavior.
* Retuning weights unless required after dominance is enforced.
* Improving LLM answer style (verbosity) (optional later).

---

# Current Behavior (Problem)

* Vector search produces correct candidates (shown in QA diagnostics top3).
* Ranking stage drops them via:

  * `soft_score_limit`
  * authority/confidence/time_horizon penalties
  * top-N trimming
  * injection gate “already in final” blocking better chunk within same KI
* Result: retrieval misses, generation abstains (“insufficient information”).

---

# Acceptance Criteria

For fixture `docs/fixtures/ingestion/factual_short.txt`:

### A. Hard invariant

* If `distance <= near_match_distance` then candidate:

  * is not removed by soft score
  * is not removed by authority/confidence/time penalties
  * appears in final Top-K (or is injected)

### B. Eval targets (same fixture)

* Synthetic QA hits@3: **≥ 2 / 3**
* Retrieval-on generation passes: **≥ 2 / 3**
* VIP-forced remains: **3 / 3**
* Faithfulness remains: **1.0**

### C. Regression safety

* For queries with no near-match candidates, current ranking/assist behavior remains broadly unchanged.

---

# Proposed Design

## 1) Add “protected candidate” concept

A candidate is **protected** if:

```
distance <= config('ai.retriever.near_match_distance')
```

Protected candidates must:

* bypass `soft_score_limit`
* bypass non-distance penalties (authority/confidence/time_horizon)
* bypass “distance thresholds” if those exist downstream (strict/fallback)
* be guaranteed to appear in final returned set, subject to excerpt caps (but see excerpt note below)

### Data fields on candidate (in-memory)

Add the following transient fields (no DB change):

* `__near_match` (bool)
* `__protected` (bool) (same as near_match for now)
* `__score_raw_distance` (float)
* `__score_composite` (float)
* `__drop_reason` (string|null)

> You already use `__near_match` in places; standardize it and make it authoritative.

---

## 2) Ranking pipeline order (must change)

### Current (likely)

Vector search → compute composite score → soft filter → trim → inject → final

### Required

1. Vector search (dense) → annotate distances
2. Mark protected candidates (`__protected = distance <= near_match_distance`)
3. Compute composite scores (but if protected, set score to distance-only or force best)
4. Apply filters:

   * If protected: bypass filters (except hard safety: excerpt caps / max per intent)
   * Else: apply existing soft score + thresholds
5. Trim / per-intent caps (protected items are reserved slots)
6. Assist/injection:

   * If no protected candidate exists, assists may inject
   * If a KI already has a protected candidate selected, injection cannot replace it
7. Final selection dedupe (KI-level rules; see below)

---

## 3) “Reserved slots” for protected candidates

When assembling final Top-K:

* Always include all protected candidates first (sorted by distance).
* Then fill remaining slots with best non-protected candidates by composite score.

If protected candidates exceed `return_k`:

* Keep the top `return_k` protected by distance.
* Emit log/warn: `protected_overflow`.

---

## 4) Fix injection gating and “same KI but better chunk” problem

Your dev already changed gating from “already in final” to “block only if protected already selected”. That’s correct direction. Lock it in and make it consistent everywhere.

### Rule

* Allow injection from same knowledge_item_id **unless** a protected candidate from that KI is already selected.

### Replacement rule (optional but recommended)

If a KI exists in final with a non-protected chunk, and an injected chunk for same KI is better by distance, allow replacing.

Pseudo:

```php
if (selected has KI with non_protected && injected distance < selected distance) replace
```

---

## 5) Query-aware “metric preference” (keep, but scope it)

The metric preference heuristic you added is reasonable, but it must never override protected distance dominance.

Rules:

* If protected candidate exists in the KI group, choose that regardless of role.
* Else if query looks numeric/price-like, prefer chunk_role=metric (your current heuristic).
* Else choose min distance.

---

## 6) Excerpt cap interaction (important)

If excerpt caps prevent a protected excerpt chunk from being included, that can violate dominance.

Fix:

* If a protected chunk is excluded solely due to excerpt cap, allow it anyway and decrement budget elsewhere, OR treat protected excerpt as “VIP excerpt” that bypasses the cap.

Recommended:

* `protected` bypasses excerpt cap, but mark `__excerpt_cap_bypassed=true` for observability.

---

# Implementation Plan

## Phase 1 — Instrumentation (no behavior changes)

Add per-candidate trace logging in `Retriever::knowledgeChunks()` and trace variant:

Log for top N candidates per query:

* `id`, `knowledge_item_id`
* `distance`
* `authority`, `confidence`, `time_horizon`, `chunk_role`, `chunk_type`
* computed composite score
* filters applied + drop reason

### Output format

Store into `knowledgeChunksTrace()` response and log file.

**Definition**

* `drop_reason` enum:

  * `soft_score_limit`
  * `strict_distance_threshold`
  * `fallback_distance_threshold`
  * `per_intent_cap`
  * `excerpt_cap`
  * `dedupe_ki`
  * `trim_top_n`
  * `unknown`

Deliverable:

* A trace shows exactly why the 0.0937 candidate was not in retrieved set.

---

## Phase 2 — Add protected candidate marking

Implement function:

```php
$markProtected = function($c) use ($nearMatchDistance) {
  $d = (float)($c->distance ?? $c->__distance ?? 1.0);
  $c->__score_raw_distance = $d;
  $c->__near_match = ($d <= $nearMatchDistance);
  $c->__protected = $c->__near_match;
  return $c;
};
```

Run it immediately after vector retrieval list is built, before scoring/filtering.

---

## Phase 3 — Enforce dominance in scoring/filtering

### Scoring

If protected:

* `compositeScore = distance` (or `0` plus tie-break by distance)
* do not apply penalties

If not protected:

* existing composite scoring

### Filtering

If protected:

* skip `soft_score_limit`
* skip penalty-based drops
* skip strict/fallback distance threshold drops (unless you have a hard ceiling for absolute garbage; protected already implies good)

---

## Phase 4 — Ensure protected items are guaranteed in final set

When assembling final list:

* Start with protected items (sorted by distance asc)
* Fill remaining from ranked non-protected

Add guarantee:

* If any protected exist and none appear in final, throw `PipelineInvariantViolation: ProtectedCandidateDropped` (in debug) and log error in prod.

---

## Phase 5 — Assist placement and gating

Move small_dense_assist and sparse_recall injection to run **after vector retrieval and protected marking** but **before final trimming** OR ensure they operate on the unfiltered candidate pool.

Update gating:

* block injection only if KI already has protected selected (`__near_match` true)

Replacement (recommended):

* allow injection to replace non-protected selection for same KI if it improves distance.

---

# Code Touch Points (expected)

### app/Services/Ai/Retriever.php

* `knowledgeChunks()`
* `knowledgeChunksTrace()`
* Any internal helper pipeline used by both

Add/modify:

* protected marking
* scoring adjustments
* filtering bypass for protected
* final assembly reserved slots
* injection gating consistency
* trace logging fields

### config/ai.php

No required changes for dominance; use existing:

* `near_match_distance`
* `soft_score_limit`
* thresholds
  Optional: add
* `protected_excerpt_bypass` (bool, default true)

---

# Test Plan

## Unit tests (fast)

1. **Protected dominance**

   * Provide candidate list with a protected item + others.
   * Ensure protected survives filter and is in final.
2. **Protected bypasses soft score**

   * Force composite score to fail soft score but distance qualifies; must still pass.
3. **Injection gating**

   * If protected exists for KI, injection should not add another from same KI.
   * If only non-protected exists, injection may add/replace.
4. **Metric preference**

   * For numeric query, metric chunk chosen among non-protected.
   * If protected non-metric exists, it wins.

## Eval harness (end-to-end)

Run:

```
php artisan ai:ingestion:eval ... factual_short ... --run-generation
```

Expected after dominance fix:

* Synthetic QA hits@3 improves (ideally 2/3 or 3/3)
* Retrieval-on generation improves correspondingly
* VIP-forced stays 3/3
* Faithfulness stays 1.0

---

# Rollout & Risk

* Dominance rule can increase recall and slightly increase noise if `near_match_distance` is too loose. Yours is `0.1`, which is appropriately strict.
* Add logging for:

  * count of protected candidates per query
  * protected_overflow
  * excerpt_cap_bypassed

---

# Deliverables

1. PR: Retriever dominance + assist placement + consistent gating
2. Trace logs: per-candidate scoring/drop reasons
3. Tests: unit + harness run proving ≥2/3 retrieval-on on fixture

---

# Definition of Done

* Protected candidate rule implemented and validated via trace.
* The previously missed 0.0937 chunk appears in retrieved set for that QA question.
* Harness run for `factual_short` yields:

  * hits@3 ≥ 2/3
  * retrieval-on ≥ 2/3
  * VIP-forced 3/3
  * faithfulness 1.0

---

If you paste the current `Retriever::knowledgeChunks()` full function (or even just the ranking/filtering section), I can write the exact patch (surgical diff) that implements this without refactoring the whole file.
