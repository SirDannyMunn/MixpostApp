Supreme leader — below is a backend-only, Laravel-first engineering specification for the full system (knowledge base, swipe files, templates, post generation, sentence rewrites) including **schemas, example rows, process flows, and failure cases + guards**.

---

## 0) System goals and invariants

### Goals

* High-quality writing via **layered retrieval + structure + constraints**.
* Fast UX via **async processing** (queues).
* Deterministic behavior via **schema-validated LLM outputs**.
* Low hallucination / low plagiarism risk via **tight context assembly + output guards**.

### Non-negotiable invariants

* `knowledge_items.raw_text` and `swipe_items.raw_text` are **immutable canonical sources**.
* Embeddings are stored **only on chunks** (and optionally on swipe *structures*, not swipe raw).
* LLM outputs must be **strict JSON** validated server-side; invalid → retry/fallback.
* Generation context is **bounded** (token budget) and **surgical**.

---

## 1) Tech stack assumptions (backend only)

* Laravel 11+
* Postgres 15+ with `uuid-ossp` or `pgcrypto`
* `pgvector` extension (HNSW or IVFFlat indexes)
* Redis + Horizon
* One LLM gateway (OpenRouter or direct providers)
* One embeddings provider (can be same gateway)

---

## 2) Database schema (Postgres) — production shape

Use UUID primary keys everywhere. Laravel: `uuid('id')->primary()` and `foreignUuid()`.

### 2.1 Core: knowledge ingestion + derived artifacts

#### `knowledge_items` (raw, immutable)

* `id` uuid pk
* `user_id` uuid fk
* `type` varchar (note|transcript|email|doc|url|offer|custom)
* `source` varchar (manual|upload|chrome_extension|integration)
* `title` varchar null
* `raw_text` text (immutable)
* `raw_text_sha256` char(64) (dedupe)
* `metadata` jsonb (source_url, mime, author, timestamps, etc.)
* `ingested_at` timestamptz
* `created_at`, `updated_at`

**Indexes**

* `(user_id, created_at desc)`
* `(user_id, raw_text_sha256)` unique (optional if you want strict dedupe)
* GIN on `metadata`

**Example row**

```json
{
  "id": "8ce2c5c6-9c5a-4b3a-9e5b-8c2c1b4d8caa",
  "user_id": "11111111-1111-1111-1111-111111111111",
  "type": "note",
  "source": "manual",
  "title": "Why founders fail at content",
  "raw_text": "Most SaaS founders fail at content because they treat it like a growth hack instead of reputation building.",
  "raw_text_sha256": "b8d7...e21a",
  "metadata": {"lang":"en"},
  "ingested_at": "2025-12-16T17:20:00Z"
}
```

#### `knowledge_chunks` (retrieval units)

* `id` uuid pk
* `knowledge_item_id` uuid fk
* `user_id` uuid fk
* `chunk_text` text
* `chunk_type` varchar (story|opinion|framework|instruction|example|offer_reference|misc)
* `tags` jsonb (topics, entities, intent hints)
* `token_count` int
* `embedding` vector(D) null
* `embedding_model` varchar null
* `created_at`

**Indexes**

* `(user_id, chunk_type)`
* GIN on `tags`
* Vector index on `embedding` (`hnsw` recommended)

**Example row**

```json
{
  "id": "c3d8f6b2-1dbe-46b6-84a4-1f6aa4e9b0c9",
  "knowledge_item_id": "8ce2c5c6-9c5a-4b3a-9e5b-8c2c1b4d8caa",
  "user_id": "11111111-1111-1111-1111-111111111111",
  "chunk_text": "Most SaaS founders fail at content because they treat it like a growth hack rather than long-term reputation building.",
  "chunk_type": "opinion",
  "tags": {"topics":["saas","content marketing"],"entities":["founders"],"polarity":"contrarian"},
  "token_count": 24,
  "embedding_model": "text-embedding-3-large",
  "embedding": "[…]"
}
```

#### `voice_profiles` (rolling aggregate)

* `id` uuid pk
* `user_id` uuid fk unique
* `traits` jsonb
* `confidence` float
* `sample_size` int (how many docs contributed)
* `updated_at`

**Example traits**

