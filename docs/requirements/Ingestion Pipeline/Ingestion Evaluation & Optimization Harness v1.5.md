# Appendix A — Advanced Ingestion Evaluation Extensions (Phase 1.5 → Phase 2)

This appendix extends the *Ingestion Evaluation & Optimization Harness* with **objective correctness, retrieval realism, and robustness testing**. These additions transform the system from a reporting tool into a **closed-loop optimization engine**, while preserving debuggability and phased rollout.

These extensions are **optional but strongly recommended**, and should be introduced progressively.

---

## A1. Faithfulness & Semantic Drift Evaluation (Phase 1 — REQUIRED)

### Motivation

Normalization is a **creative transformation step**. Even with low temperature, it can:

* hallucinate claims
* introduce subtle semantic drift
* poison downstream agents with false premises

Coverage alone is insufficient. A claim can exist and still be wrong.

### New Evaluation Dimension: `faithfulness`

**Definition:**
The degree to which normalized claims are *fully supported* by the source document, without invention, contradiction, or distortion.

### Mechanisms

#### A1.1 LLM-Based Faithfulness Audit

After normalization and chunking, run a structured critique:

**Input**

* Original document (or excerpt + hash)
* Normalized claims (text + confidence + authority)
* Mapping of claims → source spans (best effort)

**LLM Task**

> Identify any normalized claims that:
>
> * introduce facts not present in the source
> * contradict the source
> * overstate certainty beyond the source

**Output (strict JSON)**

```json
{
  "faithfulness_score": 1.0,
  "hallucinations_detected": [
    {
      "claim_id": "uuid",
      "issue": "invented_detail",
      "evidence": "not present in source"
    }
  ]
}
```

#### A1.2 Semantic Drift Heuristic (Cheap, Deterministic)

To supplement the LLM audit:

* Embed:

  * source paragraph(s)
  * normalized claim
* Compute cosine similarity
* Flag:

  * `< 0.70` → `high_drift`
  * `< 0.55` → `critical_drift`

This is **not a blocker**, but a **warning signal** surfaced in the report.

### Report Additions

```json
{
  "faithfulness": {
    "score": 0.92,
    "hallucinations_detected": [],
    "semantic_drift_flags": [
      { "claim_id": "uuid", "similarity": 0.63 }
    ]
  }
}
```

---

## A2. Synthetic QA Evaluation (Phase 1 — LIGHTWEIGHT)

### Motivation

Subjective “usefulness” scores are unstable.
Synthetic QA introduces a **hard, objective retrieval metric**.

### Concept

Generate a small **golden test set** of questions that *must* be answerable from the ingested document. Then verify whether retrieval surfaces the correct chunks.

This tests **retrieval readiness**, not generation quality.

---

### A2.1 Synthetic QA Generation

Immediately after ingestion, generate **2–3 factual questions**.

**Constraints**

* Each question must:

  * be answerable from a *single* chunk
  * map to 1–N `target_chunk_ids`
  * avoid synthesis or multi-hop reasoning

**Example Output**

```json
{
  "synthetic_test_set": [
    {
      "question": "Who was confirmed captured during the operation?",
      "expected_answer_summary": "Nicolás Maduro and Cilia Flores",
      "target_chunk_ids": ["uuid-1"]
    }
  ]
}
```

### A2.2 Retrieval Probe (No Distractors Yet)

For each synthetic question:

* Run retrieval (k=3)
* Record:

  * rank of first matching `target_chunk_id`
  * -1 if not retrieved

**Metrics**

* `hit_rate@k`
* `mean_retrieved_rank`

### Report Additions

```json
{
  "synthetic_qa": {
    "hit_rate@3": 0.66,
    "mean_rank": 1.5,
    "cases": [
      {
        "question": "...",
        "retrieved_rank": 1
      }
    ]
  }
}
```

### Phase 1 Scope Limitation

* No answer generation
* No similarity grading
* No distractors

**Goal:**

> “Can the system retrieve the right chunk when asked the right question?”

---

## A3. Context Pollution & Retrieval Robustness (Phase 2 — DEFERRED)

### Motivation

Strict isolation proves *existence*.
Production requires *distinguishability*.

A chunk that only retrieves when alone is not production-ready.

---

### A3.1 Context Pollution Mode

Add a new probe option:

```
--pollution=none|light|heavy
```

#### Mechanism

During the Generation Probe:

* Inject **distractor chunks** that are:

  * semantically similar
  * factually incorrect or generic
* Sources:

  * other org knowledge
  * synthetic noise
  * archived chunks

These are added **only to the retriever context**, not permanently stored.

---

### A3.2 Robust Retrieval Metrics

Under pollution:

* Measure:

  * rank decay
  * false positive selection
  * target chunk displacement

**Failure signal**

> Distractor selected over target → retrieval readiness is overstated.

### Report Additions

```json
{
  "pollution_probe": {
    "mode": "light",
    "false_positive_rate": 0.33,
    "target_displacement_events": [
      { "question": "...", "selected_chunk_id": "distractor-uuid" }
    ]
  }
}
```

### Rationale for Phase 2

This introduces **confounding variables** and should only be enabled once:

* faithfulness is stable
* synthetic QA hit rate is acceptable

---

## A4. Traceable Retriever Wrapper (Infrastructure Extension)

To support Synthetic QA and Pollution without destabilizing production code:

### Design

Introduce a **TraceableRetriever** wrapper used only during evaluation runs.

```php
$retriever = new TraceableRetriever($realRetriever);
$retriever->setDistractors($chunks); // optional
$generator->setRetriever($retriever);
```

### Capabilities

* Logs:

  * query
  * candidate list
  * similarity scores
  * rejection reasons
* Supports:

  * strict isolation filters
  * temporary distractor injection

### Output

```json
{
  "retrieval_trace": [
    {
      "query": "...",
      "candidates": [
        { "chunk_id": "...", "score": 0.82, "accepted": true }
      ]
    }
  ]
}
```

---

## A5. Phase Alignment Summary

| Capability                   | Phase   |
| ---------------------------- | ------- |
| Faithfulness checks          | Phase 1 |
| Semantic drift detection     | Phase 1 |
| Synthetic QA (hit rate only) | Phase 1 |
| Answer similarity scoring    | Phase 3 |
| Context pollution            | Phase 2 |
| Heavy distractors            | Phase 2 |
| Multi-hop QA                 | Phase 3 |

---

## A6. Design Principle (Why This Extension Exists)

These extensions enforce a critical invariant:

> **Ingested knowledge must be:**
>
> 1. Correct (faithful)
> 2. Atomic
> 3. Retrievable
> 4. Distinguishable
> 5. Useful to agents

Each phase validates **exactly one layer of that stack**, in order.

---

## A7. Final Recommendation

Append this section **as-is** to the existing engineering spec.

* It preserves the original architecture
* It adds objective metrics
* It introduces realism *without sacrificing debuggability*
* It creates a clear roadmap from ingestion correctness → agent effectiveness

If you later want, the next natural artifacts to draft are:

* the **Synthetic QA generation prompt + schema**
* the **Faithfulness audit prompt**
* or a **Phase 2 pollution playbook** with concrete distractor strategies
