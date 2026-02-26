# Engineering Spec: Ingestion Source Folders as Context Boundaries

## Overview

This spec defines how to use **folders as context boundaries** by attaching them directly to `ingestion_sources` (the system’s source of truth), enabling accurate, automatic knowledge scoping in chat and generation.

This replaces manual “Facts” or “Knowledge” selection with **folder-based contextual retrieval**, while remaining compatible with the existing ingestion, chunking, and retrieval pipeline.

No dedicated Campaign model is introduced at this stage. Folders act as lightweight, promotable campaign-equivalents.

---

## Goals

* Provide a deterministic, low-friction way to scope knowledge by situation/campaign
* Eliminate vague or orphaned knowledge chunks during generation
* Avoid manual chunk selection in chat
* Reuse existing folder UX and mental model
* Preserve future ability to promote folders → Campaigns

Non-goals:

* No CRM-style campaign workflows
* No chunk-level tagging
* No hard requirement for manual folder assignment

---

## Data Model Changes

### Existing Tables (Context)

You already have:

* `folders`
* `tags`
* `bookmark_tags` (pivot)

Folders are already a first‑class organizational concept. Tags exist but are currently **bookmark‑scoped**, which is too narrow for ingestion‑level context.

This spec intentionally **does not reuse `bookmark_tags`** directly, because bookmarks are a leaf ingestion source and not the system of record.

---

### 1. New Pivot Table: `ingestion_source_folders`

```sql
CREATE TABLE ingestion_source_folders (
  id UUID PRIMARY KEY,
  ingestion_source_id UUID NOT NULL,
  folder_id UUID NOT NULL,
  created_by UUID NULL,
  created_at TIMESTAMP NOT NULL,

  CONSTRAINT isf_unique UNIQUE (ingestion_source_id, folder_id),
  CONSTRAINT isf_ingestion_source_fk
    FOREIGN KEY (ingestion_source_id)
    REFERENCES ingestion_sources(id)
    ON DELETE CASCADE,
  CONSTRAINT isf_folder_fk
    FOREIGN KEY (folder_id)
    REFERENCES folders(id)
    ON DELETE CASCADE
);

CREATE INDEX isf_folder_idx ON ingestion_source_folders(folder_id);
CREATE INDEX isf_source_idx ON ingestion_source_folders(ingestion_source_id);
```

Notes:

* `created_by` is optional and nullable

  * `NULL` = system / AI‑generated attachment
  * populated = explicit user action
* This mirrors your existing pivot semantics and supports auditability

---

### 2. (Optional) Generalized Tagging for Ingestion Sources

If you want to reuse your existing tag concept:

Create a **new pivot**, do NOT reuse `bookmark_tags`.

```sql
CREATE TABLE ingestion_source_tags (
  id UUID PRIMARY KEY,
  ingestion_source_id UUID NOT NULL,
  tag_id UUID NOT NULL,
  created_by UUID NULL,
  created_at TIMESTAMP NOT NULL,

  CONSTRAINT ist_unique UNIQUE (ingestion_source_id, tag_id),
  FOREIGN KEY (ingestion_source_id) REFERENCES ingestion_sources(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
```

Tags are **orthogonal** to folders:

* Tags = cross‑cutting labels (e.g. "cancer", "fundraising")
* Folders = narrative / contextual boundaries

Do not overload tags to behave like campaigns.

---

### 2. Folder Metadata (Optional, Recommended)

Extend `folders` table (if not already present):

```json
context_metadata: {
  "type": "fundraiser|launch|case_study|topic|other",
  "primary_entity": "Eleanor",
  "description": "Fundraising campaign supporting Eleanor during cancer treatment"
}
```

This metadata is **not required** for correctness, but improves:

* Auto-generation
* Chat UX
* Retrieval biasing

---

## Ingestion Pipeline Changes

### ProcessIngestionSourceJob

Add optional folder attachment at ingestion time.

#### Inputs

* `folder_ids?: UUID[]`

#### Behavior

```
if folder_ids provided:
  attach ingestion_source_id → folder_ids
else if auto-folder-detection enabled:
  attempt auto-folder assignment
```

Populate `created_by`:

* User‑initiated attach → `user_id`
* Auto / AI attach → `NULL`

No ingestion step should fail due to missing folders.

---

