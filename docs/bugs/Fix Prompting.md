Below is a **formal engineering requirements + iteration spec** to fix prompt construction. This is written to be executed step-by-step by engineering, using the `ai:replay-snapshot --prompt-only` command as the feedback loop until the prompt is correct.

---

# Engineering Requirements: Prompt Construction Fix

**Component:** `PromptComposer` + prompt-building path
**Scope:** Prompt text correctness, compression, and abstraction boundaries
**Out of scope:** Retrieval logic, classification logic, validation/repair, model execution

---

## 1. Problem Statement (Current State)

The prompt builder is **leaking internal system state** directly into the user prompt. Evidence from the replay output:

* Raw JSON arrays are embedded in the user prompt
* Internal object schemas (`id`, `score`, `confidence`, `tags`, `recall_injected`) are exposed
* Internal labels (`KNOWLEDGE:`, `TEMPLATE_DATA:`, `SWIPE_STRUCTURES:`) are serialized verbatim
* The model is being asked to parse developer infrastructure instead of content signals

This violates the core architectural rule:

> **PromptComposer must compress intent, not serialize state.**

---

## 2. Target State (What “Fixed” Means)

### High-level invariant

**The LLM must only see:**

* Clear task instructions
* Human-readable summaries
* Structural guidance
* Explicit constraints

**The LLM must never see:**

* JSON
* Internal IDs
* Scores / confidence / similarity
* Object keys or schemas
* Debug or bookkeeping metadata

---

## 3. Success Criteria (Hard, Testable)

A prompt is considered **correct** only if **all** criteria pass.

### 3.1 Structural Criteria

Using `php artisan ai:replay-snapshot 019b86aa-c0b5-7130-b5f1-c496a83a0121 --prompt-only`:

* ✅ `system` is plain text only
* ✅ `user` is plain text only
* ❌ No `{`, `}`, `[`, `]` in either prompt (except inside natural language quotes)
* ❌ No strings matching: `"id":`, `"score":`, `"confidence":`, `"tags":`, `"chunk_type":`
* ❌ No section headers matching internal names (`KNOWLEDGE:`, `TEMPLATE_DATA:` etc.)

### 3.2 Semantic Criteria

* Knowledge appears as **summarized ideas**, not copied chunks
* Templates appear as **instructions**, not schema dumps
* Swipe structures influence **style and rhythm**, not explicit structure blocks
* User/business context is **concise**, non-duplicative, and readable

### 3.3 Control Criteria

* Changing internal object shapes does **not** change prompt output
* Prompt output is stable across replays of the same snapshot

---

## 4. Required Code Changes

### 4.1 PromptComposer Responsibilities (Authoritative)

`PromptComposer` must be the **only** place that converts domain objects → text.

It must:

* Accept rich domain objects (`Context`, `Template`, `Chunk[]`, `Swipe[]`)
* Emit **only text**
* Perform lossy compression intentionally

It must **not**:

* Serialize arrays or objects
* Dump raw text fields wholesale
* Include developer-facing labels

---

## 5. Specific Refactor Instructions

### 5.1 Knowledge Compression (Critical)

**Current (incorrect):**

```text
KNOWLEDGE:
[{ "id": "...", "chunk_text": "...", "score": 1 }, ...]
```

**Required behavior:**

* Deduplicate chunks
* Extract only the **core claim or lesson**
* Rewrite as short bullets or sentences

**Example target output:**

```text
Relevant insights to consider:
- Google rewards original, opinionated content and suppresses generic AI-written posts.
- Treating content as a growth hack leads to short-term gains and long-term suppression.
```

**Implementation notes:**

* Add a private method in `PromptComposer`, e.g. `summarizeKnowledge(array $chunks): string`
* Hard cap summaries (e.g. max 3–5 bullets)

---

### 5.2 Template Compression

**Current (incorrect):**

```json
{"structure":[{"section":"Hook","required":true,"description":"..."}]}
```

**Required behavior:**
Convert templates into **authoritative instructions**.

**Target output:**

```text
Follow this structure strictly:
1. Hook — open with a bold or contrarian statement.
2. Context — explain why AI content gets punished.
3. Lesson — what actually works instead.
4. Value points — 3–5 concise takeaways.
5. CTA — optional, invite discussion.
```

No JSON. No keys. No `required` flags.

---

### 5.3 Swipe Structure Handling

Swipe structures should **never appear explicitly**.

**Rule:**

* Swipe data may influence phrasing instructions only
* At most one sentence describing style influence

**Example:**

```text
Write with a contrarian, confident tone and tight pacing.
```

---

### 5.4 User / Business Context

Collapse verbose context blocks into a single paragraph.

**Required format:**

```text
Audience: SaaS founders and solo operators.
Positioning: Authority-driven, strategy-first content.
Goal: Persuasive, high-signal posts that avoid generic AI tone.
```

No labels like `SUMMARY:` or `OFFER:`.

---

## 6. Iterative Fix Loop (Required Process)

Engineering must follow this loop until all success criteria pass.

### Step 1 — Run replay

```bash
php artisan ai:replay-snapshot {snapshot_id} --prompt-only
```

### Step 2 — Inspect prompt

Check for:

* JSON artifacts
* Internal field leakage
* Oververbosity
* Redundancy

### Step 3 — Fix PromptComposer

* Adjust only **PromptComposer** (not retrieval, not context assembly)
* Add or refine summarization helpers
* Remove all serialization logic

### Step 4 — Re-run replay

Repeat Step 1.

### Step 5 — Lock fix

When all success criteria pass:

* Add a regression test asserting:

  * Prompt contains no `{` or `[` characters
  * Prompt length under expected ceiling
* Commit changes

---

## 7. Guardrails (Prevent Regression)

### Required Tests

* Unit test: `PromptComposerTest::it_outputs_plain_text_only`
* Snapshot test: prompt diff remains stable for same snapshot
* Lint rule or assertion: forbid JSON encoding inside PromptComposer

---

## 8. Non-Negotiable Rules

* **No “temporary” passthroughs**
* **No prompt debugging logic inside generation**
* **No conditionals based on debug mode inside PromptComposer**
* **PromptComposer is lossy by design — that is the point**

---

## 9. Definition of Done

The task is complete when:

* `ai:replay-snapshot --prompt-only` produces a **human-readable, minimal prompt**
* An engineer can read the prompt and understand exactly what the model is being asked to do
* Internal system complexity is completely invisible to the LLM

---

If you want, next step I can:

* Rewrite your **current prompt into the exact target version**
* Or produce a **PromptComposer pseudo-implementation** showing each compression function

Say which one.
