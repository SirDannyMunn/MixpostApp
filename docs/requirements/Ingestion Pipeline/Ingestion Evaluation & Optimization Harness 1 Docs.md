
## 1️⃣ Phase 1 Evaluation Criteria Specification

**File:** `docs/ingestion-eval/phase-1-evaluation-criteria.md`

```md
# Phase 1 Ingestion Evaluation Criteria

## Purpose
This document defines what “good ingestion” means in Phase 1.
It is the authoritative contract for evaluation, scoring, and acceptance.

Phase 1 evaluates **correctness and retrievability**, not agent reasoning.

---

## Evaluation Dimensions

### 1. Faithfulness (HARD GATE)
**Definition**
All normalized claims must be directly supported by the source document.

**Failure Conditions**
- Any claim introduces facts not present in the source
- Any claim contradicts the source
- Any claim materially overstates certainty

**Outcome**
- Any failure → Phase 1 FAIL

---

### 2. Coverage (0–10)
**Definition**
How completely the ingestion captures primary factual content.

**Scoring Guide**
- 9–10: All primary facts captured
- 6–8: Minor omissions
- 3–5: Major omissions
- 0–2: Most key facts missing

---

### 3. Atomicity (0–10)
**Definition**
Each chunk expresses one factual claim.

**Failure Indicators**
- Multiple independent claims merged into one chunk
- Lists collapsed into prose

---

### 4. Noise (0–10)
**Definition**
Absence of vague, speculative, or low-value chunks.

**Noise Examples**
- “Experts believe…”
- “It is thought that…”
- Redundant paraphrases

---

### 5. Role Accuracy (0–10)
**Definition**
Correct classification of chunk_role.

**Examples**
- Metrics → metric
- Confirmed events → belief_high
- Speculation → belief_medium

---

### 6. Retrieval Readiness (0–10)
**Definition**
Chunks are phrased to be retrievable via natural queries.

**Signals**
- Proper nouns preserved
- Clear predicates (“X was captured”)
- Avoids pronouns without referents

---

## Overall Scoring
Faithfulness is a gate.
All other scores are advisory but tracked for regression.
```

---

## 2️⃣ Faithfulness Audit Prompt

**File:** `docs/ingestion-eval/prompts/faithfulness-audit.md`

```md
SYSTEM:

You are performing a faithfulness audit of normalized knowledge claims.

INPUTS:
1. Original source document
2. List of normalized claims

TASK:
Identify any claim that:
- Introduces facts not present in the source
- Contradicts the source
- Overstates certainty beyond the source

Be strict. If a claim is not clearly supported, flag it.

OUTPUT:
Return STRICT JSON only using the provided schema.
Do not include commentary.
```

---

## 3️⃣ Faithfulness Schema

**File:** `docs/ingestion-eval/schemas/faithfulness.schema.json`

```json
{
  "type": "object",
  "required": ["faithfulness_score", "hallucinations_detected"],
  "properties": {
    "faithfulness_score": {
      "type": "number",
      "minimum": 0,
      "maximum": 1
    },
    "hallucinations_detected": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["claim_id", "issue", "explanation"],
        "properties": {
          "claim_id": { "type": "string" },
          "issue": {
            "type": "string",
            "enum": ["invented_fact", "contradiction", "overstatement"]
          },
          "explanation": { "type": "string" }
        }
      }
    }
  }
}
```

---

## 4️⃣ Semantic Drift Heuristic

**File:** `docs/ingestion-eval/semantic-drift.md`

```md
# Semantic Drift Heuristic

## Purpose
Detect normalized claims whose meaning has drifted too far from the source.

## Method
- Embed the source paragraph(s)
- Embed the normalized claim
- Compute cosine similarity

## Thresholds

| Similarity | Interpretation |
|----------|----------------|
| ≥ 0.75 | Acceptable |
| 0.70–0.74 | Drift warning |
| < 0.70 | High drift |
| < 0.55 | Probable hallucination |

## Reporting
- Drift does not automatically fail Phase 1
- Drift < 0.55 should be reviewed manually
```

---

## 5️⃣ Synthetic QA Specification

**File:** `docs/ingestion-eval/synthetic-qa.md`

```md
# Synthetic QA – Phase 1

## Purpose
Provide objective retrieval tests for ingested knowledge.

## Rules
- Generate 2–3 questions
- Each question:
  - Answerable from a single chunk
  - Factual (who/what/when)
  - No synthesis or opinion

## Output Schema

{
  "question": "string",
  "expected_answer_summary": "string",
  "target_chunk_ids": ["uuid"]
}

## Evaluation
- Retrieval only (no generation)
- k = 3
- Record:
  - rank of first target chunk
  - -1 if not retrieved

## Phase 1 Pass Condition
At least one question retrieved in top-3.
```

---

# 📁 `docs/fixtures/ingestion/`

These are **actual test documents**, not placeholders.

---

## `factual_short.txt`

