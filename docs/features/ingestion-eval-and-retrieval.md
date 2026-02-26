# Ingestion, Retrieval, and Evaluation — System Overview

This document describes the ingestion pipeline, the retrieval and generation stack, and the evaluation harness focused on ingestion quality and retrieval accuracy. It is intended as an end‑to‑end reference for engineers debugging, extending, or operating the system.

## High‑Level Flow

- Ingestion → KnowledgeItem + KnowledgeChunks
- Embeddings → dense vectors for chunks
- Retrieval → `Retriever::knowledgeChunks(...)` (semantic + heuristics)
- Generation → `ContentGeneratorService::generate(...)` consumes retrieval and overrides
- Evaluation Harness → `ai:ingestion:eval` composes reports and probes retrieval/generation

---

## Data Model (Core)

- `knowledge_items`
  - Source‑scoped document units created via ingestion.
  - Fields: `id`, `organization_id`, `user_id`, `ingestion_source_id`, `raw_text`, confidence, etc.
- `knowledge_chunks`
  - Retrieval units produced from items by chunking; each may be `excerpt` or `reference`.
  - Fields: `id`, `knowledge_item_id`, `chunk_text`, `chunk_type`, `chunk_role`, `embedding_vec`, `confidence`, `authority`, `time_horizon`, `tags`, `source_variant` (`raw`|`normalized`).
- `ingestion_sources`
  - Provenance for items, may carry `quality_score`, `origin` (e.g., `eval_harness`).

---

## Ingestion Pipeline

- Entry points
  - API (controllers) and CLI paths create `KnowledgeItem` and schedule pipeline jobs.
  - For eval harness runs, see `app/Console/Commands/AiIngestionEval.php`.

- Jobs (typical sequence)
  - Chunking → populates `knowledge_chunks` from the item’s `raw_text`.
  - Optional classification → assigns `chunk_role` and other metadata.
  - Embedding → `app/Jobs/EmbedKnowledgeChunksJob.php`
    - Queries unembedded chunks for an item.
    - Calls `App\Services\Ai\EmbeddingsService::embedOne(...)` in batches.
    - Saves vectors to `embedding_vec`.

- Integrity expectations
  - After ingestion: `chunks_total > 0` and `embedded == chunks_total` before retrieval evaluation.
  - Dedup can skip ingestion; the eval harness surfaces this early.

---

## Retrieval Service

- Entry point: `app/Services/Ai/Retriever.php::knowledgeChunks(string $orgId, string $userId, string $query, string $intent, int $limit = 5, array $filters = [])`

- Filters
  - `knowledge_item_id` or `knowledge_item_ids` (array). The service normalizes either into a list.

- Retrieval pipeline (semantic-first)
  - Embedding: `EmbeddingsService::embedOne($query)`
  - Vector search: pgvector cosine distance on `knowledge_chunks.embedding_vec` within org/user and optional KI scope.
  - Joins `knowledge_items` and `ingestion_sources` for confidence/quality signals.
  - Near‑match detection: `nearMatchDistance` (config) protects very close hits from being ranked out.
  - Composite scoring with weights (config): distance, authority, confidence, time horizon.
  - Top‑K candidate pool: recall‑first window (`top_n` and `limit * 3`).
  - Normalized‑variant preference within the same item (stable tie‑break).
  - Excerpt cap with protected bypass: keeps `excerpt` density bounded, while near matches may bypass caps.
  - Sparse Document Minimum Recall (optional): injects candidates for items that appear in distance‑TopK but would drop after heuristics, bounded by config.
  - Small‑Dense Assist (optional): favors small but dense items when close in distance, with numeric‑query hinting for `metric` role.
  - Stable unique by chunk id, truncate to `limit`.

- Return shape
  - Default: flat array of chunks `[{'id','chunk_text','chunk_type','tags','score','recall_injected'}]`.
  - Trace: The service logs metrics to `Log` for observability. Callers that require a detailed trace should wrap `Retriever` or adapt outputs. The eval harness supports both a flat array and a future structured shape `{trace:{topK,final}, final:[...]}`.

- Configuration (selected)
  - `ai.retriever.top_n` (default 20)
  - `ai.retriever.weights.{distance,authority,confidence,time_horizon}`
  - `ai.retriever.near_match_distance` (default ~0.10)
  - `ai.retriever.sparse_recall.*` and `ai.retriever.small_dense_assist.*`
  - `vector.retrieval.max_per_intent`, `vector.retrieval.max_excerpt_chunks`

---

## Content Generator Service

- Entry point: `app/Services/Ai/ContentGeneratorService.php::generate(...)`

- Responsibilities
  - Parse options into `GenerationRequest` (retrieval policy, overrides, constraints).
  - Classify prompt intent/funnel (overridable via options).
  - Retrieve knowledge (`Retriever::knowledgeChunks`) honoring limit and `retrieval_filters`.
  - Retrieve business facts and swipe structures when applicable.
  - Resolve template and optional voice profile.
  - Assemble context and compose prompts.
  - LLM calls via `LLMClient` with meta capture; optional reflexion step; validate/repair and snapshot.

- Key interactions with retrieval
  - `options.retrieval_limit` and `options.retrieval_filters` (e.g., `['knowledge_item_id' => <ki>]`).
  - VIP overrides for knowledge are injected via `OverrideResolver` and never pruned.
  - Generation probe in the eval harness leverages both VIP‑forced and Retrieval‑On modes for A/B comparison.

---

## Evaluation Harness

- CLI: `php artisan ai:ingestion:eval` (`app/Console/Commands/AiIngestionEval.php`)

