Supreme leader,

This run shows **two different bugs** clearly:

1. **Retriever is still returning the wrong rows** for Q1/Q2 (synthetic QA misses; generation uses old chunk IDs).
2. **Grader is broken** for Q1: it *passes* an answer that explicitly contradicts the expected answer (it says “cannot determine” but expected is “Jan 1, 2025”).

So you need an engineering spec that fixes **(A) retrieval correctness** and **(B) grading correctness**. Prompt tweaks are optional now; the grader + query plumbing are the real offenders.

---

# Engineering Spec: Fix Retriever Query Plumbing + Fix Generation Grader Logic

## Goal

For eval harness runs (`php artisan ai:ingestion:eval ... --run-generation`):

* Synthetic QA **hits@k** should reflect actual “top3” results (if top3 contains target chunk, the retrieved list must contain it).
* Retrieval-on generation should use the **same retriever path** as diagnostics.
* Grader should **fail** answers that contradict expected summaries.

Target outcome for `docs/fixtures/ingestion/factual_short.txt`:

* Synthetic QA hits@3: **≥ 2/3** (ideally 3/3)
* Retrieval-on generation pass rate: **≥ 2/3**
* Grader never passes “cannot determine” when expected summary is present in chunks.

---

## Observations from Current Output (Root-Cause Signals)

### Signal 1: “top3” shows the correct chunk, but “retrieved” list does not

Example Q1:

* `details[].diagnostics.top3[0].id` = **019b85a2-1e1e-...** (correct chunk, distance 0.0937)
* But `details[].retrieved[]` returns **older IDs** (019b8510..., 019b84fd..., 019b84c4...)

This is not an embedding issue. It’s **selection / filtering / finalization** returning different rows than the scored list, OR mixing org/user scope, OR applying “per KI / excerpt caps / injection” in a way that discards the correct item.

### Signal 2: Retrieval-on generation is using chunk IDs unrelated to this eval’s ingestion

Generation retrieval-on Q1 uses:
`retrieved_chunk_ids` = **019b84c6..., 019b84fd..., 019b8510...** (older)

So generation retrieval is not strictly scoped to this eval’s new `knowledge_item_id`, or it’s using a different retrieval path/config than synthetic QA.

### Signal 3: Grader passes a wrong answer

Q1 retrieval-on:

* expected: **January 1, 2025**
* generated: **cannot determine**
* grade: **pass true**
  This is a **grader logic defect**. Even if retrieval were perfect, this would hide failures.

---

# Part A — Make Retriever Return Correct Final Selection (No “top3/selected” divergence)

## A1. Add Trace Output for “topK” vs “final selected”

### File

`app/Services/Ai/Retriever.php`

### Method(s)

* `knowledgeChunks(...)`
* `knowledgeChunksTrace(...)` (already exists; extend it)

### Change

In `knowledgeChunksTrace`, include:

* IDs of **topK** (after scoring & sorting, before caps)
* IDs of **final selected** (after caps/injections/dedup)

Also include:

* `knowledge_item_id`
* `source_variant`
* `__near_match`
* `__protected`
* `chunk_type`
* `distance`

This lets you prove exactly where the correct chunk is being dropped.

**Acceptance criteria**

* When `diagnostics.top3` contains the target chunk, the final `retrieved[]` must contain it unless an explicit rule excludes it, and that exclusion must be logged.

---

## A2. Fix “protected/near-match” partition to use `__protected` consistently

You currently set both:

* `$r->__near_match`
* `$r->__protected`

But later partition uses `__near_match` in some places and `__protected` in others.

### File

`app/Services/Ai/Retriever.php`

### Methods

* `knowledgeChunks(...)`
* `knowledgeChunksTrace(...)`

### Change

Standardize: **use `__protected` everywhere** for “must-keep” behavior.

Specifically change these partitions:

```php
$protected = array_values(array_filter($topK, fn($r) => !empty($r->__near_match)));
$nonProtected = array_values(array_filter($topK, fn($r) => empty($r->__near_match)));
```

to:

```php
$protected = array_values(array_filter($topK, fn($r) => !empty($r->__protected)));
$nonProtected = array_values(array_filter($topK, fn($r) => empty($r->__protected)));
```

And all helper checks:

* `$hasProtectedInFinal`
* `$findReplaceIndex`

must check `__protected`, not `__near_match`.

**Why**
`__near_match` is a derived property; `__protected` is the policy. You already introduced it; now actually use it.

---

## A3. Ensure “max per KI / excerpt cap / replacement” cannot evict protected chunks

Right now you “protect-first”, but later injection/replacement might still push things around.

### File

`app/Services/Ai/Retriever.php`

### Methods

* `knowledgeChunks(...)`

### Change

Add a final guard right before returning `$final`:

```php
$final = array_values($final);

// Hard guarantee: if any protected chunks exist in $topK, they must be in $final (up to $limit)
$protectedTop = array_values(array_filter($topK, fn($r) => !empty($r->__protected)));
foreach ($protectedTop as $p) {
    if (count($final) >= $limit) { break; }
    $already = false;
    foreach ($final as $x) {
        if ((string)$x->id === (string)$p->id) { $already = true; break; }
    }
    if (!$already) { $final[] = $p; }
}
```

If this logs overflow, you tune `near_match_distance` or increase `return_k`.

**Acceptance criteria**

* In this fixture, Q1/Q2’s protected chunk IDs appear in final selected.

---

# Part B — Stop Retrieval-On Generation from Pulling Old/Other Chunks

