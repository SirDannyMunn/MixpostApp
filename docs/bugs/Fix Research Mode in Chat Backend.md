# Engineering Spec – Fix Research Mode in Chat (No Document Mutation)

## Goal

Make Research Mode v2 work end-to-end from the **chat UI**:

* Research output renders **in chat only**
* Research calls never return `replace_document` (or any document mutation command)
* Mode/stage are explicitly passed from the client
* Backend enforces read-only guarantees for `mode=research`

This spec fixes the current mismatch where the CLI works but chat defaults to generation.

---

## Current Symptoms (from your logs)

* `/api/v1/ai/classify-intent` returns `{ read:false, write:false }`
* `/api/v1/ai/chat` returns a `replace_document` command and prose content
* Research mode works via CLI (`ai:research:ask`) including:

  * `--stage=deep_research`
  * `--stage=angle_hooks --hooks=5`

Root cause:

* The chat endpoint is being called **without** `options.mode=research`, so `ContentGeneratorService` defaults to generation.

---

## Design Principles

1. **Execution mode must be explicit**: intent classification is not authoritative.
2. **Research output is structured**: UI renders blocks, not document text.
3. **Read-only must be enforced server-side**: client bugs must not mutate documents.
4. **Backwards compatible**: generation flow unchanged.

---

## Backend Changes

### 1) Update Chat Endpoint Contract

#### Endpoint

`POST /api/v1/ai/chat`

#### Request (new optional fields)

Add `options` object if not already present:

```json
{
  "message": "What's trending in SEO?",
  "conversation_id": "...",
  "conversation_history": [],
  "options": {
    "mode": "research",
    "research_stage": "trend_discovery",
    "industry": "seo",
    "platforms": ["x", "google"],
    "limit": 40,
    "hooks": 5
  }
}
```

Notes:

* `options.mode` enum: `generate|research`.
* `options.research_stage` enum: `trend_discovery|deep_research|angle_hooks`.
* Only relevant fields are required per stage.

#### Response (must include)

Always include `metadata.mode` and `metadata.research_stage`:

```json
{
  "response": "...",
  "command": null,
  "report": { ... },
  "metadata": {
    "mode": "research",
    "research_stage": "deep_research",
    "snapshot_id": "..."
  }
}
```

---

### 2) Normalize Options in `GenerationRequest`

File:

* `app/Services/Ai/Generation/DTO/GenerationRequest.php`

Add normalization defaults:

* If `options.mode === 'research'` and `options.research_stage` missing → default `deep_research`.
* Validate stage-specific fields.

Validation rules:

* `mode=research`:

  * `research_stage` required (default ok)
  * `hooks` only valid when `research_stage=angle_hooks`
* Invalid combos → 422 with helpful message.

---

### 3) Enforce Read-only Guarantees in `ContentGeneratorService`

File:

* `app/Services/Ai/ContentGeneratorService.php`

Add hard guard:

```php
if ($req->options->mode === 'research') {
    $req->forceReadOnly();
}
```

Implementation details for `forceReadOnly()`:

* Disallow any document mutation commands (`replace_document`, `insert`, etc.)
* Bypass prompt composer/validator/repair pipeline for generation
* Only allow:

  * TrendDiscoveryService
  * Research retrieval + clustering + report composition
  * HookGenerationService

If an internal component returns a command anyway, strip it and log an error.

---

### 4) Make `/classify-intent` Mode-Aware (Optional but Recommended)

Endpoint:
`POST /api/v1/ai/classify-intent`

Add support for receiving `options.mode`:

* If `options.mode=research` → always return `{ read:true, write:false }`

This prevents confusing UI states.

---

### 5) Ensure Research Responses Never Include `command`

Where response is assembled:

* If `metadata.mode === 'research'`:

  * `command = null`
  * `response` can be a short summary string (optional)
  * `report` must contain structured payload

For deep research:

* `report` should be the JSON report
* `content` may still be a JSON string for backwards compatibility, but UI should use `report`

---

### 6) Add Logging for Mode Mismatches

Add a single structured log line per chat request:

* request `mode`
* effective `mode`
* `research_stage`
* whether command stripping occurred

This makes the next bug obvious.

---

