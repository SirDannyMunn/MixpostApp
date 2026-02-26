## Engineering Spec: Ingestion Pipelines → Normalized Knowledge → Chunking → Embeddings → Retrieval

### Goal

Build **source-aware ingestion pipelines** that transform messy inputs (bookmarks, pasted notes, transcripts, docs, Notion imports, past posts, tweet drafts) into **normalized, indexable Knowledge Chunks** (and optionally Business Facts + Voice Traits), so your generation system can reliably retrieve high-signal context via pgvector.

---

## Non-goals

* Not building full UI flows (covered elsewhere).
* Not building perfect “truth” verification. We score confidence and provenance, we don’t claim factual certainty.
* Not rewriting content generation prompt system (assumes existing ContentGeneratorService + ContextFactory).

---

## Current System Interfaces (assumed)

* `KnowledgeItem` table exists (raw_text + metadata).
* `ChunkKnowledgeItemJob` chunks a KnowledgeItem into `knowledge_chunks`.
* `EmbedKnowledgeChunksJob` embeds `knowledge_chunks.embedding_vec`.
* `Retriever::knowledgeChunks(...)` vector searches `knowledge_chunks`.
* Business facts and voice traits jobs exist (can be integrated or gated).

This spec adds: **Pipeline Orchestrator + Pipeline Profiles + Normalization & Extraction + Typed Chunk Objects**.

---

# 1) Data Model Changes

## 1.1 Ingestion Source (Option A foundation)

**Table: `ingestion_sources`**

* `id` (uuid)
* `organization_id` (uuid)
* `user_id` (uuid)
* `source_type` enum:

  * `bookmark`, `pasted_text`, `file`, `youtube_transcript`, `notion`, `internal_doc`, `tweet_draft`, `past_post`, `blog_draft`, `custom`
* `status` enum: `created`, `ingesting`, `ingested`, `failed`
* `title` nullable
* `raw_text` nullable (for pasted, extracted text)
* `raw_text_sha256` nullable
* `mime_type` nullable
* `external_url` nullable (bookmark/url sources)
* `external_id` nullable (tweet id, notion page id)
* `platform` nullable (twitter/linkedin/tiktok/etc)
* `metadata` json nullable (author, timestamp, channel, language, etc)
* `quality` json nullable (ingestion scoring output)
* `error` text nullable
* timestamps

**Relations**

* `ingestion_sources` can link to:

  * `bookmarks` (1:1 via `ingestion_source_links` or `ingestion_source_id` on bookmarks)
  * `files` (1:1 if you have file uploads)
  * `knowledge_items` (1:many OR 1:1 depending on design)

## 1.2 KnowledgeItem enhancements (minimal)

If not already present:

* `source_type` (mirror ingestion_sources.source_type) OR use existing `source` + `source_platform` + `source_id`
* `ingestion_source_id` (uuid, index)
* `quality` json nullable (optional: quality at item level)
* indexes:

  * `(organization_id, user_id, ingestion_source_id)`
  * `raw_text_sha256` unique per org/user if desired

## 1.3 KnowledgeChunk enhancements (typed chunk objects)

Add these fields to support pipeline output and weighting:

* `chunk_role` enum:

  * `belief_high`, `heuristic`, `definition`, `strategic_claim`, `causal_claim`, `example`, `quote`, `metric`, `instruction`, `other`
* `authority` enum: `high`, `medium`, `low`
* `confidence` float (0..1)
* `time_horizon` enum: `current`, `near_term`, `long_term`, `unknown`
* `source_type` enum (copied from ingestion source)
* `source_ref` json (ids to replay: ingestion_source_id, knowledge_item_id, bookmark_id, file_id, offsets)
* `tags` json (already exists)
* indexes:

  * vector index on `embedding_vec`
  * `(organization_id, user_id, source_type, chunk_role)`
  * `(knowledge_item_id)`

---

# 2) Pipeline Architecture

## 2.1 Pipeline Contract

Every ingestion pipeline implements:

```php
interface IngestionPipeline {
  public function supports(IngestionSource $src): bool;

  /** Return PipelineResult including knowledge items, chunks, facts, voice artifacts, quality report */
  public function run(IngestionSource $src, array $options = []): PipelineResult;
}
```

### PipelineResult

* `knowledgeItems[]` created
* `chunks[]` created OR number of chunks
* `facts[]` created (optional)
* `voiceTraits` created (optional)
* `qualityReport` (required)
* `trace` (required): structured steps, counts, timings

## 2.2 Orchestrator

`IngestionOrchestrator`:

* selects pipeline profile by `source_type`
* runs stages:

  1. Acquire text (fetch/extract)
  2. Normalize
  3. Extract + classify
  4. Persist KnowledgeItem
  5. Persist KnowledgeChunks
  6. Embed chunks (queue or sync)
  7. Optional: business facts, voice traits
  8. Compute and persist quality report

## 2.3 Jobs

* `IngestIngestionSourceJob(ingestion_source_id)`

  * idempotent, retry-safe, updates status
