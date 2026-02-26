Supreme leader,

Partially. The **bookmark-content resolution problem looks fixed**, but you’ve now surfaced a **real pipeline invariant bug** (and your new guardrail is correctly killing the job).

## What’s fixed

### ✅ Bookmark ingestion is no longer “empty”

Evidence:

* `Knowledge Chunk Report` shows real `chunk_text` coming from `source_type: bookmark`.
* `ingestion_sources.raw_text` is still `null` (correct by design).
* `KnowledgeItem` clearly had text (chunking found 6 paragraphs, 705 chars).

So the “don’t fetch social / resolve internally from bookmarks” contract is effectively working.

## What’s not fixed (new blocker)

### ❌ Chunk classification count mismatch is still happening

You still get:

* `ChunkKnowledgeItemJob`: **created_chunks: 1**
* `ClassifyKnowledgeChunksJob`: **expected: 1, got: 3**
* `ProcessIngestionSourceJob`: **error → PipelineInvariantViolation: Chunk count mismatch**

That means your debug invariant is doing its job — but the underlying mismatch is not solved.

## Likely causes (based on the pattern)

This mismatch almost always comes from one of these:

1. **Classifier query is selecting more chunks than “this run created”**

   * e.g. selecting by `knowledge_item_id` only (grabs old chunks too), instead of “chunks created in this run”.

2. **Duplicate chunks already exist for that KnowledgeItem**

   * Reprocessing without cleaning old chunks.
   * Or chunker creates new rows but doesn’t delete prior ones.

3. **Chunker creates 1, but classifier expands into 3 “derived rows”**

   * e.g. classifier is writing “claims” as separate chunks or splitting and persisting new chunks.
   * If so, the invariant itself is wrong: classification should update chunks, not create new ones.

Your *other* KnowledgeItem (`019b8005-1976...`) works cleanly:

* expected 1, updated 1
  So this is **data-dependent or stale-data-dependent**, not a global logic error.

## What to do next (no guessing, direct steps)

### Step 1 — confirm whether extra chunks pre-existed

Run:

```sql
select id, chunk_type, created_at
from knowledge_chunks
where knowledge_item_id = '019b8005-0999-7184-9a77-30d3d8c9ded1'
order by created_at asc;
```

If you see **3 rows**, you have stale/duplicate chunks and your classifier is just seeing them.

### Step 2 — make chunking idempotent

Before creating new chunks for a knowledge item + variant, do one of:

* **delete existing chunks** for that KI+variant, then recreate
* or **upsert with a deterministic key** (`chunk_hash`)

Minimal safe approach:

* Delete existing `knowledge_chunks` for that KI where `variant=raw` (or whatever you use)
* Then insert the fresh set

### Step 3 — fix “expected vs got” to compare the same set

Your invariant should compare either:

* count of chunks **created in this run** vs classifier input count, OR
* chunk count **after cleaning** (idempotent run), OR
* chunk count by `(knowledge_item_id, variant)` if variant is stable and deduped

Right now it’s comparing “created=1” to “selected=3”, which screams **the selection scope is wider than the creation scope**.

---

## Verdict

* ✅ **Bookmark resolver/content contract:** fixed enough to proceed.
* ❌ **Pipeline:** not fixed; classifier mismatch is a real bug (or stale data) and you correctly fail fast in debug.

If you paste the SQL output for the chunks on that failing `knowledge_item_id`, I can tell you which of the three causes it is in one message.

---

# Implementation Notes (2026-01-02)

- Idempotent chunking
  - `app/Jobs/ChunkKnowledgeItemJob.php` now deletes existing chunks for the targeted variant before creating new ones:
    - If normalized claims exist → delete where `(knowledge_item_id, source_variant) = (KI, 'normalized')`.
    - Else (raw mode) → delete where `(knowledge_item_id, source_variant) = (KI, 'raw')`.
- Variant-scoped classification
  - `app/Jobs/ClassifyKnowledgeChunksJob.php` now selects chunks for a single variant per run:
    - Prefers `normalized` when claims exist; otherwise `raw`.
    - Filters by `where('source_variant', $variant)` and orders by `created_at`.
  - In debug, the count mismatch invariant includes the active `variant` in logs and throws.

These changes address the mismatch by ensuring classifier compares against the exact set produced by the latest chunk stage and prevents stale/duplicate rows from inflating counts.
