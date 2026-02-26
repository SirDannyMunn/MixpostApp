# LLM-Based Classification System

## Date: January 12, 2026

## Overview

Implemented **LLM-powered classification** as the single authoritative decision point for mode/submode selection in the MixpostApp system. The classifier determines read/write permissions, execution mode (generate vs research), and research sub-modes in a single API call.

## Architecture

### Single Source of Truth

`POST /api/v1/ai/classify-intent` is the **only** decision engine for:
- Read permission (`true`/`false`)
- Write permission (`true`/`false`)
- Execution mode (`generate` or `research`)
- Research sub-mode (`deep_research`, `angle_hooks`, or `null`)
- Confidence score (0.0 to 1.0)

**No downstream overrides are allowed.** The classifier's decision is final.

### Decision Flow

```
User Message
     ↓
LLM Classifier (classify-intent)
     ↓
{ read, write, mode, submode, confidence }
     ↓
Chat Controller (hard routing)
     ↓
ResearchExecutor (if research)
     OR
ContentGeneratorService (if generate)
```

## API Contract

### Request

```http
POST /api/v1/ai/classify-intent
Content-Type: application/json

{
  "message": "Update this post: we raised £157,000",
  "document_context": "..." // optional
}
```

### Response

```json
{
  "read": false,
  "write": true,
  "mode": "generate",
  "submode": null,
  "confidence": 0.98
}
```

### Valid Combinations

| read | write | mode     | submode       | Meaning                          |
|------|-------|----------|---------------|----------------------------------|
| false| true  | generate | null          | Create new content               |
| true | true  | generate | null          | Edit existing content            |
| true | false | generate | null          | Summarize/analyze (no mutation)  |
| false| false | research | deep_research | Research without document        |
| false| false | research | angle_hooks   | Generate hook ideas              |
| true | false | research | deep_research | Analyze document (research mode) |

**Invalid combinations throw exceptions:**
- `write=true` with `mode=research` → Runtime error
- `mode=research` with `submode=null` → Runtime error

## Classification Logic

### Prompt Structure

The LLM receives a structured prompt that defines all fields together:

```
FIELDS TO DECIDE:
1. read: Does this require reading the current document?
2. write: Does the user want to write/modify content?
3. mode: Execution mode - 'generate' or 'research'
4. submode: Research sub-type - 'deep_research', 'angle_hooks', or null
5. confidence: How certain are you? (0.0 to 1.0)

CRITICAL RULES:
- Editing, rewriting, updating, creating → mode = 'generate', submode = null
- Research, analysis, investigation → mode = 'research', write = false
- Requesting hooks, angles, or ideas → mode = 'research', submode = 'angle_hooks'
- If mode = 'research', you MUST select exactly one submode
- If write = true, mode MUST be 'generate'

EXAMPLES:
"Update this post: we raised £157,000" 
  → {"mode": "generate", "write": true, "submode": null}

"What are people saying about AI?"
  → {"mode": "research", "submode": "deep_research", "write": false}

"Give me 5 hooks for AI tools"
  → {"mode": "research", "submode": "angle_hooks", "write": false}
```

### Submode Semantics

**deep_research:**
- Trigger phrases: analysis, evidence, pros/cons, synthesis, "what are people saying", trends
- Use case: Cluster analysis of existing content, market intelligence
- Output: Research report with claims, disagreements, angles

**angle_hooks:**
- Trigger phrases: hooks, angles, headlines, openers, "give me ideas"
- Use case: Creative ideation for content starters
- Output: List of hooks with archetypes and confidence scores

## Safety Guards

### 1. Write-Intent Veto

```php
if ($write && $mode === 'research') {
    throw new \RuntimeException(
        'Invalid: write requests cannot be research mode'
    );
}
```

**Rationale:** Research mode is read-only by design. Users cannot accidentally trigger document mutation during research.

### 2. Research Requires Submode

```php
if ($mode === 'research' && $submode === null) {
    throw new \RuntimeException(
        'Invalid: research mode requires submode'
    );
}
```

**Rationale:** Every research operation must have a clear execution path (deep_research or angle_hooks).

### 3. Confidence Threshold Warning

```php
if ($mode === 'research' && $confidence < 0.7) {
    Log::warning('ai.classify-intent.low_confidence', [
        'mode' => $mode,
        'confidence' => $confidence,
        // ... context
    ]);
}
```

**Rationale:** Low-confidence research classifications are logged for monitoring. Execution still proceeds (no silent downgrade).

## Chat Routing (Hard Rule)

### Before (Problematic)

```php
// Mixed heuristics, keyword detection, optional overrides
$classifier = app(ResearchStageClassifier::class);
$decision = $classifier->classify($message);

if ($decision->isResearch) {
    // But frontend might have sent mode=generate...
}
```

**Problem:** Multiple decision points, inconsistent behavior, accidental mode triggering.

### After (Authoritative)

```php
// Single classification call
$classificationResult = $openRouter->classify([...]);

// Extract decision (no second-guessing)
$mode = $classificationResult['mode'];
$submode = $classificationResult['submode'];

// Apply to options (hard rule)
$options['mode'] = $mode;
$options['research_stage'] = $submode;

// Route based on classifier decision ONLY
if ($mode === 'research') {
    return $this->executeResearch($submode, ...);
}
return $this->generateContent(...);
```

**Result:** One classifier. One decision. No guessing.

## Implementation Files

### Core Controller