```json
{
  "sentence_length": {"mean": 9, "style": "short"},
  "tone": ["direct","confident","low-fluff"],
  "emoji_usage": "none",
  "formatting": ["line_breaks","punchy_openers"],
  "rhetoric": ["contrast","imperatives"],
  "taboo": {"avoid":["excessive hype","corporate jargon"]}
}
```

#### `business_facts` (BOF truth layer)

* `id` uuid pk
* `user_id` uuid fk
* `type` varchar (offer|icp|pain_point|objection|outcome|proof)
* `text` text
* `confidence` float
* `source_knowledge_item_id` uuid fk null
* `created_at`

**Example row**

```json
{
  "type": "pain_point",
  "text": "Founders can’t stay consistent with content because they don’t have a repeatable structure and they overthink tools.",
  "confidence": 0.84
}
```

---

### 2.2 Swipe system: external patterns + structures

#### `swipe_items` (raw capture)

* `id` uuid pk
* `owner_user_id` uuid fk null (null = community/global)
* `platform` varchar (linkedin|x|reddit|blog|newsletter|other)
* `source_url` text null
* `author_handle` varchar null
* `raw_text` text
* `raw_text_sha256` char(64)
* `engagement` jsonb null (likes, comments, reposts, scraped_at)
* `saved_reason` text null
* `created_at`

#### `swipe_structures` (the reusable part)

* `id` uuid pk
* `swipe_item_id` uuid fk
* `intent` varchar (educational|persuasive|emotional|contrarian|story)
* `funnel_stage` varchar (tof|mof|bof)
* `hook_type` varchar
* `cta_type` varchar (none|implicit|soft|direct)
* `structure` jsonb (section schema)
* `language_signals` jsonb (optional; can be separate table)
* `confidence` float
* `created_at`

**Example `structure`**

```json
{
  "sections": [
    {"key":"hook","goal":"pattern interrupt","rules":{"lines":2}},
    {"key":"contrast","goal":"shatter misconception","rules":{"lines":3}},
    {"key":"lesson","goal":"deliver principle","rules":{"lines":3}},
    {"key":"soft_cta","goal":"invite follow-up","rules":{"lines":1}}
  ]
}
```

---

### 2.3 Templates: schemas (not prompts)

#### `templates`

* `id` uuid pk
* `owner_user_id` uuid fk null (null = system template)
* `name` varchar
* `description` text null
* `structure` jsonb (section keys + per-section constraints)
* `constraints` jsonb (max chars, emoji allowed, tone flags, platform)
* `tags` jsonb
* `created_at`

#### `template_examples`

* `id` uuid pk
* `template_id` uuid fk
* `example_text` text
* `source_type` varchar (system|user|swipe)
* `source_id` uuid null
* `created_at`

---

### 2.4 LLM observability + retries (strongly recommended)

#### `llm_calls`

* `id` uuid pk
* `user_id` uuid fk null
* `purpose` varchar (chunk|embed|voice|facts|swipe_structure|classify|generate|rewrite|repair)
* `model` varchar
* `input_tokens` int
* `output_tokens` int
* `cost_usd` numeric(10,4) null
* `latency_ms` int
* `request_hash` char(64) (idempotency)
* `status` varchar (ok|retry|failed)
* `error_code` varchar null
* `created_at`

#### `processing_runs` (generic job run tracking)

* `id` uuid pk
* `user_id` uuid fk
* `subject_type` varchar (knowledge_item|swipe_item|template|generation)
* `subject_id` uuid
* `processor` varchar (chunker|embedder|voice|facts|swipe_parser|template_parser|generator|repair)
* `status` varchar (queued|running|ok|failed|deadletter)
* `attempt` int
* `error` text null
* `meta` jsonb null
* `created_at`, `updated_at`

---

### 2.5 Generations + rewrites

#### `generated_posts`

* `id` uuid pk
* `user_id` uuid fk
* `platform` varchar (linkedin|x|etc)
* `intent` varchar
* `funnel_stage` varchar
* `topic` varchar null
* `template_id` uuid fk null
* `request` jsonb (user prompt, extra context, options)
* `context_snapshot` jsonb (what you injected: chunk ids, fact ids, template id, swipe_structure ids, voice version)
* `content` text null
* `status` varchar (queued|draft|failed)
* `validation` jsonb null (checks + scores)
* `created_at`, `updated_at`

