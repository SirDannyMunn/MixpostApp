# Embeddings and PGVector

This doc inventories every embedding-related table, model, and component in MixpostApp.
It also separates PGVector-backed vector search from non-PGVector embedding storage.

## Overview

- Primary PGVector store: `knowledge_chunks.embedding_vec` (semantic retrieval) and
  `folder_embeddings.embedding` (folder auto-scoping).
- Embeddings are generated via `App\Services\Ai\EmbeddingsService`, which defaults to
  OpenRouter `openai/text-embedding-3-small` with 1536 dimensions.
- Social Watcher stores embeddings as base64-encoded binary in `sw_embeddings` and
  `sw_normalized_content_embeddings`, plus JSON embeddings on `sw_ti_items`.
  These are not PGVector columns; similarity is computed in PHP.

## PGVector-backed tables

### knowledge_chunks

- Table: `knowledge_chunks`
- Columns:
  - `embedding_vec` `vector(1536)` (pgvector)
  - `embedding_model` (string)
  - legacy `embedding` (jsonb)
- Migrations:
  - `database/migrations/2025_01_01_000110_create_knowledge_chunks_table.php` (adds `embedding` + `embedding_model`)
  - `database/migrations/2025_12_14_000500_enable_pgvector_and_embedding_vector.php` (adds `embedding_vec` + HNSW index)
- Purpose: core semantic retrieval for knowledge chunks.
- PGVector index: `knowledge_chunks_embedding_vec_hnsw` with `vector_cosine_ops`.

### folder_embeddings

- Table: `folder_embeddings`
- Columns:
  - `embedding` `vector(1536)` (pgvector)
  - `representation_text` (text) and versioning fields
- Migration:
  - `database/migrations/2026_01_09_000100_create_folder_embeddings_table.php`
- Purpose: folder auto-scoping (choose relevant folders for retrieval based on prompt similarity).
- PGVector index: `folder_embeddings_embedding_hnsw` with `vector_cosine_ops`.

## Non-PGVector embedding tables

### sw_embeddings (Social Watcher)

- Table: `sw_embeddings`
- Columns:
  - `vector` (base64-encoded binary)
  - `dimensions`, `model`, `provider`, `text`, `text_hash`
- Migration:
  - `packages/social-watcher/database/migrations/2026_01_08_200300_create_sw_embeddings_table.php`
- Purpose: shared embeddings for Creative Intelligence and Topic Intelligence content.
- Usage: similarity computed in PHP (see `CiVectorRepository`), not pgvector.

### sw_normalized_content_embeddings (Social Watcher)

- Table: `sw_normalized_content_embeddings`
- Columns:
  - `vector` (base64-encoded binary)
  - `dimensions`, `model`, `provider`, `text`, `text_hash`
- Migration:
  - `packages/social-watcher/database/migrations/2026_01_09_000003_create_sw_normalized_content_embeddings_table.php`
- Purpose: embeddings for normalized research fragments (creative intelligence pipeline).

### sw_ti_items (Social Watcher)

- Table: `sw_ti_items`
- Columns:
  - `embedding` (json)
  - `embedding_status`, `embedding_model`, `embedded_at`
- Migration:
  - `packages/social-watcher/database/migrations/2026_01_07_000200_create_sw_ti_items_table.php`
- Purpose: Topic Intelligence item embeddings used for clustering and promotion logic.

## Models that store or expose embeddings

- `App\Models\KnowledgeChunk` (`knowledge_chunks`): has legacy `embedding` + `embedding_model`,
  pgvector column exists on the table as `embedding_vec`.
- `App\Models\FolderEmbedding` (`folder_embeddings`): stores folder scope vectors.
- `LaundryOS\SocialWatcher\Models\Embedding` (`sw_embeddings`): base64 vector, accessors
  `vectorArray` and `setVectorFromArray`.
- `LaundryOS\SocialWatcher\Models\NormalizedContentEmbedding` (`sw_normalized_content_embeddings`):
  base64 vector accessors.
- `LaundryOS\SocialWatcher\Models\TopicIntelligenceItem` (`sw_ti_items`): JSON `embedding`,
  `embedding_status`, `embedding_model`.
- `LaundryOS\SocialWatcher\Models\CreativeUnit`: morphMany to `Embedding` via `embeddable`.
- `LaundryOS\SocialWatcher\Models\NormalizedContent`: hasMany `NormalizedContentEmbedding`.

