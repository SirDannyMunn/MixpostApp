# Console Commands

This document describes the Artisan console commands defined in `app/Console/Commands`.
Each entry includes the signature and a brief description of what it does.

## AI and generation

- `ai:ingestion:evaluate`  
  Runs ingestion on a single input and produces an evaluation report (Phase A/B/C), with options for models, output format, and optional generation probes.

- `ai:research:ask {question}`  
  Runs a Research Mode query against Creative Intelligence data with optional source filters and output formats.

- `ai:context:hydrate {--type=all} {--force}`  
  Dispatches jobs to extract business facts and swipe structures from existing content.

- `ai:snapshots:list {--org=} {--intent=} {--limit=20} {--json}`  
  Lists recent generation snapshots with IDs and metadata.

- `ai:snapshots:replay {snapshot_id} [options]`  
  Replays a generation snapshot and outputs new results and scores as JSON, with optional prompt-only and model overrides.

- `ai:prompts:show {snapshot_id}`  
  Shows the system instruction and raw prompt string that would be sent for a snapshot.

- `llm:accounting-status {--days=7} {--detailed}`  
  Displays LLM accounting health and summary statistics for recent usage.

## Ingestion and knowledge

- `ingestion:process [filters and options]`  
  Processes one or many ingestion sources, extracting knowledge chunks and business facts; supports sync, force, and debug output.

- `ingestion:convert:social-watcher [options]`  
  Converts Social Watcher normalized content into ingestion sources and queues processing.

- `backfill:ingestion:sources {--limit=100} {--type=} {--force} {--debug}`  
  Dispatches ingestion jobs for pending ingestion_sources records.

- `backfill:ingestion:source-folders {--org=} {--limit=0} {--dry-run}`  
  Backfills ingestion_source_folders by running AI folder inference per ingestion source.

- `backfill:ingestion:bookmarks {--org=} {--limit=0} {--dry-run}`  
  Creates ingestion_sources for existing bookmarks without converting to KnowledgeItems.

- `backfill:folders:embeddings {--org=} {--only-missing} {--rebuild-stale}`  
  Backfills folder embeddings used for auto-scoped retrieval.

- `export:business-facts {path=database/seeders/data/business_facts.json}`  
  Exports the business_facts table to a JSON file.

- `seed:swipes {--count=5}`  
  Promotes random KnowledgeItems (from bookmarks) into SwipeItems and triggers structure extraction.

## Social Watcher

- `social-watcher:content:normalize {content_item_id} {--sync} {--queue=}`  
  Runs the NormalizeContentItem job for a Social Watcher ContentItem (sync or queued).

## Voice

- `voice:posts:attach`  
  Attaches Social Watcher normalized content posts to a Voice Profile, optionally creating the profile.

## Reports and diagnostics

- `content-service:report:get {snapshot_id?}`  
  Dumps a generation snapshot report to `storage/logs`.

- `db:verify:pgvector`  
  Verifies PostgreSQL connectivity and the pgvector extension setup.

## Maintenance and utilities

- `mixpost:publish`  
  Publishes all assets requested by Mixpost.

- `mixpost:gitignore:setup`  
  Sets up the `.gitignore`.

- `dev:ids:list`  
  Lists the first organization and user UUIDs for testing.

- `tinker:debug {file}`  
  Runs a PHP script from `tinker/debug` within the Laravel app context.
