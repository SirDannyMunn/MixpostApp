## Engineering Spec: Ingestion Evaluation & Optimization Harness (Artisan Command + Report)

### Purpose

Build a repeatable, diffable evaluation harness that:

1. ingests a document through the existing ingestion pipeline,
2. analyzes ingestion outputs (claims/chunks/classification/embeddings readiness) with an LLM,
3. scores ingestion quality across multiple dimensions,
4. optionally runs the content generation system to test whether chunks are retrieved/used,
5. produces a persisted report (JSON + optional Markdown) for inspection, regression tracking, and tuning.

---

## Goals

* **One-command evaluation** of ingestion quality for a specific input document.
* **Full traceability**: include all produced claims/chunks and key pipeline metadata.
* **Actionable scoring**: multi-axis scores with concrete improvement suggestions.
* **Agent-aware**: evaluate usefulness relative to current agent/retriever/generator configuration.
* **Optional closed-loop probe**: run ContentGeneratorService and measure utilization and grounding.
* **Deterministic enough to diff**: snapshot config + prompts + model identifiers.

## Non-goals

* Replacing unit tests for pipeline correctness (this is quality/utility evaluation).
* Perfect “truth” scoring (LLM analysis is advisory; we aim for consistency + regression sensitivity).
* Large-scale batch evaluation in v1 (support single input per run; batching can follow).

---

## High-level Flow

### Phase A — Controlled Ingestion

Input document → create an `ingestion_sources` record → run `ProcessIngestionSourceJob` chain synchronously (or dispatch and poll) → collect:

* knowledge_item
* normalized_claims (if any)
* chunks (raw + normalized variants)
* classification metadata
* embedding meta readiness / coverage (vector presence, model)

### Phase B — LLM Evaluation (Structured Critique)

Build an evaluation payload containing:

* original document (or excerpt if too long; plus hash)
* normalized claims + summary
* chunks list with `chunk_role`, `authority`, `confidence`, `time_horizon`, `source_variant`
* current config snapshot (normalization thresholds, chunk limits, retriever settings, generator settings)

Ask the LLM to return strict JSON with:

* dimension scores
* rationales
* detected issues
* recommended changes (prompt tweaks, thresholds, taxonomy mapping, filtering, chunking adjustments)

### Phase C — Optional Generation Probe (Closed-loop)

Run ContentGeneratorService using:

* a fixed probe prompt (or provided prompt)
* retrieval enabled and constrained to this newly ingested material (see “Isolation modes”)
  Capture:
* retrieval candidates (accepted + rejected, with reasons)
* chunks actually included in context
* final output
* grounding/utilization analysis (LLM + heuristics)

### Phase D — Persist + Report Output

Persist a canonical evaluation record:

* JSON report (complete)
* optional markdown summary for quick review
* CLI prints report paths + top scores + key failures

---

## CLI Interface

### Command

`php artisan ai:ingestion:eval`

### Options (v1)

* `--org=UUID` (required)
* `--user=UUID` (required)
* `--input=PATH` OR `--text="..."` (required)
* `--title="..."` (optional)
* `--source-type=text|file|transcript` (default: `text`)
* `--force` (default false) bypass dedup short-circuit if needed
* `--run-generation` (default false)
* `--prompt="..."` (optional; default probe prompt)
* `--platform=generic|linkedin|x|...` (default generic)
* `--retrieval-limit=3` (default 3)
* `--isolation=none|strict` (default `strict` if run-generation; otherwise none)
* `--store` (default true) persist DB record
* `--format=json|md|both` (default both)
* `--out=/path` (optional; default storage/app/ai/ingestion-evals/{id}/)
* `--model-eval=...` override eval model (optional)
* `--model-gen=...` override gen model (optional)
* `--seed=INT` (optional; used where supported)
* `--max-doc-chars=INT` (default e.g. 12000 for eval prompt packing)

### Exit codes

* `0` success
* `2` ingestion failed
* `3` evaluation failed
* `4` generation probe failed
* `5` persistence failed (report still written to disk)

---

## Data Model

### Table: `ingestion_evaluations`

Stores run-level metadata and report pointers.

Fields:

* `id` (uuid)
* `organization_id`, `user_id`
* `ingestion_source_id` (uuid, nullable)
* `knowledge_item_id` (uuid, nullable)
* `input_type` (`text|file|transcript`)
* `input_title` (string, nullable)
* `input_sha256` (char64)
* `input_chars` (int)
* `pipeline_status` (`completed|failed`)
* `eval_status` (`completed|failed`)
* `generation_probe_status` (`skipped|completed|failed`)
* `config_snapshot` (json) — critical: normalization, chunking, classifier, embedding, retriever, generator policies
* `eval_model` (string), `gen_model` (string, nullable)
* `scores` (json) — dimension scores + overall
* `report_json_path` (string)
* `report_md_path` (string, nullable)
* `created_at`, `updated_at`

