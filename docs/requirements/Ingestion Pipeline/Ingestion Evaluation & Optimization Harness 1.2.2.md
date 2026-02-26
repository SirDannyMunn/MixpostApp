This is the **Optimization & Hardening Specification**.

Now that the Evaluation Harness is trusted and the "Hallucination Loop" is closed, we transition from **Repair Mode** to **Optimization Mode**. The goal is no longer to "fix the test," but to use the test to **fix the retrieval performance** and lock in stability.

---

# Engineering Spec: Retrieval Optimization & Regression Hardening

## 1. Executive Summary

**Current Status:** The system is functionally correct. Normalization is faithful. Generation uses injected context correctly.
**The Bottleneck:** Retrieval recall is objectively weak (1/3 hits). Diagnostics prove the correct chunks are vector-aligned (Top-1) but are being filtered out by overly aggressive thresholds.

**Objective:**

1. **Tune Retrieval:** Use the new "Glass Box" data to calibrate `config/ai.php` thresholds, aiming for 3/3 recall on the standard fixture.
2. **Harden Regression:** Formalize the success metrics into CI/CD-ready exit codes.
3. **Scale Testing:** Introduce a multi-fixture suite to prevent overfitting to `factual_short.txt`.

---

## 2. Optimization A: Retrieval Threshold Calibration

*The harness proved that correct chunks exist but are being silenced. We must relax the filter without admitting noise.*

### 2.1 The Calibration Loop

**Target:** `config/ai.php` -> `retriever.strict_distance_threshold`

1. **Analyze the Misses:**
* Review the latest `report.json` under `synthetic_qa.details`.
* Identify the `diagnostics.nearest_neighbor.distance` for the missed questions.
* *Example:* If missed chunks have distances of `0.38` and `0.41`, and the current limit is `0.35`, the system is blinding itself.


2. **Adjust & Verify:**
* **Action:** Bump `strict_distance_threshold` to `0.42` (approx. +10% margin over the observed miss).
* **Constraint:** Ensure `fallback_distance_threshold` is at least `0.50` to catch outliers.
* **Run Eval:** `php artisan ai:ingestion:eval ...`
* **Success Metric:** `synthetic_qa.hits` increases to 3/3.


3. **Noise Check (Generation Probe Guardrail):**
* Because Phase C (Generation Probe) is now trusted, we can safely relax retrieval. If we relax it too much and retrieve garbage, Phase C will fail (hallucination or wrong answer).
* *Logic:* "If Phase C passes, the retrieval noise was acceptable."



---

## 3. Optimization B: Regression Hardening (CI/CD)

*Ensure no future commit breaks the "Override Wiring" or "Normalization Fidelity" again.*

### 3.1 Strict Exit Codes

Update the `IngestionEvaluationService` to return specific exit codes for automated pipelines.

| Metric | Threshold | Action |
| --- | --- | --- |
| **Faithfulness** | `< 1.0` (Any violation) | **FAIL (Exit 1)**. Zero tolerance for hallucination in normalization. |
| **Generation Probe** | `< 66%` (2/3) | **FAIL (Exit 1)**. The system must be usable. |
| **Retrieval Recall** | `< 33%` (1/3) | **WARN (Exit 0)**. Low recall is a tuning issue, not a code bug. |
| **Artifacts** | `embedded != total` | **FAIL (Exit 1)**. Data integrity failure. |

### 3.2 The "Payload Shape" Helper

The feedback noted the manual construction of the override array (`['type' => 'reference']`) was the key fix. This is currently "magic code" inside the probe.

**Action:** Refactor this into a reusable helper to prevent regression.

* **Create:** `Tests/Helpers/ContextPayloadFactory.php`
* **Method:** `makeVipKnowledgeReference(string $id, string $content)`
* **Usage:** The Probe calls this helper instead of raw array construction. This ensures if `ContentGeneratorService` changes its contract, we fix it in one place.

---

## 4. Optimization C: Test Suite Expansion

*Prevent overfitting to "factual_short.txt".*

Add two new standard fixtures to the `docs/fixtures/ingestion/` folder:

1. **`conflicting_viewpoints.txt`**
* *Content:* Two paragraphs expressing opposite views on a topic (e.g., "Remote Work is Good" vs "Remote Work is Bad").
* *Goal:* Verify Normalization creates distinct chunks and Generation doesn't merge them into incoherent mush.


2. **`noisy_format.md`**
* *Content:* A document with heavy Markdown, broken lists, and headers.
* *Goal:* Verify Normalization strips formatting noise without losing semantic meaning.



---

## 5. Immediate Action Plan

1. **Analyze:** Open your last `report.json` and read the `distance` values for the missed retrieval items.
2. **Tune:** Edit `config/ai.php` and raise the `retriever.strict_distance_threshold` to cover those distances.
3. **Verify:** Run `php artisan ai:ingestion:eval` again.
* *Expectation:* Retrieval hits = 3/3. Generation Probe = 3/3.


4. **Commit:** This config change is the final "Production Optimization."
