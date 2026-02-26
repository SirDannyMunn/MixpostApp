Supreme leader — below is a **clean, engineering-grade spec** that fits **exactly** on top of your current system.
No re-architecture. No fantasy components. Everything here plugs into what you already built.

I’ll break it into **5 sections**, each with:

* purpose
* components
* data model changes
* process flow
* acceptance criteria

This is the spec you could hand to a senior engineer (or future you) and implement without reinterpretation.

---

# 1. QUALITY EVALUATION LOOP

*(automatic “good vs bad” measurement)*

## Purpose

Detect low-quality generations **without human review**, and create feedback signals to tune:

* retrieval thresholds
* context size
* templates
* prompts

This is **not** RLHF. This is deterministic evaluation.

---

## Core idea

Every generated post gets a **Quality Report** computed immediately after generation.

The report:

* does not affect the user experience
* is stored for analytics + tuning
* optionally triggers regeneration later

---

## Components

### New Service

`App\Services\Ai\QualityEvaluator`

### Inputs

* generated content
* `GenerationContext`
* validation result
* retrieval distances (already logged)

### Outputs

A structured quality score + reasons.

---

## Data model

### New table: `generation_quality_reports`

```sql
id UUID
generated_post_id UUID
scores JSONB
flags JSONB
overall_score FLOAT
created_at TIMESTAMP
```

### Example row

```json
{
  "scores": {
    "relevance": 0.82,
    "specificity": 0.74,
    "structure_adherence": 0.91,
    "voice_alignment": 0.88,
    "redundancy": 0.15
  },
  "flags": {
    "generic_phrasing": false,
    "low_context_usage": true,
    "template_violation": false
  },
  "overall_score": 0.83
}
```

---

## Evaluation logic (deterministic + cheap LLM)

### Heuristics (no LLM)

* % of sentences referencing retrieved chunks
* overlap between prompt keywords and output
* template section count match
* repeated sentence patterns
* length variance

### Optional LLM pass (cheap, JSON only)

Prompt:

> “Given the context and output, score relevance, specificity, and originality from 0–1. Return JSON only.”

---

## Process flow

```
GeneratePostJob
 → PostValidator
 → QualityEvaluator
 → store generation_quality_reports
```

---

## Acceptance criteria

* Every generation produces a quality report
* Low scores are detectable *without reading content*
* Reports are queryable by intent, template, user

---

# 2. TOKEN-BUDGET BASED CONTEXT PRUNING

*(prevents silent quality collapse)*

## Purpose

Prevent the “too much context → generic output” failure mode.

You already cap item counts. This caps **tokens**, which is what actually matters.

---

## Core idea

ContextAssembler gets a **hard token budget**, enforced deterministically.

---

## Components

### New config

`config/ai_context.php`

```php
return [
  'max_tokens' => 1800,
  'allocations' => [
    'voice' => 120,
    'template' => 150,
    'chunks' => 800,
    'facts' => 400,
    'swipes' => 250,
    'user_context' => 300,
  ],
];
```

---

## Changes to `GenerationContext`

Add:

```php
public function estimatedTokens(): array
```

And:

```php
public function pruneToBudget(): self
```

---

## Pruning rules (deterministic)

Order of removal:

1. lowest-similarity chunks
2. excess swipe structures
3. excess facts
4. trim chunk text length (not count)
5. last resort: drop lowest-priority section

**Voice + template are never removed.**

---

## Process flow

```
ContextAssembler::build()
 → estimate tokens
 → pruneToBudget()
 → return context
```

---

## Acceptance criteria

* Context token count is logged
* Context never exceeds budget
* Removing items degrades gracefully (never empties context)

---

# 3. SWIPE EMBEDDING + STRUCTURE SIMILARITY

*(next big quality unlock)*

## Purpose

Select swipe structures based on **structural similarity**, not tags or intent alone.

This avoids:

* repetitive hooks
* mismatched pacing
* wrong emotional shape

---

## Core idea

Swipe structures get embeddings based on their **structure JSON**, not text.

---

## Data model changes

### Add to `swipe_structures`

```sql
embedding_vec VECTOR
embedding_model TEXT
```

---

## Embedding input (important)

You do **not** embed raw text.

You embed a normalized representation:

```json
{
  "sections": ["hook", "contrast", "lesson"],
  "hook_type": "contrarian",
  "cta_type": "implicit",
  "intent": "educational"
}
```

Serialized + embedded.

---

## Retrieval changes

### New method

`Retriever::swipeStructuresSemantic()`

Process:

1. build “desired structure” embedding from:

   * intent
   * funnel stage
   * selected template
2. vector search against `swipe_structures.embedding_vec`
3. return top N structures

Fallback:

* current heuristic method

---

## Acceptance criteria

* Swipe selection varies by structure, not randomness
* Repeated generations don’t reuse same hook shapes
* Swipe text never enters generation context

---

# 4. REPLAYABLE GENERATION DEBUGGING

*(“why did this post look like this?”)*

## Purpose

Enable **exact replay** of any generation.

Critical for:

* debugging
* trust
* future fine-tuning
* support

---

## Core idea

Persist **everything needed to replay**, but nothing more.

---

## Data model changes

### Extend `generated_posts`

```sql
context_snapshot JSONB
prompt_payload JSONB
model_config JSONB
retrieval_scores JSONB
```

---

### Example snapshot

```json
{
  "chunks": [
    { "id": "kc1", "distance": 0.12 },
    { "id": "kc7", "distance": 0.18 }
  ],
  "swipes": ["ss3"],
  "template": "t1"
}
```

---

## Replay flow

```
load generated_post
 → load snapshot IDs
 → rehydrate context
 → re-run ContextAssembler (replay mode)
 → re-run generation (optional)
```

Replay mode:

* disables pruning randomness
* disables new retrieval
* uses stored distances

---

## Acceptance criteria

* Any generation can be replayed byte-for-byte (model permitting)
* Snapshot is human-readable
* Debug UI can show “inputs → output”

---

# 5. SHIP CHECKLIST

*(what to lock before real users)*

## Must-lock (do not ship without)

### Data & infra

* [ ] pgvector indexes (HNSW)
* [ ] embedding model consistency enforced
* [ ] pg_hba.conf secured
* [ ] queue retry limits sane
* [ ] dead-letter queue logging

### AI behavior

* [ ] strict JSON schemas everywhere
* [ ] max one repair pass
* [ ] token budget enforced
* [ ] raw swipe text ban tested

### Observability

* [ ] retrieval distance logging enabled
* [ ] quality reports generated
* [ ] generation replay works
* [ ] error paths logged with context ID

### UX safety

* [ ] empty knowledge fallback tested
* [ ] no context → safe generic response
* [ ] sentence rewrite isolated
* [ ] bulk generation rate-limited

---

## Final verdict

With these five specs implemented:

You will have:

* measurable quality
* predictable scaling
* explainable outputs
* structural differentiation
* long-term maintainability

This is **not** how typical AI content tools are built.
That’s why yours will hold up when usage grows.

If you want, next I can:

* turn this into Jira tickets
* write the first QualityEvaluator implementation
* spec the swipe embedding job
* design the replay UI schema

Say the word.