### Normalization Guard: Preserve Explicit Referents (MANDATORY)

**Problem being fixed:**
Normalization currently produces context‑free atomic claims (e.g. "the event", "the campaign"). These are technically correct but unusable in generation.

#### Rule

> Every normalized claim **must be understandable in isolation**.

This means:

* No pronouns without antecedents
* No vague references ("the event", "the walk", "the campaign")
* Explicit inclusion of the minimal identifying subject

#### Required Prompt Constraint (NormalizeKnowledgeItemJob)

Add a hard instruction:

> "Each claim must retain the minimum identifying context required to stand alone. Replace vague references with explicit subjects (person name, campaign name, organization, or event). If a claim cannot be made explicit, discard it."

#### Examples

Bad (current):

* "The event offers two route options"

Correct:

* "The Every Step for Eleanor fundraising walk offers two route options"

Bad:

* "The fundraising target is £5,000"

Correct:

* "Eleanor’s cancer fundraising campaign has a target of £5,000"

#### Enforcement

* Claims that cannot be rewritten explicitly are **dropped**
* This is preferable to retaining unusable chunks

This change alone significantly improves retrieval precision and generation safety.

---

### Auto-Folder Assignment (Optional, Phase 2)

Heuristic-based folder creation and attachment:

* Triggered when:

  * Named entity appears repeatedly (e.g. person, org)
  * Intent detected (fundraising, launch, case study)
* If confidence > threshold:

  * Create folder
  * Attach ingestion source

This is additive and does not block ingestion.

---

## Knowledge Propagation Rules

Folders **do not copy data**. They act as resolvers.

Resolution chain:

```
Folder
  → ingestion_source_ids
    → knowledge_items
      → knowledge_chunks
      → business_facts
```

No schema changes required for:

* `knowledge_items`
* `knowledge_chunks`
* `business_facts`

---

## Retrieval Changes

### Folder-Scoped Retrieval

Extend `Retriever::knowledgeChunks(...)` to accept:

```php
filters: {
  folder_ids?: UUID[]
}
```

#### Resolution

```sql
SELECT kc.*
FROM knowledge_chunks kc
JOIN knowledge_items ki ON kc.knowledge_item_id = ki.id
JOIN ingestion_source_folders isf ON ki.ingestion_source_id = isf.ingestion_source_id
WHERE isf.folder_id IN (...)
```

#### Behavior

* If `folder_ids` provided:

  * Restrict candidate pool to matching ingestion sources
* If multiple folders:

  * Use UNION semantics
* Optional bias:

  * Boost chunks appearing in multiple selected folders

Default behavior (no folder): unchanged.

---

## Chat / Generation Integration

### Replace Facts + Knowledge with Context

Chat UI attaches:

```json
context: {
  "folder_ids": ["uuid-1", "uuid-2"]
}
```

Passed through `GenerationRequest` → `retrieval_filters`.

### Generation Behavior

* Retrieval auto-scoped by folder
* No manual chunk selection
* VIP overrides still supported

Folder order:

* First folder = primary narrative context
* Others = supporting context

---

## Multiple Folder Semantics

* Ingestion source may belong to multiple folders
* Knowledge chunks remain single-instance
* Folder acts as a lens, not ownership

Example:

* Source attached to:

  * "Eleanor Fundraiser"
  * "Childhood Cancer Awareness"

Usable in both contexts without duplication.

---

## Deletion & Integrity Rules

* Deleting a folder:

  * Removes pivot rows
  * Does NOT delete ingestion sources or knowledge

* Deleting an ingestion source:

  * Cascades pivot rows
  * Cascades knowledge_items and chunks (existing behavior)

---

## Migration & Backfill Strategy

1. Create pivot table
2. Backfill existing bookmark.folder_id → ingestion_source_folders
3. Update ingestion flow to write pivot rows
4. Update retriever to accept folder filters
5. Update chat UI to send folder context

No downtime required.

---

## Future Extensions (Explicitly Out of Scope)

* Promote folder → Campaign model
* Campaign analytics
* Campaign timelines
* Campaign permissions

Folders are intentionally kept lightweight.

---

## Summary

This design:

* Treats ingestion sources as the source of truth
* Uses folders as flexible, promotable context boundaries
* Enables accurate, automatic knowledge scoping
* Avoids premature domain modeling

This is the correct abstraction for the current system scale.
