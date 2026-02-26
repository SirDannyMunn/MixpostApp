# ContentGeneratorService: Architecture and Flow

File: `app/Services/Ai/ContentGeneratorService.php`
Class: `App\Services\Ai\ContentGeneratorService`

This document provides a comprehensive walkthrough of the service responsible for unified content generation, including classification, retrieval, context assembly, prompting, validation/repair, and snapshot/quality persistence. It also covers helper methods and replay/enforcement flows.

## Overview

The ContentGeneratorService is the central orchestrator for AI-powered content generation in the system. It supports two primary modes:

1. **Generate Mode** (default): Creates social media posts using advanced retrieval, classification, and composition techniques.
2. **Research Mode**: Executes research pipelines for trend discovery, deep research, and creative angle/hook generation.

### Key Features

- **Modular Pipeline**: 25+ injected dependencies enable flexible, testable, and extensible architecture.
- **Policy-Driven**: Retrieval, constraints, voice, templates, and Creative Intelligence controlled via declarative policies.
- **Folder-Scoped Retrieval**: Automatic and manual knowledge scoping for organized context management.
- **Creative Intelligence**: Optional hook/emotion/audience recommendation with strict mode enforcement.
- **Reflexion Loop**: Optional critic-refiner pattern for iterative draft improvement.
- **VIP Overrides**: Direct injection of specific knowledge, facts, swipes, or templates.
- **Comprehensive Telemetry**: Decision traces, prompt mutations, token tracking, cost estimation, and quality metrics.
- **Replay & Debug**: Snapshot-based replay for testing, benchmarking, and prompt inspection.
- **Research Integration**: Unified research executor for web search, trend discovery, and hook generation.

### Generation Flow Summary

```
Input (prompt + options)
  ↓
GenerationRequest (normalize & extract policies)
  ↓
Resolve VIP Overrides (knowledge/facts/swipes)
  ↓
Classification (intent + funnel stage)
  ↓
Prompt Signal Extraction (hook/emotion/audience)
  ↓
Creative Intelligence Recommendation (optional)
  ↓
Folder Scope Resolution (optional, auto-detect)
  ↓
Retrieval (knowledge chunks + business facts)
  ↓
Relevance Gate (filter low-quality chunks)
  ↓
Chunk Kind Policy (fact/narrative/educational)
  ↓
Business Profile Integration (emoji, context)
  ↓
Template & Voice Resolution
  ↓
Swipe Structure Matching
  ↓
Context Assembly (via ContextFactory)
  ↓
Minimum Context Check (fail-fast if insufficient)
  ↓
[Prompt-Only Mode Exit] OR [Continue to Generation]
  ↓
Prompt Composition (system + user, with CI block)
  ↓
Primary Generation (LLM call with JSON schema)
  ↓
[Optional] Reflexion Loop (critique → refine)
  ↓
Validation & Repair (constraints + style)
  ↓
[If empty] Emergency Compose (last resort)
  ↓
Snapshot Persistence (replayable context)
  ↓
Quality Evaluation & Persistence
  ↓
Return (content + metadata + validation)
```

Note on LLM settings and JSON behavior (updated):
- Use a single system message that combines JSON‑only rule, tone/constraints, and structure guidance.
- Enforce JSON with OpenRouter `response_format: { type: "json_object" }` and a blunt JSON‑only instruction.
- Do not set `max_tokens` for JSON calls; enforce content limits via prompt/validator/repair, not API truncation.
- Disable streaming for JSON calls to avoid partial objects.
- Optionally pass a concise schema hint to the LLM (e.g., `{ "content": "<final post text>" }`) to reduce ambiguity.

## Constructor Dependencies

Injected services used across the pipeline:

- `LLMClient $llm`: Low‑level model invocation client used for direct/fallback calls.
- `Retriever $retriever`: Retrieves knowledge chunks, business facts, and swipe structures.
- `TemplateSelector $selector`: Template selection (legacy or adjunct) support.
- `ContextAssembler $assembler`: Legacy/adjunct context assembly orchestrator.
- `PostValidator $validator`: Validates generated posts against constraints and style guards.
- `SchemaValidator $schemaValidator`: Validates strict JSON schemas when applicable.
- `PostClassifier $classifier`: Classifies prompt intent and funnel stage when not overridden.
- `PromptComposer $composer`: Builds system/user prompts from the context and constraints.
- `PromptSignalExtractor $promptSignalExtractor`: Detects explicit hook/emotion/audience/format signals.
- `FolderScopeResolver $folderScopeResolver`: Resolves folder-scoped retrieval boundaries for knowledge organization.
- `CreativeIntelligenceRecommender $ciRecommender`: Fetches CI recommendations to fill missing dimensions.
- `RelevanceGate $relevanceGate`: Filters retrieved knowledge for relevance to the prompt.
- `GenerationRunner $runner`: Runs LLM calls that return structured JSON with meta.
- `ValidationAndRepairService $validatorRepair`: Centralized validate‑then‑repair flow on drafts.
- `OverrideResolver $overrideResolver`: Resolves VIP overrides (knowledge, facts, swipes) to concrete items and reference IDs.
- `BusinessProfileResolver $bpResolver`: Injects business profile context and derives defaults (emoji policy, etc.).
- `SnapshotPersister $snapshotPersister`: Persists generation snapshot and quality metrics for replay/analysis.
- `TemplateService $templateService`: Previews and finalizes templates, returns structure signatures.
- `ContextFactory $contextFactory`: Builds strong typed `Context` objects from parts or snapshots.
- `ContentGenBatchLogger $cgLogger`: Batched logging system for generation telemetry and diagnostics.
- `ReflexionService $reflexion`: Implements critic-refiner loop for iterative draft improvement.
- `PromptInsightSelector $insightSelector`: Selects relevant insights from facts based on prompt intent.
- `ResearchReportComposer $researchReportComposer`: Composes research reports from raw research data.
- `TrendDiscoveryService $trendDiscovery`: Discovers trends from sources and enrichment data.
- `HookGenerationService $hookGenerator`: Generates creative hooks and angles for content.
- `ResearchExecutor $researchExecutor`: Unified executor for all research-mode pipelines.
- `ChatResearchFormatter $researchFormatter`: Formats research results for chat presentation.

These dependencies together implement a modular, policy‑driven generation pipeline with research capabilities.

## Public API

### generate(string $orgId, string $userId, string $prompt, string $platform, array $options = []): array

Unified entry point used by both chat and async jobs. Returns a rich result:

```
[
  'content' => string,                  // final draft
  'context_used' => array,              // snapshot IDs (template/facts/knowledge/swipes)
  'validation_result' => bool,          // true if validation passed
  'validation' => array,                // detailed validation report
  'metadata' => array{                  // classification + run metadata
    'intent' => ?string,
    'funnel_stage' => ?string,
    'template_id' => ?string,
    'run_id' => string,
  },
]
```

#### Inputs and Derived Variables

- `GenerationRequest $req` created from arguments. Normalizes:
  - `runId` (string): ID for logging/snapshots.
  - `prompt` (string): normalized prompt.
  - `mode` (string): 'generate' (default) or 'research' for research pipelines.
  - `researchStage` (string): When mode is 'research', specifies the research stage (deep_research, trend_discovery, angle_hooks).
  - `retrievalPolicy` (array): flags like `useRetrieval`, `retrievalLimit`, `useBusinessFacts`.
  - `contextInputs` (array): `{ userContext, businessContext, referenceIds }` from options.
  - `vipOverrides` (array): `{ template_id, knowledge[], swipes[], facts[] }` VIP inputs.
  - `swipePolicy` (array): `{ mode, swipeIds }` from options.
  - `templatePolicy` (array): `{ templateId }` from options.
  - `constraints` (`Constraints`): `{ maxChars, emojiPolicy, tone }` from options/defaults.
  - `classificationOverrides` (array): `{ intent?, funnel_stage? }` from options.
  - `voiceOverrides` (array): `{ voiceProfileId?, voiceInline? }` from options.
  - `ciPolicy` (array): `{ mode, hook, emotion, audience, max_hooks, max_angles, allow_verbatim_hooks }` from options.
  - `folderIds` (array): Explicit folder IDs for scoped knowledge retrieval.
  - `folderScopePolicy` (array): `{ mode: off|auto|augment }` for automatic folder detection.

- Convenience locals extracted from `$req`: `retrievalLimit`, `userContext`, `businessContext`, `referenceIds`, `overrides`, `swipeMode`, `swipeIds`, `templateOverrideOpt`, constraints (`maxChars`, `emojiPolicy`, `tone`), classification overrides (`intentOverride`, `funnelOverride`), voice overrides (`voiceProfileId`, `voiceInline`), ciPolicy, `folderIds`.

#### Step 0: Resolve VIP Overrides into Concrete Inputs

- Knowledge: `overrideResolver->resolveVipKnowledge(overrides['knowledge'])` → returns `vipChunks[]` and `referenceIds[]` to merge.
- Facts: `overrideResolver->resolveVipFacts(orgId, overrides['facts'])` → `vipFacts[]`, associated IDs.
- Swipes: `overrideResolver->resolveVipSwipes(orgId, overrides['swipes'])` → `vipSwipes[]`, associated IDs.
- `overrideTemplateId`: from overrides or `templatePolicy`.
- Deduplicate `referenceIds`.

#### Step 1: Classification with Optional Overrides