## Embedding generation components

### Core embedding client

- `App\Services\Ai\EmbeddingsService`
  - Calls OpenRouter `/embeddings`.
  - Default model: `openai/text-embedding-3-small`.
  - Normalizes vector length to 1536 and provides a deterministic fallback vector.

### Knowledge chunk embeddings (PGVector)

- `App\Jobs\EmbedKnowledgeChunksJob`
  - Embeds normalized `knowledge_chunks` and writes `embedding_vec` via `CAST(? AS vector)`.
  - Updates `embedding_model` to `text-embedding-3-small`.

### Folder embeddings (PGVector)

- `App\Services\Ai\FolderEmbeddingBuilder`
  - Builds a text representation for a folder from metadata + sampled sources.
- `App\Jobs\RebuildFolderEmbeddingJob`
  - Embeds the folder representation and writes to `folder_embeddings.embedding`.
- `App\Services\Ai\FolderEmbeddingScheduler`
  - Marks stale and queues `RebuildFolderEmbeddingJob`.
- `App\Observers\FolderObserver`
  - Schedules rebuilds on folder changes.
- `App\Console\Commands\BackfillFolderEmbeddings`
  - Backfill tool to queue missing folder embeddings.

### Social Watcher embeddings (non-PGVector)

- `LaundryOS\SocialWatcher\Services\TopicIntelligence\EmbeddingService`
  - Provider abstraction; defaults to OpenRouter (delegates to `App\Services\Ai\EmbeddingsService`).
- `LaundryOS\SocialWatcher\Jobs\EmbedTopicIntelligenceItem`
  - Stores embeddings on `sw_ti_items.embedding` (JSON).
- `LaundryOS\SocialWatcher\Jobs\EmbedCreativeUnit`
  - Stores embeddings on `sw_embeddings` for creative hooks, angles, and CI core text.
- `LaundryOS\SocialWatcher\Jobs\EmbedNormalizedContentFragment`
  - Stores embeddings on `sw_normalized_content_embeddings`.

## Vector search components (PGVector)

### Knowledge retrieval

- `App\Services\Ai\Retriever`
  - Performs pgvector search on `knowledge_chunks.embedding_vec` using cosine distance.
  - Builds vector literal and runs `embedding_vec <=> CAST(? AS vector)` ordering.
  - Applies role filters from `config/ai_chunk_roles.php`.
  - Uses limits from `config/vector.php`.

### Evaluation probe

- `App\Services\Ai\Evaluation\Probes\GenerationProbe`
  - Uses pgvector search to select top-N chunks within a single knowledge item.

### Folder auto-scoping

- `App\Services\Ai\FolderEmbeddingRepository`
  - Runs pgvector search on `folder_embeddings.embedding`.
- `App\Services\Ai\FolderScopeResolver`
  - Embeds the prompt and chooses folders based on similarity scores.

## Vector search components (non-PGVector)

- `App\Services\Ai\Generation\Ci\CiVectorRepository`
  - Loads `sw_embeddings` and computes cosine similarity in PHP.
- `App\Services\Ai\Generation\Steps\CreativeIntelligenceRecommender`
  - Optionally uses `CiVectorRepository` when `ai.ci.vector.enabled` is true.
- `LaundryOS\SocialWatcher\Services\CreativeIntelligence\CreativeClusteringService`
  - Clusters creative units using vector arrays (non-PGVector).
- `LaundryOS\SocialWatcher\Services\TopicIntelligence\TopicClusteringService`
  - Clusters Topic Intelligence items using embedding arrays (non-PGVector).

## Config and infrastructure

- `docker/postgres/init/001-enable-pgvector.sql` and `docker-compose.yml`:
  enable the pgvector extension (Postgres image `pgvector/pgvector:pg16`).
- `app/Console/Commands/VerifyPgVector.php`:
  sanity check for pgvector extension + `knowledge_chunks.embedding_vec` column.
- `config/services.php`:
  OpenRouter embed model pricing config (used by `EmbeddingsService`).
- `config/ai.php`:
  folder scope policy and CI vector toggles.
- `config/ai_chunk_roles.php`:
  defines which chunk roles are eligible for vector search.
- `config/vector.php`:
  retrieval caps and similarity thresholds used by `Retriever`.
