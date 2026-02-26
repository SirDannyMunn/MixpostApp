Here is the requirements document to fix the persistence issue in the `conversations` table.

## Requirements Document: Conversation Context Persistence

### 1. Problem Statement

The `conversations` table schema includes fields to track the active stylistic context (Voice, Template, Swipes, Facts, References). Currently, these fields (`active_voice_profile_id`, `active_template_id`, etc.) remain `NULL` after content generation requests.

Because this "Active Context" is not persisted, subsequent requests (such as "Rewrite," "Shorten," or "Make it funny") fail to access the original stylistic settings. This forces the generator to revert to a generic "default" tone, breaking the user's workflow and style consistency.

### 2. Objective

Update the `Conversation` record immediately after every successful content generation (or context modification) to reflect the exact assets used. This ensures that the conversation "remembers" the settings, allowing future requests to inherit the correct Voice, Tone, and Template.

### 3. Data Mapping Requirements

The system must extract IDs from the `ContentGeneratorService::generate()` response and map them to the `conversations` table columns as follows:

| Source (Generation Result) | Target Column (`conversations`) | Type | Logic |
| --- | --- | --- | --- |
| `context_used.voice_profile_id` | `active_voice_profile_id` | UUID | Last successful voice used. |
| `metadata.template_id` | `active_template_id` | UUID | The template resolved and used. |
| `context_used.swipe_ids` | `active_swipe_ids` | JSON | Array of swipe IDs used. |
| `context_used.fact_ids` | `active_fact_ids` | JSON | Array of business fact IDs used. |
| `context_used.reference_ids` | `active_reference_ids` | JSON | Array of reference (knowledge) IDs. |
| `run_id` | `last_snapshot_id` | UUID | The ID of the most recent generation run. |

### 4. Technical Implementation

#### 4.1. Controller Layer (`ContentGenerationController`)

The controller responsible for handling the `generate` request is the primary integration point. It must not only return the result to the frontend but also side-load the state into the database.

**Requirement:**
After a successful call to `$service->generate(...)`, the controller **must** perform an update on the `Conversation` model associated with the request.

**Pseudo-Code Logic:**

```php
$result = $service->generate($orgId, $userId, $prompt, ...);

// ... existing logic to create/find Document ...

// NEW REQUIREMENT: Persist Context to Conversation
$conversation->update([
    'last_message_at'          => now(),
    'last_snapshot_id'         => $result['metadata']['run_id'],
    'active_voice_profile_id'  => $result['context_used']['voice_profile_id'] ?? null,
    'active_template_id'       => $result['metadata']['template_id'] ?? null,
    'active_swipe_ids'         => $result['context_used']['swipe_ids'] ?? [],
    'active_fact_ids'          => $result['context_used']['fact_ids'] ?? [],
    'active_reference_ids'     => $result['context_used']['reference_ids'] ?? [],
]);

```

#### 4.2. Service Layer Adjustment (`ContentGeneratorService`)

**Verification Required:** Ensure the `generate()` method returns `voice_profile_id` inside the `context_used` array.

* *Current behavior:* The logs suggest `context_used` contains `template_id`, `fact_ids`, etc., but `voice_profile_id` might be missing or only located in the snapshot data.
* *Requirement:* Update `ContentGeneratorService::generate` return array to explicitly include:
```php
'context_used' => [
     // ... existing fields
     'voice_profile_id' => $context->voice?->id, // Ensure this is accessible
]

```



### 5. Acceptance Criteria

1. **Persistence:** After a generation request completes, a SQL query on the `conversations` table for that `id` must show non-null values for `active_voice_profile_id`, `active_template_id`, etc.
2. **Continuity (The "Rewrite" Test):**
* **Step A:** User generates a post using "Jacky Chou" voice.
* **Step B:** User clicks "Make it longer" (Rewrite).
* **Step C:** The system automatically injects the `active_voice_profile_id` from Step A into the Step B request.
* **Result:** The output of Step B retains the "Jacky Chou" voice/style.


3. **Data Integrity:** The `active_swipe_ids` and other JSON columns must be valid JSON arrays, not null or malformed strings.

### 6. Recommended Next Step

Would you like me to write the **Controller Code Snippet** that implements this update logic, or would you prefer to start with the **Service Layer** check to ensure the Voice ID is actually being returned?