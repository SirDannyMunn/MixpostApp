Supreme leader,

You’re correct. **Yes is the answer**, and this is a structural bug, not a pipeline bug.

Right now your system has a **broken contract** between `ingestion_sources` and `bookmarks`.

The ingestion pipeline assumes `ingestion_sources.raw_text` is the canonical payload.
But for `source_type = bookmark`, the canonical payload **lives in the bookmarks table**, not in `ingestion_sources`.

That mismatch is why everything downstream looks “correct” but produces garbage or nothing.

Below is a **clean engineering spec to fix this properly**, without introducing scraping or duplicating logic.

---

# Engineering Spec

## Bookmark → Ingestion Source Text Resolution

**Phase: Phase 2.1.2 (Blocking Fix)**

### Status

🔴 **Blocking bug** — ingestion pipeline cannot function correctly for bookmarks.

---

## 1. Problem Statement

### Current State

* `ingestion_sources` is the **entry point** for all ingestion pipelines.
* Pipeline jobs (Normalize, Chunk, Classify, Embed) **read from `ingestion_sources.raw_text`**.
* For `source_type = bookmark`:

  * `raw_text` is **always NULL by design**
  * Actual content exists in the **`bookmarks` table**
* Result:

  * `ProcessIngestionSourceJob` attempts to fetch content externally (incorrect)
  * Bookmark ingestion frequently ends in `skipped_no_content`
  * Downstream jobs operate on empty or placeholder text

### Root Cause

There is **no resolution step** that maps:

```
ingestion_sources (bookmark)
        ↓
bookmarks.content / bookmarks.text
```

The pipeline is correct.
The **data contract is not**.

---

## 2. Design Decision (Authoritative)

### Canonical Rule

> **Ingestion sources NEVER fetch social content.**
> They resolve content from internal models only.

### Source of Truth

| source_type  | Canonical text location                        |
| ------------ | ---------------------------------------------- |
| `bookmark`   | `bookmarks.text` (or equivalent content field) |
| `text`       | `ingestion_sources.raw_text`                   |
| `file`       | extracted file text                            |
| `transcript` | transcript body                                |
| others       | future resolvers                               |

---

## 3. High-Level Architecture Change

Introduce a **Content Resolution Layer** inside ingestion processing.

```
ProcessIngestionSourceJob
        ↓
resolveRawText()
        ↓
KnowledgeItem.raw_text
        ↓
Normalization / Chunking / etc
```

### Absolutely no HTTP fetching for bookmarks.

---

## 4. Implementation Details

---

### 4.1 Add Content Resolver Service

**File**

```
app/Services/Ingestion/IngestionContentResolver.php
```

**Responsibility**

* Resolve raw text for an ingestion source
* Return text or null
* Never fetch external URLs

**Interface**

```php
class IngestionContentResolver
{
    public function resolve(IngestionSource $source): ?string;
}
```

**Behavior**

```php
switch ($source->source_type) {
    case 'bookmark':
        return $this->resolveBookmark($source);

    case 'text':
        return trim($source->raw_text);

    default:
        return null;
}
```

---

### 4.2 Bookmark Resolution Logic

**Method**

```php
protected function resolveBookmark(IngestionSource $source): ?string
```

**Steps**

1. Use `source_id` to load `Bookmark`
2. Extract **existing stored content only**
3. Validate content
4. Return text or null

**Rules**

* If bookmark not found → return null
* If bookmark content empty → return null
* No scraping
* No fallback text
* No lorem ipsum

**Example**

```php
$bookmark = Bookmark::find($source->source_id);

if (!$bookmark) return null;

$text = trim($bookmark->text ?? '');

return $text !== '' ? $text : null;
```

---

### 4.3 Update `ProcessIngestionSourceJob`

**File**

```
app/Jobs/ProcessIngestionSourceJob.php
```

#### Replace this logic:

* ❌ `bookmark.fetch_text`
* ❌ any HTTP fetch
* ❌ any attempt to read `raw_url`

#### With:

```php
$text = $resolver->resolve($source);

if ($text === null) {
    $source->status = 'failed';
    $source->error = 'No internal content for ingestion source';
    $source->save();

    $logger->flush('skipped_no_internal_content');
    return;
}
```

---

### 4.4 Knowledge Item Creation Rule (Critical)

When creating the `KnowledgeItem`:

```php
KnowledgeItem::create([
    'raw_text' => $text,
    'source' => $source->source_type,
    'ingestion_source_id' => $source->id,
]);
```

**Never** leave `raw_text` empty for items that enter the pipeline.

If you can’t populate `raw_text`, **do not create a KnowledgeItem**.

---

## 5. Status & Error Semantics (Strict)

### Valid states

| Status       | Meaning                        |
| ------------ | ------------------------------ |
| `pending`    | awaiting processing            |
| `processing` | resolving + scoring            |
| `completed`  | successfully ingested          |
| `failed`     | no internal content or invalid |

### Bookmark-specific failures

* `No internal content for ingestion source`
* `Bookmark missing content`
* `Bookmark not found`

---

## 6. Deletion & Re-import Safety

Since you deleted:

* all ingestion sources
* lorem ipsum bookmarks

This fix is **safe to re-import**:

* bookmarks remain canonical
* ingestion sources will correctly hydrate text
* no duplicates if dedup hash remains stable

---

## 7. Acceptance Criteria (Non-Negotiable)

✅ Bookmark ingestion **never performs HTTP fetch**
✅ Bookmark ingestion **uses stored bookmark text only**
✅ `raw_text` populated before KnowledgeItem creation
✅ No lorem ipsum reaches vector DB
✅ `skipped_no_content` only occurs when bookmark truly has no content
✅ Phase 2 pipelines receive meaningful text or do not run

---

## 8. Why This Is the Right Fix

* Preserves your earlier architectural decision (bookmark model separation)
* Keeps ingestion_sources lightweight and generic
* Prevents accidental scraping liability
* Makes ingestion deterministic and debuggable
* Sets you up cleanly for Phase 2 pipelines

---

# Implementation Notes (2026-01-02)

- Added `app/Services/Ingestion/IngestionContentResolver.php` with strict internal-only resolution for `bookmark` and `text`.
- Updated `app/Jobs/ProcessIngestionSourceJob.php` to:
  - Remove all HTTP fetching for bookmarks.
  - Use `IngestionContentResolver` to get text.
  - Mark sources with missing internal content as `failed` and log `skipped_no_internal_content`.
  - Ensure `KnowledgeItem` creation always includes non-empty `raw_text`.
- Backfill command `app/Console/Commands/BackfillBookmarkIngestionSources.php` already creates `ingestion_sources` for bookmarks without populating `raw_text`, matching the contract.

Acceptance criteria in section 7 are now satisfied.

