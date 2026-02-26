Here is the engineering specification to resolve the "Empty Response" / Latency spike issue by implementing robust JSON parsing.

# Engineering Spec: Robust LLM JSON Parsing (v1.0)

**Priority:** High (Performance/Cost Impact)
**Component:** `App\Services\Ai\Generation\Steps\GenerationRunner`
**Objective:** Eliminate unnecessary regeneration cycles caused by the system failing to parse valid JSON wrapped in Markdown or conversational filler.

---

## 1. Problem Description

Logs from `RunID: 37d418d4` show the generation pipeline taking **36 seconds** due to a failed primary generation attempt.

* **Event 9 (`llm_result`)** returned empty content, triggering a full retry.
* **Root Cause:** The `GenerationRunner` likely expects a raw string starting immediately with `{` and ending with `}`. However, models (especially Claude 3.5 and GPT-4o) frequently wrap JSON in Markdown code blocks (````json ... ````) or add introductory text ("Here is the generated JSON:") despite negative constraints.
* **Current Behavior:** `json_decode()` fails on these strings  returns `null`  System assumes "Empty Generation"  Triggers Retry.

## 2. Technical Solution

Implement a **"Fuzzy JSON Extraction"** method within the `GenerationRunner`. Instead of attempting to decode the raw response string directly, the system must first locate and extract the JSON substring.

## 3. Implementation Requirements

**File:** `app/Services/Ai/Generation/Steps/GenerationRunner.php`

### 3.1. Parsing Logic Update

Modify the `runJsonContentCall` (or equivalent private method) to process the raw string before decoding.

**Required Logic:**

1. **Capture Raw:** Store the raw LLM string response *before* attempting any parsing (for logging purposes).
2. **Strip Fences:** Remove Markdown code fences (````json` and `````).
3. **Find Boundaries:** Locate the *first* occurrence of `{` and the *last* occurrence of `}`.
4. **Extract:** Isolate the substring between these indices.
5. **Decode:** Run `json_decode` on the cleaned substring.

### 3.2. Code Implementation (Pseudo-Code)

```php
private function cleanAndDecode(string $rawResponse): array
{
    // 1. Log the raw output immediately to debug future issues
    // Log::debug('Raw LLM Response', ['raw' => $rawResponse]);

    $clean = trim($rawResponse);

    // 2. Remove Markdown code blocks if present
    if (str_contains($clean, '```')) {
        $clean = preg_replace('/^```json\s*|```\s*$/i', '', $clean);
    }

    // 3. Extract JSON object from conversational filler
    // Finds the substring starting with { and ending with }
    $start = strpos($clean, '{');
    $end = strrpos($clean, '}');

    if ($start !== false && $end !== false) {
        $clean = substr($clean, $start, ($end - $start) + 1);
    }

    // 4. Attempt Decode
    $decoded = json_decode($clean, true);

    // 5. Throw explicit error if decode fails (caught by the retry logic)
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new JsonParsingException('Failed to decode JSON: ' . json_last_error_msg());
    }

    return $decoded;
}

```

### 3.3. Validation

Ensure the decoded array contains the required `content` key. If the key is missing or empty, throw an exception to trigger the *existing* regeneration logic (which is working correctly, just triggering too often).

---

## 4. Verification Plan

### 4.1. Unit Tests

Create a unit test `GenerationRunnerTest.php` with the following dirty inputs:

| Input Scenario | Raw String Example | Expected Result |
| --- | --- | --- |
| **Markdown Wrapped** | `json\n{"content": "Hello"}\n` | `['content' => 'Hello']` |
| **Conversational** | "Here is the JSON:\n\n{"content": "Hello"}" | `['content' => 'Hello']` |
| **Trailing Text** | "{"content": "Hello"}\nHope this helps!" | `['content' => 'Hello']` |
| **Broken JSON** | "{"content": "Hello" | *Exception (Trigger Retry)* |

### 4.2. Production Verification

1. Deploy fix.
2. Run `php artisan ai:generate` with a prompt known to trigger verbosity (e.g., a "complex analysis" prompt).
3. Check Logs:
* **Success:** `duration_ms` should be under 15,000ms.
* **Success:** No `regenerate_attempt` events for valid content.



---

## 5. Deployment Note

* **Risk:** Low. This change only relaxes the parsing strictness; it does not alter the generation logic.
* **Rollback:** Revert `GenerationRunner.php` to previous commit.