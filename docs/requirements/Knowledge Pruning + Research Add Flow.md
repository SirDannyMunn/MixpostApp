# Engineering Spec (Backend): Knowledge Pruning + Research Add Flow

Scope: backend changes to support (1) safe knowledge pruning, (2) fact/angle classification and overrides, (3) research-mode “search + add” with intentional classification, and (4) observability.

This spec assumes your current generation pipeline (`ContentGeneratorService`) and snapshot persistence model as described in your architecture doc. fileciteturn0file0

---

## 1) Objectives

1. Allow users to **exclude** knowledge chunks from generation safely (soft delete / disable).
2. Allow users to **reclassify** chunks into **Fact vs Angle vs Example vs Quote** and control usage.
3. Support a **Research Search API** that returns candidates (chunks + sources) and allows users to **Add to KB** with an explicit classification.
4. Ensure **generation retrieval respects** exclusions and classifications.
5. Provide **auditability** (who changed what, when) and snapshot traceability.

Non-goals

* No hard deletion by default.
* No editing chunk text in v1.
* No embedding regeneration work beyond what’s already in place.

---

## 2) Data Model Changes

### 2.1 knowledge_chunks table (or equivalent)

Add columns:

* `is_active BOOLEAN NOT NULL DEFAULT true`
* `chunk_kind VARCHAR(16) NOT NULL DEFAULT 'fact'`
  Allowed: `fact | angle | example | quote`
* `usage_policy VARCHAR(16) NOT NULL DEFAULT 'normal'`
  Allowed: `normal | inspiration_only | never_generate`
* `source_type VARCHAR(32) NULL` (optional; e.g. `web`, `doc`, `manual`, `research_chat`)
* `source_ref VARCHAR(255) NULL` (optional id/url)
* `source_title VARCHAR(255) NULL`
* `confidence DECIMAL(4,3) NULL` (if you don’t already have it)

Indexes:

* `(organization_id, is_active)`
* `(organization_id, chunk_kind)`
* `(organization_id, usage_policy)`

### 2.2 knowledge_chunk_events (audit log)

New table: `knowledge_chunk_events`

Columns:

* `id UUID`
* `organization_id UUID`
* `user_id UUID`
* `chunk_id UUID`
* `event_type VARCHAR(32)`
  (`deactivated`, `activated`, `reclassified`, `policy_changed`, `added_from_research`, `deleted_hard`)
* `before JSON NULL`
* `after JSON NULL`
* `reason VARCHAR(255) NULL`
* `created_at TIMESTAMP`

Indexes:

* `(organization_id, chunk_id, created_at)`

### 2.3 saved_research_items (optional, recommended)

If research mode produces ephemeral items not yet stored as chunks:

Table: `saved_research_items`

* `id UUID`
* `organization_id UUID`
* `user_id UUID`
* `query TEXT`
* `results JSON` (top N snippets + metadata)
* `created_at`

This is optional; useful for UX “recent searches” and debugging.

---

## 3) Service Layer Changes

### 3.1 Retrieval filtering

Update your `Retriever::knowledgeChunks(...)` (or equivalent) to apply:

* `WHERE is_active = true`
* `AND usage_policy != 'never_generate'`

Additionally:

* Facts and angles should be returned separately or tagged in the return payload.

Return shape change (example):

```php
[
  'facts' => [Chunk...],
  'angles' => [Chunk...],
  'examples' => [Chunk...],
  'quotes' => [Chunk...],
  'rejected' => [ [chunk_id, reason] ] // optional debug
]
```

Then in `ContextFactory->fromParts([...])`, preserve this structure so `PromptComposer` can provide different rules per kind.

### 3.2 Enforce angle caps

Add a retrieval policy default:

* `max_angles = 1`
* `max_examples = 1` (optional)
* Facts are prioritized.

Implementation: after scoring and gating, truncate each group.

### 3.3 Relevance gating integration

If you implement the Relevance Gate from prior spec, the gate should run **before** the grouping/truncation.

Pipeline:

1. Retrieve topK candidates
2. RelevanceGate accept/reject
3. Group accepted by `chunk_kind`
4. Apply caps

Persist gate results in snapshot options for debugging.

---

## 4) Knowledge Management API

Namespace: `api/v1/knowledge`

### 4.1 List chunks

