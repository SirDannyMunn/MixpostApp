# Swipe Models: `SwipeItem` + `SwipeStructure`

## What these models are for (system purpose)

In MixpostApp, **swipes** are “inspiration examples” (real-world posts or snippets) that you save so the AI system can learn *structure and pacing* from them.

- **`SwipeItem`** stores the **raw capture** (immutable-ish canonical text + metadata).
- **`SwipeStructure`** stores a **derived, reusable structure template** extracted from a swipe (intent, funnel stage, hook/CTA type, ordered sections, and a confidence score).

These swipes are then used by your AI generation pipeline as **prompt context** (see “SWIPE_STRUCTURES” in the debug prompt output) and for **auto-selecting structurally similar examples** based on the chosen template.

---

## Data model overview

### `SwipeItem` (raw capture)

**Model:** [app/Models/SwipeItem.php](../../app/Models/SwipeItem.php)

**Table:** `swipe_items`

**Migration:** [database/migrations/2025_01_01_000140_create_swipe_items_table.php](../../database/migrations/2025_01_01_000140_create_swipe_items_table.php)

**Primary key:** UUID `id` (non-incrementing)

**Timestamps:** disabled (`$timestamps = false`); the table stores `created_at` explicitly.

**Fillable fields:**

- `organization_id` (uuid): organization that owns the swipe
- `user_id` (uuid, nullable FK to `users`): user who saved it
- `platform` (string): `linkedin|x|reddit|blog|newsletter|other` (API validation)
- `source_url` (text, nullable): where it came from
- `author_handle` (string, nullable): attribution/handle
- `raw_text` (longText): the saved content (required)
- `raw_text_sha256` (char(64), indexed): hash of `raw_text` (used for dedupe/identity)
- `engagement` (jsonb, nullable): metrics blob from capture/import
- `saved_reason` (text, nullable): why it was saved
- `created_at` (timestamp)

**Casts:**

- `engagement` → array
- `created_at` → datetime

### `SwipeStructure` (derived structure template)

**Model:** [app/Models/SwipeStructure.php](../../app/Models/SwipeStructure.php)

**Table:** `swipe_structures`

**Migration:** [database/migrations/2025_01_01_000150_create_swipe_structures_table.php](../../database/migrations/2025_01_01_000150_create_swipe_structures_table.php)

**Primary key:** UUID `id` (non-incrementing)

**Timestamps:** disabled (`$timestamps = false`); the table stores `created_at` explicitly.

**Fillable fields:**

- `swipe_item_id` (uuid FK → `swipe_items.id`, cascade delete)
- `intent` (string(50), nullable): high-level intent such as educational/story/etc
- `funnel_stage` (string(10), nullable): tof/mof/bof
- `hook_type` (string(100), nullable): question/statistic/etc
- `cta_type` (string(20), default `none`): soft/hard/none
- `structure` (jsonb): extracted structure sections
- `language_signals` (jsonb, nullable): reserved for future signals
- `confidence` (float, default 0): stored as 0.0–1.0 in current job
- `created_at` (timestamp)

**Casts:**

- `structure` → array
- `language_signals` → array
- `confidence` → float
- `created_at` → datetime

---

## Relationships

### On `SwipeItem`

- `structures()` → hasMany `SwipeStructure`
- `swipeStructures()` → hasMany `SwipeStructure`

Both methods currently point to the same relationship. `swipeStructures()` is the “semantic” name used by several jobs/commands and reads better.

### On `SwipeStructure`

- `swipeItem()` → belongsTo `SwipeItem`

**DB behavior:** deleting a `SwipeItem` cascades to its `SwipeStructure` rows via FK.

---

## Lifecycle (how swipes enter the system)

### 1) Creating a swipe (API)

**Controller:** [app/Http/Controllers/Api/V1/SwipeItemController.php](../../app/Http/Controllers/Api/V1/SwipeItemController.php)

**Routes:** [routes/api.php](../../routes/api.php)

- `POST /api/v1/swipe-items`
  - Validates platform and post text (50–5000 chars)
  - Hashes `raw_text` into `raw_text_sha256`
  - Creates a `SwipeItem`
  - Dispatches `ExtractSwipeStructureJob` to analyze structure
  - Returns `{ id, status: "queued" }`

- `GET /api/v1/swipe-items`
  - Lists swipe items for the current organization
  - Supports `search` against `raw_text`, `author_handle`, `saved_reason`
  - Returns a *paginated* response, but the collection is transformed down to:
    - `id`
    - `label` (reason or first ~80 chars)
    - `author_handle`

### 2) Seeding swipes from existing content

**Command:** [app/Console/Commands/SeedSwipesFromBookmarks.php](../../app/Console/Commands/SeedSwipesFromBookmarks.php)

- Promotes random `KnowledgeItem`s into `SwipeItem`s
- Copies `raw_text`, platform, and optional `metadata.source_url`
- Dispatches `ExtractSwipeStructureJob` per created swipe

### 3) Backfilling structure extraction (“hydrate”)

**Command:** [app/Console/Commands/HydrateAiContext.php](../../app/Console/Commands/HydrateAiContext.php)

