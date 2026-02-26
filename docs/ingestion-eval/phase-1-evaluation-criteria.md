# Phase 1 Ingestion Evaluation Criteria

## Purpose
Define what “good ingestion” means in Phase 1. Authoritative contract for evaluation, scoring, and acceptance. Phase 1 evaluates correctness and retrievability, not agent reasoning.

---

## Evaluation Dimensions

### 1. Faithfulness (HARD GATE)
Definition: All normalized claims must be directly supported by the source document.

Failure Conditions
- Any claim introduces facts not present in the source
- Any claim contradicts the source
- Any claim materially overstates certainty

Outcome
- Any failure → Phase 1 FAIL

### 2. Coverage (0–10)
Definition: How completely the ingestion captures primary factual content.
Scoring Guide
- 9–10: All primary facts captured
- 6–8: Minor omissions
- 3–5: Major omissions
- 0–2: Most key facts missing

### 3. Atomicity (0–10)
Definition: Each chunk expresses one factual claim.
Failure Indicators
- Multiple independent claims merged into one chunk
- Lists collapsed into prose

### 4. Noise (0–10)
Definition: Absence of vague, speculative, or low-value chunks.
Noise Examples
- “Experts believe …”
- “It is thought that …”
- Redundant paraphrases

### 5. Role Accuracy (0–10)
Definition: Correct classification of chunk_role.
Examples
- Metrics → metric
- Confirmed events → belief_high
- Speculation → belief_medium

### 6. Retrieval Readiness (0–10)
Definition: Chunks are phrased to be retrievable via natural queries.
Signals
- Proper nouns preserved
- Clear predicates (“X was captured”)
- Avoids pronouns without referents

## Overall Scoring
Faithfulness is a gate. All other scores are advisory but tracked for regression.