`GET /api/v1/knowledge/chunks`

Query params:

* `q` (search)
* `kind` (`fact|angle|example|quote`)
* `status` (`active|inactive|all`)
* `policy` (`normal|inspiration_only|never_generate`)
* `source_type`
* pagination: `page`, `per_page`

Return:

* `data[]` chunk summary
* `meta` pagination

### 4.2 Read chunk detail

`GET /api/v1/knowledge/chunks/{id}`

Return:

* full chunk text
* metadata
* audit trail (last N events)

### 4.3 Soft deactivate / activate

`POST /api/v1/knowledge/chunks/{id}/deactivate`
`POST /api/v1/knowledge/chunks/{id}/activate`

Body:

* `reason?`

Writes audit event.

### 4.4 Reclassify (Fact/Angle/Example/Quote)

`POST /api/v1/knowledge/chunks/{id}/reclassify`

Body:

* `chunk_kind`
* `reason?`

Writes audit event.

### 4.5 Change usage policy

`POST /api/v1/knowledge/chunks/{id}/set-policy`

Body:

* `usage_policy` (`normal|inspiration_only|never_generate`)
* `reason?`

Writes audit event.

### 4.6 Hard delete (admin only)

`DELETE /api/v1/knowledge/chunks/{id}`

Guard:

* feature flag or role permission
* must include `confirm=true`

Write audit event type `deleted_hard` and delete.

---

## 5) Research Mode Search + Add API

This follows your research-mode concept doc. fileciteturn0file1

### 5.1 Search

`POST /api/v1/research/search`

Body:

* `query` (string)
* `filters?` (folders/tags, time bounds, source types)
* `limit?` default 20

Returns:

* `results[]` each with:

  * `snippet_text`
  * `score`
  * `source_type`, `source_ref`, `source_title`
  * `suggested_kind` (optional heuristic)
  * `suggested_policy` (optional)

Implementation notes:

* Can be backed by existing chunk embeddings + any other indices.
* Results should include **enough metadata** to add as a chunk without re-fetch.

### 5.2 Add selected result to KB

`POST /api/v1/research/add-to-knowledge`

Body:

* `snippet_text`
* `chunk_kind` (required)
* `usage_policy` (default `normal`)
* `source_type`, `source_ref`, `source_title`
* `reason?`

Server behavior:

* Create new `knowledge_chunk` row with `is_active=true`.
* Enqueue embedding job if needed.
* Write `knowledge_chunk_events` event `added_from_research`.

---

## 6) Permissions & Safety

### 6.1 Roles/permissions (names are examples)

* `knowledge.view`
* `knowledge.edit`
* `knowledge.deactivate`
* `knowledge.reclassify`
* `knowledge.set_policy`
* `knowledge.delete_hard` (admin)
* `research.search`
* `research.add_to_knowledge`

### 6.2 Rate limits

* Research search: protect against spam; per-user per-minute cap.
* Add-to-knowledge: cap per day or per minute to prevent accidental KB flooding.

---

## 7) Generation Integration

### 7.1 PromptComposer changes

* Facts: may be used as ground truth when relevant.
* Angles: optional inspiration; use at most one.
* Inspiration-only: may guide framing but must not be stated as fact.

### 7.2 Snapshot persistence

Add to snapshot `options`:

* `knowledge_context_breakdown: { facts, angles, examples, quotes }`
* `knowledge_disabled_count`
* `knowledge_gate: { candidates, accepted, rejected[] }` (if gate enabled)
* `knowledge_user_overrides_applied: bool`

This aligns with your existing snapshot persistence strategy in the service. fileciteturn0file0

---

## 8) Migration Plan

1. Add columns to `knowledge_chunks`.
2. Backfill `chunk_kind='fact'` and `usage_policy='normal'`.
3. Deploy API endpoints (behind feature flag).
4. Update retrieval filtering (`is_active`, `usage_policy`).
5. Update generation composer rules.
6. Turn on UI.

---

## 9) Acceptance Criteria

* Deactivated chunks never appear in new generations.
* Angles are not treated as mandatory context; facts are prioritized.
* Research search returns results; add-to-knowledge requires explicit kind.
* Audit log shows who changed chunk status/kind/policy.
* Snapshots clearly show what knowledge categories were used.
