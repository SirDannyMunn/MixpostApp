# Folder-Scoped Knowledge Retrieval (ContentGeneratorService)

This document describes how folder-based scoping is produced during ingestion and then enforced during knowledge retrieval for `ContentGeneratorService`.
It focuses on knowledge items, knowledge chunks, the new folder-embedding auto-scope pipeline, and the retrieval path, with special attention to folder name inference, candidate scoring, and the folder attachment model.

Note: the code uses `InferContextFolderJob` (not `InfoContextFolderJob`). This document follows the actual class name and behavior.

## Why Folder Scoping Exists

Folder scoping provides a stable, reusable boundary for knowledge retrieval. A folder represents a reusable semantic context (campaign, recurring narrative, theme) so that retrieval can be limited to knowledge chunks that belong to that context.

Folder scoping is applied only to knowledge retrieval (not facts, swipes, templates).

## Data Model: IngestionSource <-> Folder

Folder scoping is attached at the **ingestion source** level and inherited by knowledge items and chunks via the source link.

- `IngestionSource` has a many-to-many relation to `Folder` via `ingestion_source_folders`.
- `IngestionSourceFolder` is the pivot model that stores attachments and optional attribution (`created_by`).

Relevant code:
- `app/Models/IngestionSource.php`
- `app/Models/IngestionSourceFolder.php`

Key fields on the pivot:
- `ingestion_source_id`
- `folder_id`
- `created_by` (null for AI attachments; user id for manual attachment)
- `created_at`

## Data Model: Folder Embeddings (Auto-Scope)

Auto-scoping uses a one-to-one embedding record per folder to run similarity search.

Table: `folder_embeddings`
- `id` (uuid)
- `folder_id` (uuid, unique)
- `org_id` (uuid)
- `text_version` (int)
- `representation_text` (text)
- `embedding` (vector(1536), pgvector)
- `stale_at` (timestamp, nullable)
- `updated_at` (timestamp, nullable)

Model/relations:
- `FolderEmbedding` model
- `Folder::embedding()` hasOne relation

Relevant code:
- `database/migrations/2026_01_09_000100_create_folder_embeddings_table.php`
- `app/Models/FolderEmbedding.php`
- `app/Models/Folder.php`

## Ingestion Flow: When and How Folder Attachments Happen

### 1) Manual folder attachment (explicit folder_ids)

`ProcessIngestionSourceJob` can receive `folderIds` (typically from API or UI) and attach them immediately, before any inference:

- Only attaches if `ingestion_source_folders` exists.
- Uses `created_by` if provided or falls back to the ingestion source user id.
- Does not detach existing folders (uses `syncWithoutDetaching`).

Relevant code:
- `app/Jobs/ProcessIngestionSourceJob.php`

### 2) Automatic folder inference (AI)

After resolving ingestion content, `ProcessIngestionSourceJob` dispatches `InferContextFolderJob` (best-effort; ingestion continues even if it fails).

`InferContextFolderJob`:
- Skips runs for `origin = eval_harness`.
- Skips if the source already has a **manual folder attachment** (`created_by` set).
- Resolves the raw content via `IngestionContentResolver`.
- Sends the content and source metadata to the LLM to decide whether to create a folder.

Relevant code:
- `app/Jobs/InferContextFolderJob.php`
- `app/Services/Ingestion/IngestionContentResolver.php`

#### Folder name generation rules (LLM prompt)

The inference prompt enforces that a folder represents a reusable context, not a platform or source, and that names are stable and reusable.

Rules enforced:
- Folder must represent a reusable campaign, narrative, or topic.
- Folder name is short (2-6 words), singular, and not platform-based.
- If no reusable context, return `should_create_folder = false`.

Additional server-side validation:
- Reject if confidence < 0.70.
- Reject if name or context type missing.
- Reject if name contains platform terms (instagram, tiktok, etc.).
- Context type must be one of:
  `fundraiser`, `launch`, `case_study`, `awareness`, `research_theme`, `content_series`, `event`, `personal_campaign`.

### 3) Scoring candidates and attaching

If `InferContextFolderJob` proposes a folder, `ScoreFolderCandidatesJob` runs to decide reuse vs create.

Decision steps:
1. **Exact match**: reuse if `system_name` matches and metadata context matches.
2. **Near duplicate**: reuse if same `context_type` and `primary_entity` and very high name similarity (>= 0.92).
3. **Candidate scoring** (LLM): rank top candidates and reuse if best score >= 0.85.
4. **Create new**: if no good reuse candidate is found.

All attachments are stored in `ingestion_source_folders` with `created_by = null` for AI decisions.

Relevant code:
- `app/Jobs/ScoreFolderCandidatesJob.php`
- `app/Models/IngestionSourceFolder.php`
- `app/Models/Folder.php`

### 4) Embedding staleness on attachment changes

Whenever an ingestion source is attached to a folder (manual or AI), the folder embedding is marked stale and a rebuild job is scheduled with a debounce.

Relevant code:
- `app/Services/Ai/FolderEmbeddingScheduler.php`
- `app/Jobs/ProcessIngestionSourceJob.php`
- `app/Jobs/ScoreFolderCandidatesJob.php`
- `app/Http/Controllers/Api/V1/IngestionSourceController.php`

## Folder Embedding Lifecycle

### Representation text

`FolderEmbeddingBuilder` composes the text to embed:
1) Header fields (stable metadata):
   - `Folder: {system_name}`
   - `Type: {context_type}` (from folder metadata)
   - `Primary entity: {primary_entity}` (from folder metadata, optional)
   - `Summary: {description}` (from folder metadata, optional)
2) Evidence summary from attached ingestion sources:
   - Up to 20 latest sources
   - Up to 20 highest-signal sources (quality_score/confidence_score)
   - Uses title/platform + compact snippets from source metadata or raw text