- If `intentOverride` and `funnelOverride` are both present → use them.
- Else call `$classifier->classify($prompt)` and fill missing pieces with defaults (`intent: educational`, `funnel_stage: tof`).
- `classificationOverridden = bool(intentOverride || funnelOverride)` used downstream in snapshot metadata.

#### Step 1.5: Prompt Signal Extraction (Creative Intelligence)

- `promptSignalExtractor->extract(prompt, platform, options)` detects whether the user already provided hook, emotion, audience, sophistication, or format.
- Signal output is stored for CI gating and snapshot diagnostics.

#### Step 1.6: Creative Intelligence Recommendation (Optional)

- If `ciPolicy.mode !== 'none'`, call:
  - `$ciRecommender->recommend(orgId, userId, prompt, platform, classification, signals, ciPolicy)`.
- Returns structured CI recommendations (hooks, angles, formats, resolved persona/emotion targets).
- Failures are non-fatal; generation proceeds without CI.

#### Step 1.7: Folder Auto-Scope Resolution (Optional)

- If `folderScopePolicy.mode !== 'off'` and no explicit folder IDs provided (or mode is 'augment'):
  - `folderScopeResolver->resolve(orgId, userId, prompt, platform, classification, options)` analyzes the prompt to detect relevant folder scope.
  - Returns `{ folderIds, confidence, reasoning, detected, blocksRetrieval }`.
  - If `blocksRetrieval` is true, knowledge retrieval is skipped (hard scope boundary).
  - Results merged into `options['folder_ids']` and captured in diagnostics.
- This enables automatic knowledge scoping based on prompt content analysis.

#### Step 2: Retrieval (Knowledge and Business Facts)

- `useRetrieval` (default true) → `retriever->knowledgeChunks(orgId, userId, prompt, classification.intent, retrievalLimit)`.
- Knowledge retrieval now respects `folder_ids` for scoped retrieval within specific knowledge folders.
- `useBusinessFacts`:
  - From policy when explicitly set; otherwise derived from classification (MOF/BOF or persuasive intent).
  - If true → `facts = retriever->businessFacts(orgId, userId, 8)`.
- Retrieved chunks are filtered through `RelevanceGate` to ensure context quality.
- `ChunkKindResolver` classifies chunks as 'fact', 'narrative', or 'educational' for downstream processing.

#### Step 2.5: Business Profile Integration

- `bpResolver->resolveForOrg(orgId, businessContext, options)` returns:
  - Potentially enriched `businessContext` string.
  - Derived `emojiPolicy` (may override option/default via `GenerationPolicy::resolveEmojiPolicy`).
  - Snapshot/version flags: `{ snapshot, version, used, retrieval_level }`.
- Template preview and resolution:
  - `templateService->previewTemplate(orgId, intent, funnel_stage, platform)`
  - `templateService->resolveFinal(orgId, preview, overrideTemplateId, templateOverrideOpt)` → returns final template and additional `referenceIds`.
- Swipe structure matching:
  - Derive `structureSignature` from the resolved template.
  - If `swipeMode === 'strict'` and IDs provided → fetch those; else `auto` fetch via `retriever->swipeStructures(...)` with optional signature.
  - Merge `vipSwipes` via `GenerationPolicy::resolveSwipeList`.

#### Step 3: Voice and Template Resolution

- `template` := resolved template from previous step.
- Voice selection priority:
  1) `voiceProfileId` → fetch `VoiceProfile` for org; if found, mark `voiceSource='override_reference'` and store `voiceProfileUsedId`.
  2) `voiceInline` → synthesize transient voice object with a description; `voiceSource='override_reference'`.
  3) else → `voice=null`, `voiceSource='none'`.

#### Step 4: Context Assembly

`contextFactory->fromParts([...])` with:

- `voice`, `template`,
- `chunks` (retrieved knowledge), `facts` (business facts), `swipes` (structure hints),
- VIP injections: `vip_chunks`, `vip_facts` (and optionally `vip_swipes`, currently empty in generate),
- `user_context` (free text), `business_context`,
- `options`, `creative_intelligence`, `reference_ids`,
- `decision_trace` (tracking all major decisions in the pipeline),
- `prompt_mutations` (tracking template/CI/context modifications),
- `ci_rejections` (tracking CI recommendations that were rejected).

The resulting `Context` encapsulates all materials, exposes `snapshotIds()`, `debug()`, and is safe for prompt composition.

#### Step 4.5: Minimum Viable Context Check

Before proceeding to generation, the service validates that sufficient context is available:

- Must have a resolved template (`hasTemplate`)
- Must have at least one of: knowledge chunks, business facts, or user context
- If minimum context is not met, the system attempts emergency generation strategies or returns a diagnostic error
- This prevents wasteful LLM calls with insufficient input material

#### Step 5: Prompt Composition and Primary Generation

