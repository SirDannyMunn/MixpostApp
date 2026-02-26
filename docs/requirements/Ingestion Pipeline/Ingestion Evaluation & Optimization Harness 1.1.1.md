Short answer: **no — this is *not* working as expected**, and the report is actually doing you a favor by exposing **three real bugs and one design trap**.

The good news:
Nothing here is mysterious. This is exactly the kind of failure Phase 1 is supposed to surface.

I’ll walk through this clinically.

---

## 1. Hard red flag #1: **The ingested content is not the fixture you passed**

You ran:

```bash
--input=docs/fixtures/ingestion/factual_short.txt
```

But the chunk text is:

```text
Acme Robotics announced on January 1, 2025, that it acquired Beta AI for $50 million...
```

That is **not** the contents of `factual_short.txt`.

### Root cause

Your report shows:

```json
"dedup_reason": "knowledge_item_duplicate"
```

Meaning:

* The ingestion pipeline **short-circuited**
* It reused an existing `knowledge_item`
* **None of the new text was processed**

So Phase A did *not* ingest your fixture at all.

### Why this matters

Everything downstream (faithfulness, QA, retrieval) is invalid because:

> You are evaluating **the wrong document**.

### Immediate fix (mandatory)

For evaluation runs, **deduplication must be disabled or isolated**.

You already planned for this — but it’s not active.

**Do one of these now:**

**Option A (best):**
In `IngestionRunner`, force uniqueness for eval runs:

```php
$ingestionSource->metadata['evaluation_run_id'] = $evaluationId;
```

and include metadata in the dedup hash.

**Option B (temporary):**
Always pass `--force` in `ai:ingestion:eval` and **assert** in the report:

```json
"dedup_reason": null
```

Until this is fixed, **all eval results are garbage**.

---

## 2. Red flag #2: **Normalization did not run (and should have)**

Your config snapshot:

```json
"normalization": {
  "min_chars": 100,
  "eligible_sources": ["bookmark","text","file","transcript"]
}
```

Your fixture is ~300 characters → **eligible**.

But:

```json
"normalized_claims": null
"normalized_claims_count": 0
```

### Why this is wrong

`factual_short.txt` is *exactly* the kind of input normalization is designed for.

Expected outcome:

* 3–5 normalized claims
* chunk_variant = `normalized`
* raw fallback **not used**

Actual outcome:

* Raw fallback mode
* Single paragraph chunk
* Atomicity destroyed

### Likely causes (check in this order)

1. **Dedup short-circuit**

   * Normalization never ran because ingestion exited early
     ✔ This is almost certainly the cause here

2. Normalization job gating incorrectly reading `source_type`

   * `source` is `manual`
   * You map eligibility from `ingestion_sources.source_type`
   * Confirm mapping logic

3. Normalization job silently failing

   * Add logging for:

     * eligibility decision
     * reason for skipping

### Action item

After fixing dedup:

* Add explicit normalization audit fields to the report:

```json
"normalization": {
  "eligible": true,
  "executed": false,
  "skip_reason": "deduplicated"
}
```

This is **mandatory observability**.

---

## 3. Red flag #3: **Synthetic QA is failing correctly**

This part is actually **working as designed**.

You generated:

```json
"target_chunk_ids": ["019b847e-dd79-7169-8295-806dc4b17004"]
```

But retrieval results:

```json
"retrieved": [],
"rank": -1
```

### Why this happened

Because:

* There is only **one chunk**
* It is **long, merged, paragraph-level**
* It contains multiple facts
* Embedding similarity to short factual questions is poor

This is **exactly the failure Synthetic QA is meant to catch**.

So this is not a bug — it’s a **signal**.

If normalization had run, retrieval would almost certainly hit.

---

## 4. Design trap you just discovered (important)

Right now your evaluation pipeline assumes:

> “If chunks exist and embeddings exist, retrieval should work.”

This run proves that assumption is false.

You have discovered a **structural invariant**:

> **Paragraph-level chunks are not reliably retrievable by factual queries.**

This validates:

* normalization necessity
* atomic chunking requirement
* Phase 1 value

That’s a success.

---

## 5. What *should* have happened (expected Phase 1 behavior)

For `factual_short.txt`, a correct report would show:

### Ingestion

* `dedup_reason = null`
* `normalized_claims_count ≈ 4`
* `chunk_variant = normalized`
* `chunks_total ≈ 4`

### Faithfulness

* status: `ok`
* score ≥ 0.95

### Synthetic QA

* ≥1 hit @ k=3
* rank = 1 or 2

### Atomicity

* 1 fact per chunk

You got **none** of that — for a single root cause.

---

## 6. Required fixes before any further testing

### 🔴 Blocking fixes (do these first)

1. **Disable deduplication for eval runs**
2. **Assert normalization execution status**
3. **Fail the eval if dedup_reason ≠ null**

Add this guard:

```php
if ($source->dedup_reason) {
    throw new RuntimeException(
        "Evaluation aborted: ingestion was deduplicated"
    );
}
```

Otherwise you will silently test nothing.

---

## 7. Final verdict

| Component                   | Status            |
| --------------------------- | ----------------- |
| Phase A command             | ✅ implemented     |
| Artifact capture            | ✅ working         |
| Synthetic QA logic          | ✅ working         |
| Faithfulness skip logic     | ✅ correct         |
| Dedup handling              | ❌ broken for eval |
| Normalization observability | ❌ missing         |
| Test validity               | ❌ invalid run     |

This is **exactly** the right failure to hit at this stage.
Your system is surfacing real issues instead of hiding them.

---

## Next step (do this next, not later)

Fix dedup isolation, rerun **the same fixture**, and paste the new report.

That second run will tell us whether:

* normalization logic is correct
* QA retrieval improves
* Phase 1 actually passes

Once that works, you’re genuinely ready to trust Phase 1.
