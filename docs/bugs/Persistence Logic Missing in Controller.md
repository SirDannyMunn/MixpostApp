

# Feedback: Persistence Logic Missing in Controller

**Status:** ❌ Database records not updating
**Severity:** High (Feature blocking)

### 1. The Issue

While you successfully updated `ContextAssembler` and `AiController` to **pass** the `conversation_id` and `voice_profile_id` *into* the generation process, the code that takes the **result** and updates the database record is missing.

Your notes mention: *"This enables SnapshotService to update AiCanvasConversation"*.
However, unless you modified `SnapshotPersister.php` (which is not in the diffs) to perform a direct DB write on the conversation model, the update never happens.

**We should stick to the original requirement: Handle the update in the Controller.** This is safer because the Controller owns the `Conversation` model context, whereas the Service layer is often unaware of the specific Eloquent models used by the API.

### 2. The Missing Code

You modified the **input preparation** in `AiController::generateChatResponse`, but you did not modify the **response handling**.

**Current Flow (Incomplete):**
`Request` -> `Controller` (Prepares Options) -> `Service` (Generates) -> `Controller` (Returns JSON)

**Required Flow:**
`Request` -> `Controller` (Prepares Options) -> `Service` (Generates) -> **`Controller` (UPDATES DB)** -> `Controller` (Returns JSON)

### 3. Required Fix

Please update `app/Http/Controllers/Api/V1/AiController.php` immediately after the `$this->contentGenerator->generate(...)` call.

**Copy/Paste this logic into `AiController.php`:**

```php
// ... inside generateChatResponse, existing code ...

$result = $this->contentGenerator->generate($orgId, $userId, $prompt, $platform, $options);

// =================================================================
// 🔴 MISSING BLOCK: Persist the context back to the Conversation
// =================================================================
if (!empty($conversationId)) {
    // 1. Resolve the model (assuming you have a Conversation model imported)
    // You might need: use App\Models\AiCanvasConversation;
    $conversation = \App\Models\Conversation::find($conversationId);

    if ($conversation) {
        $conversation->update([
            'last_message_at'         => now(),
            // Map the run_id from metadata
            'last_snapshot_id'        => $result['metadata']['run_id'] ?? null,
            // Map the specific context IDs returned by your ContextAssembler fix
            'active_voice_profile_id' => $result['context_used']['voice_profile_id'] ?? null,
            'active_template_id'      => $result['metadata']['template_id'] ?? null,
            'active_swipe_ids'        => $result['context_used']['swipe_ids'] ?? [],
            'active_fact_ids'         => $result['context_used']['fact_ids'] ?? [],
            'active_reference_ids'    => $result['context_used']['reference_ids'] ?? [],
        ]);
    }
}
// =================================================================

return response()->json($result);

```

### 4. Verification Checklist

Before submitting, please verify:

1. Run a generation request.
2. Check the `conversations` table in the database.
3. Ensure `active_voice_profile_id` is **NOT NULL**.
4. Ensure `last_snapshot_id` matches the `run_id` in the JSON response.