#### `sentence_rewrites`

* `id` uuid pk
* `user_id` uuid fk
* `generated_post_id` uuid fk null
* `original_sentence` text
* `instruction` varchar
* `rewritten_sentence` text
* `meta` jsonb (voice version, model)
* `created_at`

---

## 3) Async processing pipeline (Laravel queues)

### 3.1 On knowledge item created

**Event:** `KnowledgeItemIngested($knowledgeItemId)`
**Jobs (chain):**

1. `ChunkKnowledgeItemJob`
2. `EmbedKnowledgeChunksJob`
3. `ExtractVoiceTraitsJob` (updates rolling profile)
4. `ExtractBusinessFactsJob`

**Guard:** make each job idempotent (checks existing rows / hashes).

### 3.2 On swipe item created

1. `ExtractSwipeStructureJob`
2. `ExtractSwipeLanguageSignalsJob` (optional)
3. (optional) embed the *structure* or tags for retrieval

### 3.3 Template created from pasted post

1. `ParseTemplateFromTextJob` → store `templates.structure` + constraints

---

## 4) API endpoints (Laravel controllers)

### Knowledge

* `POST /v1/knowledge-items`

  * body: `{type, source, title?, raw_text, metadata?}`
  * response: `{id, status:"ingested"}`

### Swipes

* `POST /v1/swipe-items`

  * body: `{platform, raw_text, source_url?, author_handle?, engagement?, saved_reason?}`
  * response: `{id, status:"queued"}`

### Templates

* `POST /v1/templates`

  * body: `{name, structure, constraints, tags?}` (manual)
* `POST /v1/templates/parse`

  * body: `{name?, raw_text, platform?}` → returns `{template_id}`

### Generation

* `POST /v1/generate/post`

  * body:

    ```json
    {
      "platform":"linkedin",
      "prompt":"Write a post about…",
      "context":"Yesterday a founder told me…",
      "options":{"max_chars":1200,"cta":"soft"}
    }
    ```
  * response: `{generation_id, status:"queued"}`

* `GET /v1/generate/post/{id}` → `{status, content, validation}`

### Sentence rewrite

* `POST /v1/rewrite/sentence`

  * body: `{generated_post_id?, sentence, instruction}`
  * response: `{rewritten_sentence}`

---

## 5) Prompt contracts (strict JSON) + validation strategy

### Rule: every extraction/generation tool returns JSON inside a single top-level object

* Parse with `json_decode($text, true)`.
* Validate with Laravel validator rules + custom JSON schema checks.
* If invalid: retry with a “YOU MUST OUTPUT VALID JSON” repair prompt.
* If still invalid: fallback (rules-based minimal structure) or fail gracefully.

**Recommended pattern**

* `LLMClient::call($purpose, $model, $system, $user, $schemaName)`
* `SchemaValidator::validate($schemaName, $decoded)`
* Retry policy: `maxAttempts=2` for extractors, `maxAttempts=3` for generation/repair.

---

## 6) Retrieval + generation flow (backend pseudocode)

### 6.1 Generate a post

```php
public function generatePost(User $user, GeneratePostDTO $dto): string
{
    $gen = GeneratedPost::create([
        'user_id' => $user->id,
        'platform' => $dto->platform,
        'request' => $dto->toArray(),
        'status' => 'queued',
    ]);

    dispatch(new GeneratePostJob($gen->id));

    return $gen->id;
}
```

### 6.2 Job: `GeneratePostJob`

