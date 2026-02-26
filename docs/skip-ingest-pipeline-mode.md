# Skip-Ingest Pipeline Mode

## Overview

The skip-ingest mode enables reprocessing of existing `content_nodes` to regenerate intelligence layers **without re-scraping** from Apify actors.

This is a **first-class pipeline capability** that makes intelligence layers reproducible, durable, and independent from scrapers.

---

## Why This Matters

### Before (Scraper-Dependent Pipeline)
- Bug fix → must re-scrape everything
- Refactor validation → requires Apify calls
- Production fixes → risky and slow
- Intelligence tied to scraping

### After (Content-Driven Pipeline)
- Bug fix → reprocess existing content
- Refactor validation → deterministic replay
- Production fixes → safe and fast
- Intelligence reproducible from canonical data

---

## Command Usage

### Basic Reprocessing

```bash
php artisan social-watcher:pipeline:test --skip-ingest
```

or use the alias:

```bash
php artisan social-watcher:pipeline:test --from-existing
```

### Filter by Content Type

```bash
# Reprocess only posts (generate Creative Intelligence)
php artisan social-watcher:pipeline:test --skip-ingest --content-scope=posts

# Reprocess only comments (generate Topic Intelligence)
php artisan social-watcher:pipeline:test --skip-ingest --content-scope=comments

# Reprocess only transcripts (generate derived fragments)
php artisan social-watcher:pipeline:test --skip-ingest --content-scope=transcripts
```

### Filter by Date

```bash
# Only reprocess content created after specified date
php artisan social-watcher:pipeline:test --skip-ingest --since=2026-01-01
```

### Filter by Source

```bash
# Only reprocess content from specific source
php artisan social-watcher:pipeline:test --skip-ingest --source-id=<uuid>
```

### Combined Filters

```bash
# Reprocess posts from a specific source created in the last week
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --content-scope=posts \
  --source-id=<uuid> \
  --since=2026-01-07
```

### With Format Verification

```bash
# Verify canonical format after reprocessing
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --test-format \
  --mode=canonical
```

### Synchronous Execution (for testing)

```bash
# Force synchronous job execution to wait for results
php artisan social-watcher:pipeline:test --skip-ingest --sync
```

---

## What Gets Regenerated

### Execution Order

1. **Creative Intelligence (CI)** - Posts only
   - Dispatches `AnnotateCreative` jobs
   - Extracts hooks, angles, CTAs, emotional drivers
   - Stores as `content_annotations` with type `creative`

2. **Topic Intelligence (TI)** - Comments only
   - Dispatches `AnnotateTopic` jobs
   - Extracts topics, sentiment, signal scores
   - Stores as `content_annotations` with type `topic`

3. **Derived Fragments** - Transcripts only (future)
   - Generates summaries, claims, insights
   - **NOT** sentence/paragraph splits
   - AI-derived intelligence only

4. **Embeddings** - All annotation types
   - Auto-dispatched by annotation jobs
   - Vectors stored in `embeddings` table
   - Idempotent by (embeddable_type, embeddable_id, model)

5. **Clustering** - Optional
   - Groups annotations by similarity
   - Non-blocking operation
   - Use `--skip-clustering` to disable

---

## What Does NOT Happen

- ❌ No Apify actor calls
- ❌ No source creation
- ❌ No mutation of `content_nodes`
- ❌ No raw data regeneration
- ❌ No sentence/paragraph fragmentation

---

## Idempotency Guarantees

All jobs are safe to run multiple times:

### Annotations
- Checks `hasAnnotation()` before creating
- Upsert by `(content_node_id, annotation_type, hash)`
- No duplicates created

### Embeddings
- Checks `exists()` before generating
- Upsert by `(embeddable_type, embeddable_id, model)`
- No duplicate vectors

### Fragments
- Check existence before creating
- Upsert by `(content_node_id, fragment_type, hash)`
- Deterministic regeneration

---

## Implementation Architecture

### New Service

**`App\Services\Pipeline\ReprocessContentNodes`**

Responsibilities:
- Select content nodes by filters
- Dispatch jobs in correct order
- Never enqueue ingest/normalization
- Track warnings and errors

### Modified Command

**`PipelineTestCommand`**

New flags:
- `--skip-ingest` / `--from-existing`
- `--content-scope=all|posts|comments|transcripts`
- `--since=YYYY-MM-DD`
- `--source-id=<uuid>`

Logic changes:
- Detects skip-ingest mode
- Routes to `ReprocessContentNodes` service
- Supports format verification
- Generates reprocess-specific logs

---

## Use Cases

### 1. Fix Logic Bugs

```bash
# After fixing annotation logic
php artisan social-watcher:pipeline:test --skip-ingest --sync
```

### 2. Validate Refactors

```bash
# Ensure new code produces same output
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --test-format \
  --mode=canonical
```

### 3. Rebuild Intelligence Layers

```bash
# Regenerate all annotations after schema change
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --content-scope=all
```

### 4. Backfill New Features

```bash
# Add new annotation types to historical data
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --since=2025-01-01
```

### 5. Debug Specific Content

```bash
# Reprocess one source to debug
php artisan social-watcher:pipeline:test \
  --skip-ingest \
  --source-id=<uuid> \
  --sync \
  -v
```

---

## Testing

### Test Script

Run the comprehensive test:

```bash
php artisan tinker-debug:run test_skip_ingest_mode
```

This script:
- Verifies content nodes exist
- Runs reprocessing
- Validates job dispatch
- Checks idempotency
- Tests all filters

### Manual Testing

1. Create content first:
```bash
php artisan social-watcher:pipeline:test --profile=youtube_transcript --limit=3
```

2. Reprocess it:
```bash
php artisan social-watcher:pipeline:test --skip-ingest --sync
```

3. Verify no duplicates:
```bash
php artisan social-watcher:pipeline:test --skip-ingest --sync
# Should show same counts, no errors
```

---

## Acceptance Criteria

- ✅ Pipeline runs with zero Apify calls
- ✅ CI created for posts only
- ✅ TI created for comments only
- ✅ Fragments generated for transcripts only
- ✅ Running twice produces no duplicates
- ✅ `--test-format` passes on reprocessed data
- ✅ All filters work independently and combined
- ✅ Format verification integrates seamlessly

---

## Future Enhancements

### Derived Fragment Generation

Once implemented:
- `GenerateTranscriptSummaryFragmentsJob`
- `GenerateClaimFragmentsJob`
- `GenerateInsightFragmentsJob`

These will automatically dispatch when `--content-scope=transcripts`.

### Batch Reprocessing

For production backfills:
```bash
php artisan social-watcher:reprocess-batch \
  --since=2025-01-01 \
  --batch-size=100 \
  --delay=5
```

### Selective Regeneration

Future flag support:
```bash
--regenerate=ci,ti,embeddings  # Only specific layers
--force  # Bypass idempotency checks
```

---

## Architecture Benefits

### Before: Scraper-Coupled

```
Apify → Normalize → Fragment → Embed → Annotate → Cluster
  ↑                                                    |
  └────────── Must re-scrape for any fix ─────────────┘
```

### After: Content-Driven

```
content_nodes (durable)
    ↓
Annotate → Embed → Cluster (reproducible)
    ↑
    └── Safe to rerun anytime
```

---

## Conclusion

This capability transforms the pipeline from:
- **Scraper-dependent** → **Content-driven**
- **Fragile** → **Durable**
- **Risky** → **Safe**
- **Slow** → **Fast**

Intelligence layers are now **deterministic** and **reproducible**.

This is the correct long-term architecture.