```txt
On January 3, 2026, U.S. forces conducted a military operation inside Venezuela.
Nicolás Maduro and his wife, Cilia Flores, were captured during the operation.
The U.S. government stated the operation targeted leadership involved in drug trafficking.
No Venezuelan ministers were confirmed captured.
```

---

## `factual_long.txt`

```txt
On January 3, 2026, the United States carried out a military operation within Venezuelan territory.
According to U.S. officials, the operation resulted in the capture of President Nicolás Maduro and First Lady Cilia Flores.

Defense Minister Vladimir Padrino López appeared in a video hours later, confirming he was alive and not detained.
Reports circulating on social media claimed that several senior officials had been killed, but these claims remain unverified.

U.S. authorities stated the operation was conducted due to allegations of drug trafficking involving senior members of the Venezuelan government.
At least 110 people were reported killed during strikes on boats associated with the operation.
```

---

## `mixed_opinion.txt`

```txt
The U.S. operation in Venezuela marks a significant escalation in foreign policy.
President Nicolás Maduro was reportedly captured, though some analysts urge caution.

Supporters argue the operation was necessary to combat drug trafficking.
Critics claim it violates international law and could destabilize the region.

Defense Minister Vladimir Padrino López later released a video denying reports of his death.
```

---

## `ambiguous_claims.txt`

```txt
Reports on social media suggest that multiple Venezuelan officials were killed during the U.S. operation.
Some sources claim Defense Minister Vladimir Padrino López is dead, while others say he appeared in a video afterward.

There has been no official confirmation regarding the status of Interior Minister Diosdado Cabello.
The U.S. government has not released a full list of individuals detained or killed.
```

---

## `edge_case_bullets.txt`

```txt
Key facts:
- Date: January 3, 2026
- Location: Venezuela
- Operation conducted by: United States
- Captured: Nicolás Maduro, Cilia Flores
- Casualties: at least 110 reported

Unconfirmed:
- Deaths of senior Venezuelan officials
- Capture of cabinet ministers
```

---

## Fixture README

**File:** `docs/fixtures/ingestion/README.md`

```md
# Ingestion Test Fixtures

These documents are used to validate Phase 1 ingestion behavior.

## Expectations
- factual_short.txt → clean atomic claims, high faithfulness
- factual_long.txt → normalization + multiple claims
- mixed_opinion.txt → facts separated from opinion
- ambiguous_claims.txt → belief_medium classification
- edge_case_bullets.txt → bullet handling without claim merging

Hallucination of any new facts is a failure.
```

---

# 6️⃣ Phase 1 Acceptance Checklist

**File:** `docs/ingestion-eval/phase-1-acceptance.md`

```md
# Phase 1 Acceptance Checklist

Phase 1 is complete when:

- [ ] No hallucinations detected
- [ ] Faithfulness score ≥ 0.9
- [ ] Semantic drift warnings ≤ 1
- [ ] ≥ 1 synthetic QA hit @ k=3
- [ ] No chunk contains >2 factual claims
- [ ] All high-confidence facts correctly classified

Failure on any blocking item halts progression to Phase 2.
```

---

## Final State Check

With these documents:

✅ Phase 1 is **fully specified**
✅ Your existing Artisan command can now be **meaningfully tested**
✅ You have objective regression baselines
✅ You are safe to proceed to Phase B (LLM evaluation) next

Below are the **six documents you need**, written in a form you can **drop straight into your repo** (e.g. `docs/ingestion-eval/`).
They are intentionally **concise, test-oriented, and operational**, not academic.

I am not rewriting the engineering spec.
This is the **evaluation contract** that makes Phase 1 *real*.

---

# 1. Phase 1 Evaluation Criteria Specification

**File:** `docs/ingestion-eval/phase-1-evaluation-criteria.md`

## Purpose

Define what “good ingestion” means in Phase 1.
These criteria govern LLM evaluation, human review, and regression testing.

## Evaluation Dimensions (Phase 1)

### 1. Coverage (0–10)

**Definition:**
How well the ingested chunks capture the *material facts* present in the source.

**Signals**

* Missing major factual claims → score ↓
* Trivial or redundant facts → neutral
* All primary claims present → score ↑

**Failure**

* > 30% of primary factual content missing

---

### 2. Atomicity (0–10)

**Definition:**
Each chunk should express **one claim or fact**.

**Signals**

* Multiple facts merged → score ↓
* Clean single-claim chunks → score ↑

**Failure**

* Any chunk containing ≥3 distinct claims

---

### 3. Noise (0–10)

**Definition:**
Absence of low-value, vague, or speculative chunks.

**Signals**

* Generic phrasing (“experts believe…”) → score ↓
* Clearly actionable facts → score ↑

**Failure**

* > 25% of chunks marked low-confidence *without necessity*

---

### 4. Role Accuracy (0–10)

**Definition:**
Correct assignment of `chunk_role`.

**Signals**