```php
public function handle()
{
    $gen = GeneratedPost::findOrFail($this->id);
    $user = $gen->user;

    // 1) classify intent + funnel stage
    $classification = $this->llm->classify($gen->request['prompt'], $gen->request['platform']);

    // 2) load voice profile
    $voice = VoiceProfile::where('user_id',$user->id)->first();

    // 3) retrieve knowledge chunks (pgvector + filters)
    $chunks = $this->retriever->knowledgeChunks(
        userId: $user->id,
        query: $gen->request['prompt'],
        intent: $classification['intent'],
        limit: 5
    );

    // 4) retrieve business facts (only for MOF/BOF or persuasive intent)
    $facts = [];
    if (in_array($classification['funnel_stage'], ['mof','bof']) || $classification['intent']==='persuasive') {
        $facts = $this->retriever->businessFacts($user->id, limit: 8);
    }

    // 5) retrieve swipe structure + template
    $swipeStructures = $this->retriever->swipeStructures(
        ownerUserId: $user->id,
        intent: $classification['intent'],
        platform: $gen->platform,
        limit: 2
    );

    $template = $this->templateSelector->select(
        intent: $classification['intent'],
        funnelStage: $classification['funnel_stage'],
        platform: $gen->platform
    );

    // 6) assemble bounded context snapshot
    $context = $this->contextAssembler->build([
        'voice' => $voice,
        'template' => $template,
        'chunks' => $chunks,
        'facts' => $facts,
        'swipes' => $swipeStructures,
        'user_context' => $gen->request['context'] ?? null,
        'options' => $gen->request['options'] ?? [],
    ]);

    // 7) generate
    $draft = $this->llm->generatePost($context);

    // 8) validate + repair if needed
    $validation = $this->validator->checkPost($draft, $context);
    if (!$validation['ok']) {
        $draft = $this->llm->repairPost($draft, $context, $validation);
        $validation = $this->validator->checkPost($draft, $context);
    }

    if (!$validation['ok']) {
        $gen->update(['status'=>'failed','validation'=>$validation]);
        return;
    }

    $gen->update([
        'status' => 'draft',
        'content' => $draft,
        'intent' => $classification['intent'],
        'funnel_stage' => $classification['funnel_stage'],
        'template_id' => $template?->id,
        'context_snapshot' => $context->snapshotIds(),
        'validation' => $validation,
    ]);
}
```

---

## 7) Failure cases + guards (by component)

### 7.1 Knowledge ingestion

**Failure cases**

* Empty / tiny text
* Massive text (50k+ chars) kills costs/time
* Duplicate uploads
* Malicious HTML / scripts in “raw_text”

**Guards**

* Validation: `min:50 chars`, `max:200k chars` (hard cap)
* Dedup: `raw_text_sha256` per user
* Sanitize for display only (store raw as-is, render escaped)
* Chunking job splits huge docs; if too huge, chunk incrementally by paragraphs

---

### 7.2 Chunking

**Failure cases**

* Chunker produces 1 mega-chunk
* Chunker produces 500 micro-chunks
* Wrong `chunk_type` spam (“framework” for everything)

**Guards**

* Hard rules after chunking:

  * `token_count` must be within `[150..900]` (tune)
  * max chunks per item (e.g. 80). If exceeded: merge adjacent chunks.
* If LLM chunking fails: fallback to deterministic chunking:

  * split by headings/blank lines → then enforce size window.

---

### 7.3 Embeddings

**Failure cases**

* Provider downtime / rate limit
* Embedding dimension mismatch
* Null embeddings cause retrieval gaps

**Guards**

* Store `embedding_model` + dimension D; enforce D consistency
* Retry with backoff; if still failing:

  * mark chunk `embedding=null` and schedule a nightly re-embed
* Retrieval must degrade:

  * if embeddings missing → keyword fallback (ILIKE / tsvector)

---

### 7.4 Voice profile extraction (rolling aggregate)

**Failure cases**

* Voice oscillates wildly based on one weird input
* LLM returns generic “friendly professional”
* Low confidence but still used

**Guards**

* Weighted merge:

  * only update traits if `confidence >= 0.65`
  * apply decay/EMA instead of overwriting
* Require `sample_size >= N` before “strict enforcement”

  * until then, use “light constraints” only

---

### 7.5 Business facts extraction

**Failure cases**

* Hallucinated offers/claims
* Facts duplicate endlessly
* Wrong funnel facts used in TOF posts

**Guards**

* Require `source_knowledge_item_id` for extracted facts (traceability)
* Similarity dedupe on `text` (sha or embedding)
* Only include facts when intent/funnel requires it (hard rule)
* Confidence threshold per type:

  * `proof` requires higher threshold than `pain_point`

---

### 7.6 Swipe structure extraction (most brittle)

