# IngestionSource Model (Content Generator Service)

This document describes the `IngestionSource` model as the canonical entry point into the knowledge base. It captures raw content, its origin, and processing state, then drives the pipeline that produces `KnowledgeItem`s, chunks, embeddings, and downstream enrichment.

## Purpose and Role

`IngestionSource` is the system's front door for knowledge ingestion. It:

- Normalizes how different inputs (bookmarks, manual text, social watcher imports, file uploads) enter the pipeline.
- Tracks deduplication, quality scoring, and failure state at the source level.
- Acts as the parent link for derived knowledge artifacts (items, chunks, facts).
- Provides a stable boundary for folder scoping and retrieval.

Relevant code:
- `app/Models/IngestionSource.php`
- `app/Jobs/ProcessIngestionSourceJob.php`
- `app/Services/Ingestion/IngestionContentResolver.php`

## Core Fields and Semantics

Key attributes (non-exhaustive):

- `source_type`: `bookmark`, `text`, `file` (current supported types in API).
- `source_id`: source model ID or external identifier (e.g., bookmark UUID, composite id for normalized content, file upload id).
- `origin`: origin tag used for routing and audit (examples: `browser`, `manual`, `social_watcher`, `upload`, `eval_harness`).
- `raw_text`, `raw_url`, `mime_type`: raw content fields resolved internally (no external fetching).
- `dedup_hash`: stable hash for idempotency and coarse deduplication.
- `status`: `pending`, `processing`, `completed`, `failed`.
- `quality_score`, `confidence_score`, `quality` (if column exists): quality metrics.
- `title`, `metadata`: optional rich data to preserve context.
- Structure extraction fields: `swipe_structure_id`, `structure_status`, `structure_confidence`.

## Relationships

- `IngestionSource -> Organization`
- `IngestionSource -> User`
- `IngestionSource -> KnowledgeItem` (via `knowledge_item_id` when present)
- `IngestionSource <-> Folder` (many-to-many via `ingestion_source_folders`)

Relevant code:
- `app/Models/IngestionSource.php`
- `app/Models/KnowledgeItem.php`
- `app/Models/IngestionSourceFolder.php`

## Supported Source Types and Content Resolution

`IngestionContentResolver` resolves content strictly from internal models:

- `text`: uses `IngestionSource.raw_text`.
- `bookmark`: loads `Bookmark` and uses `title` + `description` (no HTTP fetch).
- `file`: **created and stored**, but ingestion is handled by a separate file worker (explained below).

Relevant code:
- `app/Services/Ingestion/IngestionContentResolver.php`
- `app/Jobs/ProcessIngestionSourceJob.php`
- `app/Http/Controllers/Api/V1/IngestionSourceController.php`

## Ingestion Pipeline Capabilities

When `ProcessIngestionSourceJob` runs (currently supports `bookmark` + `text`):

1. **Folder attachment** (optional): attaches `folder_ids` on creation or re-ingest.
2. **Content resolution**: internal-only resolution (no external fetch).
3. **Deduplication**: resolves or skips based on existing `KnowledgeItem`.
4. **Quality scoring**: computes `quality_score` and optional `quality` payload.
5. **Knowledge pipeline**: normalize -> chunk -> embed -> voice traits -> business facts.

Relevant code:
- `app/Jobs/ProcessIngestionSourceJob.php`
- `app/Jobs/InferContextFolderJob.php`
- `app/Jobs/ScoreFolderCandidatesJob.php`
- `app/Services/Ingestion/QualityScorer.php`

## Models That Convert Into Ingestion Sources

These are the **current model-backed sources** that can be converted into ingestion sources.

### 1) Bookmark (`App\Models\Bookmark`)

Purpose: a curated saved link with platform metadata; ingestion turns the stored description/title into knowledge chunks.

Conversion:
- Auto-linked on create (`BookmarkController@store`) via `IngestionSource::firstOrCreate`.
- Backfilled for existing data (`BackfillBookmarkIngestionSources`).

Fields mapped:
- `source_type = bookmark`, `source_id = bookmark.id`
- `origin = browser`, `platform = bookmark.platform`
- `raw_url = bookmark.url`, `title = bookmark.title`
- `dedup_hash = IngestionSource::dedupHashFromUrl(bookmark.url)`

Relevant code:
- `app/Models/Bookmark.php`
- `app/Http/Controllers/Api/V1/BookmarkController.php`
- `app/Console/Commands/BackfillBookmarkIngestionSources.php`
- `app/Services/Ingestion/IngestionContentResolver.php`

### 2) Social Watcher Normalized Content (`LaundryOS\SocialWatcher\Models\NormalizedContent`)

Purpose: normalized, cross-platform social content captured by the Social Watcher pipeline.
This is the "scraped en masse" source that becomes knowledge chunks.

Conversion:
- `ConvertNormalizedContentToIngestionSourceJob` creates a `text` ingestion source.
- `ConvertNormalizedContentToIngestionSource` command runs the job over one or many records.

Fields mapped:
- `source_type = text`
- `source_id = "sw_norm:{org}:{normalized_id}"` (composite, idempotent)
- `origin = social_watcher`, `platform = normalized.platform`
- `raw_url = normalized.url`, `raw_text = composed from title + text`
- `metadata.social_watcher = {...normalized metrics...}`
- `dedup_hash = IngestionSource::dedupHashFromText(raw_text)`

Relevant code:
- `packages/social-watcher/src/Models/NormalizedContent.php`
- `app/Jobs/ConvertNormalizedContentToIngestionSourceJob.php`
- `app/Console/Commands/ConvertNormalizedContentToIngestionSource.php`

## Non-Model Entry Points (Still Ingestion Sources)

These are ingestion sources created directly, but not backed by a dedicated model.

### Manual Text (API)

Purpose: user-provided text (pasted content, notes).

Creation:
- `IngestionSourceController@store` with `type=text`.
- `origin = manual`, `source_type = text`, `raw_text` from request.

Relevant code:
- `app/Http/Controllers/Api/V1/IngestionSourceController.php`

### File Uploads (API)

Purpose: uploaded files captured by an external worker; the ingestion source is the record of intent and metadata.

Creation:
- `IngestionSourceController@store` with `type=file`.
- `origin = upload`, `source_type = file`, `source_id` comes from the upload subsystem.
- Extraction is explicitly deferred to a separate file ingestion worker.

Relevant code:
- `app/Http/Controllers/Api/V1/IngestionSourceController.php`

### Evaluation Harness / Programmatic Text

Purpose: ingestion evaluation runs for QA and tuning.

Creation:
- `IngestionRunner` creates an ingestion source with `origin = eval_harness`.
- `dedup_hash` may include evaluation ID to avoid cross-run collisions.

Relevant code:
- `app/Services/Ingestion/IngestionRunner.php`
- `app/Console/Commands/AiIngestionEval.php`

## Summary

`IngestionSource` is the single, canonical entry point into the knowledge base. Bookmark and Social Watcher models are the two model-backed sources that automatically become ingestion sources today, while manual text and file uploads provide direct ingestion entry points via API. All downstream knowledge artifacts are rooted in the ingestion source record, which is why it is treated as the primary boundary for deduplication, quality scoring, folder scoping, and retrieval.
