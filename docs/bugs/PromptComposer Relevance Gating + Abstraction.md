# Engineering Spec: PromptComposer Relevance Gating + Abstraction (ISA Layer)

## Goal

Stop irrelevant / overly-long / “raw chunk” content from entering the LLM prompt by adding a deterministic **Insight Selection & Abstraction (ISA)** layer inside `PromptComposer` (or a dedicated helper used by it).

This fixes the current failure mode where retrieval returns semantically-related-but-task-irrelevant chunks (e.g., “Autonomous R&D”) and PromptComposer forwards them (partially summarized / truncated) into the user prompt.

## Non-Goals

* No changes to embeddings, ingestion, pgvector search, or retriever scoring
* No LLM-based reranking or semantic filtering (deterministic first)
* No changes to validation/repair pipeline

---

## Success Criteria (Hard, Testable)

### Prompt integrity

Using:

```bash
php artisan ai:replay-snapshot <snapshot_id> --prompt-only
```

**Must be true:**

1. `user` prompt contains **no JSON dumps** and **no raw arrays/objects** (already mostly fixed).
2. `user` prompt contains **no markdown headings** from chunks (no `###`, `##`, `**` artifacts).
3. No truncated raw-text artifacts like `"... moving from."` or `"... autonomous sc."`
4. “Relevant insights to consider” contains **max 3 bullets**.
5. Each bullet is **one sentence** and **clearly supports the task prompt**.

### Regression tests

* Unit test: ISA output never includes `###`, `##`, `{`, `}`, `[`, `]`
* Unit test: insight list length capped to configured max (default 3)
* Unit test: when given the “Autonomous R&D” chunk and task “Google punishes AI content”, it is excluded

---

## Architecture Change

### Add ISA layer (Insight Selection & Abstraction)

Placement: inside `PromptComposer` or a new `PromptInsightSelector` service used by `PromptComposer`.

**Inputs**

* `string $taskPrompt` (original user prompt)
* `string $intent` (classification intent)
* `array<KnowledgeChunk|array> $chunks` (retriever/VIP merged, normalized shape)
* Optional: `$platform`, `$constraints`, `$context`

**Outputs**

* `array<string> $insightBullets` (already abstracted, 1 sentence each)
* Optional `array $debug` (counts: input, dropped_by_reason, kept)

---

## Implementation Plan

### Phase 1 — Deterministic relevance gate (required)

Create config for ISA:

`config/ai.php`

````php
'prompt_isa' => [
  'max_insights' => 3,
  'max_chunk_chars' => 600,     // reject long raw chunks
  'min_keyword_hits' => 1,
  'drop_if_contains' => ['###', '##', '```'],
  'strip_markdown' => true,
  'stopwords' => [...],
  'task_keywords_max' => 12,
],
````

#### 1.1 Keyword extraction from task prompt

Add helper:

```php
private function extractTaskKeywords(string $taskPrompt): array
```

Rules:

* lowercase
* split on non-letters/numbers
* remove stopwords
* keep unique
* keep top N tokens (e.g., 12)
* optionally add domain synonyms:

  * if “google” present: add “search”, “serp”
  * if “ai content” present: add “generated”, “spam”, “helpful content”

#### 1.2 Chunk cleaning (pre-filter)

Add helper:

```php
private function normalizeChunkText(string $text): string
```

Rules:

* trim
* collapse whitespace
* if configured: strip markdown markers (`**`, `_`, backticks)
* remove headings lines starting with `#` (or drop chunk if it contains headings per rules)

#### 1.3 Relevance scoring + drop reasons

Add helper:

```php
private function scoreChunkRelevance(string $chunkText, array $taskKeywords): int
```

MVP scoring:

* count of task keywords present as whole-word matches (or simple `str_contains` for speed)
* return hit count

Drop rules (deterministic):

* DROP if contains any `drop_if_contains` token
* DROP if raw text length > `max_chunk_chars`
* DROP if relevance score < `min_keyword_hits`
* DROP if chunk becomes too short after cleaning (< 40 chars)

> This immediately removes “Autonomous R&D” and other off-task generic AI strategy content when task is about Google/SEO.

---

### Phase 2 — Abstraction to one-sentence insights (required)

After a chunk passes gating, turn it into a one-sentence “supporting insight”.

Add helper:

```php
private function abstractInsight(string $chunkText, string $taskPrompt, string $intent): ?string
```

Deterministic abstraction rules (no LLM):

