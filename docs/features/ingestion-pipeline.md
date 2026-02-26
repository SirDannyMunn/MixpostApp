# Ingestion Pipeline

This document explains the full ingestion pipeline end‑to‑end, covering the background jobs, control flow, chunk types, and the database schema used by ingestion. It consolidates behavior from:

- Jobs: `ProcessIngestionSourceJob`, `NormalizeKnowledgeItemJob`, `ChunkKnowledgeItemJob`, `ClassifyKnowledgeChunksJob`, `EmbedKnowledgeChunksJob`, `ExtractVoiceTraitsJob`, `ExtractBusinessFactsJob`, `BookmarkToKnowledgeItemJob`
- Models: `IngestionSource`, `KnowledgeItem`, `KnowledgeChunk`, `BusinessFact`, `Bookmark`
- Services: `IngestionContentResolver`, `QualityScorer`, `LLMClient`/`OpenRouterService`, `EmbeddingsService`
- Migrations: knowledge_items, knowledge_chunks (+ typed fields + pgvector), ingestion_sources (+ quality fields), business_facts, bookmarks


## Overview

There are two primary entry points into the pipeline:

- `ProcessIngestionSourceJob` (recommended): Runs against an `ingestion_sources` record. It resolves content strictly from internal storage, de‑duplicates by content hash within the organization, persists a canonical `knowledge_items` row, then chains the downstream jobs (normalize → chunk → classify → embed → voice → facts).
- `BookmarkToKnowledgeItemJob`: A direct path that creates a `knowledge_items` row from a `bookmarks` record without going through `ingestion_sources`. It then chains the same downstream jobs (chunk → classify → embed → voice → facts). Used for older or direct bookmark ingestion flows.

Both paths enforce: no external HTTP fetch in ingestion; only internally stored text is used.


## Flow Diagram

```mermaid
flowchart TD
    A[Start] --> B{Entry}
    B -->|Ingestion Source| C[ProcessIngestionSourceJob]
    B -->|Direct Bookmark| Z[BookmarkToKnowledgeItemJob]

    %% IngestionSource path
    C --> C1[status=processing]
    C1 --> C2[Resolve content (IngestionContentResolver)]
    C2 -->|empty| CErr[Fail: No internal content]
    C2 -->|text| C3[Compute sha256(raw_text)]
    C3 --> C4{Duplicate in org?}
    C4 -->|yes & !force| C5[Mark completed\n dedup_reason=knowledge_item_duplicate\n link to canonical KI]
    C4 -->|no or force| C6[Create KnowledgeItem\n type=bookmark→excerpt, text→note]
    C6 --> C7[Score quality (QualityScorer)\n update ingestion_sources.quality_score (+quality JSON if column exists)]
    C7 --> C8[Chain jobs]

    %% Direct Bookmark path
    Z --> Z1[Assemble raw from title+description]
    Z1 -->|empty| ZErr[Skip/log (debug: throw)]
    Z1 --> Z2[Compute sha256(raw_text)]
    Z2 --> Z3{Existing KI for same org + bookmark + hash?}
    Z3 -->|yes| Z4[Skip\n (duplicate)]
    Z3 -->|no| Z5[Create KnowledgeItem\n type=excerpt, source=bookmark]
    Z5 --> Z6[Chain jobs]

    %% Downstream (shared)
    C8 --> N[NormalizeKnowledgeItemJob]
    Z6 --> N
    N --> CH[ChunkKnowledgeItemJob]
    CH --> CL[ClassifyKnowledgeChunksJob]
    CL --> EM[EmbedKnowledgeChunksJob]
    EM --> VT[ExtractVoiceTraitsJob]
    VT --> BF[ExtractBusinessFactsJob]
    BF --> End[Done]

    CErr --> End
    ZErr --> End
    C5 --> End
```


## Job Details

### ProcessIngestionSourceJob

- Input: `ingestionSourceId` and `force` (boolean)
- Status lifecycle on `ingestion_sources`:
  - `pending` → `processing` → `completed` (or `failed` with `error` message)
