Below is a **formal engineering design** for the **Sparse-Document Recall Rule**.
This is written to be directly implementable, auditable, and safe in production.

---

# Engineering Spec: Sparse-Document Recall Guarantee

## 1. Problem Statement (Precisely Scoped)

### Observed Failure Mode

Sparse knowledge items (1–2 chunks) are **semantically relevant** but **structurally disadvantaged** in global retrieval because:

* Global retrieval is competitive across all knowledge items
* Sparse items have fewer vectors → fewer chances to appear in Top-K
* Long “misc/raw” chunks embed broadly, not sharply
* Even with low distance, they lose ranking to denser items

This causes:

* Correct ingestion
* Correct embedding
* Correct generation (when forced)
* **Incorrect natural retrieval**

This is a **recall problem**, not a precision problem.

---

## 2. Design Goal

Guarantee **minimum recall fairness** for sparse documents **without**:

* Introducing hard ID-based retrieval
* Breaking production parity
* Polluting Top-K with irrelevant chunks
* Masking real retrieval regressions

---

## 3. Design Principles

1. **Recall-first, not precision-first**
2. **Opt-in only for sparse items**
3. **Single-slot guarantee**
4. **Distance-bounded**
5. **Observable, not silent**
6. **Never overrides generation correctness**

---

## 4. Formal Definition

### 4.1 Terminology

* **Knowledge Item (KI)**: A single ingested document
* **Sparse KI**: A KI with `chunk_count ≤ SPARSE_CHUNK_THRESHOLD`
* **Candidate Chunk**: Chunk appearing in Top-K by distance
* **Guaranteed Slot**: A reserved inclusion slot in retrieval results

---

## 5. Sparse-Doc Recall Rule (Formal)

### Rule Name

**Sparse-Document Minimum Recall Guarantee (SD-MRG)**

### Trigger Conditions (ALL must be true)

1. `chunk_count(KI) ≤ SPARSE_CHUNK_THRESHOLD`
   *Default: 2*

2. At least one chunk from KI appears in:

   ```
   Top-K candidates ranked by raw embedding distance
   ```

   *(before composite weighting, before pruning)*

3. That chunk’s distance satisfies:

   ```
   distance ≤ SPARSE_DISTANCE_CEILING
   ```

   *Default: 0.20*

4. The chunk is not already selected in final results

---

## 6. Behavioral Contract

### When Triggered

* **Exactly one** chunk from the KI is injected
* Injection happens **after Top-K ranking**, **before final truncation**
* Injected chunk:

  * Counts toward `retrieval_limit`
  * Is tagged as `recall_injected: true`
  * Does NOT bypass excerpt caps or other safety limits

### When Not Triggered

* If multiple chunks from the KI already appear → do nothing
* If distance is too high → do nothing
* If KI is non-sparse → do nothing

---

## 7. Algorithm (Step-by-Step)

### Stage 1 — Normal Retrieval (Already Implemented)

```php
$ranked = sortByCompositeScore($scoredChunks);
$topK = array_slice($ranked, 0, max($limit * 3, $topN));
```

---

### Stage 2 — Detect Sparse Candidates

```php
$sparseCandidates = [];

foreach ($topK as $chunk) {
    $ki = $chunk->knowledge_item_id;
    if ($chunkCounts[$ki] <= SPARSE_CHUNK_THRESHOLD &&
        $chunk->__distance <= SPARSE_DISTANCE_CEILING) {
        $sparseCandidates[$ki][] = $chunk;
    }
}
```

---

### Stage 3 — Inject Recall Slot (Once per KI)

```php
foreach ($sparseCandidates as $ki => $chunks) {
    if (!alreadySelected($final, $ki)) {
        $best = minByDistance($chunks);
        $best->__recall_injected = true;
        $final[] = $best;
    }
}
```

---

### Stage 4 — Final Selection

```php
$final = array_slice(stableUnique($final), 0, $limit);
```

---

## 8. Configuration Knobs

```php
SPARSE_CHUNK_THRESHOLD = 2
SPARSE_DISTANCE_CEILING = 0.20
SPARSE_MAX_INJECTIONS = 1   // per retrieval call
```

All values must be **configurable**, not hard-coded.

---

## 9. Observability & Diagnostics (Required)

Every injected chunk must emit:

```json
{
  "recall_injected": true,
  "reason": "sparse_doc_recall",
  "chunk_id": "...",
  "knowledge_item_id": "...",
  "distance": 0.11
}
```

Expose metrics:

* `sparse_recall_injections`
* `sparse_recall_hit_rate`
* `sparse_recall_false_positive_rate`

---

## 10. Safety Analysis

### Why this does NOT degrade precision

* Only triggers if chunk already ranks in Top-K by distance
* Never injects unrelated content
* Adds **at most one** chunk
* Does not override ranking, only supplements it

### Why this does NOT hide real failures

* If VIP-forced fails → ingestion failure
* If sparse recall fails → logged explicitly
* If distance is high → no injection

---

## 11. Success Criteria

### Functional

* Sparse factual fixtures achieve:

  ```
  VIP-Forced pass_rate = 1.0
  Retrieval-On pass_rate ≥ 0.9
  ```

### Diagnostic

* `retrieval_regression` verdict disappears for sparse-only docs
* Synthetic QA `hits_at_k ≥ 1` for sparse docs

### Safety

* No increase in hallucinations
* No increase in irrelevant chunk usage
* No regression on dense documents

---

## 12. Explicit Non-Goals (Important)

This rule does **not**:

* Guarantee recall for bad embeddings
* Override poor chunking
* Replace tuning
* Apply to dense documents
* Mask broken ingestion

---

## 13. Final Assessment

You are now **one rule away from convergence**.

* Architecture: ✅ correct
* Evaluation harness: ✅ truthful
* Verdict logic: ✅ correct
* Remaining issue: **structural recall unfairness for sparse docs**

This spec fixes that **cleanly, minimally, and safely**.

If you want next, I can:

* Provide the exact patch for `Retriever::knowledgeChunks`
* Simulate expected metrics after rollout
* Define rollout flags and safe-deploy strategy
