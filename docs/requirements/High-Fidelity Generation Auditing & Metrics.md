This requirements document establishes a high-fidelity audit and analytics layer for the generation pipeline. By capturing the exact prompts sent and the technical metrics of the execution, you transform the system from a "black box" into a transparent, data-driven engine.

---

# Engineering Spec: High-Fidelity Generation Auditing & Metrics

**Priority:** High

**Objective:** Capture point-in-time execution data (Final Prompts) and performance metrics (Tokens/Latency/Repairs) to enable 100% auditability and financial tracking.

---

## 1. Database Schema Refactor

We will use the **"Wide Table"** approach with specialized JSON columns for the metrics to maintain high query performance without excessive table complexity.

**Table:** `generation_snapshots`

### 1.1 New Execution Columns (Auditability)

| Column Name | Type | Description |
| --- | --- | --- |
| `final_system_prompt` | `LONGTEXT` | The exact system string with all voice signatures and constraints baked in. |
| `final_user_prompt` | `LONGTEXT` | The exact user string with knowledge, facts, and the overridden tone line. |

### 1.2 New Metric Columns (Business Intelligence)

| Column Name | Type | Key Data Points Included |
| --- | --- | --- |
| `token_metrics` | `JSON` | `prompt_tokens`, `completion_tokens`, `total_tokens`, `estimated_cost` |
| `performance_metrics` | `JSON` | `total_latency_ms`, `provider_latency_ms`, `model_identifier` |
| `repair_metrics` | `JSON` | `repair_count`, `repair_types` (array), `initial_validation_score`, `repair_log` |

---

## 2. Pipeline Refactor Requirements

### 2.1 Prompt Capture (`ContentGeneratorService`)

The service must extract the final strings from the `Prompt` DTO immediately after composition and before the LLM call.

1. Execute `$promptObj = $this->composer->composePostGeneration(...)`.
2. Store `$promptObj->system` as `final_system_prompt`.
3. Store `$promptObj->user` as `final_user_prompt`.

### 2.2 Metrics Collection Logic

The pipeline must be updated to track and pass the following objects to the `SnapshotPersister`:

* **Tokens:** Captured from the `LLMClient` or `Runner` response metadata.
* **Latency:** A timer must start at the beginning of `generate()` and stop after `validateAndRepair()`.
* **Repairs:** The `ValidationAndRepairService` must return a "Repair Result" DTO containing the count and the issues found.

---

## 3. Detailed Data Object Definitions

### 3.1 `token_metrics`

* **`prompt_tokens`**: Total tokens in the input.
* **`completion_tokens`**: Total tokens in the output.
* **`total_tokens`**: Sum of both.
* **`estimated_cost`**: Calculated locally ().

### 3.2 `performance_metrics`

* **`total_latency_ms`**: Wall-clock time for the entire request.
* **`provider_latency_ms`**: Time spent waiting for the API response only.
* **`model_identifier`**: The precise model version string (e.g., `anthropic/claude-3.5-sonnet:beta`).

### 3.3 `repair_metrics`

* **`repair_count`**: Number of times the AI was asked to fix a draft.
* **`repair_types`**: Array of issue slugs (e.g., `['length_exceeded', 'missing_json_key']`).
* **`initial_validation_score`**: The quality score assigned to the raw draft before any repairs.
* **`repair_log`**: A text or JSON log of the specific feedback provided to the AI during repair.

---

## 4. Snapshot Persistence Update

The `SnapshotPersister->persistGeneration()` method must be updated to handle the new inputs.

**Updated Method Signature:**

```php
public function persistGeneration(
    // ... Existing Core IDs (orgId, userId, platform) ...
    string $finalSystemPrompt,
    string $finalUserPrompt,
    array $tokenUsage,    // Array containing prompt, completion, total, cost
    array $performance,   // Array containing latency, model_identifier
    array $repairInfo     // Array containing count, types, log, initial_score
): string;

```

---

## 5. Verification & Acceptance Criteria

1. **Immutability Test:** Verify that once a snapshot is saved, the `final_user_prompt` reflects the **overridden** tone line, not the raw business context.
2. **Auditability Test:** Copy the `final_system_prompt` and `final_user_prompt` into a 3rd party playground (like OpenRouter or OpenAI) and verify it generates a similar result.
3. **Financial Test:** Run a SQL query to calculate the average `estimated_cost` for posts generated using a specific Template ID.
4. **Stability Test:** Identify the "Top 3 Templates" triggering the highest `repair_count` over a 7-day period.

---

### Deployment Step for the Developer

1. **Run Migration:** Create the `add_metrics_and_final_prompts_to_snapshots` migration.
2. **Update DTOs:** Ensure `Prompt` and `GenerationContext` can pass these new fields.
3. **Update Persister:** Modify the `SnapshotPersister` to map these to the model attributes.
4. **Refactor Service:** Ensure the timer and token collector are active in the main generation loop.
