---
title: Generate() repair step returns empty content after emoji validation
owners: ai-platform
status: open
severity: high
first_seen: 2025-12-31
related:
  - app/Services/Ai/ContentGeneratorService.php
  - app/Services/Ai/PostValidator.php
  - app/Services/Ai/LLMClient.php
  - app/Services/Ai/SchemaValidator.php
  - app/Console/Commands/ReplaySnapshot.php
---

# Generate() repair returns empty content

## Summary

- Initial draft from LLM is produced, validator flags emoji policy, and the subsequent repair step sometimes returns empty or invalid output, resulting in empty final content.
- Emoji policy is not consistently aligned between business profile, options, validator defaults, and prompt constraints, creating avoidable validation failures.
- Snapshot `user_context` is a pruned strategy blob and will not textually match the composed multi‑section user prompt sent to the LLM, which complicates debugging.

## Impact

- Final generated post can be empty after repair.
- Increases token usage and latency due to avoidable repair attempts.
- Confusing debugging signals due to mismatch between snapshot context and actual prompt.

## Steps To Reproduce

- Run: `php artisan ai:replay-snapshot <SNAPSHOT_UUID> --via-generate`
- Observe cases where validator flags `emoji_disallowed` and repair returns empty; `char_count: 0` is recorded.

## Expected vs Actual

- Expected: If emojis violate policy, local sanitize or repair yields non‑empty content that passes validation.
- Actual: Repair can return empty/invalid JSON; pipeline ends with empty content.

## Affected Components

- `ContentGeneratorService::generate()` (initial draft; validation; repair attempt)
- `PostValidator::checkPost()`
- `LLMClient::call()` (JSON mode)
- `SchemaValidator::validate('post_generation')`
- `BusinessProfileService` (tone/emoji source)
- `app/Console/Commands/ReplaySnapshot.php` (`--via-generate`)

## Findings

- Emoji policy conflict
  - Business profile often sets `tone_signature.emoji = true` (allow).
  - Validator default is disallow unless options override.
  - System prompt did not always assert emoji constraints explicitly.
- Snapshot vs live prompt
  - Snapshot stores a pruned `user_context` strategy block.
  - Actual user prompt is a composed, multi‑section message (KNOWLEDGE/FACTS/TEMPLATE/SWIPES/USER_CONTEXT) and will not match snapshot textually.
- Repair response empty
  - Repair route/model occasionally returns empty/invalid JSON.
  - Prior logic could accept empty content; validator lacked an explicit `empty_content` failure.

## Current Mitigations/Instrumentation

- Added explicit constraints in system prompt (max_chars, emoji, tone).
- If `options.emoji` not set, inherit from business profile (allow/disallow).
- Added logs: `content.generator.initial_llm_response`, `content.generator.validation_failed`, `content.generator.emoji_sanitized_applied`, `content.generator.repair_response`, `content.generator.repair_retry_response`, `content.generator.repair_empty`.
- Local emoji‑stripping before repair to reduce unnecessary LLM calls.
- Validator now flags `empty_content`.
- CLI `--via-generate` enables end‑to‑end replay through `generate()`.

## Root Cause

- Policy authority drift: emoji allowance differed across business profile, options, validator defaults, and prompt constraints.
- Repair robustness gaps: occasional empty/invalid JSON from repair model with insufficient schema enforcement and fallback handling.

## Remediation Plan

- Single‑source emoji/tone constraints
  - Resolve once in `generate()`; set final `options['emoji']` and propagate everywhere.
  - Validator and prompt must both read the resolved option only (no separate defaults).
- Strengthen repair
  - Use a JSON‑stable model/route with lower temperature for repair.
  - Enforce schema strictly; on invalid JSON: retry once, then fallback.
  - Fallback order: emoji‑sanitized original draft → original draft.
  - Record flags: `repair_failed: true`, `fallback_used: true` in snapshot/metrics.
- Improve debugging clarity
  - Persist raw prompts for debugging (even truncated): `raw_system_prompt`, `raw_user_prompt` attached to snapshot or log.
  - Rename snapshot field from `user_context` to something explicit (e.g., `strategy_user_context`) to avoid confusion; keep BC alias if needed.

## Acceptance Criteria

- Empty final content cannot occur unless input draft was empty and repair/fallback paths exhausted; such cases are marked with `repair_failed` and `fallback_used`.
- Validator emoji decision matches prompt constraint and resolved `options['emoji']` in 100% of calls.
- Replay via `--via-generate` shows consistent, non‑empty outputs for the previously failing snapshot(s).
- Logs include at least one entry for initial draft, validation failure, repair attempt, and fallback decision.

## Notes

- Replace any real names in sample logs with redactions when sharing externally.
- Storing raw prompts may be size‑limited; truncate thoughtfully and include hashes for correlation.

## References

- `app/Services/Ai/ContentGeneratorService.php`
- `app/Services/Ai/PostValidator.php`
- `app/Services/Ai/LLMClient.php`
- `app/Services/Ai/SchemaValidator.php`
- `app/Console/Commands/ReplaySnapshot.php`

