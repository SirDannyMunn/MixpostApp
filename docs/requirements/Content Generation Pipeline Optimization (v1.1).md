Here is the engineering specification to resolve the identified issues. This spec targets the `ContentGeneratorService`, `ContextFactory`, and `Retriever` classes.

# Engineering Spec: Content Generation Pipeline Optimization (v1.1)

**Status:** Draft
**Priority:** High
**Owner:** Backend Engineering
**Effort Estimate:** 1.5 Days (Dev + Test)

## 1. Executive Summary

This feature patch addresses three inefficiencies in the `ContentGeneratorService` pipeline discovered during audit `RunID: 0cda8f30`.

1. **Context Duplication:** Identical text in `user_context` and `business_context` is inflating token costs by ~30%.
2. **Data Integrity:** Token usage metrics in the database do not match actual LLM usage.
3. **Retrieval "Silence":** The RAG pipeline returns 0 results for broad queries due to overly aggressive relevance thresholds.

---

## 2. Issue 1: Context Duplication (The "Echo" Effect)

### Problem Description

The `ContentGeneratorService` currently treats `user_context` (from the request) and `business_context` (resolved from the org) as distinct inputs, even when they are identical strings. This results in the prompt containing two copies of the same ~450-token block.

### Technical Solution

Implement a **Semantic Deduplication Step** within `ContextFactory::fromParts`.

### Implementation Details

**File:** `app/Services/Ai/Generation/Factories/ContextFactory.php`

1. **Modify `fromParts` method:**
Before assigning properties to the `Context` object, compare `$parts['user_context']` and `$parts['business_context']`.
2. **Logic:**
* Normalize both strings (trim, lowercase).
* Calculate `similar_text` percentage or simple strict equality `===`.
* **Rule:** If similarity > 90%, prioritize `business_context` and set `user_context` to `null` to avoid prompt pollution.



```php
// Pseudo-code implementation
$userCtx = trim($parts['user_context'] ?? '');
$bizCtx = trim($parts['business_context'] ?? '');

if ($bizCtx !== '' && $userCtx !== '') {
    // Check for exact duplication or high similarity
    if ($userCtx === $bizCtx || str_contains($userCtx, $bizCtx)) {
         // User context is just a copy of business context -> drop it
        $parts['user_context'] = null;
        Log::info('ContextFactory: Pruned duplicate user_context');
    }
}

```

### Verification

* **Unit Test:** Pass identical strings to `ContextFactory`. Assert the resulting `Context` object has `user_context === null`.
* **Impact:** Token usage for `run_id: 0cda8f30` replay should drop from ~1156 to ~700.

---

## 3. Issue 2: Token Usage Data Mismatch

### Problem Description

The `SnapshotPersister` records token usage from the initial `options` array passed at the *start* of the request. However, the pipeline dynamically assembles, prunes, and modifies context during execution. This causes a discrepancy (e.g., Logs show 1156 tokens, DB shows 609).

### Technical Solution

Update `ContentGeneratorService` to pass the **final** authoritative usage stats from the `Context` object into the `SnapshotPersister`.

### Implementation Details

**File:** `app/Services/Ai/ContentGeneratorService.php`

1. **Locate Step 7 (Persist Snapshot):**
Currently, the code merges `options` with a `token_usage` array derived early in the process.
2. **Refactor:**
Extract the usage *after* the `generate` call or directly from the `Context` debug method just before persistence.

```php
// Current (Flawed)
$usage = (array) ($debug['usage'] ?? []); // This might be stale if captured too early

// Fix
$finalUsage = $context->calculateTokenUsage(); // Ensure this method exists or use debug()
$optionsForSnapshot['token_usage'] = $finalUsage;

```

3. **Database Migration (Optional):**
If strict reporting is needed, add a dedicated `actual_tokens_used` integer column to the `generation_snapshots` table, rather than burying it in the `options` JSON blob.

---

## 4. Issue 3: Retrieval "Silence" (Zero Chunks)

### Problem Description

The `Retriever` service likely uses a fixed minimum similarity score (e.g., `min_score > 0.7`) to filter vector search results. Broad queries like "Write a post about AI content" (Top of Funnel) often yield scores around `0.6 - 0.65`, resulting in 0 chunks returned.

### Technical Solution

Implement **Adaptive Thresholding** (Fallback Retrieval). If the primary strict search yields 0 results, perform a secondary "loose" search.

### Implementation Details

**File:** `app/Services/Ai/Retriever.php`

1. **Modify `knowledgeChunks` method:**

```php
public function knowledgeChunks(..., $limit = 3): array
{
    // Attempt 1: High Precision (Strict)
    $chunks = $this->vectorDb->search($query, min_score: 0.75, limit: $limit);

    // Fallback: If no results, try wider net (High Recall)
    if (empty($chunks)) {
        Log::info("Retriever: Strict search failed, attempting fallback.");
        $chunks = $this->vectorDb->search($query, min_score: 0.60, limit: $limit);
    }

    return $chunks;
}

```

2. **Configuration:**
Move these hardcoded thresholds (`0.75`, `0.60`) into a config file `config/ai.php` so they can be tuned without code deploys.

### Verification

* **Test Case:** Run generation with the prompt: *"write a post about ai content"*.
* **Success Criteria:** `context_used.chunk_ids` in the response must NOT be empty.

---

## 5. Deployment Plan

1. **Hotfix Branch:** `fix/context-duplication-and-retrieval`
2. **Deploy Order:**
* Deploy Code Changes.
* Clear Application Cache (to load new retrieval configs).
* (No Database migrations required).


3. **Sanity Check:**
* Replay the specific snapshot `019b773f-967f-7390-aa34-33bdaadd2732` using the `replayFromSnapshot` endpoint.
* Verify the prompt in logs contains only ONE instance of the context.