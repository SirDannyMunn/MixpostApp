Below is a **clean Phase 2 Engineering Requirements Document** that assumes **Phase 1 is complete and stable** (which it is). This is written so you can hand it directly to your developer without additional context.

---

# Phase 2 Engineering Requirements

**Semantic Intelligence & Explainability Layer**

**Status:** Planned
**Depends on:** Phase 1 (Typed Chunks, Quality Scoring, Weighted Retrieval)
**Primary Goal:**
Increase *reasoning quality*, *retrieval precision*, and *explainability* without increasing generation complexity.

---

## Phase 2 Objectives (What This Unlocks)

Phase 1 made retrieval *relevant*.
Phase 2 makes retrieval *intelligent*.

Specifically, Phase 2 must:

1. Convert raw text into **machine-usable beliefs**, not paraphrasable prose
2. Improve chunk typing beyond heuristics
3. Enable **explainable retrieval** (“why did this appear?”)
4. Prevent low-signal narrative text from polluting generation
5. Prepare the system for long-term learning and belief conflict resolution

---

## Scope Overview

Phase 2 introduces **three new layers**:

1. **Semantic Normalization Pipeline**
2. **LLM-assisted Chunk Classification**
3. **Replay & Retrieval Explainability**

None of these alter the generation pipeline API.

---

## 1. Semantic Normalization Pipeline

### 1.1 Purpose

Transform raw ingested text into **atomic, reusable, non-performative knowledge units**.

This pipeline runs **before chunking and embedding** for eligible ingestion sources.

---

### 1.2 Applicable Ingestion Sources

Semantic normalization applies to:

* Pasted text / internal notes
* Long-form documents
* Transcripts (YouTube, meetings)
* Strategy docs
* Imported Notion pages

It does **NOT** apply to:

* Short bookmarks
* Social posts
* Quotes/excerpts (unless explicitly enabled)

---

### 1.3 New Pipeline Stage

Add a new job:

```
NormalizeKnowledgeItemJob
```

**Execution order**

```
IngestionSource
→ KnowledgeItem
→ NormalizeKnowledgeItemJob   ← NEW
→ ChunkKnowledgeItemJob
→ EmbedKnowledgeChunksJob
```

---

### 1.4 Semantic Normalization Responsibilities

Given `KnowledgeItem.raw_text`, the job must:

1. Remove narrative framing and examples
2. Collapse repeated ideas
3. Convert prose into **declarative statements**
4. Normalize metaphors into system concepts
5. Produce **context-independent claims**

---

### 1.5 Output Contract

Normalization produces a structured artifact stored on the KnowledgeItem:

```json
{
  "normalized_claims": [
    {
      "text": "AI increases strategic value by improving decision selection.",
      "type": "strategic_claim",
      "confidence": 0.85,
      "authority": "high"
    }
  ]
}
```

**Storage**

* New column on `knowledge_items`: `normalized_claims` (JSON)
* Original `raw_text` remains unchanged

---

### 1.6 LLM Usage Rules

* One-shot LLM call
* No embeddings
* Deterministic prompt
* JSON-only response
* Model must not invent facts

---

## 2. LLM-Assisted Chunk Role Classification

### 2.1 Purpose

Replace heuristic chunk typing with **semantic classification**.

This improves:

* Retrieval ranking
* Reasoning quality
* Explainability

---

### 2.2 New Job

```
ClassifyKnowledgeChunksJob
```

Runs **after chunk creation, before embedding**.

---

### 2.3 Classification Targets

Each chunk must be classified into:

**Roles**

* `belief_high`
* `belief_medium`
* `definition`
* `heuristic`
* `strategic_claim`
* `causal_claim`
* `instruction`
* `metric`
* `example`
* `quote`

**Additional fields**

* `authority`: high | medium | low
* `confidence`: 0.0–1.0
* `time_horizon`: current | near_term | long_term | unknown

---

### 2.4 Input to Classifier

Each chunk is evaluated **independently**, but with minimal context:

```json
{
  "chunk_text": "...",
  "source_type": "text",
  "normalized_claim": true,
  "ingestion_quality": 0.78
}
```

---

### 2.5 Output Contract

```json
{
  "chunk_role": "strategic_claim",
  "authority": "high",
  "confidence": 0.9,
  "time_horizon": "long_term"
}
```

Results overwrite heuristic defaults.

---

### 2.6 Performance Constraints

* Batch classification (e.g. 10–20 chunks per call)
* Cached per chunk hash
* Safe retry on failure (fallback to heuristic)

---

## 3. Retrieval Explainability & Replay

### 3.1 Purpose

Allow developers and power users to answer:

> “Why did this generation use this knowledge?”

---

### 3.2 Retrieval Trace Storage

Extend retrieval to optionally emit:

```json
{
  "chunk_id": "...",
  "score": 0.742,
  "similarity": 0.81,
  "weights": {
    "quality": 1.12,
    "role": 1.10,
    "authority": 1.05,
    "time": 0.98
  }
}
```

---

### 3.3 Snapshot Enhancements

Extend generation snapshots to store:

* Ranked chunks with scores
* Applied weights
* Source provenance
* Ingestion source ID

No UI required yet; API-level only.

---

### 3.4 New Debug APIs (Internal)

* `GET /api/debug/generation/{id}/retrieval`
* `GET /api/debug/chunk/{id}/provenance`

Access controlled (admin / dev only).

---

## 4. Configuration & Control

### 4.1 Weight Configuration

Move hardcoded weights into config:

```php
config('retrieval.weights.role.belief_high')
```

Allow fast calibration without redeploy.

---

### 4.2 Pipeline Flags

Each ingestion source may specify:

```json
{
  "use_normalization": true,
  "use_llm_classification": true
}
```

Defaults defined per source type.

---

## 5. Non-Goals (Explicitly Out of Scope)

Phase 2 does **not** include:

* Belief conflict resolution
* Cross-source contradiction detection
* Automatic knowledge pruning
* User-facing explanation UI
* Multi-hop reasoning chains

These are Phase 3+ concerns.

---

## 6. Acceptance Criteria

Phase 2 is complete when:

* Normalized claims exist and are chunked
* Chunks have semantic roles beyond heuristics
* Retrieval ranking demonstrably changes due to roles
* Generation snapshots can explain retrieval
* No regressions to Phase 1 ingestion or generation

---

## 7. Summary (Blunt)

Phase 1 made the system **accurate**.
Phase 2 makes it **thoughtful**.

This is the phase where your system stops behaving like “AI with memory” and starts behaving like **a reasoning substrate**.

If you want, next I can:

* Break this into **tickets**
* Design the **LLM prompts**
* Or draft **Phase 3 (belief conflict + decay)**
