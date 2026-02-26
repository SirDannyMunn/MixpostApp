# Creative Intelligence v2 (Content Generator Service)

This document describes the current Creative Intelligence (CI) system used by the Content Generator Service, including the CI v2 vector retrieval integration, the existing CI v1 scaffolding, and the SocialWatcher embedding lifecycle that backs CI.

## Goals

- Replace heuristic-only CI retrieval with vector similarity search.
- Keep the CI DTO contract stable for downstream prompt composition.
- Preserve fallback behavior so CI never blocks generation.
- Centralize embedding lifecycle in SocialWatcher and treat embeddings as infrastructure.

## End-to-End Flow

```
Prompt
  -> PromptSignalExtractor
  -> CiQueryBuilder
  -> EmbeddingsService (query embedding)
  -> CiVectorRepository (sw_embeddings)
  -> CiHybridRanker
  -> CiRecommendation (DTO)
  -> PromptComposer (CREATIVE_INTELLIGENCE block)
```

If vector retrieval fails or returns too few hits, the system falls back to heuristic CI v1 selection.

## Content Generator Service Changes (Staged)

### 1) Prompt Signal Extraction

`PromptSignalExtractor` (`app/Services/Ai/Generation/Steps/PromptSignalExtractor.php`) extracts explicit signals from the prompt:

- Hook (e.g., `Hook: ...` or quoted first line).
- Emotion (keywords or `Emotion: ...` patterns).
- Audience (e.g., `Audience: ...` or "for [persona].").
- Sophistication level (beginner/intermediate/advanced).
- Format hints (thread, carousel, linkedin post, etc).

Signals are passed to CI recommendation and logged for debugging.

### 2) CI Policy Input

`GenerationRequest` (`app/Services/Ai/Generation/DTO/GenerationRequest.php`) now accepts `ciPolicy`/`ci_policy`:

- `mode`: `auto` | `none` | `strict`
- `hook`: `fill` | `respect` | `force`
- `emotion`: `fill` | `respect` | `force`
- `audience`: `fill` | `respect` | `force`
- `max_hooks`, `max_angles`
- `allow_verbatim_hooks`

### 3) CI Recommendation Flow

`ContentGeneratorService` (`app/Services/Ai/ContentGeneratorService.php`) now:

- Extracts prompt signals.
- Calls `CreativeIntelligenceRecommender`.
- Attaches CI payload to context.
- Persists CI usage and policy metadata into snapshot options.
- Supports strict CI mode (fails early if hook/angle/emotion are missing).

### 4) Prompt Composition

`PromptComposer` adds a `CREATIVE_INTELLIGENCE` block into the system prompt when CI is available. It includes:

- CI policy and signal flags
- Resolved audience/emotion/sophistication
- Recommended hook patterns or verbatim hooks (controlled by `allow_verbatim_hooks`)
- Suggested angles and formats

### 5) Snapshot Storage

New field: `creative_intelligence` is persisted on `generation_snapshots`.

- Migration: `database/migrations/2026_01_09_090000_add_creative_intelligence_to_generation_snapshots.php`
- Model update: `app/Models/GenerationSnapshot.php`
- Stored through `SnapshotService` and `ContextAssembler`.

### 6) Debug + Observability

- `ContentGenBatchLogger` now overwrites the log file each flush (latest run only).
- `ShowPrompt` command appends CI block if the final system prompt is not stored.
- New command: `content-service:get-report` dumps a snapshot report to `storage/logs`.

## CI v2 Vector Retrieval (New)

### CiQueryBuilder

`app/Services/Ai/Generation/Ci/CiQueryBuilder.php`

Builds canonical query text and SQL filters:

```
TOPIC: {topic_keywords}
INTENT: {intent}
AUDIENCE: {audience_persona}
EMOTION: {primary_emotion}
PLATFORM: {platform}
```

Empty lines are omitted and order is stable.

Filters include:

- `is_business_relevant = true`
- `noise_risk <= threshold`
- `buyer_quality_score >= threshold`
- platform match (if provided)
- audience match (if explicit)
- format match (if explicit)

### CiVectorRepository

`app/Services/Ai/Generation/Ci/CiVectorRepository.php`

Searches `sw_embeddings` for `object_type = ci_unit_core` and model compatibility:

- `embeddable_type = CreativeUnit`
- `object_type = ci_unit_core`
- `model = social-watcher.embedding.model`

The implementation computes cosine similarity in PHP by decoding vectors and scanning in chunks. This is not a DB-level vector index; use `ai.ci.vector.max_candidates` to cap work.

### CiHybridRanker

`app/Services/Ai/Generation/Ci/CiHybridRanker.php`

Final score:

```
0.45 * similarity
0.20 * novelty
0.15 * recency
0.10 * buyer_quality
0.10 * engagement
```

Also enforces:

- Cluster de-duplication (one unit per cluster when cluster items are loaded).
- Hook archetype diversity caps (max 2 per archetype).

### CreativeIntelligenceRecommender

`app/Services/Ai/Generation/Steps/CreativeIntelligenceRecommender.php`

Flow:

1. Build CI query text.
2. Embed with `EmbeddingsService`.
3. Vector search via `CiVectorRepository`.
4. Rank via `CiHybridRanker`.
5. If vector fails or returns too few hits, fall back to heuristic retrieval.

Shadow mode executes vector search but does not impact output.

## Config Flags

`config/ai.php`:

```
ai.ci.vector.enabled
ai.ci.vector.shadow_mode
ai.ci.vector.k
ai.ci.vector.min_results
ai.ci.vector.max_candidates
ai.ci.vector.chunk_size
```

These values default to safe, low-risk values and can be overridden via env:

```
AI_CI_VECTOR_ENABLED
AI_CI_VECTOR_SHADOW_MODE
AI_CI_VECTOR_K
AI_CI_VECTOR_MIN_RESULTS
AI_CI_VECTOR_MAX_CANDIDATES
AI_CI_VECTOR_CHUNK_SIZE
```

## SocialWatcher Embedding Lifecycle (Dependency)

CI v2 relies on SocialWatcher embedding guarantees. Key changes:

- New composite embedding type `ci_unit_core`.
- Canonical text:
  - `HOOK: {hook_text}`
  - `ANGLE: {angle}`
  - `CONTENT: {normalized_content_excerpt (<= 500 chars)}`
  - `AUDIENCE: {audience_persona}`
  - `EMOTIONS: {emotion_labels}`
- Embedding job is idempotent by `(embeddable_type, embeddable_id, object_type, model)`.
- CreativeUnit observer triggers embeddings on create/update.
- Backfill command:
  - `php artisan social-watcher:backfill-creative-unit-embeddings --type=ci_unit_core --batch=100`

Implementation locations:

- `packages/social-watcher/src/Jobs/EmbedCreativeUnit.php`
- `packages/social-watcher/src/Observers/CreativeUnitObserver.php`
- `packages/social-watcher/src/Console/Commands/BackfillCreativeUnitEmbeddingsCommand.php`
- `packages/social-watcher/src/SocialWatcherServiceProvider.php`

## Tests (Staged)

- `tests/Unit/PromptSignalExtractorTest.php`
- `tests/Unit/PromptComposerCiBlockTest.php`

## Operational Notes

- CI strict mode is enforced at generation time and during prompt-only builds.
- Snapshot reports now include CI payloads and policy decisions.
- Vector retrieval is safe to enable in shadow mode before driving output.

## Future Enhancements

- DB-native vector index for `sw_embeddings` to replace in-memory scan.
- Additional diversity constraints (persona/format caps).
- CI quality tuning based on shadow mode metrics.