- Branching by `ingestion_sources.source_type`:
  - `bookmark`: Resolve by `IngestionContentResolver` from `bookmarks` only; compose title + description.
  - `text`: Use `ingestion_sources.raw_text` directly.
- Content invariant: empty content causes a failure (no external fetching is attempted).
- De‑duplication: compute `sha256` of a normalized text (trim + whitespace collapse) and check existing `knowledge_items` with the same `organization_id` and `raw_text_sha256`.
  - If duplicate and not `force`: mark `ingestion_sources` as `completed`, `dedup_reason=knowledge_item_duplicate`, optionally backfill `knowledge_item_id` if the column exists; dispatch `IngestionSourceDeduped` event; do not continue the chain.
- Knowledge item creation:
  - For `bookmark`: `type=excerpt`, `source=bookmark`, `source_id=bookmark.id`, `source_platform`, `title`, `raw_text`, `raw_text_sha256`, and `metadata` including `source_url`, `image_url`, `favicon_url`; `confidence=0.3`.
  - For `text`: `type=note`, `source=manual`, `title=null`, `metadata=null`; `confidence=0.6`.
  - Always sets `ingested_at` (timestamp) and foreign keys `organization_id`, `user_id`, and `ingestion_source_id`.
  - Invariant: created rows must have non‑empty `raw_text`.
- Quality scoring: `QualityScorer::score($raw)` computes `overall` (0..1) plus sub‑metrics; persists `quality_score` to `ingestion_sources` and, if present, full `quality` JSON.
- Chains these jobs:
  1. `NormalizeKnowledgeItemJob`
  2. `ChunkKnowledgeItemJob`
  3. `ClassifyKnowledgeChunksJob`
  4. `EmbedKnowledgeChunksJob`
  5. `ExtractVoiceTraitsJob`
  6. `ExtractBusinessFactsJob`


### BookmarkToKnowledgeItemJob

- Input: `bookmarkId`, `userId`, `organizationId`.
- Builds `raw_text` by concatenating `bookmark.title` and `bookmark.description` (no external fetch). Empty content is skipped (throws in debug).
- De‑duplication within the organization by `raw_text_sha256`; first tries matching pair (`source=bookmark` + `source_id=bookmark.id` + hash), then falls back to hash‑only.
- Creates a `knowledge_items` row (`type=excerpt`, `source=bookmark`, `confidence=0.3`) with `metadata` from the bookmark.
- Chains: `ChunkKnowledgeItemJob` → `ClassifyKnowledgeChunksJob` → `EmbedKnowledgeChunksJob` → `ExtractVoiceTraitsJob` → `ExtractBusinessFactsJob`.


### NormalizeKnowledgeItemJob

- Purpose: Convert long raw text into atomic, reusable “normalized claims” and an optional summary.
- Gating and eligibility (config `config/ai.php`):
  - `ai.normalization.min_chars` (default 400)
  - `ai.normalization.min_quality` (default 0.55)
  - `ai.normalization.eligible_sources` (default `[bookmark, text, file, transcript]`), using either `ingestion_sources.source_type` when linked or a best effort from the item’s `source` (e.g., `manual` → `text`).
- If not eligible (short text, low quality, ineligible source), it explicitly unsets `normalized_claims` and returns.
- Idempotency: if `normalized_claims.normalization_hash` matches `sha256(raw_text)`, skip.
- LLM prompt returns a JSON object: `normalized_claims: [{id?, text, type, confidence, authority}], summary?`
  - Claims are sanitized: empty text filtered, confidence clamped `0..1`, authority normalized to `high|medium|low`.
- Persists to `knowledge_items.normalized_claims` as JSON:
  - `claims`: array of `{id, text, type, confidence, authority}`
  - `summary`: string (optional)
  - `source_stats`: `{original_chars, claims_count}`
  - `normalization_hash`: `sha256(raw_text)`


### ChunkKnowledgeItemJob