**Special Mode: Prompt-Only**

- If `options['mode'] === 'prompt_only'`, the service builds prompts and context diagnostics without executing the LLM.
- Returns detailed metadata about context assembly, template selection, and prompt structure.
- Used for debugging and prompt inspection via `php artisan ai:show-prompt`.

**Standard Generation Flow:**

- `Constraints` constructed from current `maxChars`, `emojiPolicy`, and `tone`.
- `PromptComposer::composePostGeneration(context, constraints, prompt)` builds a single authoritative `system` message (JSONonly rule + tone/constraints + structure). If CI is present, it injects a short `CREATIVE_INTELLIGENCE` block and precedence rules (user intent wins; no verbatim hooks unless allowed).
- `GenerationRunner::runJsonContentCallWithMeta('generate', promptObj)` executes the model with `response_format=json_object`, no streaming, and no `max_tokens` limit. Expects strict JSON with field `content`.
- Returns `{ content, meta: { model, tokens, latency } }` for telemetry tracking.
- `draft` := `(string) (gen['content'] ?? '')`.
- Model tracking: `LlmStageTracker` records which model was used for each stage, enabling per-stage model override analysis.

If empty or detected as meta/non‑draft (see `looksLikeMetaNoDraft`), attempt a strict regeneration using `regenerateFromContext(system, user)` and log diagnostics.

#### Step 5.5: Optional Reflexion Loop (Critic → Refiner)

- If `options['enable_reflexion'] === true` and draft is non-empty:
  - `ReflexionService::critique(draft, context, constraints)` analyzes the draft for weaknesses.
  - If critique suggests improvements: `ReflexionService::refine(draft, critique, context, constraints)` generates an improved version.
  - Reflexion results are logged and the refined draft replaces the original.
  - This implements an iterative self-improvement pattern for higher quality outputs.

#### Step 6: Validate and Repair

- `ValidationAndRepairService::validateAndRepair(draft, context, constraints)` returns `{ content, validation }`.
- Validation includes character limits, emoji policy enforcement, tone adherence, and structure compliance.
- Circuit breaker: If draft is empty at this stage, skip validation and attempt regeneration immediately.
- If still empty/meta after repair, make one last regeneration attempt and re‑validate.
- Absolute last resort: If minimum context is viable but draft remains empty, `emergencyCompose()` synthesizes a minimal post from available context.

#### Step 7: Snapshot and Quality Persistence

- `snapshot = context->snapshotIds()` used as `context_used` in the return value.
- Enrich `options` with diagnostics and persist:
  - `SnapshotPersister::persistGeneration(...)` with prompt, classification, context pointers, options, content, and optional conversation IDs.
  - `SnapshotPersister::persistQuality(...)` after running `PostQualityEvaluator::evaluate(...)`.
- Snapshot includes extensive telemetry:
  - `creative_intelligence` JSON and CI usage flags: `ci_policy`, `ci_used`, `ci_mode`, `ci_hook_applied`, `ci_emotion_applied`, `ci_audience_applied`.
  - `folder_scope` diagnostics: detected folders, confidence, reasoning.
  - `relevance_gate` and `knowledge_gate` metrics.
  - `context_breakdown`: fact/narrative/educational chunk distribution.
  - `prompt_mutations`: template/CI/context modification tracking.
  - `decision_trace`: full decision log for reproducibility.
  - `llm_stages`: per-stage model usage for cost and performance analysis.
- Cost estimation: Tracks token usage and computes estimated cost per generation.
- Returns final array (see API above) with `validation_result`, `validation`, `metadata` (intent, funnel_stage, template_id, run_id, model_used, tokens, cost).

#### Research Mode Pipeline

When `mode === 'research'`, the generate method delegates to research-specific handlers:

- **deep_research**: Executes comprehensive research via `ResearchExecutor` with multi-stage web search, source analysis, and synthesis.
- **trend_discovery**: Uses `TrendDiscoveryService` to identify emerging patterns and trends from sources.
- **angle_hooks**: Uses `HookGenerationService` to generate creative angles and hooks from research material.

Research results are formatted via `ChatResearchFormatter` and returned with structured report data, source citations, and metadata.

Research snapshots include:
- Search queries executed
- Sources retrieved and analyzed
- Enrichment data (summaries, key points)
- Research stage and intent mapping
- Creative signals (hooks, angles) when applicable

#### Empty/Meta Detection

- `looksLikeMetaNoDraft(string $text): bool`: Guards against responses like apologies or “no draft provided.”
- If detected at key points, regeneration is triggered with a more prescriptive instruction.

### enforce(string $orgId, string $userId, string $draft, string $platform, array $options = []): array

Enforces constraints on an existing `draft` without performing retrieval or classification.