**Failure cases**

* Invalid JSON output
* Structure too vague (“hook/body/cta” always)
* Misclassified intent/funnel
* Hidden plagiarism risk if you later inject raw swipe text

**Guards**

* JSON schema validation + retry with repair prompt
* Reject low-information structures:

  * must have >= 3 sections with distinct `goal`
* Confidence gating:

  * if `<0.7`, store but don’t use for generation until reprocessed
* Never inject swipe `raw_text` into generation context (hard-coded ban)

---

### 7.7 Template parsing from pasted post

**Failure cases**

* Template becomes overfit to one example
* Missing constraints means rambling posts
* Users create nonsense schemas

**Guards**

* Normalize section keys to a controlled vocabulary (hook/setup/lesson/cta/etc.)
* Validate templates:

  * `sections count` in range `[3..8]`
  * must include `hook`
  * must include some “value delivery” section (lesson/framework)
* Default constraints inserted if missing:

  * `max_chars`, emoji policy, CTA policy

---

### 7.8 Intent classification

**Failure cases**

* Wrong classification → wrong retrieval → bad post
* Edge prompts that mix story + conversion

**Guards**

* Allow multi-label classification:

  * primary intent + secondary flag (`has_story=true`)
* If classifier confidence low:

  * run rules backup: presence of “sell”, “CTA”, “my product” => persuasive
* Store classification confidence in `generated_posts.validation`

---

### 7.9 Controlled context assembly

**Failure cases**

* Context too large → diluted output
* Wrong ordering → model ignores constraints
* “Context collisions” (facts contradict chunks)

**Guards**

* Token budgeter:

  * allocate budgets: voice (small), template (small), user context (medium), chunks (largest), facts (medium), swipes (small)
  * hard truncation with “keep first, drop rest”
* Deterministic ordering (always):

  1. constraints + template schema
  2. voice traits
  3. user-provided context
  4. retrieved chunks
  5. business facts
  6. swipe structures
* Collision guard:

  * if contradictions detected (simple heuristic or mini LLM check), drop lower-priority items

---

### 7.10 Post-generation validation + repair

**Failure cases**

* Exceeds length
* Missing required sections
* Contains emojis when disallowed
* Contains prohibited CTA style (too salesy in TOF)
* “AI smell” patterns (generic openers, hedging)
* Plagiarism proximity to swipe text

**Guards**

* Deterministic validators:

  * char count
  * section presence (regex or LLM section tagger)
  * emoji regex
  * banned phrase list (configurable)
* Repair step:

  * Provide validator failures to LLM + re-generate with constraints
* Plagiarism guard:

  * compute similarity between output and `swipe_items.raw_text` for the swipes used
  * if above threshold: regenerate with “do not reuse phrasing” stronger constraint and remove the swipe structure from context

---

### 7.11 Sentence-level rewrites

**Failure cases**

* Rewrite changes meaning
* Rewrite breaks tone constraints
* Rewrite introduces banned content/emojis

**Guards**

* Include “meaning preservation” rule
* Validate rewritten sentence (emoji, length, tone)
* If fails: return best of N candidates (generate 2–3, pick first passing validator)

---

## 8) Why this reliably improves quality (mechanically)

* **Reduces randomness:** templates + validators force structure.
* **Grounds content:** knowledge chunks and business facts are user-derived, not model-invented.
* **Prevents AI rambling:** strict token budgets + section schemas.
* **Prevents plagiarism:** swipe *structure* only + similarity checks.
* **Maintains voice:** rolling voice profile, applied consistently but gated by confidence.

---

## 9) Minimal MVP vs full build (backend scope)

### MVP tables

* `knowledge_items`, `knowledge_chunks`
* `swipe_items`, `swipe_structures`
* `templates`
* `generated_posts`
* `llm_calls` (you want this on day 1)

### MVP jobs

* chunk + embed
* swipe structure extract
* generate + validate + repair

Add later:

* `voice_profiles`, `business_facts`, rewrite history, richer run tracking

---

If you want the next step, I can output **Laravel migrations (actual PHP files)** for all tables above, plus the **exact JSON schemas** (validator rules) for swipe parsing, template parsing, classification, generation, and repair.
