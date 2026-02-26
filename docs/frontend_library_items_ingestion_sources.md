# Frontend Requirements: Library Items are Ingestion-Source-First

Date: 2026-01-06

## Summary
The `/api/v1/library-items` endpoint now returns **Ingestion Sources** as the primary list items (e.g., `text`, `bookmark`, `file`).

- The response `data[]` represents ingestion sources.
- A nested `bookmark` object is included **only when** `type === "bookmark"` AND the ingestion source is bookmark-backed.
- This change fixes prod returning an empty list when the org has ingestion sources but no bookmarks.

## API Endpoint

### Request
`GET /api/v1/library-items`

Supported query params (existing + clarified):
- `sort`: `created_at` (default), `updated_at`, `title`, `ingestion_status`
- `order`: `asc` | `desc` (default `desc`)
- `per_page` or `limit`: page size (default 20)
- `type`: ingestion source type (see below)
  - Accepts legacy alias `pasted_text` which is treated as `text`
- `ingestion_status`: `pending` | `completed` | `failed`
  - Accepts aliases: `processing` → `pending`, `ingested` → `completed`
  - Note: `not_ingested` is no longer meaningful (see Filtering section)

Bookmark-only filters (they still exist, but now intentionally apply only to bookmark-backed items):
- `folder_id` (uuid or literal string `null`)
- `platform`
- `tag_id` (uuid or tag name)
- `is_favorite` (boolean)
- `is_archived` (boolean)

`search`:
- Searches across ingestion source (`title`, `raw_text`, `raw_url`) and bookmark fields when present.

## Library Item Identifier

### `id`
Each item has an `id` of the form:
- `"lib_{ingestionSourceId}"`

Important:
- Previously the frontend assumed `lib_{bookmarkId}`.
- Actions that call ingestion-source endpoints (e.g. extract-structure, reingest) must now send the **new** `lib_{ingestionSourceId}` id.
- Backend remains backward-compatible and can still resolve legacy `lib_{bookmarkId}` in several ingestion-source routes, but frontend should move to the new id.

## Response Shape (Contract)

### Top-level
Same as Laravel pagination:
- `data: LibraryItem[]`
- `links`, `meta`

### `LibraryItem`

Required fields:
- `id: string` (always prefixed with `lib_`)
- `type: string` (ingestion source type; e.g. `text`, `bookmark`, `file`)
- `source: string` (typically equals ingestion source `origin`; fallback values used if empty)
- `title: string`
- `preview: string`
- `thumbnail_url: string | null`
- `created_at: string | null` (ISO 8601)
- `updated_at: string | null` (ISO 8601)

`ingestion` object:
- `ingestion.status: "pending" | "completed" | "failed" | string`
- `ingestion.ingested_at: string | null` (ISO 8601; set when status is `completed`)
- `ingestion.confidence: number | null` (maps to ingestion source `confidence_score`)
- `ingestion.error_message: string | null` (set when status is `failed`)

`bookmark` object:
- `bookmark: Bookmark | null`
- MUST be treated as nullable.
- Present only when `type === "bookmark"` AND bookmark exists.

### `Bookmark` (when included)
Same fields as before:
- `id, url, platform, image_url, favicon_url`
- `folder` (light object) and `folder_id`
- `tags: Array<{id,name,color}>`

## Rendering Requirements

### General list behavior
- The Library list must render items for `type === "text"` (and other non-bookmark types).
- Do not assume `bookmark` exists.
- Use `created_at/updated_at` from the library item itself (ingestion source timestamps).

### Title / Preview
- `title` is always returned as a string; render as-is.
- `preview` is always returned as a string; render as-is.
  - For `text` items, `preview` will be derived from the ingestion source `raw_text` (shortened).
  - For `bookmark` items, `preview` comes from bookmark description.

### Thumbnail
- Only bookmark-backed items may have `thumbnail_url`.
- For non-bookmark items, expect `thumbnail_url: null`.

### Type badges / icons
- Use `type` to choose the badge/icon:
  - `bookmark`: show bookmark UI affordances (and any bookmark-specific actions)
  - `text`: show a “Text”/“Pasted text” (depending on product wording)
  - `file`: show “File”