- Builds a minimal context (no voice/template/knowledge), using provided `options`.
- Runs `validateAndRepair(...)` and returns `[ 'content' => ..., 'validation' => ... ]`.

### replayFromSnapshot(GenerationSnapshot $snap, array $overrides = []): array

Replays generation with context reconstructed from a stored `GenerationSnapshot`, optionally applying overrides.

- Rebuild context with `contextFactory->fromSnapshot($snap, $overrides)`.
- Compose prompts and run via `runJsonContentCallWithMeta('replay_generate', ...)` to capture usage meta.
- Validate and compute quality; can optionally persist a `QualityReport` if `store_report` override is set.
- Returns extended metadata including `model_used`, token usage, processing time, and debug link `php artisan ai:show-prompt {snapshot_id}`.

### buildPrompt(string $orgId, string $userId, string $prompt, string $platform, array $options = []): PromptBuildResult

Side-effect-free prompt builder for debugging and inspection. Does not execute LLM calls or persist any data.

**Purpose:**
- Build complete prompts without executing generation
- Inspect context assembly, template selection, and retrieval results
- Debug prompt composition and constraint application
- Used by `php artisan ai:show-prompt` command

**Returns:** `PromptBuildResult` with:
- `system` (string): Complete system prompt
- `user` (string): User prompt with context
- `meta` (array): Comprehensive diagnostics including:
  - Classification (intent, funnel_stage)
  - Template (id, name, structure)
  - Voice (profile or inline)
  - Creative Intelligence (signals, recommendations, resolved targets)
  - LLM parameters (temperature, model, etc.)
- `contextSummary` (array): Detailed breakdown of:
  - Knowledge chunks (count, preview)
  - Business facts (count, preview)
  - Swipes (count, preview)
  - User context, business context
  - VIP overrides (chunks, facts, swipes)
  - ISA (Intent-Specific Insights) debug information
  - Folder scope (if used)

**Flow:**
1. Reuses `GenerationRequest` for option normalization
2. Resolves VIP overrides (knowledge, facts, swipes)
3. Classifies prompt with override support
4. Runs CI recommendation (optionally via `_ci` option to inject pre-computed CI)
5. Retrieves knowledge and facts with folder scoping
6. Resolves business profile and emoji policy
7. Resolves template and swipes
8. Selects voice profile (if provided)
9. Assembles context via `ContextFactory`
10. Validates CI strict mode requirements (if applicable)
11. Composes final prompts via `PromptComposer`
12. Builds ISA (Intent-Specific Insights) for fact-based prompts
13. Returns structured result with full diagnostics

