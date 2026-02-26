Supreme leader,

This report confirms **three concrete defects** (not “tuning”):

1. **Synthetic QA retrieval list is coming from a different query/scope than the scored top3**

   * Q1/Q2: `diagnostics.top3[0].id` is the correct freshly-ingested chunk (`019b85a2-...`) but `details[].retrieved[]` are older IDs (`019b84..`, `019b85..` different). That means you’re computing top3 from one dataset and outputting “retrieved” from another, or you’re applying a post-filter/cap that is discarding the correct chunk without reflecting it in the report.

2. **Generation “retrieval_on” is not scoped to the eval’s knowledge_item**

   * Q1 retrieval-on: `retrieved_chunk_ids` are *all older*, and none match this run’s chunk IDs except Q3 where it accidentally included the correct one.

3. **The grader is invalid**

   * Q1 retrieval-on: expected “January 1, 2025”, generated “cannot determine”, yet `pass:true score:1`. That’s a hard bug.

Below is a **specific engineering spec** with filenames and targeted changes.

---

# Engineering Spec: Make Eval Harness Deterministic + Fix Grader + Fix Retriever/Report Consistency

## Objective

For `php artisan ai:ingestion:eval ... --run-generation`:

* Synthetic QA `details[].retrieved[]` must reflect the same retrieval output that produced scoring/top3.
* Generation retrieval-on must pull from the eval’s newly ingested chunks (or explicitly show dual-scope).
* Grader must fail contradictions (“cannot determine” vs specific expected).

Target for `docs/fixtures/ingestion/factual_short.txt`:

* Synthetic QA hits@3: **≥ 2/3** (ideally 3/3)
* Generation retrieval-on pass rate: **≥ 2/3**
* No “pass” when generated contradicts expected.

---

## Files to Change (Required)

### 1) `app/Services/Ai/Retriever.php`

**Problem it addresses**

* Your code is producing “top3” that contains the correct chunk, but the final “retrieved” list that gets reported/used elsewhere is different. Also generation retrieval appears to be using a different path or scope.

**Changes**

1. **Add explicit scoping options** to the retriever call (eval mode needs deterministic scoping):

   * Accept an `$opts` array with:

     * `knowledge_item_ids` (array of ULIDs)
     * `evaluation_id` (optional, for trace/log tags)
     * `source_variant` (already implied in your system; ensure it’s applied consistently)

   Example signature (illustrative):

   ```php
   public function retrieve(string $orgId, string $userId, string $query, array $opts = []): array
   ```

2. **Apply scope in the underlying query**:

   * If `$opts['knowledge_item_ids']` is provided:

     * `whereIn('knowledge_item_id', $opts['knowledge_item_ids'])`
   * Also ensure org/user scope is always applied (it is, but verify no “global” path bypasses it).

3. **Expose a trace payload** so the eval harness can store both:

   * `trace.topK` (post-score, pre-caps)
   * `trace.final` (post-caps/dedup/injections)
   * Include: `id`, `knowledge_item_id`, `distance`, `authority`, `confidence`, `time_horizon`, `chunk_type`, `chunk_role`, `source_variant`.

4. **Fix near-match protection consistency** (this is a common reason “top3” diverges from “final”):

   * If you have both `__near_match` and `__protected`, standardize on one (use `__protected`).
   * Ensure later capping/dedup logic **cannot evict protected chunks** without explicitly logging why.

**Acceptance criteria**

* For Q1/Q2, if `topK` includes the target chunk with distance ≤ `near_match_distance` (0.1), then:

  * `final` includes it, and
  * the eval report’s `details[].retrieved[]` includes it.

---

### 2) `app/Console/Commands/Ai/IngestionEvalCommand.php`

(or wherever `ai:ingestion:eval` lives)

**Problem it addresses**

* Generation retrieval-on is pulling old chunks unrelated to the eval knowledge_item.

**Changes**

