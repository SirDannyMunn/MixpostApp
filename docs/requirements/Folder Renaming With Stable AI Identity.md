## Engineering Spec — Folder Renaming With Stable AI Identity

### Goal

Allow users to rename folders for organization **without** breaking AI/retrieval semantics. Maintain an immutable AI-given name for long-term stability and auditability.

---

## Scope

* Backend: DB schema, models, API changes, validation, migrations, tests.
* Frontend: minimal contract changes (display name vs system name).
* Retrieval/generation: no behavior change other than using folder IDs as boundary (names are not used for retrieval).

Out of scope:

* Folder creation UX
* Folder permissions model (assume existing org scoping)
* Folder move/merge flows (unless already present)

---

## Definitions

* **system_name**: AI-given canonical name, immutable once set.
* **display_name**: User-editable name used only for UI display.
* **effective_name**: `display_name ?? system_name` (what UI shows).
* **folder identity**: `id` (UUID). Names never determine identity.

---

## Data Model Changes

### Database: `folders` table

Add:

* `system_name` (string, required)
* `display_name` (string, nullable)
* Optional (recommended) auditing:

  * `system_named_at` (timestamp, nullable)
  * `display_renamed_at` (timestamp, nullable)

#### Constraints

* `system_name` NOT NULL
* `display_name` NULLABLE
* (Optional) unique constraints:

  * **Do not** require uniqueness on `display_name` (users may want duplicates).
  * `system_name` uniqueness is optional; recommended **not** unique (AI may reuse generic labels).

---

## API Contract

### GET `/api/v1/folders`

Return both names so the UI can show effective name.

Example response item:

```json
{
  "id": "uuid",
  "system_name": "Laundry Industry Knowledge",
  "display_name": "My Laundry Notes",
  "effective_name": "My Laundry Notes"
}
```

Backend should compute `effective_name` for convenience, or frontend can compute.

### PATCH `/api/v1/folders/{id}`

Rename endpoint updates **only** `display_name`.

Request:

```json
{ "display_name": "Q1 Campaign Research" }
```

Response returns updated object.

Rules:

* `display_name` can be set to `null` or empty string to “reset” to `system_name`.
* Attempts to set `system_name` via API must be rejected.

### POST `/api/v1/folders`

On create:

* `system_name` must be provided (from AI naming pipeline or deterministic naming step).
* `display_name` optional; default null.

Request:

```json
{
  "system_name": "High-Performing LinkedIn Styles",
  "display_name": null
}
```

---

## Authorization

* Folder belongs to an organization.
* All folder reads/writes require:

  * `Authorization: Bearer ...`
  * `X-Organization-ID: ...`
* Ensure rename only allowed for folder within org.

---

## Backend Implementation Details (Laravel)

### Migration

Create a migration (date-based) that:

1. Adds columns `system_name`, `display_name` to `folders`.
2. Backfills `system_name` for existing rows:

   * If current column `name` exists: copy it into `system_name`.
   * If no existing name: set `system_name` to `"Folder"` or `"Untitled Folder"` (better: `"Folder {short_id}"`).
3. Optionally keep old `name` column temporarily for compatibility, then deprecate/remove in later migration.

**If you already have `folders.name`:**

* Rename `name` → `system_name`
* Add `display_name`

That is the cleanest path.

### Eloquent Model (`Folder`)

* Add fillable/guarded:

  * `system_name` guarded (not mass-assignable)
  * `display_name` fillable
* Accessor:

  * `getEffectiveNameAttribute(): string` returns `display_name ?: system_name`

### Validation

Rename request:

* `display_name`: `nullable|string|max:120` (or your standard)
* Trim whitespace
* Treat `""` as null (reset behavior)

### Controllers / Actions

* `FoldersController@index`: return names + effective_name
* `FoldersController@update`:

  * Only allow `display_name`
  * Set `display_renamed_at = now()` when changed
  * Do not modify `system_name`

### Serialization

Return shape consistent across endpoints. Add `effective_name` as a computed field in resource.

---

## AI / Retrieval Interaction

### Core rule

* AI/retrieval **never uses folder names** for scoping.
* Scoping is by folder IDs only.

### Why

Names are mutable; IDs are stable.

---

## Generation Snapshots / Audit (recommended)

To support replay/debugging, store the folder IDs used during generation.

### Option A (simple)

Add `folder_ids` JSON column to `generation_snapshots.options` (already happening in your diff).
Pros: no schema change if you already store options JSON.
Cons: harder to query at DB level.

### Option B (better querying)

Add a dedicated column to `generation_snapshots`:

* `folder_ids` JSON nullable (array of UUIDs)
  Pros: easy filtering, analytics, debugging.

If you choose Option B, implement:

* In `SnapshotPersister::persistGeneration(...)`, write `folder_ids` directly.
* In replay, allow overriding via CLI `--folder-ids=` and persist into the new snapshot when “replace snapshot” is invoked.

---

## Replace Snapshot Command Changes

Wherever you have a “replace snapshot” / “replay snapshot” command:

* Add argument/option: `--folder-ids=uuid,uuid`
* Parse UUIDs
* Include `folder_ids` in the options passed into:

  * `ContentGeneratorService->replayFromSnapshot(...)` (override)
  * and persisted output snapshot.

Acceptance criteria:

* Replay with folder IDs produces retrieval limited to those folders (knowledge chunks only).
* Snapshot records folder IDs used for that run (options JSON or column).

---

## Edge Cases

* User renames folder after generation: historical snapshot still points to folder IDs, so replay remains stable.
* User deletes folder: replay behavior should degrade gracefully:

  * retrieval returns 0 chunks for missing folder IDs
  * snapshot still shows folder IDs; UI can show “Deleted folder” label if desired.
* Duplicate display names: allowed.
* Reset rename: setting `display_name=null` reverts UI to `system_name`.

---

## Acceptance Criteria

1. Users can rename folders via API (PATCH) without affecting `system_name`.
2. GET folders returns both names and an `effective_name`.
3. UI can display `effective_name` everywhere without special cases.
4. Any AI retrieval scoping uses folder IDs, not names.
5. Replay/replace snapshot supports passing `folder_ids` and persists them with the generation.

---

## Tests

* Migration backfill test (if you run migrations in tests):

  * Existing folder rows end up with non-null `system_name`.
* API tests:

  * PATCH updates `display_name`, does not change `system_name`.
  * PATCH rejects `system_name` in payload.
  * GET returns `effective_name`.
* Generation/replay test:

  * With `folder_ids`, knowledge retrieval is called with `retrieval_filters.folder_ids`.

---

## Implementation Notes / Strong Defaults

* Keep `system_name` immutable in code (guarded + validation).
* Always return `effective_name` to simplify frontend.
* Store `folder_ids` at snapshot level (dedicated JSON column preferred) for analytics and debugging.

If you want, paste your current `folders` table schema (or migration) and I’ll give you the exact migration diff (rename vs add columns) matching what you already have.