* Take first 1–2 sentences only (split on `.`, `!`, `?`)
* Remove citations/formatting remnants
* If it starts with a heading-like prefix (“4.”, “###”, “Summary”) drop or strip
* Enforce one sentence max:

  * truncate at first sentence boundary
  * then cap length (e.g., 180 chars) *after* abstraction
* Ensure it reads like a claim:

  * if it’s just a fragment, drop it

Optional (recommended): task-conditioning rewrite templates based on keyword groups:

* If task mentions “Google/SEO/rankings”, prefer insight phrasing that references “search visibility”, “rankings”, “Helpful Content”, “spam”.
* If no relevant anchor exists, keep generic but still one sentence.

---

### Phase 3 — Dedup + cap + ordering (required)

After abstraction:

#### 3.1 Deduplicate

Dedup by normalized string hash:

* lowercase
* remove punctuation
* collapse whitespace

#### 3.2 Cap

Hard cap to `max_insights` (default 3).

#### 3.3 Ordering

Order by:

1. relevance score desc
2. shorter length (prefer concise)
3. stable fallback (original order)

---

## Integration Points

### PromptComposer changes

Replace the current “Relevant insights to consider” assembly with:

```php
$insights = $this->insightSelector->buildInsights(
  taskPrompt: $prompt,
  intent: $context->classificationIntent(),
  chunks: $context->chunks(),
  vipChunks: $context->vipChunks(),
  constraints: $constraints,
);

$userPrompt = $this->renderUserPrompt(
  prompt: $prompt,
  insights: $insights,
  template: $context->template(),
  voice: $context->voice(),
  businessContext: $context->business_context,
  ...
);
```

Important: ISA output is **plain text bullets only**.

### VIP overrides behavior

VIP chunks should **not bypass ISA completely**. They should bypass retrieval, not relevance.

Policy:

* VIP chunks are still gated, but with relaxed thresholds:

  * allow `min_keyword_hits = 0` for VIP only
  * still enforce: no headings, no raw-long chunks, abstraction required, hard cap applies

This prevents VIP knowledge from being injected as raw dumps.

---

## Debug / Observability (Required)

When running `ai:replay-snapshot --prompt-only`, include ISA debug metrics in `meta` (not in prompt):

```json
"meta": {
  "isa": {
    "input_chunks": 7,
    "kept": 3,
    "dropped": {
      "too_long": 1,
      "contains_heading": 1,
      "low_keyword_hits": 2
    },
    "task_keywords": ["google","punishes","ai","content","seo","rankings"]
  }
}
```

This lets you tune thresholds without guessing.

---

## Iteration Loop (Exact Procedure)

1. Run:

```bash
php artisan ai:replay-snapshot 019b86aa-c0b5-7130-b5f1-c496a83a0121 --prompt-only
```

2. Verify:

* Insight bullets are max 3
* No headings/markdown artifacts
* No irrelevant domain spill (life sciences R&D, etc.)
* No truncated fragments

3. Adjust config (not logic) first:

* `min_keyword_hits`
* `max_chunk_chars`
* keyword extraction stopwords/synonyms

4. Re-run the command.
   Repeat until success criteria passes.

---

## Test Plan

### Unit tests (required)

Create `tests/Unit/Ai/PromptInsightSelectorTest.php`

Test cases:

1. **Drops headings**

   * chunk contains `###` → dropped
2. **Drops off-task chunk**

   * task: “Google punishes AI content”
   * chunk: Autonomous R&D text → dropped
3. **Caps insights**

   * provide 10 relevant chunks → returns 3
4. **No truncation artifacts**

   * chunk long but relevant → output is one sentence without `...` and not cut mid-word
5. **VIP still gated**

   * VIP chunk with heading → dropped even if VIP

### Snapshot-level smoke test (recommended)

* Add a fixture snapshot or synthetic context and assert that prompt output matches expectations.

---

## Rollout Plan

* Implement ISA behind a config flag:

  * `ai.prompt_isa.enabled = true`
* Default enabled in local/dev, then production once replay snapshots look correct.
* If something breaks, disable flag without rolling back.

---

## Expected Outcome

After this change, retrieval can keep being recall-first and “messy”, while PromptComposer becomes the editorial gate that ensures the model sees only:

* relevant,
* clean,
* concise,
* task-aligned insights.

This is the correct separation of concerns.

If you paste your current `PromptComposer::composePostGeneration(...)` method (or the part where it renders the user prompt), I can point to the exact lines to replace and the minimal diff to implement this ISA layer.