**`app/Http/Controllers/Api/V1/AiController.php`**

Key methods:
- `classifyIntent()` - LLM classification endpoint
- `buildClassificationPrompt()` - Prompt engineering
- `generateChatResponse()` - Chat routing with hard rule

### Supporting Services

- `app/Services/OpenRouterService.php` - LLM API wrapper
- `app/Services/Ai/ContentGeneratorService.php` - Generation executor
- `app/Services/Ai/Research/ResearchExecutor.php` - Research executor

### Documentation

- `docs/features/classification-system-update-jan-2026.md` - Implementation guide
- `docs/features/ai_content_generation_chat_system.md` - System overview
- `docs/features/research-chat-mode.md` - Research mode docs

## Observability

### Classification Logging

Every classification decision is logged to `ai.classify-intent`:

```php
Log::info('ai.classify-intent', [
    'message_preview' => mb_substr($message, 0, 120),
    'has_context' => (bool) $documentContext,
    'read' => true,
    'write' => false,
    'mode' => 'research',
    'submode' => 'deep_research',
    'confidence' => 0.92,
]);
```

### Low Confidence Warnings

```php
Log::warning('ai.classify-intent.low_confidence', [
    'mode' => 'research',
    'submode' => 'deep_research',
    'confidence' => 0.65,
    'message_preview' => '...',
]);
```

### Query Patterns

```sql
-- Classification accuracy monitoring
SELECT 
    mode,
    submode,
    AVG(confidence) as avg_confidence,
    COUNT(*) as total
FROM classification_logs
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY mode, submode;

-- Low confidence events
SELECT message_preview, mode, confidence
FROM classification_logs
WHERE confidence < 0.7 AND mode = 'research'
ORDER BY created_at DESC;
```

## Testing & Validation

### Test Script

`Scratch/test_new_classifier.php` validates all critical paths:

```bash
php artisan tinker-debug:run test_new_classifier
```

### Test Cases

| Message | Expected Mode | Expected Submode | Status |
|---------|---------------|------------------|--------|
| "Update this post: we raised £157k" | generate | null | ✓ PASS |
| "What are people saying about AI?" | research | deep_research | ✓ PASS |
| "Give me 5 hooks for AI tools" | research | angle_hooks | ✓ PASS |
| "Rewrite the introduction" | generate | null | ✓ PASS |
| "Write a blog post about AI" | generate | null | ✓ PASS |
| "Analyze trends in SaaS pricing" | research | deep_research | ✓ PASS |

### Exact Production Case

The original failing case now works correctly:

```bash
php artisan tinker-debug:run test_exact_failing_case
```

**Input:** "Update this post: we've already raised £157,000..."  
**Output:** `mode=generate, write=true, confidence=0.98` ✓

## Performance

### Latency

- Classification call: ~200-500ms (OpenRouter API)
- No local computation (pure LLM)
- Cached for conversation context (future enhancement)

### Token Usage

- Typical prompt: ~800 tokens
- Response: ~50 tokens
- Cost per classification: ~$0.001 (OpenRouter pricing)

## Migration & Deprecation

### Removed Components

The following are **deleted** (no longer needed):

- ❌ `app/Services/Ai/Classification/ResearchStageClassifier.php` (keyword-based)
- ❌ `app/Services/Ai/Classification/ResearchStageDecision.php` (DTO for old system)
- ❌ `config/research_stages.php` (keyword configuration)

### Backward Compatibility

- Existing `mode` and `research_stage` options still work (for explicit overrides)
- CLI commands unchanged (`php artisan ai:research:ask --stage=...`)
- No database migrations required
- No breaking API changes

## Benefits

### For Users

- ✓ Natural language classification (no mode selection needed)
- ✓ Consistent behavior across all entry points
- ✓ Clear confidence scores
- ✓ No more accidental research mode

### For Developers

- ✓ Single source of truth (no scattered conditionals)
- ✓ LLM-powered (no brittle keyword matching)
- ✓ Easy to tune via prompt engineering
- ✓ Full observability via logging
- ✓ Type-safe decision structure

### For System

- ✓ Deterministic routing (one decision)
- ✓ Replayable (logged with confidence)
- ✓ Extensible (add submodes via examples)
- ✓ Self-documenting (prompt contains rules)

## Tuning & Maintenance

### Adjusting Classification

**To bias toward generation:**
```
Add more examples of generation patterns
Emphasize "creating" and "writing" keywords in rules
```

**To bias toward research:**
```
Add more examples of research patterns
Lower confidence threshold for warnings
```

### Adding New Submodes

1. Update `buildClassificationPrompt()` with new rules
2. Add examples to prompt
3. Update safety guards if needed
4. Add test cases
5. Deploy (no config files needed)

### Monitoring Misclassifications

```bash
# Check logs for unexpected classifications
tail -f storage/logs/laravel.log | grep "ai.classify-intent"

# Look for patterns in low-confidence decisions
grep "low_confidence" storage/logs/laravel.log | jq '.confidence, .message_preview'
```

## Conclusion

The new LLM-based classification system provides:

1. **Single authoritative decision** - No guessing, no overrides
2. **Natural language understanding** - LLM interprets intent correctly
3. **Safety by design** - Invalid combinations throw errors
4. **Full observability** - Every decision is logged
5. **Easy maintenance** - Prompt engineering (no config files)

The original issue (accidental research mode triggering) is **fully resolved**.