* Metrics labeled as beliefs → score ↓
* Strategic vs causal distinctions preserved → score ↑

**Failure**

* Misclassification of ≥2 high-confidence chunks

---

### 5. Faithfulness (0–10) **(Hard Gate)**

**Definition:**
Normalized claims must not invent, contradict, or overstate the source.

**Failure**

* Any hallucinated claim → automatic Phase 1 failure

---

### 6. Retrieval Readiness (0–10)

**Definition:**
Chunks are phrased in a way that makes them retrievable via natural queries.

**Signals**

* Proper nouns preserved
* Clear predicates (“X was captured”) → score ↑

---

## Overall Score

Weighted mean (Phase 1 default):

| Dimension           | Weight |
| ------------------- | ------ |
| Faithfulness        | Gate   |
| Coverage            | 0.25   |
| Atomicity           | 0.20   |
| Retrieval Readiness | 0.20   |
| Role Accuracy       | 0.15   |
| Noise               | 0.20   |

---

# 2. Faithfulness Audit Prompt + Schema

**Files:**

* `docs/ingestion-eval/prompts/faithfulness-audit.md`
* `docs/ingestion-eval/schemas/faithfulness.schema.json`

## Prompt (System)

> You are auditing knowledge normalization.
>
> Given:
>
> * the original source text
> * a list of normalized claims
>
> Identify any claim that:
>
> * introduces facts not present in the source
> * contradicts the source
> * overstates certainty
>
> Be strict. Return JSON only.

## Output Schema

```json
{
  "faithfulness_score": 0.0,
  "hallucinations_detected": [
    {
      "claim_id": "uuid",
      "issue": "invented_fact | contradiction | overstatement",
      "explanation": "short explanation"
    }
  ]
}
```

## Rules

* `hallucinations_detected.length > 0` ⇒ Phase 1 FAIL
* Score is advisory; hallucinations are blocking

---

# 3. Semantic Drift Heuristic Definition

**File:** `docs/ingestion-eval/semantic-drift.md`

## Purpose

Detect subtle meaning drift not caught by LLM audit.

## Method

1. Embed:

   * Source paragraph(s)
   * Normalized claim
2. Compute cosine similarity

## Thresholds

| Similarity | Action                              |
| ---------- | ----------------------------------- |
| ≥ 0.75     | OK                                  |
| 0.70–0.75  | Warning (`drift_warning`)           |
| < 0.70     | Flag (`high_drift`)                 |
| < 0.55     | Critical (`probable_hallucination`) |

## Reporting

Drift flags **do not fail** Phase 1 alone, but reduce scores and surface warnings.

---

# 4. Synthetic QA Specification (Phase 1 – Minimal)

**File:** `docs/ingestion-eval/synthetic-qa.md`

## Purpose

Create objective retrieval tests.

## Generation Rules

* Generate **2–3 questions**
* Each question:

  * answerable from **one chunk**
  * factual (who / what / when)
  * no synthesis or opinion

## Output Schema

```json
{
  "question": "string",
  "expected_answer_summary": "string",
  "target_chunk_ids": ["uuid"]
}
```

## Evaluation Metric

* Retrieval only (no answer generation)
* k = 3
* Record:

  * retrieved rank of first target chunk
  * -1 if missing

## Phase 1 Success

* ≥1 question retrieved @ k=3

---

# 5. Phase 1 Test Fixture Pack

**Folder:** `docs/fixtures/ingestion/`

## Required Fixtures

```
factual_short.txt        # clear facts, short
factual_long.txt         # long-form article
mixed_opinion.txt        # facts + commentary
ambiguous_claims.txt     # uncertainty + rumors
edge_case_bullets.txt    # lists, bullets, fragments
```

## Each Fixture Must Include (README.md)

```md
Expected:
- Primary facts: [...]
- Expected chunk count: ~N
- Known ambiguities: [...]
- Must-not-hallucinate: [...]
```

These fixtures become your **baseline regression set**.

---

# 6. Phase 1 Acceptance Checklist

**File:** `docs/ingestion-eval/phase-1-acceptance.md`

## Phase 1 Is Complete When

* [ ] No hallucinations detected
* [ ] Faithfulness score ≥ 0.9
* [ ] Semantic drift warnings ≤ 1
* [ ] ≥ 1 synthetic QA hit @ k=3
* [ ] No chunk contains >2 claims
* [ ] All high-confidence chunks correctly typed

Failure on **any blocking item** halts progression to Phase 2.

---

# Final Reality Check

Your developer delivered the **execution engine**.

These six documents deliver:

* correctness guarantees
* objective metrics
* regression safety
* confidence to move forward

Without them, Phase 1 *runs* — but it does not *mean* anything.

---

## Next Highest-Leverage Step

If you want to proceed incrementally, do this next:

1. Implement **Faithfulness Audit**
2. Implement **Synthetic QA (retrieval-only)**
3. Run against fixture pack

---

# 📁 `docs/ingestion-eval/`

---