**Special Options:**
- `_ci`: Pre-computed Creative Intelligence object to bypass recommendation
- `retrieval_filters`: Additional filters for knowledge retrieval
- `mode === 'prompt_only'`: Not used here (that's in `generate()`)

This method provides complete visibility into the prompt assembly process without side effects.

## Helper Methods

- `private function looksLikeMetaNoDraft(string $text): bool`
  - Detects apology/empty‑draft patterns; used to trigger regeneration.
  - Patterns include: apologies, explanations about missing drafts, requests for more input, etc.

- `private function regenerateFromContext(string $system, string $user, array $options, ?LlmStageTracker $tracker): array`
  - Issues a direct call via `$llm->callWithMeta('generate_fallback', ...)` with firm instructions to produce JSON with `content`.
  - Applies stage-specific model options (supports per-stage model overrides).
  - Tracks model usage in `LlmStageTracker` for telemetry.
  - Returns `[ $draft2, [ 'used' => 'generate_fallback', 'preview' => '...', 'model' => '...' ] ]` for logging.

- `private function stripEmojis(string $text): string`
  - Delegates to `EmojiSanitizer::strip`.

- `private function stageModelOptions(array $options, string $stage): array`
  - Resolves per-stage model overrides from options.
  - Supports `$options['model']` (default for all stages) and `$options['models'][stage]` (stage-specific).
  - Returns LLM options array with resolved model and parameters.

- `private function evaluateCiStrict(array $ciPolicy, ?array $ci): array`
  - Evaluates whether CI strict mode requirements are met.
  - Checks for presence of: hook, angle, emotion.
  - Returns `{ ok: bool, missing: string[] }` to guide strict mode enforcement.

- `private function emergencyCompose(object $context, string $prompt, Constraints $constraints): string`
  - Last-resort composition when all generation attempts fail but viable context exists.
  - Synthesizes a minimal post from template structure, chunks, and facts.
  - Enforces constraints (emoji, max_chars) on synthesized output.
  - Returns a basic but valid post to prevent total failure.

- `private function applyChunkKindPolicy(array $chunks): array`
  - Classifies and filters chunks by kind (fact, narrative, educational).
  - Uses `ChunkKindResolver` to determine chunk types.
  - Returns `[$filteredChunks, $contextBreakdown]` with distribution stats.
  - Supports disabling specific chunk kinds via options.

- `private function estimateCost(int $promptTokens, int $completionTokens, string $model): ?float`
  - Estimates generation cost based on token usage and model pricing.
  - Returns cost in dollars (rounded to 6 decimals).

## Research-Specific Private Methods

- `private function generateResearchReport(GenerationRequest $req, array $options, LlmStageTracker $llmStages, float $t0): array`
  - Main entry point for research mode pipelines.
  - Delegates to `ResearchExecutor` for unified research execution.
  - Formats results via `ChatResearchFormatter` for chat presentation.
  - Returns structured report with sources, insights, and metadata.

- `private function mapResearchIntent(string $stage): string`
  - Maps research stage to intent classification for downstream consumers.

- `private function buildResearchSnapshotContext(array $options, array $debug): GenerationContext`
  - Builds a minimal context object for research snapshot persistence.

- `private function logResearchGuardrail(string $stage, string $composer, array $platforms, int $itemsConsidered): void`
  - Logs research execution guardrails and telemetry.

- `private function summarizeCreativeSignals(array $items): array`
  - Summarizes creative signals (hooks, angles) from research items for logging.

## Options and Policies (Key Fields)

### Core Options
- `mode`: `'generate'` (default) or `'research'` to switch between content generation and research pipelines.
- `research_stage`: When mode is 'research', specifies the stage: `'deep_research'`, `'trend_discovery'`, or `'angle_hooks'`.
- `constraints`: `{ max_chars, emoji, tone }` influence composition and validation.
- `retrievalPolicy`: `{ useRetrieval, retrievalLimit, useBusinessFacts }` determine context breadth.
- `swipePolicy`: `{ mode: auto|none|strict, swipeIds }` shapes structure guidance.
- `templatePolicy`: `{ templateId }` sets the final template (overrides allowed).
- `vipOverrides`: `{ template_id, knowledge[], swipes[], facts[] }` preempts default retrieval.
- `voiceOverrides`: `{ voiceProfileId, voiceInline }` injects stylistic voice.
- `ciPolicy`: `{ mode: auto|none|strict, hook: fill|respect|force, emotion: fill|respect|force, audience: fill|respect|force, max_hooks, max_angles, allow_verbatim_hooks }`.
- `folderIds`: Explicit array of folder IDs to scope knowledge retrieval.
- `folderScopePolicy`: `{ mode: off|auto|augment }` for automatic folder detection from prompt analysis.

### Advanced Options
- `enable_reflexion`: `bool` - Enables critic-refiner loop for iterative improvement.
- `model`: `string` - Default model for all stages.
- `models`: `array<stage, model>` - Per-stage model overrides (e.g., `{ 'generate': 'gpt-4', 'repair': 'claude-3-opus' }`).
- `retrieval_filters`: Additional filters for knowledge retrieval.
- `_ci`: Pre-computed Creative Intelligence object (used by `buildPrompt()` to bypass recommendation).

### Telemetry and Logging
- `run_id`: Unique ID for generation run (auto-generated if not provided).
- `conversation_id`: Associates generation with a conversation thread.
- `message_id`: Associates generation with a specific message.
- `store_report`: (replay mode) Whether to persist quality report.

### Research Options
- `research_options`: Full research configuration (sources, enrichment, platforms, limits, etc.).
- See `ResearchOptions` DTO for complete schema.

## Error Handling and Logging

- Warnings on empty/meta outputs during primary and repair stages with run and prompt lengths.
- Snapshot write protected by try/catch; logs on persistence issues without failing the response.
- Regeneration attempts logged with stage markers for observability.
- `ContentGenBatchLogger` provides batched telemetry capture with flush at run completion.
- All major decisions tracked via `DecisionTraceCollector` for replay and debugging.
- LLM stage tracking via `LlmStageTracker` captures per-stage model usage and enables cost analysis.
- Folder scope failures are logged but non-blocking; generation proceeds with unscoped retrieval.
- Creative Intelligence failures are non-fatal; generation proceeds without CI recommendations.
- Relevance gate rejections are logged with reasoning for quality monitoring.

## Extensibility Notes

- New override types can be added in `OverrideResolver` and forwarded via `vipOverrides`.
- Additional constraints can be added to `Constraints` and respected in `PromptComposer` and validators.
- To bias structure further, expand `TemplateService` structure signatures and `Retriever::swipeStructures` scoring.
- Replay path (`replayFromSnapshot`) is ideal for A/B testing or benchmarking model changes.
- `php artisan ai:show-prompt {snapshot_id}` will include the CI block for snapshots that stored it (or when final prompts are absent).
- New research stages can be added by extending `ResearchStage` enum and implementing handlers in `ResearchExecutor`.
- Chunk kind policies can be extended in `ChunkKindResolver` and filtered in `applyChunkKindPolicy`.
- Per-stage model routing can be customized via `stageModelOptions` and `$options['models']`.

## Performance and Cost Optimization

- **Token tracking**: Full prompt/completion token counts captured per stage for cost analysis.
- **Cost estimation**: Automatic cost calculation based on model pricing and token usage.
- **Stage-specific models**: Use cheaper models for low-risk stages (e.g., classification) and premium models for generation.
- **Relevance gating**: Filters low-relevance chunks before prompt composition, reducing token waste.
- **Minimum context check**: Prevents expensive LLM calls when context is insufficient.
- **Prompt-only mode**: Build and inspect prompts without executing (zero cost for debugging).
- **Chunk kind filtering**: Disable specific chunk types to reduce context size while preserving quality.

## Testing and Debugging Tools

- **`buildPrompt()`**: Side-effect-free prompt inspection with full diagnostics.
- **`php artisan ai:show-prompt {snapshot_id}`**: Displays complete prompts from persisted snapshots.
- **Decision trace**: Full audit trail of classification, template selection, CI application, folder scope, etc.
- **Prompt mutations**: Tracking of all modifications to base prompts (template fallbacks, CI injections, etc.).
- **Context breakdown**: Detailed chunk distribution (fact/narrative/educational) in snapshot metadata.
- **Replay mode**: Reproduce exact generation conditions from snapshot for regression testing.
- **Research guardrails**: Logged limits on sources, enrichment, and creative signal generation.

## Integration Points

- **Chat interface**: Primary consumer via `mode=generate` with conversation tracking.
- **Async jobs**: Background generation with snapshot persistence for heavy workloads.
- **Research pipelines**: Integrated trend discovery, hook generation, and deep research via `mode=research`.
- **Knowledge management**: Folder-scoped retrieval enables organized knowledge bases.
- **Voice profiles**: Persistent voice definitions for consistent brand tone.
- **Templates**: Structured content frameworks with swipe file support.
- **Business profiles**: Organizational defaults and context injection.
- **Quality monitoring**: Automated quality evaluation and persistence for analytics.

## Key DTOs and Supporting Classes

### Data Transfer Objects

- **`GenerationRequest`**: Normalizes all input options, extracts policies, and provides type-safe access to request parameters.
- **`Constraints`**: Encapsulates content constraints (maxChars, emojiPolicy, tone) for validation and composition.
- **`PromptBuildResult`**: Return type for `buildPrompt()` with system/user prompts, meta, and context summary.
- **`ResearchOptions`**: Configuration for research pipelines (sources, enrichment, platforms, limits).
- **`CiRecommendation`**: Structured Creative Intelligence output (hooks, angles, persona, emotion).

### Supporting Classes

- **`LlmStageTracker`**: Tracks which models were used for each generation stage, enabling cost and performance analysis.
- **`DecisionTraceCollector`**: Records all major pipeline decisions (classification source, template selection, CI application, folder scope detection, etc.) for reproducibility and debugging.
- **`ChunkKindResolver`**: Classifies knowledge chunks as 'fact', 'narrative', or 'educational' based on content analysis.
- **`EmojiSanitizer`**: Strips emoji characters from text when emoji policy is 'disallow'.
- **`GenerationPolicy`**: Static utility methods for resolving policies (emoji, retrieval, swipes) with precedence rules.
- **`PostQualityEvaluator`**: Evaluates generated content quality across multiple dimensions (coherence, engagement, accuracy, etc.).

### Enums

- **`ResearchStage`**: Defines research pipeline stages (deep_research, trend_discovery, angle_hooks).
- **`LlmStage`**: Defines LLM usage stages (classify, generate, repair, critique, refine, etc.) for tracking and model routing.

## CLI Commands

The ContentGeneratorService ecosystem includes several Artisan commands for debugging, analysis, and snapshot management.

### content-service:report:get

**Command:** `php artisan content-service:report:get {snapshot_id?} {--count=1}`

**Aliases:** `content-service:get-report`

**Description:** Exports generation snapshot reports to JSON files for analysis and debugging.

**Usage:**

```bash
# Export a specific snapshot by ID
php artisan content-service:report:get 550e8400-e29b-41d4-a716-446655440000

# Export the most recent snapshot
php artisan content-service:report:get

# Export the 5 most recent snapshots
php artisan content-service:report:get --count=5
```

**Output Location:** `storage/logs/content-service-report-{snapshot_id}.json` or `storage/logs/content-service-report-most-recent-{count}.json`

**Report Contents:**
- Snapshot metadata (ID, timestamps, organization, user)
- Classification and intent data
- Template and voice profile references
- Retrieved context (chunks, facts, swipes)
- Structure resolution and fit scores
- Creative Intelligence data
- Generated content and prompts (system + user)
- Token metrics and performance data
- Repair metrics and LLM stage tracking

**Use Cases:**
- Analyzing generation quality across multiple runs
- Exporting data for external analysis tools
- Debugging context assembly issues
- Tracking performance metrics over time

### ai:snapshots:replay

**Command:** `php artisan ai:snapshots:replay {snapshot_id} [options]`

**Aliases:** `ai:replay-snapshot`

**Description:** Replays a generation snapshot to reproduce results, test changes, or compare model performance.

**Core Options:**

```bash
# Basic replay with original settings
php artisan ai:snapshots:replay 550e8400-e29b-41d4-a716-446655440000

# Override constraints
php artisan ai:snapshots:replay {id} --max-chars=280 --emoji=disallow --tone=professional

# Test with different model
php artisan ai:snapshots:replay {id} --model=anthropic/claude-3.5-sonnet

# Per-stage model testing
php artisan ai:snapshots:replay {id} --model-generate=x-ai/grok-2-latest --model-repair=anthropic/claude-3.5-sonnet

# Enable reflexion loop
php artisan ai:snapshots:replay {id} --reflexion

# Folder-scoped retrieval
php artisan ai:snapshots:replay {id} --folder-ids=uuid1,uuid2,uuid3
```

**Advanced Options:**

- `--platform=` : Override platform (twitter, linkedin, etc.)
- `--budget=` : Set context token budget
- `--model=` : Default model for all stages (overridden by per-stage models)
- `--models=` : JSON object mapping stage to model (e.g., `{"generate":"x-ai/grok-4-fast"}`)
- `--model-classify=` : Model for classification stage
- `--model-generate=` : Model for main generation
- `--model-replay-generate=` : Model for replay generation
- `--model-repair=` : Model for validation repair
- `--model-generate-fallback=` : Model for fallback regeneration
- `--model-reflexion-critique=` : Model for reflexion critique
- `--model-reflexion-refine=` : Model for reflexion refine
- `--reflexion` / `--reflection` : Enable Critic-Refiner loop
- `--no-report` : Skip quality report persistence
- `--via-generate` : Run through generate() instead of replay (uses live retrieval)
- `--no-overrides` : Use live retrieval instead of snapshot-frozen context
- `--prompt-only` : Build and print prompts without LLM execution

**Output Format (JSON):**

```json
{
  "mode": "replay",
  "snapshot_id": "550e8400-...",
  "metadata": {
    "model_used": "x-ai/grok-2-latest",
    "total_tokens": 2450,
    "processing_time_ms": 1820,
    "intent": "educational",
    "platform": "twitter"
  },
  "input_snapshot": { /* original snapshot data */ },
  "output": {
    "content": "Generated post content...",
    "validation": {
      "valid": true,
      "char_count": 275,
      "violations": []
    }
  },
  "quality_report": {
    "overall_score": 8.5,
    "breakdown": {
      "coherence": 9.0,
      "engagement": 8.5,
      "accuracy": 8.0
    }
  },
  "context": { /* assembled context details */ },
  "debug_links": ["php artisan ai:show-prompt {snapshot_id}"]
}
```

**Replay Modes:**

1. **Classic Replay** (default): Uses frozen snapshot context exactly as captured during original generation
2. **Via-Generate** (`--via-generate`): Runs through full generate() pipeline with live retrieval while respecting snapshot configuration
3. **Prompt-Only** (`--prompt-only`): Builds prompts and context without LLM execution, useful for debugging prompt composition

**Use Cases:**

- **Model Comparison:** Test same prompt with different models by varying `--model-*` flags
- **Constraint Testing:** Validate how constraint changes affect output (`--max-chars`, `--emoji`, `--tone`)
- **Regression Testing:** Ensure changes don't break existing generation quality
- **Prompt Debugging:** Use `--prompt-only` to inspect assembled prompts before execution
- **Reflexion Testing:** Compare outputs with and without `--reflexion` to measure quality improvement
- **Live Retrieval Comparison:** Use `--via-generate --no-overrides` to test current knowledge base against historical snapshots
- **Folder Scoping Validation:** Test folder-scoped retrieval with `--folder-ids`

**Tips:**

- Use `php artisan ai:list-snapshots` to find recent snapshot IDs
- Combine with `content-service:report:get` for comprehensive analysis workflow
- Per-stage model overrides enable fine-grained cost/performance optimization
- `--prompt-only` is invaluable for understanding context assembly without API costs

## Related Documents

- `docs/features/content-generator-service/snapshot-persister.md`
- `docs/features/creative-intelligence.md`
- `docs/features/research-executor.md`
- `docs/features/folder-scope-resolver.md`
- `docs/features/reflexion-service.md`
