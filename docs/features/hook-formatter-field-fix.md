# Hook Formatter Field Name Fix

**Date:** January 12, 2026  
**Issue:** Hook responses were not formatted correctly in the chat endpoint

## Problem

When requesting hook ideas via the research mode (e.g., "give me 5 hook ideas for Multi-platform search fragmentation"), the API response showed:

```json
{
    "report": {
        "formatted_report": "## Creative Hooks",
        "raw": {
            "hooks": [
                {
                    "text": "Ever typed the same query...",
                    "archetype": "Rhetorical Question"
                }
            ]
        }
    }
}
```

The `formatted_report` only contained the heading, but none of the actual hook content was displayed.

## Root Cause

**Field name mismatch** between the data producer and consumer:

**`HookGenerationService` returns:**
```php
[
    'text' => 'Hook text here',
    'archetype' => 'Rhetorical Question'
]
```

**`ChatResearchFormatter` was looking for:**
```php
$hookText = trim((string) ($hook['hook_text'] ?? ''));  // WRONG FIELD NAME
```

The formatter was looking for `hook_text` but the service returns `text`, causing all hooks to be skipped during formatting.

## The Fix

Updated [app/Services/Ai/Research/Formatters/ChatResearchFormatter.php](c:\laragon\www\MixpostApp\app\Services\Ai\Research\Formatters\ChatResearchFormatter.php):

```php
// OLD (BROKEN)
$hookText = trim((string) ($hook['hook_text'] ?? ''));

// NEW (FIXED) - supports both formats for backward compatibility
$hookText = trim((string) ($hook['text'] ?? $hook['hook_text'] ?? ''));
```

This change:
1. Prioritizes the correct field name: `text`
2. Falls back to legacy `hook_text` for backward compatibility
3. Ensures hooks are properly formatted

## Expected Output After Fix

```json
{
    "report": {
        "formatted_report": "## Creative Hooks\n\n### Hook 1 - Rhetorical Question\n\nEver typed the same query into Google, TikTok, and Bing—only to get wildly different worlds? That's search fragmentation in action.\n\n### Hook 2 - Problem Statement\n\nMulti-platform search is splintering the internet: One query, a dozen realities.\n\n...",
        "raw": {
            "hooks": [...]
        }
    }
}
```

## Files Modified

- [app/Services/Ai/Research/Formatters/ChatResearchFormatter.php](c:\laragon\www\MixpostApp\app\Services\Ai\Research\Formatters\ChatResearchFormatter.php) - Fixed field name in `formatAngleHooks()` method

## Files Verified (No Changes Needed)

- [app/Services/Ai/Research/Formatters/CliResearchFormatter.php](c:\laragon\www\MixpostApp\app\Services\Ai\Research\Formatters\CliResearchFormatter.php) - Already using correct `text` field
- [app/Services/Ai/Research/HookGenerationService.php](c:\laragon\www\MixpostApp\app\Services\Ai\Research\HookGenerationService.php) - Correctly outputs `text` field

## Testing

Created test: [Scratch/test_hook_formatter.php](c:\laragon\www\MixpostApp\Scratch\test_hook_formatter.php)

**Test Result:** ✅ All hooks formatted correctly

```
### Hook 1 - Rhetorical Question
Ever typed the same query into Google, TikTok, and Bing—only to get wildly different worlds?

### Hook 2 - Problem Statement
Multi-platform search is splintering the internet: One query, a dozen realities.
...
```

## Impact

- ✅ Hook requests now display formatted content properly
- ✅ All 5 hooks visible in chat response
- ✅ Archetype labels displayed correctly
- ✅ Backward compatible with legacy `hook_text` field
- ✅ CLI formatter already compatible (no changes needed)

## Related Issues Fixed

This completes the full fix for hook requests:
1. **Classification issue** - Frontend `mode: "generate"` was bypassing auto-classification ✅ FIXED
2. **Formatting issue** - Hooks not displaying in formatted report ✅ FIXED

Both issues are now resolved, and the complete flow works end-to-end.
