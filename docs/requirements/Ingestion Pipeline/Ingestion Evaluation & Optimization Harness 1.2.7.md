Good — this run exposed **a different failure class**, and it’s not retrieval anymore. The retriever changes didn’t “not work”; they **never got exercised**.

I’ll be blunt and precise.

---

## 1. What just happened (and why this looks confusing)

Your latest run did **not** test retrieval ranking at all.

It failed **upstream of retrieval**.

### Key evidence (this line matters):

```json
"artifacts": {
  "chunks": 1,
  "embedding_coverage": 0,
  "normalized_claims": 0
}
```

That means:

* No normalized claims were produced
* No embeddings were created
* No vector search ran
* No ranking ran
* No recall logic ran
* No SD-MRG / small-dense assist ran

So all of this:

* metric preference
* protected near-match logic
* injection gating
* weight tuning

👉 **None of it executed.**

---

## 2. Root cause (this is not subtle)

### Normalization silently collapsed

You ended up with:

```json
"chunk_type": "misc",
"chunk_role": "other",
"source_variant": "raw",
"embedded": 0
```

That means the normalization pipeline **decided not to emit claims**, despite:

* identical input text
* identical quality score
* identical normalization hash

This is the real regression.

---

## 3. Why this happened *now*

Your retriever changes are not the trigger.
The trigger is this invariant being violated:

> **The eval harness assumes normalized claims exist.**

But your pipeline currently allows this state:

```
normalization.executed = true
normalized_claims_count = 0
```

That is an invalid eval state.

Once that happens:

* chunker falls back to a single raw “misc” chunk
* embeddings are skipped
* synthetic QA cannot be generated
* generation is skipped
* evaluation degrades into a no-op

That’s why you see:

```
Artifacts integrity failed: embedded != total
```

---

## 4. This is a Phase Boundary Bug, not Retrieval

You now have **three distinct system phases**, and they are bleeding into each other:

| Phase   | Responsibility            |
| ------- | ------------------------- |
| Phase 0 | Ingestion + normalization |
| Phase 1 | Retrieval competitiveness |
| Phase 2 | Generation faithfulness   |

Your latest run failed **Phase 0**, so Phase 1 was never tested.

This is expected behavior — but your harness does not enforce it clearly yet.

---

## 5. What must be fixed immediately (before touching retrieval again)

### A. Enforce a hard invariant in eval mode

In **eval harness only**:

```pseudo
if normalization.executed && normalized_claims_count == 0:
    FAIL EARLY
    reason = "normalization_empty"
```

Do **not** allow fallback-to-misc during eval.

This prevents false negatives and phantom regressions.

---

### B. Make normalization deterministic for eval inputs

Your own data proves the text *can* normalize into 5 claims.

So one of these is true:

1. A gating condition changed (min_quality, min_chars, eligibility)
2. A classifier failure path is swallowing outputs
3. A timeout / retry path is returning empty results
4. A race condition is deleting claims before chunking

**Action for dev:**

Add logging **inside normalization**:

```text
- input chars
- quality score
- eligibility decision
- LLM response size
- parsed claim count
- reason for zero-claim outcome
```

Until this is stable, retrieval tuning is meaningless.

---

## 6. Important: your retriever changes are directionally correct

Let’s be clear so you don’t second-guess the right work.

### These changes are GOOD and should stay:

* Metric-aware chunk selection for numeric queries
* “Protected” near-match semantics
* Allowing injection even when KI is present
* Weight shift toward distance dominance
* Increasing `top_n` to widen candidate pool

None of those caused this failure.

They simply never ran.

---

## 7. What to do next (strict order)

### Step 1 — Lock Phase 0

Before running `ai:ingestion:eval` again, ensure:

* `normalized_claims_count > 0`
* `chunks_total >= normalized_claims_count`
* `embedded == chunks_total`

If any fail → **stop the eval immediately**.

---

### Step 2 — Re-run factual_short.txt

Only after Phase 0 is stable:

Expected minimum:

```text
normalized_claims = 5
chunks = 5
embedding_coverage = 1.0
```

If not, do **not** inspect retrieval output — it’s invalid.

---

### Step 3 — Then evaluate retrieval again

Only once embeddings exist should you expect:

* Synthetic QA items > 0
* Retrieval-On generation executed
* Near-match chunks surviving ranking

---

## 8. Final diagnosis (no hedging)

You did **not** break retrieval.

You uncovered a **normalization nondeterminism bug** that your eval harness didn’t guard against.

That’s actually a win:

* You now know Phase 0 needs a hard invariant
* You prevented weeks of chasing phantom retrieval regressions
* Your retriever logic is now strong enough to expose upstream failures

Fix normalization determinism **first**.

Then your new retriever logic will finally get the chance to pass — and it likely will.