* For heavy sources:

  * `ExtractTextFromFileJob`
  * `FetchBookmarkContentJob` (optional; if you later scrape)
  * `YouTubeTranscriptFetchJob`
* Reuse:

  * `ChunkKnowledgeItemJob`
  * `EmbedKnowledgeChunksJob`
  * `ExtractBusinessFactsJob`
  * `ExtractVoiceTraitsJob`

---

# 3) Pipeline Profiles (v1)

You want predictable quality. That means **each source_type has a different pipeline**.

## 3.1 Pasted Text / Internal Notes Pipeline

**source_type:** `pasted_text`, `internal_doc`, `user_note`

### Steps

1. **Clean**

   * strip boilerplate, normalize whitespace
   * detect language (store in metadata)
2. **Semantic Normalization (LLM-assisted)**

   * output: `normalized_claims[]` (declarative)
3. **Epistemic Classification**

   * label each claim as:

     * belief_high, heuristic, definition, strategic_claim, causal_claim
   * attach confidence/authority/time_horizon
4. **Chunk Object Creation**

   * each claim becomes one chunk
   * dedupe near-identical claims within this source
5. Persist KnowledgeItem

   * raw_text = original
   * metadata: {source_type, language, normalization_version, …}
6. Persist KnowledgeChunks
7. Queue embeddings
8. Quality score + store

**Output expectations**

* chunk_count: 5–40 (depends)
* chunk_role distribution skewed toward belief/strategic/causal/definition

## 3.2 Bookmark (Social Post) Pipeline

**source_type:** `bookmark`

### Steps

1. Acquire content

   * v1: title + description + url + platform_metadata (no scraping)
   * store `raw_text = "{title}\n\n{description}\n\nURL: ..."`
2. **Structure extraction (optional)**

   * detect hook/body/cta (cheap heuristic or LLM)
3. **Quote vs Claim split**

   * If it’s “inspiration”, preserve as `quote` chunk_role
   * Extract 1–5 normalized claims (optional) into `strategic_claim` chunks
4. Persist KnowledgeItem with:

   * source_platform, source_id, url, author handle (if known)
5. Persist chunks:

   * `quote` (verbatim text, marked disallowed_for_copy unless user wants)
   * `strategic_claim` (normalized)
6. Embed
7. Quality scoring

**Key requirement**

* Bookmarks should be retrievable as:

  * inspiration (“swipe” style)
  * and as reasoning substrate (normalized claim)

## 3.3 Transcript Pipeline (YouTube/Podcast)

**source_type:** `youtube_transcript`, `transcript`

### Steps

1. Segment transcript by timestamps / speaker blocks
2. Summarize into:

   * definitions
   * causal claims
   * heuristics
3. Chunking strategy:

   * one chunk per strong claim; keep “quote” chunks optional
4. Metadata: timestamps in `source_ref` for replay

## 3.4 Blog Draft / Past Post / Tweet Draft Pipeline

**source_type:** `blog_draft`, `past_post`, `tweet_draft`

Goal: reuse your own writing as **voice + reusable beliefs**.

* Extract:

  * beliefs/heuristics/definitions
  * plus store a “style chunk” group for voice analysis (optional)
* Chunk role includes `example` where relevant

---

# 4) Normalization + Extraction Components

## 4.1 SemanticNormalizer (LLM)

`SemanticNormalizer::normalize($text, $sourceType): NormalizedOutput`

Output JSON described by schema:

```json
{
  "normalized_claims": [
    { "text": "...", "notes": "...optional..." }
  ]
}
```

Requirements:

* Deterministic-ish: set temperature low
* Enforce declarative sentences
* Remove anecdotes and rhetorical framing

## 4.2 EpistemicClassifier (LLM or rules)

`EpistemicClassifier::classify($claims[]): ClassifiedClaims`

Output JSON:

```json
{
  "claims": [
    {
      "text": "...",
      "chunk_role": "strategic_claim",
      "confidence": 0.82,
      "authority": "high",
      "time_horizon": "long_term",
      "tags": ["ai", "strategy", "data_moat"]
    }
  ]
}
```

## 4.3 Deduplication

Dedup happens at two levels:

### (A) IngestionSource → KnowledgeItem

* If `raw_text_sha256` already exists for same org/user/source_type → skip create unless `force=true`.

### (B) KnowledgeItem → Chunks

* Dedup by:

  * exact hash of normalized claim text
  * optional: vector similarity against existing chunks from same user/org with high overlap threshold (e.g. distance <= 0.10)
* If duplicate found:

  * increment a `usage_count` or add backlink reference
  * do not create duplicate chunk

---

# 5) Ingestion Quality Scoring (required)

## 5.1 Ingestion Quality Report

Stored on `ingestion_sources.quality` (and optionally copied to knowledge_items.quality).

Fields:

* `signal_density` (0..1): ratio of “claim-like” sentences
* `redundancy` (0..1): internal duplication
* `specificity` (0..1): contains concrete nouns/verbs, avoids generic
* `extractability` (0..1): did normalization produce meaningful claims
* `embedding_coverage` (0..1): chunks_embedded / chunks_total
* `overall` (0..1): weighted combination
* `warnings[]`: e.g. “too short”, “mostly noise”, “no claims extracted”
* `stats`: counts, timings