- Dual‑mode chunking depending on whether `normalized_claims` exist:
  1. Normalized mode
     - Deletes any existing chunks for the item with `source_variant=normalized` (idempotency).
     - For each claim (up to 200): creates a `knowledge_chunks` row with:
       - `chunk_text` = claim text
       - `chunk_type=normalized_claim`
       - `chunk_role=other` (to be classified later)
       - `authority` = normalized from claim (`high|medium|low`)
       - `confidence` = claim or fallback item confidence
       - `time_horizon=unknown`
       - `source_type` = normalized item source (e.g., `bookmark`→`bookmark`, `manual`→`text`)
       - `source_variant=normalized`
       - `source_ref` = `{ingestion_source_id, knowledge_item_id}`
       - `tags=null`
       - `token_count ≈ floor(length/4)`
  2. Raw fallback mode
     - Deletes any existing chunks with `source_variant=raw`.
     - Splits `raw_text` into paragraphs and packs them into chunks limited to ~2500 chars (up to 80 chunks).
     - `chunk_type=excerpt` if the item’s `type=excerpt`, else `misc`.
     - `chunk_role=quote` default for excerpts, else `other`.
     - `authority=medium` for bookmarks, else `low`.
     - `confidence` = item confidence, `time_horizon=unknown`, `source_type` normalized, `source_variant=raw`, `source_ref` populated, `tags=null`, `token_count` estimated.


### ClassifyKnowledgeChunksJob

- Selects variant to classify: if the item has normalized claims, uses `source_variant=normalized`; otherwise `raw`.
- Batches up to 20 chunks ordered by `created_at`.
- For each chunk, builds a deterministic `classification_hash` from: `source_type | source_variant | isNormalized | ingestion_quality | text-preview`. If the chunk `tags.classification_hash` matches this hash, the chunk is skipped (idempotency on content + context).
- LLM returns `results` with the same count/order as inputs. Each result contains:
  - `chunk_role` (enum): `belief_high, belief_medium, definition, heuristic, strategic_claim, causal_claim, instruction, metric, example, quote`
  - `authority`: `high|medium|low`
  - `confidence`: `0..1`
  - `time_horizon`: `current|near_term|long_term|unknown`
- The job sanitizes any out‑of‑range or unknown values and updates the chunk, recording `tags.classification_hash` and `tags.classified_at`.
- If the batch size was full, the job re‑queues itself to continue classifying the remaining chunks.


### EmbedKnowledgeChunksJob

- Waits until at least one chunk exists for the item; otherwise releases and tries later.
- Processes up to 100 chunks per run where `embedding_vec IS NULL`.
- Requests embeddings from `EmbeddingsService` (defaults to OpenRouter `openai/text-embedding-3-small`, 1536 dims).
- Persists embeddings to `knowledge_chunks.embedding_vec` (pgvector) and sets `embedding_model`.
- Adds metadata to `tags.embedding_meta` with `{variant, model}` where `variant` mirrors the chunk’s `source_variant`.
- If more chunks remain without embeddings, re‑queues itself.


### ExtractVoiceTraitsJob

- Uses a short sample (up to 1,000 chars) of the item’s `raw_text`.
- Calls the model to extract up to 3 writing “voice” traits (e.g., authoritative, whimsical, data‑driven) and merges them into the organization/user’s `voice_profiles` record:
  - Merges into `traits.tone` (unique and capped at 10 tokens)
  - Updates `traits_preview`, increments `sample_size`, nudges `confidence` upward (capped at 0.95)


### ExtractBusinessFactsJob

- Uses up to ~2,000 chars of `raw_text`.
- Extracts up to 3 concise items useful for marketing: `{text, type, confidence}` where `type ∈ {pain_point, belief, stat, general}` and `confidence ∈ [0,1]`.
- Persists to `business_facts` with `source_knowledge_item_id` linking back to the item.


## Chunk Types and Roles

- `chunk_type` values created by the pipeline:
  - `normalized_claim`: A single atomic claim from normalization.
  - `excerpt`: A paragraph‑level chunk when the item is an `excerpt` (e.g., from a bookmark).
  - `misc`: Paragraph‑level chunk when not an excerpt.

- `chunk_role` values (set by the classifier; defaults to `other` before classification):
  - `belief_high`, `belief_medium`, `definition`, `heuristic`, `strategic_claim`, `causal_claim`, `instruction`, `metric`, `example`, `quote`, `other`

