
## Engineering Spec: Retrieval Optimization & Scoring Reform

### Version

**v1.3.0**

### Status

Required – blocks retrieval performance and long-term RAG quality

---

## 1. Problem Statement

The evaluation harness has proven the following facts:

1. **Normalization is faithful**
2. **Embeddings are correct**
3. **Generation works when given correct context**
4. **Retrieval recall is failing**

The failure mode is consistent:

* Correct chunks appear in **Top-N nearest neighbors**
* They are **filtered out before ranking**
* Hard cosine distance thresholds suppress valid results
* Generation succeeds only when retrieval is bypassed (VIP injection)

**Conclusion:**
Retrieval is not failing due to semantic mismatch.
It is failing due to **over-aggressive distance gating**.

---

## 2. Design Goal

Replace *binary distance filtering* with a **ranking-first retrieval model** that:

* Maximizes recall
* Preserves precision via downstream checks
* Uses generation faithfulness as the final guardrail
* Is measurable, repeatable, and CI-safe

---

## 3. Scope (Explicit)

### In Scope

* Retrieval filtering logic
* Scoring & ranking
* Threshold calibration
* Evaluation-driven optimization

### Out of Scope

* Deduplication
* Re-ingestion correctness
* Vector generation
* Chunking strategy
* Long-term learning / feedback loops

---

## 4. Current Architecture (Observed)

```
Query
 └─> Vector Search
      └─> Distance Threshold Filter (STRICT)
           └─> Distance Threshold Filter (FALLBACK)
                └─> Rank
                     └─> Return K
```

### Problem

Correct chunks are removed **before ranking**, even when they are semantically closest.

---

## 5. Target Architecture

```
Query
 └─> Vector Search (Top-N)
      └─> Rank (multi-factor)
           └─> Soft threshold / score floor
                └─> Return K
                     └─> Generation Probe
                          └─> Faithfulness Gate
```

---

## 6. Required Changes

### 6.1 Retrieval Strategy Change (Mandatory)

**Replace hard filtering with ranked retrieval.**

#### Before

```php
WHERE distance < strict_threshold
```

#### After

```php
SELECT top_N
ORDER BY composite_score ASC
```

Distance becomes **one input**, not a gate.

---

### 6.2 Introduce Composite Retrieval Score

Each chunk receives a score:

```
score =
  (distance_weight * cosine_distance)
+ (authority_weight * authority_penalty)
+ (confidence_weight * confidence_penalty)
+ (time_weight * time_penalty)
```

#### Initial Weights (Baseline)

| Factor       | Weight |
| ------------ | ------ |
| Distance     | 0.6    |
| Authority    | 0.15   |
| Confidence   | 0.15   |
| Time Horizon | 0.1    |

> These are tuning knobs, not constants.

---

### 6.3 Soft Threshold (Optional but Recommended)

Instead of filtering by distance:

* Always retrieve Top-N (e.g. 20)
* Drop only results with **absurd scores** (e.g. > 0.9)

This prevents catastrophic recall loss.

---

### 6.4 Preserve Generation Probe as Safety Net

No retrieval change is allowed to ship unless:

* Generation Probe pass rate ≥ threshold
* Faithfulness score = 1.0

Retrieval noise is acceptable **only if generation remains correct**.

---

## 7. Configuration Changes

Add to `config/ai.php`:

```php
'retriever' => [
    'top_n' => 20,
    'return_k' => 5,

    'weights' => [
        'distance' => 0.6,
        'authority' => 0.15,
        'confidence' => 0.15,
        'time_horizon' => 0.1,
    ],

    'soft_score_limit' => 0.9,
]
```

---

## 8. Evaluation Integration

No changes required to:

* Faithfulness Audit
* Synthetic QA
* Generation Probe

These already provide correct signals.

---

## 9. Success Criteria (Engineering-Level)

This spec is considered **complete** when all of the following are true:

| Metric               | Requirement       |
| -------------------- | ----------------- |
| Synthetic QA recall  | ≥ 66% (2/3)       |
| Preferred target     | 100% (3/3)        |
| Generation pass rate | ≥ 90%             |
| Faithfulness         | Score = 1.0       |
| Artifacts integrity  | embedded == total |
| CI exit code         | 0                 |

---

## 10. Non-Negotiable Constraints

* Retrieval must never suppress correct chunks that are Top-N neighbors
* Distance must not be used as a hard truth filter
* Optimization must be driven exclusively by evaluation reports

---
