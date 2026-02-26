# SnapshotPersister (ContentGeneratorService)

File: `app/Services/Ai/Generation/Steps/SnapshotPersister.php`
Related: `app/Services/Ai/SnapshotService.php`
Used by: `app/Services/Ai/ContentGeneratorService.php`

This document describes what the `SnapshotPersister` records during content generation, how it is used by `ContentGeneratorService`, and which data is included in persisted snapshots and quality reports.

## Purpose

`SnapshotPersister` is a thin wrapper around `SnapshotService` and `QualityReportService` that:
- Persists a replayable `GenerationSnapshot` after a generation attempt (success or failure).
- Computes and stores an optional quality report for later analysis.
- Keeps persistence failures from breaking generation by catching and logging exceptions.

## Public Methods

### persistGeneration(...): string

Persists a snapshot and returns the snapshot ID. It forwards all inputs to `SnapshotService::storeSnapshot(...)`.

Inputs (from `SnapshotPersister::persistGeneration`):
- `orgId`, `userId`, `platform`, `prompt`
- `classification` (intent + funnel stage)
- `context` (`GenerationContext`)
- `options` (enriched diagnostics and request options)
- `content` (final draft or failure message)
- `finalSystemPrompt`, `finalUserPrompt` (optional, for auditing)
- `tokenUsage`, `performance`, `repairInfo` (optional, for auditing)
- `llmStages` (optional, stage -> model mapping)
- `generatedPostId` (optional)
- `conversationId`, `conversationMessageId` (optional)

### persistQuality(...): void

Evaluates quality and stores a report. Errors are caught and logged as `snapshot.persistQuality_failed`.

Inputs:
- `orgId`, `userId`, `classification`, `content`, `context`, `options`
- `snapshotId` (required)
- `generatedPostId` (optional)

Internals:
- Calls `PostQualityEvaluator::evaluate(...)` with `content`, `context`, `classification`, and `options`.
- Stores results via `QualityReportService::store(...)`.

## Snapshot Payload (GenerationSnapshot)

`SnapshotService::storeSnapshot(...)` writes the snapshot with the following fields:

### Identity and linkage
- `organization_id`, `user_id`
- `generated_post_id` (from options if provided)
- `conversation_id`, `conversation_message_id` (if provided)

### Prompting and classification
- `platform`
- `prompt` (the input prompt)
- `classification` (array with `intent` and `funnel_stage`)

### Template, voice, and structure
- `template_id`
- `template_data` (full template structure at time of generation)
- `voice_profile_id` (from `options['voice_profile_id']`)
- `voice_source` (from `options['voice_source']` or derived)
- `swipes` (raw swipe payloads in context)
- `structure_resolution` (from first swipe, if present)
- `structure_fit_score` (from first swipe, if present)
- `resolved_structure_payload` (first swipe metadata: id, is_ephemeral, origin, title, intent, funnel_stage, cta_type, structure)

### Context inputs
- `chunks` (array of `{ id, text }` from context chunks)
- `facts` (array of `{ id, text, confidence }` from context facts)
- `user_context` (free text from request)
- `creative_intelligence` (CI object injected into context, if any)

### Output and auditing
- `options` (full enriched options array, including diagnostics)
- `output_content` (final draft or failure message)
- `final_system_prompt` (if provided)
- `final_user_prompt` (if provided)
- `token_metrics` (if provided)
- `performance_metrics` (if provided)
- `repair_metrics` (if provided)
- `llm_stages` (validated stage -> model map)
- `created_at`

### LLM stage tracking validation
`SnapshotService` filters `llm_stages` to a known allowlist and requires a non-empty `model` string per stage. Unknown or malformed entries are dropped before persisting.

### Conversation context update
When `conversation_id` is provided, `SnapshotService` updates `AiCanvasConversation` with:
- `last_snapshot_id`
- `active_voice_profile_id`
- `active_template_id`
- `active_swipe_ids`
- `active_fact_ids`
- `active_reference_ids` (from `context->snapshotIds()['reference_ids']`)

This is best-effort; failures do not block snapshot storage.

## ContentGeneratorService Integration

`ContentGeneratorService` calls `SnapshotPersister` in three paths. Each path records a snapshot with a slightly different `options` payload.

### 1) Creative Intelligence strict mode failure
When strict CI requirements are not met, generation returns a failure message and still persists a snapshot. The `options` payload includes:
- `run_id`, `folder_ids`
- Voice diagnostics: `voice_source`, `voice_profile_id`
- Swipe diagnostics: `swipe_mode`, `swipe_ids`, `swipe_scores`, `swipe_rejected`
- `token_usage` (context token usage)
- CI diagnostics: `ci_policy`, `ci_used`, `ci_mode`, `ci_hook_applied`, `ci_emotion_applied`, `ci_audience_applied`
- CI strict flags: `ci_strict_failed`, `ci_strict_missing`

### 2) Minimum context failure
When minimum viable context is missing, generation returns a failure message and persists a snapshot with:
- `run_id`, `folder_ids`
- Voice diagnostics: `voice_source`, `voice_profile_id`
- Swipe diagnostics (empty scores and rejected list)
- `token_usage`
- CI diagnostics: `ci_policy`, `ci_used`, `ci_mode`, `ci_hook_applied`, `ci_emotion_applied`, `ci_audience_applied`

### 3) Normal success path
On successful generation, the options payload includes everything in the previous sections plus:
- `classification_overridden`, `classification_original`
- Retrieval flags: `retrieval_enabled`, `business_facts_enabled`
- Business profile data: `business_context`, `business_profile_snapshot`, `business_profile_used`, `business_profile_version`, `business_retrieval_level`
- Template resolver diagnostics: `template_selected`, `template_id`, `template_candidates`, `fallback_used`, `resolver_score_debug`, `template_resolution_failed`, `fallback_template_used`, `fallback_template_id`

The success path also passes these high-fidelity auditing fields into the snapshot:
- `final_system_prompt`, `final_user_prompt`
- `token_usage` (prompt/completion/total/estimated cost)
- `performance` (total latency, provider latency, model identifier)
- `repair_info` (repair count, types, initial score, log)
- `llmStages` (stage -> model map)

## Quality Report Payload

`SnapshotPersister::persistQuality(...)` stores a quality report with:
- `orgId`, `userId`
- `intent` (from classification)
- `overall` score
- `scores` (category breakdown)
- `snapshotId`
- `generatedPostId` (if provided)

This is best-effort and does not affect the generation response.

## Related Documents

- `docs/features/content-generator-service.md`
