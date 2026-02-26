# Backend Requirements Document

**Unified Ingestion + Library System (Phase 1)**

## 1. Purpose

Introduce a robust, extensible ingestion architecture that:

* Preserves the existing Bookmarks system unchanged
* Adds a canonical ingestion layer for *all* content sources
* Enables conversion of bookmarks, pasted text, files, drafts, etc. into knowledge
* Exposes a **unified Library API** to the frontend
* Supports replay, provenance, and explainability (“why was this used?”)

This phase focuses on backend correctness, API shape, and migration safety.

---

## 2. Core Principles (Non-Negotiable)

1. **Bookmarks remain a capture UI**

   * No breaking changes to bookmark creation, update, delete
   * Bookmarks are not automatically ingested

2. **Ingestion is explicit**

   * Ingestion only occurs when a user explicitly requests it
   * Ingestion is idempotent and replayable

3. **Ingestion Sources are backend-only**

   * Never exposed directly to frontend
   * Used for provenance, lifecycle, retries

4. **Frontend consumes a unified “Library Items” API**

   * Frontend never queries bookmarks directly
   * Frontend never reasons about ingestion_sources or knowledge_items

---

## 3. New Database Table: `ingestion_sources`

### Purpose

Canonical representation of *anything* the user wants processed into knowledge.

### Table: `ingestion_sources`

| Field           | Type          | Notes                                                                       |
| --------------- | ------------- | --------------------------------------------------------------------------- |
| id              | UUID          | PK                                                                          |
| organization_id | UUID          | Required                                                                    |
| user_id         | UUID          | Required                                                                    |
| source_type     | enum          | `bookmark`, `text`, `file`, `transcript`, `draft`, `post`, `ai_output`, etc |
| source_id       | UUID / string | Points to bookmark.id, file.id, etc                                         |
| origin          | string        | `browser`, `manual`, `upload`, `integration`, `ai`                          |
| platform        | string        | Optional (twitter, linkedin, notion, etc)                                   |
| raw_url         | text          | Optional (for bookmarks)                                                    |
| raw_text        | longtext      | Optional (for pasted/manual content)                                        |
| mime_type       | string        | Optional (files)                                                            |
| dedup_hash      | string        | Required, indexed                                                           |
| status          | enum          | `pending`, `processing`, `completed`, `failed`                              |
| error           | text          | Nullable                                                                    |
| created_at      | timestamp     |                                                                             |
| updated_at      | timestamp     |                                                                             |

### Constraints

* Unique index on `(source_type, source_id)`
* Secondary index on `dedup_hash`

---

## 4. Bookmark → Ingestion Flow (Option A)

### What does NOT change

* Bookmark creation endpoint
* Bookmark table
* Bookmark UI
* Bookmark API responses (outside the Library API)

### What changes

#### Endpoint:

`POST /api/v1/bookmarks/{bookmark}/ingest`

#### Behavior

1. **Idempotent ingestion source creation**

   ```php
   IngestionSource::firstOrCreate(
     [
       'source_type' => 'bookmark',
       'source_id'   => $bookmark->id,
     ],
     [
       'organization_id' => $bookmark->organization_id,
       'user_id'         => $bookmark->created_by,
       'origin'          => 'browser',
       'platform'        => $bookmark->platform,
       'raw_url'         => $bookmark->url,
       'dedup_hash'      => sha1(normalize_url($bookmark->url)),
       'status'          => 'pending',
     ]
   );
   ```

2. **Dispatch ingestion pipeline**

   ```php
   dispatch(new ProcessIngestionSourceJob($ingestionSource->id));
   ```

3. **Return**

   ```json
   { "status": "queued" }
   ```

### Important

* **Do NOT create ingestion_sources when bookmarks are created**
* Only create ingestion_sources on explicit ingest

---

## 5. Knowledge Creation (Unchanged, but Rewired)

All knowledge creation now flows:

```
IngestionSource
  → KnowledgeItem(s)
    → KnowledgeChunk(s)
      → Embeddings
      → Facts / Voice / Metadata
```

### KnowledgeItem additions

* `ingestion_source_id` (required FK)
* No direct reference to bookmarks or files

This enables:

* Replay
* Explainability
* Confidence scoring
* Re-ingestion

---

## 6. Unified Library API (Critical Change)

### New Endpoint

```
GET /api/v1/library-items
```

This **replaces frontend usage of `/bookmarks`**.

---

## 7. Library Item API Contract (DTO)

### Top-level shape (uniform for all items)

```json
{
  "id": "lib_xxx",
  "type": "bookmark | text | file | draft | post | transcript",
  "source": "twitter | manual | upload | ai",
  "title": "string",
  "preview": "string",
  "thumbnail_url": "string | null",
  "created_at": "ISO8601",

  "ingestion": {
    "status": "not_ingested | ingested | failed | processing",
    "ingested_at": "ISO8601 | null",
    "confidence": "float | null"
  },

  "bookmark": {
    "id": "bookmark_uuid",
    "url": "string",
    "platform": "twitter",
    "image_url": "string | null",
    "favicon_url": "string | null",
    "folder_id": "uuid | null",
    "tags": []
  }
}
```

### Rules

* `bookmark` is **only present when type === bookmark**
* Non-bookmark items set `bookmark: null`
* Frontend must not infer backend storage

---

## 8. Backend Query Architecture

Introduce a query/transformer layer:

### Example

```php
LibraryItemQuery::forOrg($orgId)
  ->withBookmarks()
  ->withIngestionStatus()
  ->paginate();
```

Internally:

* Base query: bookmarks
* LEFT JOIN ingestion_sources
* Map results → `LibraryItemDTO`

Later phases may UNION with other sources (files, drafts).

---

## 9. Frontend Migration Strategy (Backend-Driven)

### Phase 1

* Implement `/library-items`
* Return bookmark-backed library items only
* Frontend renders same UI

### Phase 2

* Add ingestion metadata
* Add ingest/re-ingest actions

### Phase 3

* Add new item types (text, file, draft)
* No API redesign required

---

## 10. Explicit Non-Goals (This Phase)

* UI redesign
* Template selection changes
* Advanced weighting
* Multi-source aggregation

Those are future phases.

---

## 11. Success Criteria

* Bookmarks work exactly as before
* Ingestion is explicit, idempotent, and replayable
* Frontend consumes a single list API
* Provenance is traceable end-to-end
* No frontend code depends on raw tables

---

## 12. Summary (for the developer)

> You are not replacing bookmarks.
> You are wrapping them in a higher-order ingestion and library system.

If this is implemented correctly:

* You unlock every future ingestion type
* You unlock replay and explainability
* You avoid UI/DB coupling forever