- Other typed fields on chunks:
  - `authority`: `high|medium|low`
  - `confidence`: `0..1` (float)
  - `time_horizon`: `current|near_term|long_term|unknown`
  - `source_type`: normalized from `KnowledgeItem.source` (e.g., `manual` → `text`)
  - `source_variant`: `normalized` or `raw`
  - `source_ref`: JSON like `{ingestion_source_id, knowledge_item_id}`
  - `tags`: JSON metadata
    - `classification_hash`: idempotency token for classification
    - `classified_at`: ISO timestamp
    - `embedding_meta`: `{variant, model}` added by embedding job


## Database Schema Reference

Field lists are summarized from the migrations present in `database/migrations` and casts in the models.

### ingestion_sources

- `id` (uuid, pk)
- `organization_id` (uuid)
- `user_id` (uuid)
- `source_type` (string, e.g., bookmark, text, file, transcript, draft, post, ai_output)
- `source_id` (string, nullable) – source record ID (e.g., bookmark UUID)
- `origin` (string, nullable) – browser, manual, upload, integration, ai
- `platform` (string, nullable) – e.g., twitter, linkedin, notion
- `raw_url` (text, nullable)
- `raw_text` (longText, nullable)
- `mime_type` (string, nullable)
- `title` (string, nullable)
- `metadata` (json, nullable)
- `confidence_score` (float, nullable)
- `quality_score` (float, nullable)
- `quality` (json, nullable) – full quality report if enabled
- `dedup_hash` (string, indexed)
- `status` (string, default `pending`) – `pending|processing|completed|failed`
- `dedup_reason` (string, nullable) – reason when completed due to de‑duplication
- `error` (text, nullable) – error message on failures
- `created_at`, `updated_at` (timestamps)
- `deleted_at` (soft delete)
- Indexes: `unique(source_type, source_id)`, `index(organization_id, created_at)`, `index(status, dedup_reason)`

Helpers on the model:
- `normalizeUrl()` and `dedupHashFromUrl()` – coarse URL normalization and hashing
- `dedupHashFromText()` – builds a sha1 hash from the first ~2,000 chars


### knowledge_items

- `id` (uuid, pk)
- `organization_id` (uuid)
- `user_id` (uuid)
- `ingestion_source_id` (uuid, nullable, indexed) – origin link when created via ingestion_sources
- `type` (string) – e.g., `excerpt` (from a bookmark) or `note` (manual text)
- `source` (string) – e.g., `bookmark`, `manual`
- `source_id` (string, nullable) – source record identifier (bookmark UUID)
- `source_platform` (string, nullable) – bookmark platform if available
- `title` (string, nullable)
- `raw_text` (longText) – required non‑empty invariant
- `raw_text_sha256` (char(64), indexed) – content de‑duplication key per organization
- `metadata` (json, nullable) – e.g., `{source_url, image_url, favicon_url}`
- `normalized_claims` (json, nullable) – set by normalization job
  - `claims`: array of `{id, text, type, confidence, authority}`
  - `summary`: string
  - `source_stats`: `{original_chars, claims_count}`
  - `normalization_hash`: sha256 of the raw text used
- `confidence` (float) – initial confidence (0.3 for bookmarks, 0.6 for manual)
- `ingested_at` (timestamp, nullable)
- `created_at`, `updated_at` (timestamps)
- Indexes: `index(organization_id, created_at)`

Relations:
- `chunks()` – hasMany `knowledge_chunks`
- `businessFacts()` – hasMany `business_facts` via `source_knowledge_item_id`
- `ingestionSource()` – belongsTo `ingestion_sources`


### knowledge_chunks

From base + augmenting migrations (typed fields and pgvector):

- Identity and linkage
  - `id` (uuid, pk)
  - `knowledge_item_id` (uuid, fk → knowledge_items, cascade on delete)
  - `organization_id` (uuid)
  - `user_id` (uuid, fk → users)

