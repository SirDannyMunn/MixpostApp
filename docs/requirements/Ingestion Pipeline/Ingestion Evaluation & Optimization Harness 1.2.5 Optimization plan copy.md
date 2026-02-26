Below are **two standalone documents** you can hand directly to your developer.
They are written to be **actionable, unambiguous, and completion-oriented**, with no architectural debate left open.

---

# Document 1: Success Criteria — Ingestion Evaluation & Retrieval Optimization

## Purpose

Define **when the system is considered “done”** for ingestion evaluation, retrieval quality, and generation usability.

This system does **not** aim for perfect retrieval.
It aims for **diagnosable, optimizable, and production-relevant behavior**.

---

## A. Scope Clarification (Non-Goals)

The evaluation system is **NOT** responsible for:

* Deduplication correctness
* Re-ingestion behavior
* Cross-document contamination
* Long-term knowledge drift
* Global corpus ranking quality

Those concerns are explicitly **out of scope**.

---

## B. Core Success Definition (Top-Level)

The system is successful when it can **reliably distinguish** between:

1. **Bad ingestion**
2. **Good ingestion but weak retrieval competitiveness**
3. **Good ingestion and usable generation**

…and surface this distinction **without manual debugging**.

---

## C. Phase-Level Success Criteria

### Phase A — Artifact Integrity (Hard Gate)

**Must pass 100% of the time.**

✅ Required:

* KnowledgeItem created
* ≥ 1 KnowledgeChunk created
* ≥ 1 embedding generated
* Normalization executed when eligible
* No hallucinated claims (Faithfulness = pass)

❌ Automatic failure if:

* Zero chunks
* Zero embeddings
* Faithfulness violations present

---

### Phase B — Synthetic QA & Retrieval Diagnostics (Observational)

**No hard pass/fail**, but must be *informative*.

✅ Required signals:

* Synthetic questions generated
* Each question mapped to ≥ 1 target chunk
* For each miss:

  * Raw distance logged
  * Top-3 candidate chunks logged
  * Rank = `-1` explicitly marked

❌ Failure if:

* Diagnostics are missing
* Retrieval miss is silent
* No distance data available

**Important:**
Low recall here is **acceptable**, as long as it is explained.

---

### Phase C — Generation Probe (Primary Success Gate)

This phase determines **actual usability**.

#### C1. VIP-Forced Generation (Hard Requirement)

✅ Must achieve:

* **100% pass rate**
* All answers grounded in provided chunks
* No hallucinated uncertainty
* No contradictions

❌ Failure means:

> The ingestion output is **not usable**, regardless of retrieval quality.

---

#### C2. Retrieval-On Generation (Competitive Signal)

This is **not required to be perfect**, but must be **meaningful**.

Target thresholds:

| Fixture Type             | Expected Pass Rate |
| ------------------------ | ------------------ |
| Sparse (≤2 chunks)       | ≥ 66%              |
| Small Dense (3–6 chunks) | ≥ 33%              |
| Large Docs               | ≥ 50%              |

❌ Regression is flagged only if:

* Retrieval-On < threshold **and**
* VIP-Forced = 100%

This combination indicates **retrieval competitiveness**, not ingestion failure.

---

## D. Verdict Logic (Final Output)

Each run must end with **exactly one** verdict:

| Verdict                | Meaning                           |
| ---------------------- | --------------------------------- |
| `pass`                 | Retrieval & generation acceptable |
| `retrieval_regression` | Ingestion OK, retrieval weak      |
| `ingestion_failure`    | Chunks or claims unusable         |
| `faithfulness_failure` | Claims not trustworthy            |

A run with:

* VIP-Forced = 100%
* Retrieval-On < threshold

→ **`retrieval_regression` is the correct and desired outcome**

---

## E. System Completion Criteria

This project is considered **complete** when:

* Developers can run the eval command
* Read the report
* Identify *why* a document failed
* Make a targeted change
* Re-run and observe improvement

No debugger required.

---

# Document 2: Optimization Plan — Developer Execution Guide

## Objective

Provide a **repeatable loop** for improving ingestion and retrieval using the evaluation harness.

---

## Step 1 — Run the Baseline Evaluation

Command (canonical):

```bash
php artisan ai:ingestion:eval \
  --org=ORG_ID \
  --user=USER_ID \
  --input=docs/fixtures/ingestion/<fixture>.txt \
  --run-generation \
  --cleanup \
  --log-files
```

Confirm:

* Phase A passes
* Report JSON + MD generated
* Verdict present

---

## Step 2 — Classify the Failure Type

Use the report to answer **one question only**:

> Did VIP-Forced generation pass?

### Case A — VIP-Forced fails

➡️ **Ingestion problem**

Investigate:

* Chunk wording
* Claim certainty inflation
* Over-fragmentation
* Missing factual anchors

Fix ingestion logic → re-run.

---

### Case B — VIP-Forced passes, Retrieval-On fails

➡️ **Retrieval competitiveness problem**

Proceed to Step 3.

---

## Step 3 — Inspect Retrieval Diagnostics (No Guessing)

From `synthetic_qa.details[]`:

Check:

* `top3[].distance`
* `rank`
* Whether the correct chunk appears in top-3

### Interpret correctly:

| Observation               | Meaning            |
| ------------------------- | ------------------ |
| Low distance, rank = -1   | Scoring issue      |
| High distance             | Embedding mismatch |
| Competing chunks dominate | Corpus competition |

---

## Step 4 — Choose the Correct Optimization Lever

### A. If distance is good (< 0.2) but rank = -1

Adjust **ranking weights**, not thresholds:

* Reduce authority dominance
* Reduce time_horizon penalty
* Increase distance weight slightly

Do **not** touch normalization.

---

### B. If sparse doc (≤2 chunks) is missing

Validate:

* `AI_SPARSE_RECALL_ENABLED=true`
* `chunk_threshold` matches fixture
* `distance_ceiling` not overly strict

Confirm `recall_injected=true` appears in trace.

---

### C. If dense small docs fail (3–6 chunks)

Acceptable options:

* Introduce *small-doc assist* (separate from sparse)
* Lower composite penalty for intra-document similarity
* Slightly boost same-KI diversity

Do **not** force recall blindly.

---

## Step 5 — Re-Run Evaluation

Re-run the same fixture.

Confirm one of the following improves:

* Retrieval-On pass count
* Rank ≥ 0 for target chunks
* Verdict changes from `retrieval_regression` → `pass`

---

## Step 6 — Lock In or Roll Back

If improvement:

* Keep change
* Document rationale in commit

If regression elsewhere:

* Revert
* Adjust more narrowly

---

## Step 7 — Final Acceptance

The system is done when:

* Developers can independently:

  * Diagnose failures
  * Make targeted changes
  * Improve metrics
* No hidden heuristics remain
* Reports tell a coherent story

---

## Final Note to Developer

If you ever feel tempted to “just raise thresholds”:

Stop.

That is not optimization.
That is hiding signal.

Use the report.
The system is already telling you what to do.
