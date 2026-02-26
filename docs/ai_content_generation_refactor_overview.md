# Content Generation Refactor — Overview

This document explains the new architecture for content generation following the incremental refactor. It covers what changed, how the system works now, and where to find the key pieces of code.

## Summary

The old `ContentGeneratorService` mixed orchestration, business logic, prompt construction, LLM/schema handling, overrides, and persistence. The refactor splits these concerns into small, focused services that the orchestrator composes. This removes drift between snapshotted context and the prompt sent to the LLM, centralizes schema/repair behavior, and isolates side effects.

## What Changed

- Prompt is built from `Context` only (no manual array stitching), eliminating context drift.
- LLM calls + JSON schema + retry logic is centralized.
- Validation + repair is centralized and consistent (including emoji handling).
- VIP overrides (template/facts/swipes/knowledge) are resolved in one place.
- Business profile resolution is decoupled from generation and does not mutate options mid-flight.
- Snapshot persistence + quality reporting are in an isolated service.
- A correlation `run_id` is included in logs and snapshots for traceability.

## Key Components

- Orchestrator: `App/Services/Ai/ContentGeneratorService.php`
  - Calls the small services in sequence and returns results.

- DTOs / Policy
  - `GenerationRequest` (normalized inputs; partially integrated now)
  - `Constraints` (maxChars/emoji/tone)
  - `Prompt` (system/user/schema/params)
  - `GenerationPolicy` (explicit precedence logic)

- Steps / Services
  - `PromptComposer` — builds `system` + `user` strings from a `GenerationContext` and `Constraints`.
  - `GenerationRunner` — calls LLM, enforces JSON schema, retries on mismatch; `runJsonContentCallWithMeta` preserves usage/latency.
  - `ValidationAndRepairService` — validates drafts and performs one repair attempt with schema-check + fallback via `EmojiSanitizer`.
  - `OverrideResolver` — resolves VIP overrides: knowledge (inline content), facts, swipes, template override.
  - `BusinessProfileResolver` — provides business context and derives emoji policy source without mutating options.
  - `TemplateService` — selects a preview template then applies precedence (override > option > selected) via policy.
  - `SnapshotPersister` — stores snapshots and quality reports.
  - `EmojiSanitizer` — centralized emoji removal (used by validator/repair paths).

- Factories
  - `ContextFactory` — builds `GenerationContext` from parts or from a snapshot (used by generate/replay).

## Files and Layout

```
App/Services/Ai/ContentGeneratorService.php           // orchestrator
App/Services/Ai/Generation/
  DTO/GenerationRequest.php
  DTO/Constraints.php
  DTO/Prompt.php
  Policy/GenerationPolicy.php
  Steps/
    PromptComposer.php
    GenerationRunner.php
    ValidationAndRepairService.php
    OverrideResolver.php
    BusinessProfileResolver.php
    TemplateService.php
    SnapshotPersister.php
    EmojiSanitizer.php
  Factories/
    ContextFactory.php
```

## Request Flow (generate)

1. Normalize inputs via `GenerationRequest` (run_id, constraints, policies, context inputs).
2. Classify prompt (existing `PostClassifier`) with optional overrides.
3. Retrieve knowledge and business facts (existing `Retriever`) according to `GenerationRequest` retrieval policy.
4. Resolve business profile via `BusinessProfileResolver` and apply emoji policy precedence via `GenerationPolicy`.
5. Resolve VIP overrides via `OverrideResolver` and merge reference IDs once.
6. Select template via `TemplateService->previewTemplate()`, then apply precedence rules with `TemplateService->resolveFinal()`.
7. Build `GenerationContext` with `ContextFactory->fromParts()`.
8. Compose `Prompt` from the `GenerationContext` with `PromptComposer`.
9. Call the model using `GenerationRunner->runJsonContentCall()` with schema check + retry.
10. Validate and repair via `ValidationAndRepairService` (single repair attempt + fallback sanitization if needed).
11. Persist snapshot and quality report via `SnapshotPersister`. Options include diagnostics and `run_id`.
12. Return draft, validation, snapshot IDs, and metadata.

## Replay and Enforce

- `replayFromSnapshot()`
  - Builds context via `ContextFactory->fromSnapshot()`.
  - Composes prompts via `PromptComposer` and calls `GenerationRunner->runJsonContentCallWithMeta()` to preserve model/usage/latency.
  - Runs validator and quality evaluator; can optionally persist a report.

- `enforce()`
  - Builds a minimal context via `ContextFactory->fromParts()`.
  - Uses `ValidationAndRepairService` with `Constraints` to repair an existing draft.

## Policies and Precedence

- Template: `overrides.template_id > options.template_id > selector` (via `GenerationPolicy` / `TemplateService`).
- Swipe mode precedence: resolved and merged via `GenerationPolicy` (overrides + retrieved swipes).
- Emoji policy: explicit option > business profile’s tone signature > default.

## Observability

- `run_id` is generated if not provided and included in logs/snapshots.
- `GenerationRunner` logs previews of LLM responses.
- Snapshot options include usage and swipe selection diagnostics.

## Backwards Compatibility Notes

- `ContentGeneratorService` public method signatures remain unchanged.
- Existing retriever/classifier/validator implementations are preserved.
- `GenerationRequest` is integrated for `generate()` and can be adopted in more call sites as needed.

## How to Extend

- Add rules to `GenerationPolicy` and reuse in Steps.
- Add new compose functions to `PromptComposer` (e.g., composeRepair variants) and reuse in `ValidationAndRepairService`.
- Introduce additional DTOs for new inputs (e.g., platform-specific constraints) and update `GenerationRequest`.