- Phases and invariants
  - Phase 0 (preflight, enforced):
    - If normalization ran but `normalized_claims_count == 0` → fail.
    - If `chunks_total < normalized_claims_count` → fail.
    - If `embedded != chunks_total` → fail.
  - Phase A (report assembly):
    - Builds an input/summary snapshot with config and normalization eligibility.
  - Phase B (LLM checks, optional with `--no-llm`):
    - Faithfulness audit of normalized claims vs source text (`runFaithfulnessAudit`).
    - Synthetic QA for retrieval diagnostics (`runSyntheticQATest`).
    - Generation probe comparing Retrieval‑On vs VIP‑forced (`runGenerationProbe`).

- Report outputs
  - Storage paths under `storage/app/ai/ingestion-evals/<evaluation_id>/`:
    - `report.json` and `report.md`
    - `artifacts/chunks.json`, `artifacts/claims.json`

- Key services
  - `IngestionEvaluationService` orchestrates the evaluation logic and materializes reports.
  - `Probes/GenerationProbe` executes Retrieval‑On and VIP‑forced generation and grades results.

### Synthetic QA (Retrieval Diagnostics)

- Flow
  - Generate 2–3 factual questions from recent chunks (LLM).
  - For each question, call `Retriever::knowledgeChunks` with deterministic scoping
    - When `config('ai.eval.scope_to_knowledge_item')` is true, and the eval has a knowledge item, a `knowledge_item_id` filter is applied.
  - Derive:
    - `retrieved`: the final returned chunk ids
    - `diagnostics.trace.topK`: derived from retriever results (or equals `final` for flat responses)
    - `diagnostics.top3`: first 3 of `trace.topK`
  - Compute hits@k and per‑question rank for target chunk ids.

- Invariants and logging
  - If `top3` is non‑empty but `retrieved` is empty → hard assert with a clear message (prevents silent misreporting).
  - Logs every retriever call with org, user, query, intent, k, filters.

### Generation Probe

- VIP‑Forced
  - Select top‑N chunks within the known knowledge item via pgvector (`topNChunksForQuestion`) and pass as VIP overrides (no automatic retrieval).
- Retrieval‑On
  - Call `ContentGeneratorService::generate` with retrieval enabled and filter to the knowledge item when configured.
- Grading
  - Lightweight grader using a normalized “contains terms” mode by default (`config('ai.eval.grader_mode', 'contains')`).
  - Verdict categories: pass, pass_with_warnings, retrieval_regression, ingestion_failure.

---

## How To Run

- Minimal eval over a text fixture with generation probe
  - `php artisan ai:ingestion:eval --org=<ORG_ID> --user=<USER_ID> --input=docs/fixtures/ingestion/factual_short.txt --title="Fixture: factual_short" --format=both --log-files --cleanup --run-generation`

- Useful flags
  - `--no-llm` to skip Phase B and probes.
  - `--retrieval-limit=3` to cap retrieval.
  - `--isolation=strict` to force knowledge‑item scoping.
  - `--prompt="..."` to probe a custom question through the generation flow.

---

## Interpreting Results

- `report.json`
  - `summary` and `metrics` expose chunk counts and embedding coverage.
  - `evaluation.faithfulness` shows pass/fail, score, and any violations.
  - `evaluation.synthetic_qa`
    - `metrics.hits_at_k` and per‑item details with `retrieved`, `rank`, `diagnostics.trace.{topK,final}`, and `diagnostics.top3`.
  - `evaluation.generation`
    - Retrieval‑On and VIP‑forced results with per‑question grades and pass rates.

- `report.md`
  - Topline summary suitable for quick scans or CI logs.

---

## Troubleshooting

- Retrieval appears empty
  - Check logs for `eval.synthetic_qa.retrieval_call` to confirm scoping, query, and limit.
  - Ensure `embedded == chunks_total`; incomplete embeddings will degrade or nullify retrieval.
  - Verify `knowledge_item_id` vs `knowledge_item_ids` use in filters.

- Synthetic QA mismatch (top3 vs retrieved)
  - The harness now asserts on this state. Investigate retriever filtering and selection heuristics if it triggers.

- Generation probe fails while VIP‑forced passes
  - Indicates retriever precision/selection issues (caps, excerpt density, or variant preference). Inspect retriever configs and distance distributions.

- Normalization produced zero claims
  - The preflight guard fails evaluation; revisit normalization eligibility and input size/quality thresholds.

---

## Configuration Reference (Selected)

- Evaluation
  - `ai.eval.scope_to_knowledge_item` (bool)
  - `ai.eval.grader_mode` (default `contains`)

- Retriever
  - `ai.retriever.top_n`, `ai.retriever.near_match_distance`
  - `ai.retriever.weights.*`
  - `ai.retriever.sparse_recall.*`, `ai.retriever.small_dense_assist.*`
  - `vector.retrieval.max_per_intent`, `vector.retrieval.max_excerpt_chunks`

---

## Key Classes & Files

- Ingestion
  - `app/Jobs/EmbedKnowledgeChunksJob.php`
  - `app/Models/KnowledgeItem.php`, `app/Models/KnowledgeChunk.php`

- Retrieval
  - `app/Services/Ai/Retriever.php`
  - `app/Services/Ai/EmbeddingsService.php`

- Generation
  - `app/Services/Ai/ContentGeneratorService.php`
  - `app/Services/Ai/LLMClient.php`

- Evaluation
  - `app/Console/Commands/AiIngestionEval.php`
  - `app/Services/Ai/Evaluation/IngestionEvaluationService.php`
  - `app/Services/Ai/Evaluation/Probes/GenerationProbe.php`

---

## Future Enhancements

- Structured trace output from `Retriever` (topK and final) to unify diagnostics across services.
- Role‑aware weighting revisitation if `chunk_role` taxonomy evolves.
- Optional per‑run trace artifacts persisted alongside `report.json` for deeper offline analysis.

