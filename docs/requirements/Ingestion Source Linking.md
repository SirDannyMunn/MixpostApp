Below is the **backend requirements document (delta + consolidated)** to support the **new create / update-or-create ingestion source functionality**, aligned with the front-end changes you just approved.

This is written as something you can hand directly to a developer.

---

# Backend Requirements Document

## Ingestion Sources – Create / Update / CRUD Support

### Purpose

Extend the backend to support **multiple ingestion source types** (bookmark, pasted text, file upload) while:

* Keeping the **existing bookmarks system intact**
* Treating `ingestion_sources` as the **canonical ingestion layer**
* Supporting **create, update, delete, re-ingest**
* Returning a **unified API shape** for the front-end list view
* Avoiding duplicate ingestion or data drift

This document **builds on Option A**, where `ingestion_sources` sits *above* bookmarks.

---

## 1. Core Model: `ingestion_sources`

### Canonical Role

`ingestion_sources` represents **anything that can be ingested into AI knowledge**, regardless of origin.

Bookmarks, pasted text, files, transcripts, imports all flow through this table.

---

### Required Fields

```sql
ingestion_sources
-----------------
id (uuid, pk)
organization_id (uuid, required)
created_by (uuid, required)

type (enum)
  - bookmark
  - pasted_text
  - file
  - transcript
  - import
  - custom

source_id (uuid, nullable)
  -- FK to bookmarks.id, files.id, etc

source_table (string, nullable)
  -- e.g. 'bookmarks', 'files'

title (string, required)

raw_text (text, nullable)
  -- Only for direct text sources

status (enum)
  - pending
  - processing
  - ready
  - failed

confidence_score (float, nullable)
quality_score (float, nullable)

metadata (json, nullable)
  -- mime type, file name, platform, etc

created_at
updated_at
deleted_at
```

---

## 2. Bookmark → Ingestion Source Linking (Create-Time)

### Requirement

When a bookmark is created, an **ingestion source must be created automatically**.

### Flow

```text
POST /bookmarks
  → create bookmark
  → create ingestion_source
      type = 'bookmark'
      source_id = bookmark.id
      source_table = 'bookmarks'
      title = bookmark.title
      status = 'pending'
```

### Constraints

* Must be **idempotent**
* If bookmark already has an ingestion source:

  * Do **not** create a duplicate
* Enforce uniqueness:

  ```sql
  unique (source_table, source_id)
  ```

---

## 3. New API: Create Ingestion Source (Non-Bookmark)

### Endpoint

```
POST /api/v1/ingestion-sources
```

### Supported Create Types

#### A) Pasted Text

```json
{
  "type": "pasted_text",
  "title": "My idea about AI SEO",
  "raw_text": "Most AI SEO tools fail because...",
  "metadata": {
    "origin": "manual"
  }
}
```

Backend behavior:

* Create ingestion source
* Status = `pending`
* Immediately queue ingestion pipeline
* Return source record

---

#### B) File Upload (Two-Step)

**Step 1 – Upload file (existing system or new)**
Returns `file_id`

**Step 2 – Create ingestion source**

```json
{
  "type": "file",
  "title": "SEO Research PDF",
  "source_id": "<file_id>",
  "source_table": "files",
  "metadata": {
    "mime": "application/pdf"
  }
}
```

Backend:

* Do **not** require `raw_text`
* Extraction happens asynchronously
* Status transitions: `pending → processing → ready`

---

## 4. Update Ingestion Source (Metadata Only)

### Endpoint

```
PATCH /api/v1/ingestion-sources/{id}
```

### Allowed Updates

* title
* metadata
* tags / folder (if applicable)

### Disallowed Updates

* type
* source_id
* source_table

These are immutable.

---

## 5. Delete Ingestion Source (Soft Delete)

### Endpoint

```
DELETE /api/v1/ingestion-sources/{id}
```

### Behavior

* Soft-delete ingestion source
* **Do not delete underlying bookmark/file**
* Cascade:

  * knowledge_items
  * knowledge_chunks
  * embeddings

### Explicit Non-Behavior

* Bookmark remains untouched
* File remains untouched

---

## 6. Re-Ingest Endpoint

### Endpoint

```
POST /api/v1/ingestion-sources/{id}/reingest
```

### Behavior

* Clear derived artifacts:

  * knowledge_items
  * chunks
  * embeddings
* Reset status → `pending`
* Re-run ingestion pipeline

---

## 7. Ingestion Pipeline Changes

### Unified Entry Point

All ingestion must begin from `ingestion_sources`.

Pipeline signature:

```php
IngestIngestionSourceJob($ingestionSourceId)
```

Internally:

* Resolve source type
* Extract raw text if needed
* Create or update KnowledgeItem(s)
* Chunk → embed
* Compute:

  * confidence_score
  * quality_score
* Update status

---

## 8. Confidence & Quality Scoring (Required)

### At Ingestion Time

Each ingestion source must store:

* `confidence_score`

  * Source reliability (URL, author, platform, etc)
* `quality_score`

  * Text length, density, clarity, duplication

These scores will later drive:

* Retrieval weighting
* Swipe / knowledge selection
* Debug & replay

---

## 9. List API Contract (Critical)

### Endpoint

```
GET /api/v1/ingestion-sources
```

### Response Shape (Canonical)

```json
{
  "id": "...",
  "type": "bookmark",
  "title": "...",
  "status": "ready",
  "confidence_score": 0.87,
  "quality_score": 0.72,
  "created_at": "...",

  "bookmark": {
    "id": "...",
    "url": "...",
    "platform": "twitter",
    "image_url": "...",
    "description": "..."
  }
}
```

Rules:

* Root object is **ingestion source**
* Attached object only exists if relevant
* Front-end must never infer structure

---

## 10. Knowledge Item Linking

### Requirement

Every KnowledgeItem must reference its ingestion source:

```sql
knowledge_items.ingestion_source_id (uuid, required)
```

This enables:

* Replay
* Debugging
* “Why did this post use this source?”

---

## 11. Replay & Debug Support (Required)

Every generation snapshot must store:

* ingestion_source_ids used
* chunk_ids used
* confidence/quality at time of generation

This allows:

* Full explainability
* Deterministic replay
* Audit trails

---

## 12. Migration Strategy

### Phase 1

* Add ingestion_sources table
* Backfill for existing bookmarks

### Phase 2

* Update bookmark create endpoint
* Add create ingestion source endpoint

### Phase 3

* Update retrieval to use ingestion_sources → knowledge_items

No breaking changes to bookmarks.

---

## 13. Non-Goals (Explicit)

Do **not**:

* Merge bookmarks into ingestion_sources
* Remove bookmark endpoints
* Expose ingestion terminology to users
* Allow ingestion source mutation beyond metadata

---

## Final Mental Model (For Devs)

> **Bookmarks are UX artifacts.
> Ingestion sources are AI memory entries.
> Knowledge is derived, never authored directly.**

This backend spec fully supports the front-end changes you outlined without introducing conceptual or architectural drift.
