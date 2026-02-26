# Engineering Spec: Ingestion Evaluation System (Final)

## 1. Executive Summary & Architecture Philosophy

**Objective:**
Transform the evaluation harness from a "Retrieval Unit Test" into a **Content Usability Evaluation System**. The system must prove that ingested content is not just *indexable*, but actually *usable* by the `ContentGeneratorService` to produce accurate outputs.

**Core Philosophy Changes:**

1. **Retrieval  Oracle:** Retrieval failure (Phase B) is treated as a diagnostic signal, not a system failure. Distance thresholds are heuristics, not binary gates.
2. **Generation is Truth (Phase C):** The authoritative test of success is whether the `ContentGeneratorService`, when forced to use the content, produces a draft that answers the prompt correctly.
3. **Production Parity:** The harness must mirror production semantics. Strict isolation filters are removed from the default path; retrieval is tested "in the wild."

---

## 2. Evaluation Hierarchy

The report and pass/fail logic will follow this strict hierarchy:

| Phase | Name | Purpose | Verdict Weight | Gating? |
| --- | --- | --- | --- | --- |
| **Phase A** | **Artifact Integrity** | Verify chunks/claims exist and are embedded. | High | **Yes (Hard Gate)** |
| **Phase B** | **Faithfulness & QA** | Audit claim accuracy and Retrieval diagnostics. | Medium (Observational) | **No** (Soft Gate) |
| **Phase C** | **Generation Probe** | Verify end-to-end content usability. | **Critical** | **Yes (Final Gate)** |

*Note: A run with 0 retrieval hits (Phase B) but perfect generation (Phase C) is considered a **PASS** (with warnings).*

---

## 3. Implementation Plan

### Milestone 1: Phase A & B Refinements (Observability)

#### 3.1 Faithfulness Audit (Strict JSON)

*Target: `App\Services\Ai\Evaluation\FaithfulnessAudit*`
Fix the "unknown status" bug by enforcing structural compliance.

* **Action:**
* Inject `response_format: { "type": "json_object" }` into the LLM call.
* Implement a `JsonRetry` loop (max 3 attempts) to handle malformed outputs.



#### 3.2 "Glass-Box" Retrieval Diagnostics (Observational)

*Target: `App\Services\Ai\Evaluation\SyntheticQA*`
Transform the "0 Hits" failure into actionable data. This step is **non-blocking**.

* **Logic:**
1. **Production-Like Retrieval:** Run `Retriever->retrieve()` without strict `knowledge_item_id` isolation.
2. **Hit Check:** If the target item is found, log `rank` and `score`.
3. **Diagnostic Fallback (If Missed):**
* Re-embed the query using the *exact same model* as the Retriever.
* Run a raw `pgvector` query scoped to the target item, ignoring all thresholds.
* **Log Only:** Record the `distance` and `chunk_text` of the top 3 candidates.
* **Do Not Fail:** Mark the step as `missed_retrieval` but continue to Phase C.





---

### Milestone 2: Phase C (The Generation Probe)

*Target: `App\Services\Ai\Evaluation\Probes\GenerationProbe*`
This is the new "Source of Truth" for the system.

#### 4.1 Integration with `ContentGeneratorService`

We must bypass the probabilistic retrieval layer to test the deterministic generation capability.

* **Mechanism:** Use the **`vipOverrides`** architecture.
* **Flow:**
1. **Input:** Takes a `KnowledgeItem` and a Question (from Synthetic QA).
2. **Execution:** Calls `ContentGeneratorService::generate()` with:
```php
[
    'vipOverrides' => [
        'knowledge' => [$knowledgeItem->id] // Forces the context assembly
    ],
    'prompt' => "Answer this question based on the context: $question",
    'platform' => 'linkedin' // Neutral default
]

```


3. **Grading:** An LLM "Grader" compares the *Generated Draft* against the *Expected Answer*.
* *Pass:* The draft definitively answers the question.
* *Fail:* The draft hallucinates or pleads ignorance.





---

## 4. Immediate Next Step

**Action:** Generate the **`GenerationProbe`** class.

This class is the missing link that connects your `IngestionEvaluationService` to your production `ContentGeneratorService`.

**Would you like me to generate the code for `GenerationProbe` now?**