- `php artisan ai:hydrate --type=swipes`
- If not `--force`, only processes `SwipeItem`s that don’t have any structures (`doesntHave('swipeStructures')`)
- Dispatches `ExtractSwipeStructureJob` for each candidate

---

## Structure extraction (how `SwipeStructure` gets created)

**Job:** [app/Jobs/ExtractSwipeStructureJob.php](../../app/Jobs/ExtractSwipeStructureJob.php)

What it does:

1. Loads the `SwipeItem` by id and truncates raw text to 3000 chars.
2. Sends a “structure-only” prompt to OpenRouter via `OpenRouterService::chatJSON()`.
3. Expects JSON with:
   - `intent`, `funnel_stage`, `hook_type`, `cta_type`
   - `structure`: array of sections
   - `confidence`: 0–100
4. Persists a `SwipeStructure` row.

Important detail:

- The job **normalizes** `confidence` from 0–100 to 0.0–1.0 by dividing by 100.

---

## Retrieval + how swipes influence AI generation

### 1) Swipe retrieval service

**Service:** [app/Services/Ai/Retriever.php](../../app/Services/Ai/Retriever.php)

Method: `Retriever::swipeStructures(...)`

- Filters `SwipeStructure` rows by organization via `whereHas('swipeItem', ...)`.
- If no structure signature is provided, returns the top N by confidence.
- If a target signature is provided, it:
  - Fetches more candidates
  - Computes a structural similarity score (Jaccard + bonuses)
  - Filters by a threshold (default from `config('swipe.similarity_threshold', 0.30)`)

Returns a bundle:

- `selected`: rows to use
- `scores`: per-id score map
- `rejected`: low-scoring candidates

### 2) AI generation uses swipes as prompt context

**Service:** [app/Services/Ai/ContentGeneratorService.php](../../app/Services/Ai/ContentGeneratorService.php)

Swipes are resolved based on `swipe_mode`:

- `none`: no swipes
- `strict`: only `swipe_ids` provided by caller
- `auto`: uses `Retriever::swipeStructures(...)` to select examples based on:
  - classification intent/funnel stage
  - the resolved template’s `template_data.structure` as a signature

The chosen swipes are injected into the assembled generation context and ultimately appear under the label `SWIPE_STRUCTURES` in the final user prompt.

### 3) VIP/override selection by swipe IDs

**Resolver:** [app/Services/Ai/Generation/Steps/OverrideResolver.php](../../app/Services/Ai/Generation/Steps/OverrideResolver.php)

Method: `resolveVipSwipes($orgId, $swipeIds)`

- Loads specific `SwipeStructure` rows by id
- Enforces org scoping via `whereHas('swipeItem', ...)`
- Returns a normalized array used by generation policy to merge/override

---

## Debugging: seeing swipes in prompts

**Command:** [app/Console/Commands/ShowPrompt.php](../../app/Console/Commands/ShowPrompt.php)

- Prints the final prompt sent for a `GenerationSnapshot`.
- If `$snap->swipes` is present, it outputs:

```
SWIPE_STRUCTURES:
<json>
```

This is the most direct way to verify that swipe retrieval is working and that the right structures are being injected.

---

## Where these models are used (index)

### `SwipeItem`

- Model: [app/Models/SwipeItem.php](../../app/Models/SwipeItem.php)
- API:
  - [app/Http/Controllers/Api/V1/SwipeItemController.php](../../app/Http/Controllers/Api/V1/SwipeItemController.php)
  - [routes/api.php](../../routes/api.php)
- Console:
  - [app/Console/Commands/SeedSwipesFromBookmarks.php](../../app/Console/Commands/SeedSwipesFromBookmarks.php)
  - [app/Console/Commands/HydrateAiContext.php](../../app/Console/Commands/HydrateAiContext.php)
- Jobs:
  - [app/Jobs/ExtractSwipeStructureJob.php](../../app/Jobs/ExtractSwipeStructureJob.php)
- Database:
  - [database/migrations/2025_01_01_000140_create_swipe_items_table.php](../../database/migrations/2025_01_01_000140_create_swipe_items_table.php)

### `SwipeStructure`

- Model: [app/Models/SwipeStructure.php](../../app/Models/SwipeStructure.php)
- Jobs:
  - [app/Jobs/ExtractSwipeStructureJob.php](../../app/Jobs/ExtractSwipeStructureJob.php)
- Retrieval/Generation:
  - [app/Services/Ai/Retriever.php](../../app/Services/Ai/Retriever.php)
  - [app/Services/Ai/ContentGeneratorService.php](../../app/Services/Ai/ContentGeneratorService.php)
  - [app/Services/Ai/Generation/Steps/OverrideResolver.php](../../app/Services/Ai/Generation/Steps/OverrideResolver.php)
- Database:
  - [database/migrations/2025_01_01_000150_create_swipe_structures_table.php](../../database/migrations/2025_01_01_000150_create_swipe_structures_table.php)

---

## Notes / invariants implied by the implementation

- `SwipeItem.raw_text` is treated as the canonical source that derived structures are extracted from.
- `SwipeStructure` rows are safe to regenerate (re-run extraction) as long as you handle dedupe/versioning at a higher level (not currently implemented in these models).
- Org scoping is enforced at query-time in several key places via `whereHas('swipeItem', org filter)`.
