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


## Frontend Changes

### 7) Add Mode/Stage Decision in Chat Submit

When user sends a message, determine whether to call with `mode=research`.

Rules (v1):

* If user is in “Research tab/mode” UI → always send `mode=research`.
* Else if message starts with:

  * `research`, `analyze`, `what's trending`, `trend`, `debate`, `compare` → send `mode=research`.
* Else default `mode=generate`.

Important: Don’t rely on `/classify-intent` to select execution mode.

---

### 8) Render Research Output in Chat Only

When receiving API response:

```ts
if (resp.metadata?.mode === 'research') {
  // Never apply resp.command
  renderResearchBlocks(resp.report)
  return
}

// generation mode (existing)
applyCommandToDocument(resp.command)
```

---

### 9) Add Research Block Components

Build a simple renderer for stage-specific schemas:

#### A) `trend_discovery`

* List cards: `trend_label`, `why_trending`, `confidence`
* Click action: “Deep research this” → sends message with `mode=research, stage=deep_research, query=trend_label`

#### B) `deep_research`

* Sections:

  * Dominant claims
  * Points of disagreement
  * Saturated angles
  * Emerging angles
  * Sample excerpts (expand/collapse)

#### C) `angle_hooks`

* Show hook list (copy buttons)
* Show extracted signals

This can be minimal HTML first.

---

### 10) Ensure Document Panel Does Not Change During Research

* If `mode=research`, do not:

  * show “Document updated” state
  * apply any patches
  * create editor history entries

Instead show:

* “Research report generated” (chat-only toast/message)

---

## API Test Cases (Must Pass)

### Case 1: Deep research from chat

Request:

* `options.mode=research`
* `options.research_stage=deep_research`

Expect:

* `metadata.mode=research`
* `command=null`
* `report.dominant_claims` exists

### Case 2: Angle hooks from chat

Request:

* `options.mode=research`
* `options.research_stage=angle_hooks`
* `hooks=5`

Expect:

* `report.hooks.length === 5`
* `command=null`

### Case 3: Generation unaffected

Request:

* no options

Expect:

* existing behavior
* commands still returned

### Case 4: Client bug safety

Request:

* `options.mode=research` but internal pipeline tries to return command

Expect:

* `command=null`
* error log emitted

---

## Rollout Plan

1. Backend: accept `options.mode/research_stage` on `/ai/chat` + enforce read-only.
2. Frontend: send mode/stage explicitly + render research blocks.
3. Add minimal “Research tab toggle” so users can intentionally stay in research.
4. Ship.

---

## Acceptance Criteria

* Research prompts in chat consistently return structured research output.
* No document mutations occur during research.
* The document panel remains unchanged (no replace/insert).
* CLI and chat outputs align for the same question/stage.
