# Engineering Spec: Relevance Gating (Point 2) & Fact / Angle Separation (Point 3)

This spec addresses two structural failures identified in the content generation system:

* **Point 2**: Knowledge chunks are injected without proving relevance to the prompt.
* **Point 3**: Opinionated strategic angles are treated as mandatory facts.

The changes below intentionally sit *between retrieval and prompt assembly* and do not require changes to ingestion jobs initially.

---

## A. Relevance Gating (Point 2)

### Goal

Only inject knowledge chunks that are **necessary** to answer the user’s prompt, not merely semantically similar.

This prevents tangential but high‑confidence chunks from dominating output.

---

### Current State (Problem)

* Retriever returns Top‑K chunks by vector similarity.
* All returned chunks are passed to `ContextFactory`.
* Prompt instructions force usage of injected chunks.

Result: similarity → injection → forced paraphrase.

---

### Proposed Architecture

Add a **Relevance Gate** immediately after retrieval and before context assembly.

```
Prompt → Classification → Retrieval → RelevanceGate → ContextFactory → PromptComposer
```

---

### Relevance Gate Contract

**Input**

* `prompt` (string)
* `classification` (intent, funnel_stage)
* `candidate_chunks[]` (retrieved knowledge chunks)

**Output**

* `accepted_chunks[]`
* `rejected_chunks[]` (for observability only)

---

### Gating Logic (Hybrid, Cheap)

Apply gates in this order:

#### 1. Hard Filters (No LLM)

Reject chunk if ANY are true:

* `chunk.confidence < 0.4`
* `chunk.authority == 'low'` AND `classification.intent == 'educational'`
* `chunk.chunk_role in ['belief_high', 'strategic_claim']` AND funnel_stage == TOF
* `chunk.token_count > max_chunk_tokens` (default: 200)

---

#### 2. Lightweight LLM Relevance Check (Optional but Recommended)

Batch chunks (5–8 at a time) and ask:

```
Given the user prompt:
"{{prompt}}"

Is this knowledge chunk NECESSARY to answer it?

Chunk:
"{{chunk_text}}"

Answer strictly as JSON:
{ "necessary": true|false }
```

Constraints:

* Use cheapest model
* Max tokens ≤ 200
* No streaming

Reject if `necessary=false`.

---

### Persistence & Observability

Add to snapshot:

```
options.relevance_gate = {
  candidates: N,
  accepted: M,
  rejected: [chunk_id, reason]
}
```

This makes retrieval failure obvious during debugging.

---

## B. Fact vs Angle Separation (Point 3)

### Goal

Stop treating **strategic opinions** as **ground truth**.

Only *facts* should be mandatory. Angles should inspire, not dominate.

---

### Current State (Problem)

Your ingestion pipeline produces chunks like:

* “Facebook Groups possess key SEO advantages…”

These are:

* Opinionated
* High‑confidence
* Injected as mandatory context

The generator cannot escape them.

---

### Proposed Chunk Taxonomy

Extend `knowledge_chunks` with:

```
chunk_kind ENUM('fact', 'angle', 'example', 'quote') DEFAULT 'fact'
```

Mapping rules:

| chunk_role      | chunk_kind |
| --------------- | ---------- |
| definition      | fact       |
| metric          | fact       |
| causal_claim    | fact       |
| belief_high     | angle      |
| belief_medium   | angle      |
| strategic_claim | angle      |
| heuristic       | angle      |
| example         | example    |
| quote           | quote      |

This can be derived deterministically during classification.

---

### Prompt Composition Rules

Update `PromptComposer` instructions:

* **Facts**: may be used verbatim
* **Angles**: optional inspiration only
* Model MUST NOT restate angles unless directly relevant

Example instruction:

> The following items marked as ANGLES are optional perspectives. Use at most one, and only if it clearly strengthens the answer.

---

### Retrieval Policy Update

Change defaults:

* Retrieve facts first
* Cap angles at `max_angles = 1`
* Never inject angles without at least one fact

---

### Snapshot Enhancements

Add:

```
options.context_breakdown = {
  facts: X,
  angles: Y,
  examples: Z
}
```

This will immediately show over‑angle contamination.

---

## Non‑Goals (Explicit)

* No ingestion rewrite yet
* No UI changes required
* No retraining or embedding changes

These fixes are **retrieval‑time only** and reversible.

---

## Expected Outcome

After implementation:

* Same prompt ≠ same post
* Knowledge informs, not dictates
* Strategic angles rotate naturally
* Irrelevant “pet ideas” stop hijacking output

This moves the system from **content factory** to **content engine**.
