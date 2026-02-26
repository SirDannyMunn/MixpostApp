Good — this run is *exactly* the confirmation you needed. Nothing here is ambiguous anymore.

Below is a **clean, no-nonsense diagnosis**, followed by a **success-criteria document** and a **developer-ready optimization plan** you can hand off as-is.

---

# 1. What this run definitively proves

## 1.1 Ingestion is solved (lock this in)

These are now **non-issues** and should not be touched during optimization:

* Normalization: **PASS (1.0, zero violations)**
* Chunking: correct, stable, semantically clean
* Embeddings: correct (near-perfect distances observed)
* VIP-Forced generation: **3/3 correct**

**Conclusion:**
👉 *The system contains the right knowledge in the right shape.*

Any further failures are **retrieval-only**.

---

## 1.2 Retrieval is provably broken for the correct reason

Look at this line carefully (this is the smoking gun):

```json
{
  "distance": 0.0937,
  "chunk_text": "Acme Robotics announced acquisition of Beta AI on January 1, 2025"
}
```

Distance **0.0937** is:

* Extremely close
* Well below `near_match_distance = 0.1`
* The *correct* chunk

Yet:

* It is **not returned**
* It is **not ranked**
* It is **not injected**
* Retrieval-On generation fails 3/3

This means:

> **Your distance signal is being overridden downstream by ranking logic.**

Not by thresholds.
Not by recall rules.
By **post-distance scoring and filtering**.

---

## 1.3 Small-dense assist did NOT fire (and that’s correct)

You configured:

```json
"small_dense_assist": {
  "chunk_threshold_min": 3,
  "chunk_threshold_max": 6,
  "max_injections": 1,
  "distance_ceiling": 0.15
}
```

But it still didn’t inject.

That tells us **one of two things** (both fixable):

1. Injection logic is evaluated **after** soft-score filtering
   → the candidate is already gone
2. Injection is only applied when *no results exist*, not when results are *wrong*

Either way, the **assist is structurally correct but placed too late** in the pipeline.

---

## 1.4 Verdict logic is now correct

This is important: your system behavior is now *coherent*.

* VIP-Forced: PASS
* Retrieval-On: FAIL
* Verdict: **retrieval_regression**

That is the **correct verdict**.

The harness is now doing exactly what it should do:

> *“The knowledge exists, but retrieval competitiveness is unacceptable.”*

---

# 2. Root cause (precise)

### The failure is NOT:

* embeddings
* chunk quality
* normalization
* sparse recall thresholds
* distance thresholds

### The failure IS:

**Distance dominance is not enforced.**

Your system currently allows:

```
authority + confidence + time_horizon penalties
```

to **outvote a near-perfect semantic match**.

That should *never* happen.

---

# 3. What must be true in a correct system

This is the invariant you are missing:

> **If a chunk is a near-perfect semantic match, it must survive ranking.**

This is not a heuristic.
This is a hard rule.

---

# 4. SUCCESS CRITERIA DOCUMENT (hand this to your dev)

## Title

**Retrieval Optimization – Acceptance Criteria**

### A. Non-Negotiable Baselines

These must remain true at all times:

1. Faithfulness score = **1.0**
2. VIP-Forced generation pass rate = **100%**
3. No hallucinated facts in any phase

If any of these regress → **reject the change immediately**.

---

### B. Retrieval Correctness (Primary Goal)

For `docs/fixtures/ingestion/factual_short.txt`:

| Metric                    | Requirement                      |
| ------------------------- | -------------------------------- |
| Synthetic QA hits@3       | **≥ 2 / 3**                      |
| Retrieval-On generation   | **≥ 2 / 3 passes**               |
| Near-match chunk (≤ 0.10) | **Must appear in retrieved set** |
| Verdict                   | **pass** or **borderline_pass**  |

---

### C. Near-Match Invariant (Hard Rule)

If a chunk satisfies:

```
distance <= near_match_distance
```

Then:

* It **must not** be removed by soft scoring
* It **must not** be excluded by authority/confidence penalties
* It **must** appear in Top-K or be injected

Violation of this rule = **retrieval bug**.

---

### D. Noise Safety

After optimization:

* Retrieval-On must **not hallucinate**
* Generation probe must still fail when incorrect chunks are retrieved
* VIP-Forced remains the oracle

---

# 5. OPTIMIZATION PLAN (step-by-step, developer-ready)

## Phase 1 — Instrumentation (no behavior change)

**Goal:** Make ranking decisions visible.

1. Log per-candidate:

   * raw distance
   * weighted score
   * rejection reason (threshold / soft_score / cap)
2. Confirm where the near-match chunk is being dropped

Expected outcome:

> You will see a near-match chunk rejected *after* distance evaluation.

---

## Phase 2 — Enforce Distance Dominance (required)

### Implement this rule:

```pseudo
if (distance <= near_match_distance) {
    bypass soft_score_limit
    bypass authority/confidence penalties
    mark as protected_candidate
}
```

This must occur **before**:

* soft score filtering
* top-N trimming

Re-run eval.

**Expected result:**

* Synthetic QA ≥ 1 / 3 immediately
* Retrieval-On generation improves

---

## Phase 3 — Fix Small-Dense Assist Placement

Move `small_dense_assist` to execute:

* After raw vector search
* Before ranking penalties
* Before result trimming

Injection condition should be:

```pseudo
if (
  chunk_count between 3 and 6
  AND no protected_candidate selected
) {
  inject best candidate under distance_ceiling
}
```

Re-run eval.

**Expected result:**

* Synthetic QA = **2–3 / 3**
* Retrieval-On generation ≥ **2 / 3**

---

## Phase 4 — Weight Tuning (only if needed)

Only adjust weights **after** dominance rules work.

Suggested order:

1. Increase `distance` weight → 0.8
2. Reduce `authority` → 0.05
3. Reduce `confidence` → 0.05

Never exceed:

* distance < 0.7
* authority + confidence + time_horizon > 0.3

---

## Phase 5 — Lock & Expand

Once `factual_short.txt` passes:

1. Run `conflicting_viewpoints.txt`
2. Run `noisy_format.md`
3. Ensure:

   * No hallucinations
   * No semantic merging
   * Retrieval remains competitive

Only then declare **retrieval v1 stable**.

---

# 6. Final, blunt conclusion

You are no longer “debugging”.

You are now in **retrieval optimization mode**.

The system is telling you, with mathematical clarity:

> “I know the answer.
> You’re just not letting me use it.”

Fix **distance dominance** and **assist placement**, and this will snap into place.