Right now, generation retrieval-on is clearly pulling from the global corpus, not from this new knowledge item. That may be “correct” for the product, but it makes eval harness unreliable.

You need **eval-mode scoping**.

## B1. Add “evaluation scope” filter to retriever (optional parameter)

### File

`app/Services/Ai/Retriever.php`

### Change signature

Add optional `$scopeKnowledgeItemIds = []` or `$scopeEvaluationId = null`.

Example:

```php
public function knowledgeChunks(
  string $organizationId,
  string $userId,
  string $query,
  array $opts = []
)
```

Where `$opts['knowledge_item_ids']` restricts results:

```php
if (!empty($opts['knowledge_item_ids'])) {
   $q->whereIn('knowledge_item_id', $opts['knowledge_item_ids']);
}
```

### Files that must pass this option (eval only)

* `app/Console/Commands/Ai/IngestionEvalCommand.php` (or your artisan command file)
* `app/Services/Ai/Evaluation/IngestionEvalHarness.php` (or wherever generation probe is run)

When running generation probe during eval, pass the ingested `knowledge_item.id` for that evaluation.

**Acceptance criteria**

* `generation.retrieval_on.results[].diagnostics.retrieved_chunk_ids` should be **only** chunks belonging to the eval’s `knowledge_item.id` (019b85a2-033d-...).

---

# Part C — Fix the Generation Grader (It is Currently Invalid)

Your grader passed Q1 even though it contradicted expected answer.

## C1. Make grader fail explicit “cannot determine” when expected contains a concrete value

### File

`app/Services/Ai/Evaluation/Graders/GenerationGrader.php` (or equivalent)

### Change

Add a deterministic pre-check before any LLM grading:

```php
$expected = strtolower(trim($expectedSummary));
$gen = strtolower($generated);

// if expected contains a specific token (date, money, number) and generated contains refusal/uncertainty, fail
$uncertain = str_contains($gen, "cannot determine")
  || str_contains($gen, "not disclosed")
  || str_contains($gen, "insufficient information")
  || str_contains($gen, "not included");

$expectedLooksSpecific = preg_match('/\d{4}|\$|million|january|february|march|april|may|june|july|august|september|october|november|december/', $expected);

if ($expectedLooksSpecific && $uncertain) {
   return [
     'pass' => false,
     'score' => 0.0,
     'rationale' => 'Generated answer expresses uncertainty/refusal despite expected specific answer.'
   ];
}
```

This prevents the grader from “rewarding caution” when the test expects retrieval-backed facts.

**Acceptance criteria**

* Q1 retrieval-on in this report would be graded **fail**, not pass, until retrieval actually includes the correct chunk and the model answers correctly.

---

## C2. Ensure “contains” checks are used when configured

You already have grading rationale lines like:

* `"expected summary contained in generated (normalized)"`

But Q1 rationale is different (it praised uncertainty).

So the grader has **two paths**:

* contains-based
* LLM-judge-based

Make it deterministic: for eval harness, use contains-based by default.

### File

`app/Services/Ai/Evaluation/Graders/GenerationGrader.php`

### Change

Add config flag:

* `config('ai.eval.grader_mode', 'contains')` for eval harness runs.

Set default for eval command to `contains`.

### File

`config/ai.php` (or `config/ai.php` + `config/ai_eval.php` if you split)

Add:

```php
'eval' => [
  'grader_mode' => env('AI_EVAL_GRADER_MODE', 'contains'),
],
```

### File

`.env.example`
Add:
`AI_EVAL_GRADER_MODE=contains`

**Acceptance criteria**

* No more “pass because cautious” outcomes.

---

# Part D — Update Synthetic QA Retrieval Diagnostics to Use the Same Retrieval Call

Right now, synthetic QA “top3” is computed from something, but the “retrieved” list comes from another selection stage. That’s fine if it’s intended, but it’s confusing.

## D1. Compute diagnostics.top3 from the same dataset as the returned “retrieved”

### File

`app/Services/Ai/Evaluation/SyntheticQAService.php` (or equivalent)

### Change

Instead of generating `top3` from an intermediate list, derive it from:

* the retriever trace output that includes topK and final

So your report should show:

* `trace.topK[0..3]`
* `trace.final[0..3]`

And “missed_retrieval” should be true only when:

* target not in `final`, even though it was in `topK` with good distance

**Acceptance criteria**

* The report makes it impossible to misinterpret (no more “top3 contains it but retrieved doesn’t” without seeing exactly why).

---

# Definition of Done

Run:
`php artisan ai:ingestion:eval ... factual_short ... --run-generation`

Must satisfy:

1. **Retriever correctness**

* For Q1/Q2, `synthetic_qa.details[].retrieved` contains the target chunk id if `topK` contains it with distance <= near_match_distance.

2. **Eval scoping**

* `generation.retrieval_on.results[].diagnostics.retrieved_chunk_ids` belong to the current eval’s `knowledge_item.id`.

3. **Grader correctness**

* A response that says “cannot determine” while expected is a specific date/price **fails**.

---

# What this latest run tells you bluntly

* Your retrieval improvements are working enough to get **2/3** retrieval-on passes, but the harness is **lying** because the grader is inconsistent and generation retrieval is likely pulling from older data.
* Fixing the grader + eval scoping will make the harness a real signal again. Then you can tune ranking safely.

If you paste the filenames/classes for:

* the eval command
* the synthetic QA service
* the generation grader
  I can rewrite this spec with the *exact* class/method names you’re using (but the above is already implementable with the paths you’ve shown: `app/Services/Ai/Retriever.php`).