## Filtering & Sorting Requirements

### Sorting
- `sort=created_at` sorts by ingestion source creation time.
- `sort=updated_at` sorts by ingestion source update time.
- `sort=title` sorts by ingestion source title, falling back to bookmark title when ingestion title is empty.
- `sort=ingestion_status` sorts by ingestion source status rank.

### Type filter
- `type=bookmark` returns only bookmark-backed ingestion sources.
- `type=text` returns text ingestion sources.
- If the UI currently sends `type=pasted_text`, it will still work (alias → `text`).

### Bookmark-only filters
When any bookmark-only filter is applied (`folder_id`, `platform`, `tag_id`, `is_favorite`, `is_archived`):
- The returned dataset becomes bookmark-only.
- Frontend should understand this and avoid confusing UX (e.g., don’t expect text results while a folder filter is active).

### `ingestion_status=not_ingested`
- Not supported meaningfully anymore because every list item is an ingestion source.
- If the frontend sends `not_ingested`, the backend currently returns an empty set.
- Frontend should remove/hide this option for the Library Items endpoint.

## Frontend Code Changes Checklist

1. Update the library list item model:
   - Allow `type` values other than `bookmark`.
   - Make `bookmark` nullable.
2. Update item ID handling:
   - Treat `lib_...` as `lib_{ingestionSourceId}`.
   - Store the full `id` string (do not strip prefix unless required).
3. Update any ingestion-source actions triggered from Library items:
   - Use the new `id` when calling ingestion-source endpoints.
4. Update UI rendering:
   - Conditionally render bookmark fields only if `bookmark != null`.
   - Add rendering support for `text` items (thumbnail absent, different metadata).
5. Update filter UI:
   - Ensure `type` filter matches ingestion source types.
   - Remove/disable “not ingested” filter option.

## Acceptance Criteria

- If an org has a single `ingestion_sources` record of `source_type=text`, `/library-items` returns `total >= 1` and the item renders in the list.
- If an org has bookmark ingestion sources, those items return `type=bookmark` and include `bookmark` details.
- The UI does not crash when `bookmark` is `null`.
- Existing bookmark-specific filters still work for bookmarks and do not break the list.

## Example: Text Item

```json
{
  "id": "lib_019b9341-a034-722b-8c7b-d649170d0bf7",
  "type": "text",
  "source": "manual",
  "title": "Fundraising post for neurodiverse children",
  "preview": "Five years ago, my world changed forever...",
  "thumbnail_url": null,
  "created_at": "2026-01-06T12:21:50+00:00",
  "updated_at": "2026-01-06T12:22:39+00:00",
  "ingestion": {
    "status": "completed",
    "ingested_at": "2026-01-06T12:22:39+00:00",
    "confidence": null,
    "error_message": null
  },
  "bookmark": null
}
```

## Example: Bookmark Item

```json
{
  "id": "lib_019b8fc0-fce4-72d1-a77b-2931e57d2759",
  "type": "bookmark",
  "source": "browser",
  "title": "Youtube: Consectetur in et suscipit.",
  "preview": "",
  "thumbnail_url": "https://via.placeholder.com/1200x628.png/002233?text=animi",
  "created_at": "2026-01-05T20:02:28+00:00",
  "updated_at": "2026-01-05T20:02:28+00:00",
  "ingestion": {
    "status": "pending",
    "ingested_at": null,
    "confidence": null,
    "error_message": null
  },
  "bookmark": {
    "id": "019b8fc0-fce4-72d1-a77b-2931e57d2759",
    "url": "https://www.youtube.com/watch?v=pdfueacyrpz",
    "platform": "youtube",
    "image_url": "https://via.placeholder.com/1200x628.png/002233?text=animi",
    "favicon_url": null,
    "folder": {
      "id": "019b8fc0-f592-7072-bb41-b4035e653177",
      "name": "Campaigns",
      "parent_id": null
    },
    "folder_id": "019b8fc0-f592-7072-bb41-b4035e653177",
    "tags": [
      {
        "id": "019b8fc0-f5cf-7052-9b47-99de309d79a7",
        "name": "Brand",
        "color": "#6366f1"
      }
    ]
  }
}
```
