Supreme leader,

This new run proves the core issue is **inside the synthetic QA harness**, not embeddings, not chunking, not scoping.

## What changed (good)

* **Generation retrieval-on is now correct and scoped**: it retrieves the 5 chunks from this eval run (`019b85c5-94..`) and answers all 3 questions correctly.
* Config snapshot shows your new flags are active: `ai.eval.scope_to_knowledge_item=true`, `grader_mode=contains`.

## What’s still broken (and now undeniable)

### 1) Synthetic QA retrieval is returning **nothing**, while “top3” is clearly computed

For every synthetic QA item:

* `diagnostics.top3` contains the correct chunks with sensible distances (e.g. 0.0937).
* But:

  * `diagnostics.trace.topK: []`
  * `diagnostics.trace.final: []`
  * `retrieved: []`
  * `rank: -1`
  * hits@3: **0/3**

That means your synthetic QA code is doing this:

* It computes a local “top3” (probably from an in-memory search / debug query / direct DB call).
* But the *actual retriever call path* (the one that should populate `trace` + `retrieved`) is either:

  * not being called,
  * returning an empty list because of a bug,
  * or being short-circuited by an exception/guard that you’re swallowing.

This is not “retriever tuning”. It’s a **logic/control-flow bug**.

### 2) Your “trace” plumbing is present but never populated

Because `trace.topK` and `trace.final` are present in JSON but empty, you’ve likely added the fields but didn’t actually assign the retriever’s trace output into the report structure (or the retriever returns it but the caller discards it).

## The most likely root causes (ranked)

### A) Synthetic QA is using a different retriever interface than generation does

Generation probe clearly calls something that works and returns chunk IDs.
Synthetic QA is likely calling:

* a different service,
* an older method signature,
* or passing opts that cause the retriever to return empty (e.g. wrong key name: `knowledge_item_id` vs `knowledge_item_ids`, or scoping by `ingestion_source_id` incorrectly).

### B) Synthetic QA is applying a post-filter that wipes results

Example: you might be filtering by `intent max_per_intent` or `distance_ceiling` incorrectly, or filtering by `chunk_role` set that doesn’t include `strategic_claim/metric/heuristic`. But if that were true, **top3 would also be empty** unless top3 is computed pre-filter.

### C) Synthetic QA swallows an exception and defaults to empty arrays

The pattern is consistent with “try/catch then []”.

## Exact fixes you need now (no more broad spec)

### Fix 1: Make Synthetic QA use the same retrieval path as Generation Probe

In `SyntheticQaService` (or wherever you build `evaluation.synthetic_qa.details[]`):

* Replace any custom “diagnostic search” with a call to the **same retriever method** used by generation probe.
* Then derive:

  * `retrieved` from `results.final ids`
  * `trace.topK` and `trace.final` from the retriever’s returned trace payload
  * `top3` should simply be the first 3 items from `trace.topK` or `trace.final` (pick one and be consistent)

**Rule:** there must be exactly one retrieval implementation in eval land.

### Fix 2: Hard assert if top3 is non-empty but retrieved is empty

This state should not be possible.
Add a guard in synthetic QA evaluation:

* If `top3` has items and `retrieved` is empty, throw with a message like:

  * “SyntheticQA inconsistency: computed top3 but retriever returned empty. Check retriever call path / opts.”

Do not silently pass, because it makes the harness lie.

### Fix 3: Add logging to show the exact retriever options Synthetic QA uses

Log the opts for each question:

* org_id, user_id
* query
* scope ids (knowledge_item_ids)
* source_variant
* any thresholds/limits

Right now you can’t see why it returned empty.

## Quick sanity check you can do in 2 minutes

In synthetic QA, temporarily dump:

* the raw return from retriever (ids + distances) *before* any filtering/capping
* and the final list

Compare to generation probe; they should match.

## Expected outcome after the fix

For this same fixture:

* Synthetic QA `retrieved` becomes non-empty.
* hits@3 becomes **3/3** (because the target chunk is literally #1 in top3 for each question).
* `trace.topK` and `trace.final` populate.

## Side note (your chunk roles got worse)

You collapsed “belief_high/belief_medium/causal_claim” into:

* `strategic_claim`, `metric`, `heuristic`

Not fatal, but you’ve lost a useful axis for ranking. Don’t touch it until the harness is stable.

If you paste the synthetic QA code path (the method that populates `details[]`), I can tell you exactly where the empty array is coming from, but you already have enough evidence to instrument and fix it immediately.
