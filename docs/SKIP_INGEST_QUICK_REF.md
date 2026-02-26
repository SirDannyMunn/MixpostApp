# Skip-Ingest Quick Reference

## What It Does
Regenerates intelligence layers (CI, TI, embeddings, clusters) from existing `content_nodes` **without re-scraping**.

## Basic Commands

```bash
# Reprocess all existing content
php artisan social-watcher:pipeline:test --skip-ingest

# Reprocess posts only (Creative Intelligence)
php artisan social-watcher:pipeline:test --skip-ingest --content-scope=posts

# Reprocess comments only (Topic Intelligence)
php artisan social-watcher:pipeline:test --skip-ingest --content-scope=comments

# Reprocess with date filter
php artisan social-watcher:pipeline:test --skip-ingest --since=2026-01-01

# Reprocess specific source
php artisan social-watcher:pipeline:test --skip-ingest --source-id=<uuid>

# Verify format after reprocessing
php artisan social-watcher:pipeline:test --skip-ingest --test-format

# Synchronous (wait for jobs)
php artisan social-watcher:pipeline:test --skip-ingest --sync
```

## What Gets Created

| Content Type | Job                | Output Table            | Annotation Type |
|--------------|-------------------|-------------------------|-----------------|
| Posts        | AnnotateCreative  | content_annotations     | creative        |
| Comments     | AnnotateTopic     | content_annotations     | topic           |
| All          | GenerateEmbedding | embeddings              | -               |
| All          | ClusterAnnotations| annotation_clusters     | -               |

## Flags Reference

| Flag                    | Values                            | Purpose                          |
|-------------------------|-----------------------------------|----------------------------------|
| --skip-ingest           | (boolean)                         | Skip Apify scraping              |
| --from-existing         | (boolean)                         | Alias for --skip-ingest          |
| --content-scope         | all\|posts\|comments\|transcripts | Filter content type              |
| --since                 | YYYY-MM-DD                        | Date filter                      |
| --source-id             | UUID                              | Filter by source                 |
| --test-format           | (boolean)                         | Verify canonical format          |
| --sync                  | (boolean)                         | Force synchronous execution      |
| --mode                  | canonical\|shadow\|legacy         | Pipeline mode                    |

## Testing

```bash
# Run test script
php artisan tinker-debug:run test_skip_ingest_mode

# Manual test
php artisan social-watcher:pipeline:test --profile=youtube_transcript --limit=3
php artisan social-watcher:pipeline:test --skip-ingest --sync
```

## Idempotency

All operations are safe to run multiple times:
- Annotations check existence before creating
- Embeddings check existence before generating
- No duplicates created

## What Does NOT Happen

- ❌ No Apify calls
- ❌ No source creation
- ❌ No content_nodes mutation
- ❌ No raw data changes

## Use Cases

1. **Fix bugs**: Reprocess after logic fix
2. **Validate refactors**: Ensure deterministic output
3. **Backfill features**: Add new intelligence to old data
4. **Debug**: Reprocess specific content
5. **Production**: Regenerate intelligence safely

## Architecture

```
content_nodes (durable source of truth)
    ↓
Annotations (reproducible intelligence)
    ↓
Embeddings (vector representations)
    ↓
Clusters (groups by similarity)
```

## Documentation

- Full guide: [docs/skip-ingest-pipeline-mode.md](docs/skip-ingest-pipeline-mode.md)
- Implementation: [SKIP_INGEST_IMPLEMENTATION.md](SKIP_INGEST_IMPLEMENTATION.md)
- Test script: [Scratch/test_skip_ingest_mode.php](Scratch/test_skip_ingest_mode.php)