1. Capture the eval-run `knowledge_item.id` (already in report: `019b85a2-033d-...`).
2. When running:

   * Synthetic QA retrieval
   * Generation probe retrieval-on
     pass `knowledge_item_ids: [$knowledgeItemId]` into the retriever options.

**Acceptance criteria**

* In `generation.retrieval_on.results[].diagnostics.retrieved_chunk_ids`, the returned IDs must be from this eval ingestion (e.g., `019b85a2-...` chunk IDs), not old IDs.

---

### 3) `app/Services/Ai/Evaluation/SyntheticQaService.php`

(or whichever class builds `evaluation.synthetic_qa`)

**Problem it addresses**

* `details[].retrieved[]` is not aligned with `diagnostics.top3`. Right now top3 is clearly computed from the correct new chunks, but retrieved[] is older.

**Changes**

1. Synthetic QA must call the same retriever function that returns the final list.
2. Report format update:

   * Store both:

     * `diagnostics.trace.topK` (first 3/5 items)
     * `diagnostics.trace.final` (the actual retrieved list, first k)
   * Set `details[].retrieved` = IDs from `trace.final`, not from any other list.
3. When computing `rank`, compute rank inside the *final* list; if not present but present in topK, mark `missed_retrieval:true` and include a `miss_reason` based on trace (dedup/cap/scope mismatch).

**Acceptance criteria**

* No case where `diagnostics.top3` contains the target but `details[].retrieved[]` is from a different corpus without an explicit `miss_reason`.

---

### 4) `app/Services/Ai/Evaluation/Graders/GenerationGrader.php`

(or your generation grading class)

**Problem it addresses**

* Q1 retrieval-on is graded pass even though it contradicts the expected answer.

**Changes**

1. Add a deterministic contradiction rule **before** any LLM-based grading:

   * If expected looks specific (date/money/number/month name) and generated contains uncertainty (“cannot determine”, “not disclosed”, “not included”), then grade **fail**.

2. Make grading mode deterministic in eval runs:

   * For eval harness, default to “contains-based” grading:

     * pass if expected summary is contained in generated OR generated matches a normalized-equivalence check.
   * Do not allow the “restraint is correct” rubric in eval harness unless the expected answer itself indicates unknown.

**Acceptance criteria**

* The exact Q1 retrieval-on in this report becomes **fail**, not pass, until retrieval returns the correct chunk(s) and the model outputs the expected date.

---

### 5) `config/ai.php`

**Problem it addresses**

* Eval harness needs deterministic grading and retrieval scoping toggles.

**Changes**
Add config entries (names are up to you, but must exist):

```php
'eval' => [
  'scope_to_knowledge_item' => env('AI_EVAL_SCOPE_TO_KI', true),
  'grader_mode' => env('AI_EVAL_GRADER_MODE', 'contains'), // contains|llm
],
```

Also update `.env.example`:

* `AI_EVAL_SCOPE_TO_KI=true`
* `AI_EVAL_GRADER_MODE=contains`

**Acceptance criteria**

* Eval harness always uses the deterministic path unless overridden.

---

## Definition of Done (Concrete Checks)

Run:
`php artisan ai:ingestion:eval --input=docs/fixtures/ingestion/factual_short.txt --run-generation ...`

Must satisfy:

1. **Synthetic QA**

* For Q1/Q2, `details[].retrieved[]` contains the actual target chunk IDs (the `019b85a2-1e..` series), not old IDs.
* hits@3 improves from **1/3 → ≥ 2/3**.

2. **Generation retrieval-on**

* For each question, `retrieved_chunk_ids` are scoped to the eval knowledge_item (`019b85a2-033d-...`) and its chunks (`019b85a2-1e..`).
* Q1 and Q2 should now answer correctly (or fail loudly with correct grading).

3. **Grader**

* “cannot determine” vs a specific expected answer is always **fail** in eval harness.

---

## What this implies (no fluff)

Right now your eval harness is not trustworthy: it mixes corpuses and it has a grader that can label wrong answers as perfect. Fix scoping + report alignment + contradiction grading first. Then, if you still miss hits@k, *then* tune weights/thresholds.
