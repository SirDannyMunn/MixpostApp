# Content Generation — Optional Next Steps

This document lists incremental next steps to further improve the system, along with the benefits they provide.

## 1) ClassificationService

- What:
  - Extract a `ClassificationService` with `classify(prompt, intentOverride?, funnelOverride?)` returning `{ final, original, overridden }`.
- Benefits:
  - Consolidates override handling and traceability of original vs. final decisions.
  - Easier to unit test classification logic and override rules.

## 2) RetrievalService

- What:
  - Encapsulate retrieval policy, knowledge chunk search, business facts inclusion rules, and swipe bundle selection into `RetrievalService`.
- Benefits:
  - Clear separation between “what to retrieve” and “how to assemble context”.
  - Simplifies orchestrator further and centralizes retrieval diagnostics.

## 3) GenerationTrace (debug object)

- What:
  - Build a single `GenerationTrace` object that records: normalized request, policy decisions (template/swipe/emoji), context counts, exact prompts, schema validation and repair steps, snapshot ID, `run_id`, token usage.
- Benefits:
  - One-stop artifact for debugging; enables CLI/HTTP endpoints to fetch and display it.
  - Great for support and regression triage.

## 4) TemplateService Enhancements

- What:
  - Add policy-driven template selection rules (e.g., intent+funnel+platform affinity, A/B testing hooks).
  - Support “template families” with graceful fallback.
- Benefits:
  - More predictable and testable template selection.
  - Easier experimentation with selection strategies.

## 5) Swipe Policy Refinement

- What:
  - Extend `GenerationPolicy` to cover swipe modes more explicitly (none/strict/auto) and VIP-insertion nuances.
  - Add structural similarity thresholds and telemetry for selection.
- Benefits:
  - Fewer regressions from unclear precedence.
  - Better explainability of why a swipe was included or rejected.

## 6) BusinessProfileResolver Extensions

- What:
  - Expose additional derived constraints (e.g., tone formality/energy) where appropriate.
  - Add a “confidence” indicator for snapshot completeness.
- Benefits:
  - Enables opt-in policy derivations beyond emoji.
  - Surfaces profile data quality to inform retrieval and repair behavior.

## 7) SnapshotPersister → Quality Pipelines

- What:
  - Add optional asynchronous quality evaluation with richer rubrics and versioning of quality checks.
  - Emit events/hooks around snapshot persistence and quality computation.
- Benefits:
  - Non-blocking generation path; richer analytics.
  - Loosely-coupled pipelines facilitate A/B evaluation and experimentation.

## 8) Validator Unification and Configurability

- What:
  - Align `PostValidator` constraints with `Constraints` DTO and `EmojiSanitizer` centrally.
  - Support platform-specific validators (e.g., character caps or emoji rules per platform).
- Benefits:
  - Removes definition drift; fewer false negatives/positives.
  - Cleaner extension points for new platforms.

## 9) Testing Strategy

- What:
  - Unit tests for: `GenerationPolicy`, `OverrideResolver`, `TemplateService`, `BusinessProfileResolver`, `ValidationAndRepairService`, and `PromptComposer`.
  - End-to-end test using a stub LLM to verify no drift between context snapshot and sent prompt.
- Benefits:
  - Confidence in refactor stability; prevents regressions in precedence logic.

## 10) Observability & Tooling

- What:
  - Add a `php artisan ai:trace <snapshot_id|run_id>` to print a compact `GenerationTrace`.
  - Add feature flags for schema strictness and repair policies.
- Benefits:
  - Faster on-call triage and feature experiments.

## 11) Security and Limits

- What:
  - Add token budgeting configuration per platform or org tier.
  - Rate-limit LLM repair attempts and retries.
- Benefits:
  - Cost control and predictable performance.

## 12) Replay and Enforce Parity

- What:
  - Reuse `ValidationAndRepairService` in replay as an optional step.
  - Add “repair-only” prompt variant to `PromptComposer` for enforce.
- Benefits:
  - One path for all schema/repair logic; fewer edge-case behaviors across entry points.

