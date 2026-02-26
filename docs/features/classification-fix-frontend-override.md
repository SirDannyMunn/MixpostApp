# Classification Fix: Frontend Override Issue

**Date:** January 12, 2026  
**Issue:** "give me 5 hook ideas" was incorrectly routed to generation mode instead of research mode

## Root Cause

The frontend was explicitly sending `"mode": "generate"` in the options, and the backend had a condition that prevented auto-classification when this was set:

```php
// OLD CODE (BROKEN)
if ($explicitMode !== 'generate' && $explicitStage === null) {
    // Auto-classify...
}
```

This meant:
- When frontend sent `mode: "generate"` → Auto-classification was **skipped**
- Result: Hook requests like "give me 5 hook ideas" were treated as generation instead of research

## The Fix

Changed the logic to make the **classifier authoritative**. It now runs auto-classification unless an **explicit research_stage** is provided:

```php
// NEW CODE (FIXED)
if ($explicitStage === null) {
    // Auto-classify (classifier decision is authoritative)
    $classifier = app(ResearchStageClassifier::class);
    $decision = $classifier->classify($message);
    
    if ($decision->isResearch) {
        $options['mode'] = 'research';  // Override frontend hint
        $options['research_stage'] = $decision->stage->value;
    }
}
```

## Behavior Changes

| Scenario | Before Fix | After Fix |
|----------|-----------|-----------|
| `"give me hook ideas"` with no options | ✓ Research | ✓ Research |
| `"give me hook ideas"` with `mode: "generate"` | ✗ Generation | ✓ **Research** (classifier overrides) |
| `"write a post"` with no options | ✓ Generation | ✓ Generation |
| Explicit `research_stage: "deep_research"` | ✓ Research | ✓ Research (no classification) |

## Files Modified

1. **`app/Http/Controllers/Api/V1/AiController.php`**
   - `generateChatResponse()`: Removed `$explicitMode !== 'generate'` condition
   - `classifyIntent()`: Simplified to always run classifier

2. **`docs/features/research-classification-implementation.md`**
   - Updated to clarify that classifier is authoritative
   - Added examples showing frontend override behavior

## Testing

Created comprehensive test suite in `Scratch/`:
- `test_hook_classification.php` - Tests the classifier directly (100% confidence for hook requests)
- `test_classify_endpoint.php` - Tests the `/api/v1/ai/classify-intent` endpoint
- `test_e2e_classification.php` - End-to-end tests covering all scenarios

**All tests pass:**
- ✅ Hook requests → `angle_hooks` research
- ✅ Deep research requests → `deep_research`
- ✅ Generation requests → `generate` mode
- ✅ Explicit stage → Bypasses classification
- ✅ Frontend `mode: "generate"` → Overridden by classifier when research is detected

## Key Principle

**The classifier is the single source of truth.** Frontend hints like `mode: "generate"` are just suggestions that can be overridden. Only an **explicit `research_stage` parameter** bypasses auto-classification.

This ensures:
1. Consistent classification across all entry points
2. Users get the right mode even if frontend makes mistakes
3. Research detection is deterministic and config-driven
4. Backend is authoritative, not frontend

## Impact

- **Immediate:** Hook requests now work correctly even when frontend sends wrong mode
- **Long-term:** Classification is more robust and less prone to frontend bugs
- **Backward compatible:** Explicit `research_stage` still works as before