### Table (optional v1.1): `ingestion_evaluation_artifacts`

If you want normalized storage for chunk lists etc., otherwise embed in report JSON.

* `evaluation_id`
* `artifact_type` (`chunks|claims|generation|retrieval_trace|prompts|raw`)
* `payload` (jsonb)
* `created_at`

Recommendation: **v1 store everything in report JSON on disk**, store summary + paths in DB.

---

## Report Schema (Canonical JSON)

Top-level:

```json
{
  "meta": {
    "evaluation_id": "...",
    "org_id": "...",
    "user_id": "...",
    "created_at": "...",
    "models": { "eval": "...", "gen": "..." },
    "input": { "sha256": "...", "chars": 1234, "title": "...", "source_type": "text" }
  },
  "pipeline": {
    "ingestion_source_id": "...",
    "knowledge_item_id": "...",
    "normalized": { "eligible": true, "claims_count": 12, "summary_present": true },
    "chunks": { "count_total": 12, "by_variant": { "normalized": 12, "raw": 0 }, "by_role": { ... } },
    "embeddings": { "expected": 12, "present": 12, "model": "text-embedding-3-small" }
  },
  "scores": {
    "overall": 7.8,
    "coverage": 8.5,
    "atomicity": 6.0,
    "noise": 7.0,
    "role_accuracy": 7.5,
    "retrieval_readiness": 6.8,
    "agent_utility": 7.2
  },
  "issues": [
    { "type": "merged_claims", "severity": "medium", "evidence": { "chunk_id": "..."} }
  ],
  "recommendations": [
    { "area": "chunking", "action": "reduce claim length cap", "expected_impact": "higher atomicity" }
  ],
  "artifacts": {
    "document_excerpt": "...",
    "normalized_claims": [...],
    "chunks": [...],
    "evaluation_llm": { "raw": "...", "parsed": { ... } }
  },
  "generation_probe": {
    "enabled": true,
    "prompt": "...",
    "retrieval_trace": { "candidates": [...], "selected": [...] },
    "context_budget": {...},
    "output": "...",
    "utilization": { "chunks_used_count": 2, "unsupported_claims": [...], "contradictions": [...] }
  }
}
```

---

## Implementation Design

### Components

#### 1) `IngestionEvaluationService`

Primary orchestrator used by artisan command.

Methods:

* `run($orgId, $userId, InputSpec $input, EvalOptions $opts): EvaluationResult`

  * calls ingestion
  * builds eval payload
  * calls LLM evaluator
  * optional generation probe
  * writes reports
  * persists DB row

#### 2) `IngestionRunner`

Runs ingestion deterministically.

Responsibilities:

* Create `ingestion_sources` with `raw_text` (or file text) + metadata
* Run `ProcessIngestionSourceJob` chain (sync execution recommended for CLI)
* Return `knowledge_item_id`, plus any pipeline metrics (durations, gating decisions)

Implementation notes:

* If your jobs are queued-only, provide a “sync pipeline mode” for CLI:

  * call underlying service methods directly or dispatch synchronously using `dispatchSync()`.
* Capture gating outputs explicitly:

  * normalization eligible? why not?
  * chunk variant chosen? counts?
  * classification batches run? how many?
  * embeddings completed? how many missing?

#### 3) `IngestionEvaluatorLLM`

Structured critic.

* Input: `EvaluationContext` (document excerpt + claims + chunks + config snapshot)
* Output: strict JSON following `EvalSchemaV1`

Add a schema validator (`SchemaValidator`) and a repair loop (like your generation path) to enforce JSON.

#### 4) `GenerationProbeRunner`

Runs ContentGeneratorService in a controlled way and captures observability.

Key requirement: **retrieval trace**.

* Add an instrumentation hook to `Retriever::knowledgeChunks()` to return:

  * candidates (top N)
  * acceptance decision + reason
  * thresholds used
* Ensure `ContextFactory` exposes:

  * selected chunk IDs
  * token budget and pruning decisions

Isolation modes:

* `strict`: restrict retrieval to the newly created `knowledge_item_id` (or `ingestion_source_id`) via query filter.
* `none`: normal retrieval (less useful for evaluation because other knowledge can dominate).

#### 5) `UtilizationAnalyzer`

Measures whether generation used chunks.

