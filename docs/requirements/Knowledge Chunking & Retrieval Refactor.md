# Knowledge Chunking & Retrieval Refactor — Engineering Spec

## 1. Purpose

Refactor the knowledge ingestion, chunking, and retrieval pipeline to ensure that **only semantically complete, context‑rich, and retrieval‑aligned knowledge artifacts** are embedded and retrieved.

The goal is to eliminate low‑signal fragments (URLs, memes, reactions), enrich normalized knowledge with domain context, and make retrieval intent‑aware rather than prompt‑literal.

This spec replaces the current “chunk first, classify later” approach with a **knowledge compiler** model.

---

## 2. Problems Being Solved

### Current Failures

* Raw fragments are embedded and pollute vector space
* Normalized claims lack subject, timeframe, and domain context
* Classification decorates bad chunks instead of gating them
* Raw and normalized chunks compete equally in retrieval
* Retrieval uses the user’s literal prompt instead of an intent‑expanded query

### Non‑Goals

* Changing embedding models
* Tuning vector similarity thresholds
* Adding more data sources

---

## 3. High‑Level Architecture Changes

### Before

```
raw_text
  ↓
chunking (raw)
  ↓
store chunks
  ↓
classify
  ↓
embed
  ↓
retrieve
```

### After

```
raw_text
  ↓
semantic gating
  ↓
normalization (LLM)
  ↓
context enrichment
  ↓
knowledge validation
  ↓
store normalized knowledge
  ↓
embed
  ↓
intent‑aware retrieval
```

---

## 4. Knowledge Artifact Model

### KnowledgeChunk (Revised Semantics)

Only **normalized knowledge artifacts** are embeddable and retrievable.

| Field          | Purpose                                    |
| -------------- | ------------------------------------------ |
| chunk_text     | Fully self‑contained knowledge statement   |
| chunk_role     | Semantic role (enum)                       |
| authority      | Source confidence                          |
| confidence     | LLM confidence score                       |
| time_horizon   | Temporal relevance                         |
| domain         | Primary knowledge domain (new)             |
| actor          | Subject / entity the claim is about (new)  |
| source_variant | Always `normalized` for retrievable chunks |

Raw chunks are never stored as `KnowledgeChunk`.

---

## 5. Semantic Gating Rules (Hard Stop)

Before normalization, candidate text MUST pass gating.

### Rejection Conditions

Reject input if **any** of the following is true:

* Token count < 12 (excluding URLs)
* > 50% URL or emoji content
* No verb detected
* No domain noun detected

### Accepted Domains (initial)

* SEO
* Content marketing
* SaaS
* Monetization
* Growth
* Business strategy

Failed inputs are discarded permanently.

---

## 6. Normalization Pipeline (LLM)

### Input

A gated raw text block.

### Output Schema (Required)

```json
{
  "claim": "<single, complete statement>",
  "context": {
    "domain": "SEO | SaaS | Content | ...",
    "actor": "author | company | product | platform",
    "timeframe": "explicit date | inferred | unknown",
    "scope": "tactical | strategic | philosophical"
  },
  "role": "strategic_claim | metric | heuristic | instruction | definition | causal_claim",
  "confidence": 0.0,
  "authority": "high | medium | low"
}
```

### Enrichment Rules

The LLM MUST:

* Add implied subject if missing
* Expand metrics with timeframe and context
* Disambiguate vague references

Example transformation:

**Before**

```
Mediavine revenue was $3,757.11
```

**After**

```
In December 2025, the author reported $3,757.11 in Mediavine ad revenue from SEO‑driven content sites.
```

---

## 7. Knowledge Validation (Post‑LLM)

Before persistence, enforce:

* `chunk_text` ≥ 20 tokens
* Contains domain keyword
* Contains actor
* Role ∈ allowed enum

Failures are logged and discarded.

---

## 8. Storage Rules

### Persisted

* Normalized, enriched, validated knowledge only
* `source_variant = normalized`
* Embedding generated immediately
* **Raw LLM normalization output JSON persisted for debugging** (see 8.1)

### Never Persisted

* Raw chunks
* URL-only text
* CTAs
* Quotes without analytical framing

---

## 8.1 LLM Raw Output Persistence (Debugging)

### Purpose

Persist the *exact* raw JSON returned by the LLM during normalization to enable:

* Debugging hallucinations or omissions
* Auditing enrichment decisions
* Comparing prompt or model changes over time
* Offline reprocessing without re-calling the LLM

This data is **non-retrievable**, **non-embedded**, and **never used directly in generation**.

### Storage Location

Option A (preferred): new table

**`knowledge_llm_outputs`**

| Column            | Type      | Description                           |
| ----------------- | --------- | ------------------------------------- |
| id                | uuid      | Primary key                           |
| knowledge_item_id | uuid      | Source item                           |
| model             | string    | Model identifier                      |
| prompt_hash       | string    | Hash of normalization prompt          |
| raw_output        | json      | Exact LLM JSON response               |
| parsed_output     | json      | Parsed/validated structure (optional) |
| created_at        | timestamp | When generated                        |

Option B (acceptable): JSON column on `knowledge_items`

* `normalized_llm_output` (json)

Option A is recommended to avoid bloating hot tables and to support multiple normalization passes.

### Write Rules

* Persist **before** validation or enrichment
* Persist even if validation fails
* Never mutate after write (append-only)

### Read Rules

* Never used by retriever
* Never injected into generation context
* Accessible only via admin/debug tooling

---

## 9. Retrieval Refactor

### Step 1: Intent Classification

From user prompt:

* intent (educational / persuasive / contrarian / etc.)
* domain
* funnel stage

### Step 2: Query Expansion

Construct retrieval query using:

* Prompt keywords
* Inferred domain terms
* Role‑specific boosters

Example:

User prompt:

```
Write about Google punishing AI content
```

Expanded query:

```
AI content
SEO penalties
Google ranking signals
content quality guidelines
search algorithm updates
```

### Step 3: Retrieval Scoring

Apply weighted scoring:

| Factor            | Weight |
| ----------------- | ------ |
| Vector similarity | 0.5    |
| Domain match      | 0.2    |
| Role priority     | 0.2    |
| Authority         | 0.1    |

Exclude:

* `quote`
* `other`

---

## 10. Role Priority Order

1. definition
2. strategic_claim
3. heuristic
4. causal_claim
5. instruction
6. metric

Quotes are non‑retrievable by default.

---

## 11. Migration Plan

1. Disable embedding on raw chunks immediately
2. Stop persisting raw chunks
3. Add new normalization schema
4. Re‑ingest high‑value sources
5. Delete existing raw embeddings
6. Rebuild vector index

---

## 12. Observability

### Metrics

* % of gated‑out inputs
* Avg tokens per stored chunk
* Retrieval hit relevance score
* Knowledge usage per generation

### Logs

* Gating rejections (reason)
* Normalization failures
* Validation failures

---

## 13. Success Criteria

* Retrieval returns context‑aligned knowledge for ≥80% of prompts
* No URL‑only or meme content in retrieval
* Fewer, denser, higher‑signal chunks
* Predictable retrieval behavior

---

## 14. Summary

This refactor converts the system from a **text fragment store** into a **knowledge compiler**.

Meaning is created **before** embedding.
Retrieval operates on **intent**, not strings.

Without this, no amount of model tuning will fix relevance.