- Content and typing
  - `chunk_text` (longText)
  - `chunk_type` (string, default `misc`) – see “Chunk Types and Roles”
  - `chunk_role` (string, nullable) – role assigned by classifier
  - `authority` (string, nullable) – `high|medium|low`
  - `confidence` (float, nullable) – `0..1`
  - `time_horizon` (string, nullable) – `current|near_term|long_term|unknown`

- Source context and metadata
  - `source_type` (string, nullable) – normalized source (bookmark/text)
  - `source_variant` (string, nullable) – `normalized|raw`
  - `source_ref` (json, nullable) – `{ingestion_source_id, knowledge_item_id}`
  - `tags` (jsonb, nullable) – arbitrary metadata including `classification_hash`, `classified_at`, `embedding_meta`
  - `token_count` (int, default 0) – rough estimate used for sizing

- Embeddings
  - `embedding` (jsonb, nullable) – legacy JSON vector store (kept for compatibility)
  - `embedding_vec` (pgvector, 1536 dims) – canonical vector store
  - `embedding_model` (string, nullable) – e.g., `text-embedding-3-small`

- Timestamps and indexes
  - `created_at` (timestamp)
  - Indexes: `index(organization_id, chunk_type)`, `index(organization_id, user_id, source_type, chunk_role)`, `index(knowledge_item_id)`, `index(knowledge_item_id, source_variant)`
  - HNSW index on `embedding_vec` with `vector_cosine_ops`


### bookmarks

- `id` (uuid, pk)
- `organization_id` (uuid, fk)
- `folder_id` (uuid, nullable, fk)
- `created_by` (uuid, fk → users)
- `title` (string, required)
- `description` (text, nullable)
- `url` (string, up to 2000)
- `image_url` (string, up to 2000, nullable)
- `favicon_url` (string, up to 2000, nullable)
- `platform` (enum) – `instagram|tiktok|youtube|twitter|linkedin|pinterest|other`
- `platform_metadata` (jsonb, nullable)
- `type` (enum) – `inspiration|reference|competitor|trend`
- `is_favorite` (bool), `is_archived` (bool)
- `created_at`, `updated_at` (timestamps), `deleted_at` (soft)
- Indexes: multiple `organization_id`‑scoped indexes; join table `bookmark_tags(bookmark_id, tag_id)`


### business_facts

- `id` (uuid, pk)
- `organization_id` (uuid)
- `user_id` (uuid)
- `type` (string) – `pain_point|belief|stat|general`
- `text` (text) – the extracted fact
- `confidence` (float) – `0..1`
- `source_knowledge_item_id` (uuid, nullable, fk → knowledge_items, null on delete)
- `created_at` (timestamp)
- Indexes: `index(organization_id, type)`


## Behavior Notes and Invariants

- Ingestion strictly uses internally stored text. No HTTP fetches happen in the jobs; `IngestionContentResolver` composes from local models only.
- Content de‑duplication is done by `raw_text_sha256` per organization. For ingestion sources, duplicates short‑circuit the pipeline and mark the source as completed with `dedup_reason=knowledge_item_duplicate`.
- `knowledge_items.raw_text` must never be empty. Both entry paths enforce this post‑insert invariant.
- Normalization is gated by `ai.normalization.*` and is idempotent for the same raw text.
- Chunking is idempotent per `source_variant` and deletes previous chunks for that variant before recreating.
- Classification uses a `classification_hash` in `knowledge_chunks.tags` to avoid rework when neither the text nor its classification context changed.
- Embeddings are stored in `embedding_vec` (pgvector) with a cosine‑distance HNSW index; the model/dimension is aligned with `text-embedding-3-small` (1536 dims). Legacy JSON `embedding` remains for compatibility but is not written by the embedding job.
- Voice trait extraction accumulates per org/user `voice_profiles` to refine tone metadata used elsewhere.
- Business facts are capped (up to 3) per item extraction to prevent noise.


## Retrievers and Consumers (FYI)

While not part of the request, downstream retrieval typically queries `knowledge_chunks` using:

- `source_variant` preference (normalized first) and/or role filters
- HNSW `embedding_vec` kNN with cosine distance (thresholds in `config/ai.php`)

These consumers assume the invariants and typing established by the pipeline above.