Two approaches (use both):

1. Heuristics:

   * direct string overlaps / entity mentions from chunks
   * contradiction checks for high-confidence claims (simple: “captured” vs “not captured”)
2. LLM judge:

   * given chunks + output, identify:

     * which chunks were used (IDs)
     * unsupported claims (statements not in chunks)
     * contradictions to chunks
     * missed opportunities (high-value chunks unused)

Return a structured JSON verdict.

---

## Evaluation Dimensions (Definition)

* **Coverage (0–10)**: how well key claims from the document are represented in chunks/claims.
* **Atomicity (0–10)**: each chunk expresses one claim; minimal conflation.
* **Noise (0–10)**: low-value, speculative, redundant chunks are minimized.
* **Role accuracy (0–10)**: classifier roles match actual semantics.
* **Retrieval readiness (0–10)**: chunks are phrased and scoped to be retrievable for likely prompts.
* **Agent utility (0–10)**: given your agent configuration (content generator + policies), chunks are directly useful.
* **Overall**: weighted mean (default weights: coverage 0.25, atomicity 0.15, noise 0.10, role 0.15, retrieval 0.20, utility 0.15). Store weights in config snapshot.

---

## Prompting (Evaluator + Probe)

### Evaluator system prompt (outline)

* Strict JSON schema
* Evaluate *relative to config snapshot*
* Provide scores + reasons + actionable changes
* Identify concrete examples referencing chunk IDs

### Probe default prompt (recommended)

Use something that forces grounding:

* “Write a factual breakdown… separate confirmed vs unverified… use retrieved knowledge… avoid unsupported claims.”

---

## Observability Requirements (Must-build for this to work)

### Retrieval trace

Persist for probe runs:

* similarity score per candidate
* filters applied (role/confidence/authority/time horizon)
* acceptance reason / rejection reason
* final selected list

### Context budget

Persist token accounting:

* business_context tokens
* template tokens
* swipes tokens
* chunks tokens
* pruned sections and reasons

### Pipeline gating signals

Persist:

* normalization eligibility: {min_chars_pass, min_quality_pass, eligible_source_pass}
* whether normalized claims existed
* chunk variant used for classification/retrieval
* embedding coverage ratio

Without these, your report will still be guesswork.

---

## File Outputs

Write to:

* `storage/app/ai/ingestion-evals/{evaluation_id}/report.json`
* `storage/app/ai/ingestion-evals/{evaluation_id}/report.md` (optional)
* `.../artifacts/*.json` optional subfiles:

  * `chunks.json`, `claims.json`, `retrieval_trace.json`, `generation.json`, `prompts.json`

Markdown summary should include:

* score table
* top 5 issues
* top 5 recommendations
* chunk list grouped by role with confidence/authority

---

## Security & Safety

* Treat inputs as untrusted:

  * avoid executing embedded code
  * redact secrets if document contains them (basic regex pass for API keys)
* Report files should be org-scoped and not publicly accessible.
* If using external LLM providers, ensure PII policy compliance (you decide; system should support an opt-out flag `--no-llm` for local-only metrics).

---

## Testing Strategy

### Unit tests

* report schema validation
* score aggregation logic
* retrieval isolation filters
* context budgeting output consistency

### Integration tests (important)

* run eval on a known fixture document
* assert:

  * expected number of chunks
  * normalized vs raw path correctness
  * embeddings completion (or properly recorded missing)
  * evaluation JSON parse passes

### Regression suite

Create a fixtures directory:

* `fixtures/docs/*.txt`
* expected min score thresholds per dimension (loose)
* store historical reports for diffs

---

## Rollout Plan

1. Implement ingestion runner + raw report (no LLM) + artifact dump.
2. Add LLM evaluator (scores + issues + recs).
3. Add generation probe with strict isolation + retrieval trace.
4. Add utilization analyzer.
5. Add “compare runs” command (v2): diff two evaluation IDs.

---

## Deliverables Checklist (v1)

* [ ] Artisan command `ai:ingestion:eval`
* [ ] `IngestionEvaluationService`
* [ ] `IngestionRunner` (sync capable)
* [ ] report JSON writer + schema validator
* [ ] DB table `ingestion_evaluations`
* [ ] LLM evaluator returning structured JSON
* [ ] generation probe (optional flag) with retrieval isolation
* [ ] retrieval + context budget instrumentation hooks
* [ ] markdown summary renderer

---

## Suggested Next Implementation Step

Start by implementing **Phase A + report artifacts** (no LLM, no probe), then add:

1. evaluator LLM,
2. retrieval trace + probe,
3. utilization judge.
