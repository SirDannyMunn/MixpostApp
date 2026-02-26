Below is a **Phase 2.1 Engineering Spec** that is narrowly scoped, corrective (not expansive), and designed to *finish* Phase 2 rather than drift toward Phase 3.

This spec assumes **Phase 2 code stays**, and Phase 2.1 **activates, wires, and hardens** it.

---

# Ingestion Pipelines — Phase 2.1

**Semantic Normalization Activation & Integration**

## Goal

Finish Phase 2 by making **semantic normalization operational, consumed, and testable** across the ingestion → retrieval pipeline, without expanding scope into new ingestion types or UI.

Phase 2.1 is considered complete when:

* Normalization runs on eligible ingestion sources (including bookmarks)
* Normalized content is **used**, not just stored
* Retrieval can explain *whether* normalized or raw content was used
* Pipeline remains idempotent and backfill-safe

---

## Non-Goals (Explicit)

* No new ingestion source types
* No UI changes (admin/debug endpoints optional but not required)
* No new embedding models
* No full re-ranking model changes

---

## Current State (Problem Summary)

* `NormalizeKnowledgeItemJob` exists but is skipped for most real data
* `normalized_claims` is never consumed downstream
* Chunking, embedding, and retrieval operate exclusively on raw text
* Phase 2 architecture exists, but normalization is inert

---

## Phase 2.1 Requirements

---

## 1. Normalization Eligibility Rules

### Objective

Enable normalization for **high-signal bookmark sources**, not just long-form/manual inputs.

### Rules

Normalization SHOULD RUN when **all** are true:

* `knowledge_items.raw_text` exists
* `strlen(raw_text) >= MIN_NORMALIZE_CHARS` (default: 400)
* `ingestion_sources.quality_score >= MIN_NORMALIZE_QUALITY` (default: 0.55)
* `ingestion_sources.source_type IN ('bookmark', 'text', 'file', 'transcript')`

Normalization SHOULD SKIP when:

* Bookmark fetch failed (`raw_text IS NULL`)
* Content is clearly boilerplate-only (cookie banners, nav-only)
* Content already normalized with identical `raw_text_sha256`

### Config

```php
// config/ai.php
'normalization' => [
    'min_chars' => 400,
    'min_quality' => 0.55,
    'eligible_sources' => ['bookmark', 'text', 'file', 'transcript'],
]
```

---

## 2. NormalizeKnowledgeItemJob (Fix & Activate)

### File

`app/Jobs/NormalizeKnowledgeItemJob.php`

### Changes Required

#### A. Remove blanket “skip bookmarks” logic

Replace with rule-based gating using config above.

#### B. Output Contract (Required)

`knowledge_items.normalized_claims` MUST store:

```json
{
  "claims": [
    {
      "id": "claim_1",
      "text": "Vandervort Architects is a Seattle-based residential architecture firm.",
      "type": "definition | strategic_claim | factual_claim",
      "confidence": 0.85
    }
  ],
  "summary": "Short neutral summary of the source",
  "source_stats": {
    "original_chars": 4840,
    "claims_count": 6
  },
  "normalization_hash": "sha256(raw_text)"
}
```

#### C. Idempotency

* If `normalization_hash` matches stored hash → skip
* Log `skipped_already_normalized`

---

## 3. Chunking Must Support Normalized Mode

### File

`app/Jobs/ChunkKnowledgeItemJob.php`

### Change

Add dual-mode chunking:

```php
$mode = $knowledgeItem->normalized_claims ? 'normalized' : 'raw';
```

#### Behavior

* If `normalized_claims.claims` exists:

  * Chunk **claims**, not paragraphs
  * One chunk per claim
  * `chunk_type = 'normalized_claim'`
* Else:

  * Fallback to existing paragraph chunking

#### Required Fields on KnowledgeChunk

```php
source_variant: 'raw' | 'normalized'
```

(Add DB column)

---

## 4. Embeddings: Normalize-Aware

### File

`app/Jobs/EmbedKnowledgeChunksJob.php`

### Change

* Embed `chunk_text` as-is (already normalized if claim-based)
* Tag embedding metadata:

```json
embedding_meta: {
  "variant": "normalized",
  "model": "text-embedding-3-small"
}
```

This allows retrieval weighting later without re-embedding.

---

## 5. Classification: Hash Must Include Variant

### File

`app/Jobs/ClassifyKnowledgeChunksJob.php`

### Change

Update classification hash to include:

```text
hash = sha1(chunk_text + chunk_role + source_variant)
```

This prevents:

* Raw chunk classification being reused for normalized claims
* Silent semantic drift

---

## 6. Retrieval: Prefer Normalized Claims When Available

### File

`app/Services/Ai/Retriever.php`

### Change

In `knowledgeChunksTrace()`:

#### Ranking Rule

* If normalized chunks exist for a KnowledgeItem:

  * Prefer them **unless** intent is `story` or `example`
* Add small multiplier:

```php
if ($chunk->source_variant === 'normalized') {
    $score *= 1.08;
}
```

#### Trace Addition (Required)

```json
trace: {
  "variant": "normalized",
  "used_normalization": true
}
```

---

## 7. IngestionSource Status Semantics (Clarification)

### Change

`ingestion_sources.status = completed` should mean:

* KnowledgeItem created
* At least one chunk embedded
* Failures at normalization do **not** block completion

Normalization failures should be **non-fatal** and logged.

---

## 8. Debug & Verification (Required for Sign-Off)

### CLI

```bash
php artisan ingestion:backfill --limit=5 --debug
```

Must show:

* At least one NormalizeKnowledgeItemJob executing (not skipped)
* Chunking mode logged (`raw` vs `normalized`)
* Retrieval trace showing `variant`

### SQL Verification

```sql
SELECT COUNT(*) FROM knowledge_chunks WHERE source_variant = 'normalized';
```

Must be > 0 after Phase 2.1 test run.

---

## Phase 2.1 Acceptance Criteria (Strict)

Phase 2.1 is complete **only if all are true**:

* Normalization runs on at least one bookmark source
* Normalized claims produce chunks
* Those chunks are embedded and retrievable
* Retrieval trace explicitly shows normalization usage
* Backfill + reingest are idempotent and safe

---

## What This Unlocks (Without Building It Yet)

Once Phase 2.1 is complete, you can *safely* move to Phase 3:

* Intent-aware retrieval strategies
* Claim-level synthesis
* Cross-source contradiction detection
* User-facing “why this was used” explanations

Without Phase 2.1, Phase 3 would sit on sand.
