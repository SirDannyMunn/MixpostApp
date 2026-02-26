Below is a **formal engineering spec** for **Ingestion Pipelines – Phase 1.2**, written in the same style as your existing internal docs and **explicitly scoped to concrete files, methods, and behaviors**.

This is the document you hand to an engineer and say: *implement exactly this*.

---

# Engineering Spec

## Ingestion Pipelines – Phase 1.2

### Canonical KnowledgeItem Enforcement & Dedup Repair

**Status:** Required
**Scope:** Backend ingestion + knowledge layer
**Risk Level:** High (data integrity)
**Prerequisite:** Phase 1 merged

---

## 1. Problem Statement

Phase 1 ingestion pipelines allow **multiple KnowledgeItem records to be created for identical content**, violating the core invariant required by retrieval and generation systems.

Observed issues:

* Multiple `knowledge_items` with identical `raw_text_sha256`
* Chunking and embedding executed multiple times for the same content
* Retrieval scores diluted across duplicates
* Backfill produces non-deterministic results
* Dedup logic incorrectly scoped by `source_id`

This phase enforces a **single canonical KnowledgeItem per unique content hash**.

---

## 2. Canonical Invariant (Must Hold)

> For a given `(organization_id, raw_text_sha256)`
> **Exactly one KnowledgeItem may exist**

All ingestion sources referencing the same content must attach to that KnowledgeItem.

---

## 3. Files in Scope

### Primary

* `app/Jobs/ProcessIngestionSourceJob.php`
* `app/Models/KnowledgeItem.php`
* `app/Models/IngestionSource.php`

### Secondary

* `app/Console/Commands/BackfillIngestionSources.php`
* `app/Jobs/ChunkKnowledgeItemJob.php`
* `app/Jobs/EmbedKnowledgeChunksJob.php`

### Database

* `database/migrations/2026_01_XX_XXXXXX_add_unique_knowledge_item_hash.php`

---

## 4. Required Changes

---

## 4.1 ProcessIngestionSourceJob – Canonical KnowledgeItem Resolution

**File:**
`app/Jobs/ProcessIngestionSourceJob.php`

### New Canonical Lookup (REQUIRED)

All ingestion paths (`bookmark`, `text`, future types) **must begin** with:

```php
$hash = hash('sha256', $rawText);

$canonical = KnowledgeItem::where('organization_id', $src->organization_id)
    ->where('raw_text_sha256', $hash)
    ->first();
```

❌ Do NOT scope by:

* `source_id`
* `source_type`
* `ingestion_source_id`

---

## 4.2 Dedup Handling (Short-Circuit Path)

### If Canonical Exists and `force === false`

```php
if ($canonical && !$this->force) {
    $src->knowledge_item_id = $canonical->id;
    $src->status = 'completed';
    $src->dedup_reason = 'knowledge_item_duplicate';
    $src->save();

    IngestionSourceDeduped::dispatch(
        $src->id,
        'knowledge_item_duplicate',
        $canonical->id
    );

    return; // MUST EXIT — no downstream jobs
}
```

### Rules

* **No KnowledgeItem creation**
* **No chunking**
* **No embedding**
* **No extraction jobs**
* Status MUST be `completed`

---

## 4.3 KnowledgeItem Creation (Only If Canonical Missing)

### Creation Block

```php
$item = KnowledgeItem::create([
    'organization_id' => $src->organization_id,
    'user_id' => $src->user_id,
    'source_type' => $src->source_type,
    'source_id' => $src->source_id,
    'raw_text_sha256' => $hash,
    'confidence' => $src->confidence_score ?? 0.5,
    'ingested_at' => now(),
]);
```

Immediately attach:

```php
$src->knowledge_item_id = $item->id;
$src->save();
```

---

## 4.4 Downstream Job Dispatch (Canonical Only)

### Allowed ONLY when KnowledgeItem was newly created

```php
Bus::chain([
    new ChunkKnowledgeItemJob($item->id),
    new EmbedKnowledgeChunksJob($item->id),
    new ExtractVoiceTraitsJob($item->id),
    new ExtractBusinessFactsJob($item->id),
])->dispatch();
```

### Guard Condition

```php
if ($canonical && !$this->force) {
    // DO NOT DISPATCH
}
```

---

## 5. Database Constraint (Strong Enforcement)

### Migration

**File:**
`database/migrations/2026_01_XX_XXXXXX_add_unique_knowledge_item_hash.php`

```php
Schema::table('knowledge_items', function (Blueprint $table) {
    $table->unique(
        ['organization_id', 'raw_text_sha256'],
        'uniq_org_knowledge_hash'
    );
});
```

---

## 6. Race Condition Handling

If a duplicate key exception occurs during creation:

```php
catch (\Illuminate\Database\QueryException $e) {
    if ($e->getCode() === '23000') {
        $canonical = KnowledgeItem::where('organization_id', $src->organization_id)
            ->where('raw_text_sha256', $hash)
            ->first();

        $src->knowledge_item_id = $canonical->id;
        $src->status = 'completed';
        $src->dedup_reason = 'knowledge_item_duplicate';
        $src->save();

        return;
    }

    throw $e;
}
```

---

## 7. Backfill Command Behavior

**File:**
`app/Console/Commands/BackfillIngestionSources.php`

### Required Guarantees

* Safe to run repeatedly
* Must never create duplicate KnowledgeItems
* Must respect `--force` flag
* Must attach historical ingestion sources to canonical items

---

## 8. Logging & Observability

### Required Log Payload on Dedup

```json
{
  "ingestion_source_id": "...",
  "canonical_knowledge_item_id": "...",
  "dedup_reason": "knowledge_item_duplicate",
  "force": false
}
```

---

## 9. Acceptance Criteria (Blocking)

Phase 1.2 is complete **only if**:

* [ ] Identical content always resolves to a single KnowledgeItem
* [ ] Backfill produces zero new KnowledgeItems on rerun
* [ ] Chunk counts remain constant across reruns
* [ ] Retrieval scores stabilize
* [ ] No downstream jobs run on dedup

Failure of any item = **do not start Phase 2**.

---

## 10. Explicit Non-Scope

This phase does **not** include:

* LLM-based chunk typing
* Source-aware pipelines (YouTube, social)
* Temporal chunking
* UI changes
* Confidence recalibration

Those belong to Phase 2.

---

## Final Note (Important)

Phase 1.2 is **not optional cleanup**.
It is a **structural correction** that prevents exponential corruption later.

Once this is merged and verified, Phase 2 can be safely designed.

If you want next:

* I can generate the **exact patch diff** for `ProcessIngestionSourceJob.php`
* Or write a **one-time reconciliation script** to merge existing duplicate KnowledgeItems
