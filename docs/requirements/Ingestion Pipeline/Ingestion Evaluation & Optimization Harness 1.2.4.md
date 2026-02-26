Below is a **clean, complete engineering specification** that directly addresses the *remaining* problem revealed by your latest diff and report. This spec is narrowly scoped, actionable, and aligned with your stated philosophy: **generation is truth, retrieval must be observable, and nothing eval-specific should pollute production semantics.**

---

# Engineering Specification

## Retrieval Recall Stabilization & End-to-End Validation

---

## 1. Problem Statement (Precisely Scoped)

The ingestion evaluation system correctly proves that:

* Ingested content is **faithful**
* Ingested content is **usable by generation when forced**
* Embeddings and normalization are **working as intended**

However, the system still exhibits a **retrieval recall failure**:

> Correct chunks are among the **top semantic neighbors** (distance ≈ 0.09)
> but are **filtered out during selection**, resulting in low retrieval hit rates.

This is no longer an embedding or distance problem.
It is a **selection-stage over-filtering problem**.

---

## 2. Root Cause Analysis (Confirmed)

Based on code and eval output, retrieval misses occur due to **three compounding factors**:

### 2.1 Soft Score Threshold Is Acting as a Hard Gate

```php
if ($row->__composite > $softScoreLimit) continue;
```

* Composite score includes non-semantic penalties
* Normalized claims are short → confidence penalties inflate
* Soft score limit (0.90) rejects otherwise valid chunks

**Result:**
Semantically perfect candidates are discarded post-ranking.

---

### 2.2 Normalized Variant Preference Is Applied Too Early

```php
if ($hasNormalized[$ki] && $variant !== 'normalized') continue;
```

* Applied **before** final Top-K selection
* Causes correct raw/normalized siblings to be dropped
* Biases selection toward weaker but normalized claims

**Result:**
Correct chunk ranks high, then gets filtered.

---

### 2.3 Phase C Masks Retrieval Defects

* Generation probe uses `vipOverrides`
* Retrieval is bypassed by design
* Retrieval regressions cannot fail the run

**Result:**
System passes while retrieval is still degraded.

---

## 3. Design Goals

This spec aims to achieve the following **explicit outcomes**:

1. **Never drop a top semantic neighbor before Top-K**
2. **Separate ranking from selection**
3. **Measure retrieval recall independently from generation**
4. **Preserve production parity**
5. **Make retrieval failures visible, not fatal**

---

## 4. Proposed Architecture Changes

---

## 4.1 Two-Stage Retrieval Pipeline (Mandatory)

### Stage 1 — Candidate Ranking (Recall-First)

**Rules:**

* Rank *all* candidates by composite score
* Do **not** apply:

  * Soft score limits
  * Variant preference
  * Excerpt caps

```php
$ranked = rankByCompositeScore($rows);
$topK = array_slice($ranked, 0, $limit * 3);
```

This stage exists purely to **maximize recall**.

---

### Stage 2 — Selection & Preference (Precision Layer)

Apply filtering **only after Top-K is fixed**:

1. Enforce normalized preference **within Top-K**
2. Apply excerpt caps
3. Optionally apply soft score trimming (logging only)

```php
$selected = applyPreferences($topK);
$final = array_slice($selected, 0, $limit);
```

**Invariant:**

> If a chunk is in Top-K semantically, it must be eligible for return.

---

## 4.2 Soft Score Limit Becomes Observational Only

### Change

* Remove hard `continue` on `soft_score_limit`
* Replace with diagnostic tagging

```php
$row->__soft_rejected = $row->__composite > $softScoreLimit;
```

### Reporting

Include in trace:

```json
{
  "soft_rejected": true,
  "composite_score": 0.94
}
```

**Rationale:**
Soft thresholds are tuning tools, not correctness gates.

---

## 4.3 Variant Preference Is Deferred

### Rule Change

* Variant preference must **never** remove a candidate
* It may only:

  * Reorder candidates
  * Break ties
  * Influence final slice

This ensures normalization helps precision **without harming recall**.

---

## 5. Evaluation Harness Enhancements

---

## 5.1 Dual Generation Probes (Required)

### Probe A — Retrieval-On Generation

```php
ContentGeneratorService::generate(
  useRetrieval: true,
  vipOverrides: null
)
```

**Purpose:**
Detect real-world failures where retrieval fails to surface required context.

---

### Probe B — VIP-Forced Generation (Existing)

```php
ContentGeneratorService::generate(
  useRetrieval: false,
  vipOverrides: ['knowledge' => [$id]]
)
```

**Purpose:**
Verify content usability independent of retrieval.

---

### Verdict Rules

| Scenario                   | Verdict                 |
| -------------------------- | ----------------------- |
| Probe A pass, Probe B pass | ✅ Healthy               |
| Probe A fail, Probe B pass | ⚠️ Retrieval regression |
| Probe A fail, Probe B fail | ❌ Ingestion failure     |

---

## 6. Success Criteria (Non-Negotiable)

A build is considered **correct** only if all conditions hold:

### Retrieval Metrics

* Synthetic QA **Hits@3 ≥ 80%**
* No missed retrieval where:

  * distance ≤ 0.20
  * chunk appears in Top-3 diagnostic

### Generation Metrics

* VIP-Forced Generation: **100% pass**
* Retrieval-On Generation: **≥ 80% pass**

### Invariants

* No hard distance or composite gates
* No candidate filtered before Top-K
* All misses explainable via diagnostics

---

## 7. Rollout Plan

### Phase 1 — Safe Refactor

* Implement two-stage retrieval
* Disable soft score gating
* Defer variant preference

### Phase 2 — Harness Upgrade

* Add Retrieval-On Generation Probe
* Update report schema

### Phase 3 — Tuning

* Reintroduce soft score limits as warnings
* Adjust weights empirically using eval runs

---

## 8. Non-Goals (Explicit)

This spec does **not** attempt to:

* Optimize embedding models
* Improve LLM prompting
* Tune authority/confidence heuristics
* Fix content verbosity

Those are downstream concerns.

---

## 9. Final Assessment

Your system is now **architecturally correct**.
This spec resolves the **last correctness gap** between:

> “The right chunk exists”
> and
> “The right chunk is reliably retrieved.”

Once implemented, retrieval failures will be **real, explainable, and actionable** — not artifacts of over-filtering.

If you want, the next step would be:

* a concrete refactor diff for `Retriever::knowledgeChunks()`
* or a checklist for validating this spec against future regressions
