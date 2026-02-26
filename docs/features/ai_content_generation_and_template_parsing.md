# AI Content Generation & Template Parsing — Feature Documentation

This document describes the newly added **AI generation**, **sentence rewriting**, **generation retrieval**, and **AI-based template parsing** features, including endpoints, request/response contracts, behavior, and internal guarantees.

---

## Overview

This change introduces four major capabilities:

1. **Async social post generation** (with org scoping, bookmarks as references, strict output constraints).
2. **Polling-based retrieval** of generated content.
3. **Deterministic single-sentence rewriting** with validation and audit trail.
4. **AI-powered template parsing** from example text into a reusable template.

All AI endpoints are:

* **Authenticated**
* **Organization-scoped**
* **Rate-limited**
* **Strictly JSON-driven**

---

## Common AI Endpoint Characteristics

**Base path**

```
/api/v1/ai
```

**Middleware**

* `auth:sanctum`
* `organization`
* `throttle:ai`

**Rate limit**

* Defined by the `ai` limiter
* Typically `20 req/min` per user or IP

**AI backend**

* `App\Services\OpenRouterService`
* Configured via:

  * `OPENROUTER_API_KEY`
  * `OPENROUTER_API_URL`
  * `OPENROUTER_MODEL`
  * `OPENROUTER_CLASSIFIER_MODEL`

---

## 1. Generate Social Post (Async)

### Endpoint

```
POST /api/v1/ai/generate-post
```

### Purpose

Queues an AI-generated social media post. Generation is **asynchronous** and must be retrieved later using the generation ID.

### Request Body

```json
{
  "platform": "twitter",
  "prompt": "Write a contrarian take on AI startups",
  "context": "Audience is technical founders",
  "options": {
    "max_chars": 1200,
    "cta": "implicit",
    "emoji": "disallow",
    "tone": "confident"
  },
  "bookmark_ids": [12, 15]
}
```

### Validation Rules

* `platform`: required, string, max 50 chars
* `prompt`: required, string, max 5000 chars
* `context`: optional, string, max 20000 chars
* `options.max_chars`: 100–4000
* `options.cta`: `none | implicit | soft | direct`
* `options.emoji`: `allow | disallow`
* `bookmark_ids`: max 10, must exist

**Important:**
Bookmark *content* is **not** injected here. Only IDs are stored. Resolution happens inside the job/service layer.

### Behavior

1. Creates a `GeneratedPost` row with status `queued`
2. Stores only prompt, options, and reference IDs
3. Dispatches `GeneratePostJob`
4. Returns immediately

### Response

```json
{
  "generation_id": 42,
  "status": "queued",
  "limits": {
    "max_chars": 1200,
    "emoji": "disallow"
  }
}
```

---

## 2. Retrieve Generated Post

### Endpoint

```
GET /api/v1/ai/generate-post/{id}
```

### Purpose

Polls the status and result of an async generation.

### Access Control

* Must belong to the same organization
* 404 if not found or unauthorized

### Response

```json
{
  "id": 42,
  "status": "completed",
  "content": "Your generated post text…",
  "validation": {
    "length_ok": true,
    "emoji_ok": true
  }
}
```

### Status Values

* `queued`
* `processing`
* `completed`
* `failed`

---

## 3. Rewrite Single Sentence

### Endpoint

```
POST /api/v1/ai/rewrite-sentence
```

### Purpose

Deterministically rewrites **one sentence** based on an instruction, with strict constraints and validation.

Designed for:

* Inline edits
* Sentence-level refinement
* UI “rewrite this line” features

### Request Body

```json
{
  "generated_post_id": 42,
  "sentence": "This tool changes everything.",
  "instruction": "Make it more skeptical",
  "rules": {
    "emoji": "disallow",
    "max_chars": 120,
    "tone": "neutral"
  }
}
```

### Validation Rules

* `sentence`: required, max 2000 chars
* `instruction`: required, max 500 chars
* `rules.max_chars`: 5–500
* `rules.emoji`: `allow | disallow`

### AI Contract

The model is forced to return **strict JSON only**:

```json
{
  "rewritten_sentence": "..."
}
```

### Behavior

1. Calls OpenRouter in JSON mode
2. Validates schema (`sentence_rewrite`)
3. Retries once if invalid
4. Applies post-checks:

   * Length
   * Emoji policy
5. Persists rewrite history (`SentenceRewrite`)

### Response

```json
{
  "rewritten_sentence": "This tool claims to change everything.",
  "ok": true
}
```

`ok = false` means constraints were violated (length, emoji, empty output).

---

## 4. Parse Template From Example Text

### Endpoint

```
POST /api/v1/templates/parse
```

### Purpose

Creates a **draft template** by analyzing raw example text using AI.

This is **async** and intended for:

* Turning swipe files into templates
* Bootstrapping template libraries
* Migrating legacy copy

### Request Body

```json
{
  "name": "LinkedIn Thought Leader Post",
  "raw_text": "Long example post text here...",
  "platform": "linkedin",
  "folder_id": 3
}
```

### Validation Rules

* `raw_text`: required, 50–200,000 chars
* `name`: optional
* `platform`: optional
* `folder_id`: optional, must exist

### Behavior

1. Creates a `Template` immediately
2. Sets:

   * `category`: `ai-parsed`
   * `template_type`: `post`
   * `is_public`: false
3. Dispatches `ParseTemplateFromTextJob`
4. Returns template ID

### Response

```json
{
  "template_id": 77
}
```

Template structure is filled asynchronously by the job.

---

## Data Models Introduced / Used

### `GeneratedPost`

* Async AI output
* Stores request metadata, status, content, validation

### `SentenceRewrite`

* Immutable audit log
* Links back to generated post (optional)

### `Template`

* Created immediately
* AI fills `template_data` later

---

## Design Guarantees

* No raw reference text is injected at request time
* All AI outputs are:

  * Schema-validated
  * Constraint-checked
  * Persisted for traceability
* Async jobs isolate latency and failures
* Organization boundaries are enforced at query level

---

## Intended Usage Pattern (Frontend)

1. `POST /ai/generate-post`
2. Poll `GET /ai/generate-post/{id}`
3. Allow sentence-level rewrites via `/ai/rewrite-sentence`
4. Convert good examples into templates via `/templates/parse`

---

## Non-Goals (Explicitly Out of Scope)

* Streaming responses
* Multi-paragraph rewrite
* Template editing during parse
* Bookmark text injection at controller level

---

This feature set forms the **MVP backbone** for AI-assisted content creation with strict correctness, auditability, and extensibility.