## 5.2 Gates

* If `overall < 0.35`:

  * mark ingestion_source as `ingested_with_warnings`
  * still persist raw KnowledgeItem, but reduce retrieval weighting later

---

# 6) Retrieval Weighting Rules (type + source + role)

Implement in Retriever scoring:

### Base weights

* `pasted_text/internal_doc`: 1.0
* `blog_draft/past_post`: 0.9
* `transcript`: 0.8
* `bookmark`: 0.7
* `tweet_draft`: 0.75
* `file` (pdf extracted): 0.85 (depends on quality)

### Role weights

* `belief_high`: 1.0
* `strategic_claim`: 0.95
* `causal_claim`: 0.9
* `heuristic`: 0.85
* `definition`: 0.7
* `quote`: 0.6 (unless “inspiration mode”)
* `example`: 0.65
* `metric`: 0.8

### Quality multiplier

`final_weight = base_weight * role_weight * clamp(quality.overall, 0.5..1.1)`

### Intent-specific adjustments

* `educational`: boost definitions/causal_claim
* `contrarian`: boost beliefs/strategic_claim
* `story`: boost examples/quotes (if allowed)
* `persuasive`: boost causal_claim + metric

### Selection

Retriever returns top-N by:

* vector distance primary
* weighted score secondary tie-break
* enforce per-role caps to avoid spam (e.g., max 2 quotes)

---

# 7) Replay Tooling (“why did this post use this bookmark?”)

## 7.1 Source References on chunks

Every `knowledge_chunk.source_ref` must include:

* `ingestion_source_id`
* `knowledge_item_id`
* `source_type`
* Optional: `bookmark_id`, `file_id`, `url`, `timestamp_range`, `offsets`

## 7.2 Generation Snapshot must persist

* Selected chunk ids + distances + weights used
* Reasons:

  * “passed threshold”
  * “boosted due to intent”
  * “quality multiplier”
* Store in `generation_snapshots.retrieval_debug`

Schema:

```json
{
  "query": "...",
  "threshold": 0.35,
  "results": [
    {
      "chunk_id": "...",
      "distance": 0.22,
      "base_weight": 0.7,
      "role_weight": 0.6,
      "quality": 0.8,
      "final_weight": 0.336,
      "source_ref": { ... }
    }
  ]
}
```

## 7.3 API

* `GET /generations/{id}/replay`

  * returns: prompt, composed system prompt (redacted), retrieval_debug, and linked sources
* `GET /knowledge-chunks/{id}/source`

  * returns ingestion source + bookmark/file/etc

---

# 8) Implementation Plan (phased)

## Phase 0: Foundations

* Add `ingestion_sources` table
* Add `ingestion_source_id` on `knowledge_items`
* Add chunk typing fields on `knowledge_chunks`
* Add source_ref json

## Phase 1: Orchestrator + Pasted Text pipeline

* `IngestIngestionSourceJob`
* `PastedTextPipeline`
* Quality scoring v1
* Dedup v1
* Ensure chunk/embed works end-to-end

## Phase 2: Bookmark pipeline

* Create ingestion_source when bookmark created
* Bookmark→KnowledgeItem conversion uses orchestrator
* Add quote + claim extraction

## Phase 3: Transcript pipeline

* Add timestamp-aware chunk refs

## Phase 4: Retrieval weighting + replay

* Implement weighting rules
* Persist retrieval_debug in snapshots
* Build replay endpoints

---

# 9) Acceptance Criteria

## Pipeline correctness

* Ingest any ingestion_source → produces:

  * 1 knowledge_item
  * > =1 knowledge_chunk
  * embeddings created for chunks
  * quality report stored
  * retrieval returns these chunks for relevant queries

## Dedup correctness

* Re-ingesting identical content does not create duplicates (unless forced)
* Near-duplicate claims within a source do not create multiple chunks

## Replay correctness

* For a generation, you can list:

  * chunk ids used
  * their source ingestion record
  * their parent bookmark/file/etc
  * distances + weights used

## Quality

* Low-signal input yields low quality score + warning
* Retrieval deprioritizes low quality sources automatically

---

# 10) Concrete Schemas (LLM outputs)

## Normalization schema

```json
{
  "normalized_claims": [
    { "text": "..." }
  ]
}
```

## Classification schema

```json
{
  "claims": [
    {
      "text": "...",
      "chunk_role": "belief_high|heuristic|definition|strategic_claim|causal_claim|quote|example|metric|instruction|other",
      "confidence": 0.0,
      "authority": "high|medium|low",
      "time_horizon": "current|near_term|long_term|unknown",
      "tags": []
    }
  ]
}
```

## Quality schema

```json
{
  "overall": 0.0,
  "signal_density": 0.0,
  "redundancy": 0.0,
  "specificity": 0.0,
  "extractability": 0.0,
  "embedding_coverage": 0.0,
  "warnings": [],
  "stats": { "claims": 0, "chunks": 0 }
}
```