Relevant code:
- `app/Services/Ai/FolderEmbeddingBuilder.php`

### Rebuild triggers

Rebuilds are scheduled (debounced) when:
- A folder is created
- Folder metadata changes that affect representation (`system_name`, `metadata.context_type`, `metadata.primary_entity`, `metadata.description`)
- Folder attachments change (ingestion source attached to folder)

Rebuild job:
- `RebuildFolderEmbeddingJob` embeds representation text with `EmbeddingsService`
- Writes pgvector `embedding` + `representation_text` + clears `stale_at`

Relevant code:
- `app/Observers/FolderObserver.php`
- `app/Jobs/RebuildFolderEmbeddingJob.php`

### Backfill command

`php artisan backfill:folders:embed --org={id?} --only-missing --rebuild-stale`

Relevant code:
- `app/Console/Commands/BackfillFolderEmbeddings.php`

## How Folder Scoping Affects Knowledge Items and Chunks

Knowledge items and knowledge chunks are linked to ingestion sources:

- `KnowledgeItem.ingestion_source_id` points to the source.
- `KnowledgeChunk.knowledge_item_id` points to the item.

Folder scoping is **not** stored directly on knowledge items or chunks. Instead, retrieval uses the ingestion source link to determine whether a chunk belongs to a folder.

Relevant code:
- `app/Models/KnowledgeItem.php`
- `app/Services/Ai/Retriever.php`

## Retrieval Scoping in Retriever

`Retriever::knowledgeChunks()` supports folder filters:

- `folder_id` (single)
- `folder_ids` (array)

When folder filters are provided:
- **SQL fallback path** (empty query): applies `WHERE EXISTS` via `ingestion_source_folders` join.
- **Vector path**: injects an `EXISTS` clause referencing `ingestion_source_folders` and the knowledge item's `ingestion_source_id`.

This guarantees that only chunks whose **ingestion source is attached to one of the requested folders** can be returned.

Relevant code:
- `app/Services/Ai/Retriever.php`

## ContentGeneratorService Integration

### 1) Folder IDs and folder-scope policy are canonicalized in the request

`GenerationRequest` normalizes `options['folder_ids']` and only keeps valid UUIDs.
The result is exposed as `GenerationRequest->folderIds`.

Relevant code:
- `app/Services/Ai/Generation/DTO/GenerationRequest.php`

It also normalizes `options['folder_scope']` / `options['folderScope']` into `folderScopePolicy`
with defaults from `config/ai.php`:
- `mode`: `off | auto | strict | augment`
- `maxFolders`
- `minScore`
- `allowUnscopedFallback`
- `candidateK`

### 2) Auto-scope resolution (optional)

Before retrieval, `ContentGeneratorService` can resolve folder IDs automatically:

- If explicit `folder_ids` are provided, auto-scope is skipped unless `mode=augment`.
- Otherwise, `FolderScopeResolver` embeds the prompt and queries folder embeddings by cosine similarity.
- The resolver returns selected folder IDs and diagnostics.
- In `strict` mode (or when `allowUnscopedFallback` is false), low confidence can block retrieval.

Relevant code:
- `app/Services/Ai/FolderScopeResolver.php`
- `app/Services/Ai/FolderEmbeddingRepository.php`

### 3) The generation pipeline applies folder scoping to retrieval

`ContentGeneratorService::generate()`:

- Stores `folder_ids` in the run options (explicit or auto-scoped).
- Writes diagnostics into options:
  - `folder_scope_used`
  - `folder_scope_mode`
  - `folder_scope_method`
  - `folder_scope_selected_ids`
  - `folder_scope_candidates` (top 5 with scores)
  - `folder_scope_minScore`
- If retrieval is enabled, merges `folder_ids` into the retrieval filters.
- Calls `Retriever::knowledgeChunks(...)` with those filters.

This ensures that **only folder-scoped knowledge chunks** enter the context for prompt composition.

Relevant code:
- `app/Services/Ai/ContentGeneratorService.php`

### 4) Knowledge chunks are scoped, other sources are not

Folder scoping applies to the knowledge chunk retrieval step only:

- Knowledge chunks: scoped via `folder_ids`.
- Business facts: not folder-scoped (separate retrieval path).
- Swipes and templates: not folder-scoped.
- VIP overrides: bypass retrieval and can inject chunks regardless of folder.

## End-to-End Summary (Folder-Scoped Retrieval)

1. **Ingestion source created** (bookmark/text/etc).
2. **Manual folder IDs** attached if provided.
3. **InferContextFolderJob** proposes a reusable folder name.
4. **ScoreFolderCandidatesJob** reuses or creates a folder and attaches it to the ingestion source.
5. **Knowledge items** created from ingestion source content.
6. **Knowledge chunks** created and embedded from those items.
7. **Folder embeddings** are rebuilt as needed (debounced).
8. **ContentGeneratorService** resolves `folder_ids` (explicit or auto-scoped) and passes them to `Retriever::knowledgeChunks()`.
9. **Retriever** limits results to chunks whose ingestion source is attached to any of those folder IDs.

## Implementation Notes and Gotchas

- Folder scoping is a retrieval boundary only. It does not affect ingestion, chunking, or knowledge chunk embeddings.
- Manual folder attachments always take precedence over AI-inferred folder attachments.
- If the folder tables do not exist, folder inference/scoring and retrieval scoping are skipped.
- The folder name used for matching is `Folder.system_name` (AI-assigned, not user display name).
- Auto-scope can be disabled via `ai.folder_scope.mode=off`.

## Related Documents

- `docs/features/INGESTION_AND_RETRIEVAL_SYSTEM.md`
- `docs/features/content-generator-service.md`
