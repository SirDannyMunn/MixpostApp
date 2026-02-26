# AI Context Plumbing, Extraction, and Data Seeding

This document captures the recent backend changes to fix model relationships, upgrade AI extraction jobs, and add reliable export/import for `business_facts`. It also outlines usage and future improvements.

## Summary of Changes

- Eloquent relationships wired up:
  - `App\Models\KnowledgeItem::businessFacts()` → `hasMany(BusinessFact::class, 'source_knowledge_item_id')`
  - `App\Models\SwipeItem::swipeStructures()` → `hasMany(SwipeStructure::class)` (existing `structures()` kept for compatibility)
  - `App\Models\BusinessFact::knowledgeItem()` → `belongsTo(KnowledgeItem::class, 'source_knowledge_item_id')`

- Hydration command corrections:
  - `app/Console/Commands/HydrateAiContext.php` now dispatches jobs with IDs: `ExtractBusinessFactsJob($item->id)` and `ExtractSwipeStructureJob($item->id)`.
  - Rewritten to remove a bad UTF-8 char and to stabilize output.

- Business Facts extraction upgraded to LLM:
  - `app/Jobs/ExtractBusinessFactsJob.php`
    - Uses `OpenRouterService::chatJSON` to extract 1–3 high‑value facts from `raw_text`.
    - Accepts either single object or `{facts: [...]}` arrays; caps at 3.
    - Normalizes `confidence` to 0..1 (divides by 100 if needed).
    - Types allowed: `pain_point|belief|stat|general`.

- Voice traits extraction upgraded to LLM:
  - `app/Jobs/ExtractVoiceTraitsJob.php`
    - Uses `OpenRouterService::chatJSON` to extract 3 tone traits.
    - Merges into `VoiceProfile.traits['tone']` (unique, max 10), increments `sample_size`, bumps `confidence` to max 0.95.

- Promote Knowledge Items into Swipes (to populate Styles):
  - `app/Console/Commands/SeedSwipesFromBookmarks.php` (`seed:swipes {--count=5}`)
    - Picks random `KnowledgeItem`s, creates `SwipeItem`s (sets `platform`, `raw_text_sha256`, `source_url` from metadata when present), and dispatches `ExtractSwipeStructureJob`.

- Business Facts export with link hints:
  - `app/Console/Commands/ExportBusinessFacts.php` (`export:business-facts [path]`)
    - Exports `business_facts` to JSON (default `database/seeders/data/business_facts.json`).
    - Includes relational hints to preserve accuracy across environments:
      - `user_email` (from users)
      - `organization_slug` (from organizations)
      - Knowledge item link hints: `ki_hash`, `ki_source`, `ki_source_id`
    - Timestamps formatted as `YYYY-MM-DD HH:MM:SS`.

- Robust Business Facts seeder that maintains real relationships:
  - `database/seeders/BusinessFactsDumpSeeder.php`
    - Truncates `business_facts` (clean sync).
    - Env toggle `BUSINESS_FACTS_SEED_PRESERVE_IDS` (default true). When false, regenerates UUIDs.
    - Resolves relations strictly:
      - `user_id` by exact ID or by `user_email`. If unresolved → skip row.
      - `organization_id` by exact ID or by `organization_slug`. If unresolved → skip row.
      - `source_knowledge_item_id` by hints within the resolved org:
        - Prefer (`ki_source`,`ki_source_id`) plus `ki_hash` when present.
        - Fallbacks: `ki_hash`, or `ki_source_id`, or `ki_source`.
        - If unresolved → set null (optional relationship).
    - Inserts only actual table columns and normalizes `created_at`.

## Usage

- Hydrate existing content:
  - `php artisan ai:hydrate --type=facts` (and/or `--type=swipes`)
  - Start worker: `php artisan queue:work`

- Export current Business Facts:
  - `php artisan export:business-facts`
  - Custom path: `php artisan export:business-facts storage/exports/business_facts.json`

- Seed Business Facts from dump:
  - Ensure `users` and `organizations` already exist (so lookups by email/slug succeed).
  - Optionally set `.env`: `BUSINESS_FACTS_SEED_PRESERVE_IDS=true|false`
  - `php artisan db:seed --class=BusinessFactsDumpSeeder`

- Promote content into Swipes (populate Styles):
  - `php artisan seed:swipes --count=5`
  - Start worker: `php artisan queue:work`

## Environment

- Set OpenRouter credentials to enable LLM calls:
  - `.env`:
    - `OPENROUTER_API_KEY=...`
    - Optional: `OPENROUTER_MODEL`, `OPENROUTER_CLASSIFIER_MODEL`

## Design Rationale

- Relationship fidelity across environments:
  - UUIDs differ across DBs; natural keys provide stable references. The exporter includes `user_email`, `organization_slug`, and KI link hints, enabling the seeder to reconstruct accurate relationships without guessing.
  - Unresolvable mandatory relations lead to skipping a row instead of remapping to the wrong records.

- Confidence normalization:
  - Downstream consumers operate on 0..1 floats; jobs normalize 0..100 → 0..1.

- Limits and caps:
  - Truncate input text and cap number of extracted facts to control cost/noise.

## Future Improvements

- Knowledge Item export/import pack
  - Add exporter/importer for `knowledge_items` + `knowledge_chunks` to guarantee KI presence when seeding related facts; keep mapping by (`organization_slug`, `ki_hash`, `ki_source`, `ki_source_id`).

- Strict validation mode for seeding
  - Add `BUSINESS_FACTS_SEED_STRICT=true` to fail the seeder on any unresolved relation and report counts for CI visibility.

- Dry‑run + report
  - Add a `--dry-run` option to the seeder to output a summary (resolved/Skipped/Null KI links) without writing.

- JSON schema + versioning
  - Include `schema_version` and `generated_at` in export payloads to enable migrations of dump formats.

- Tests
  - Add unit tests to validate export format and seeder resolution logic (email/slug/ki hints).

- Performance
  - Chunk exports for very large tables to control memory.
  - Consider indexes on resolution keys if repeatedly importing (`users.email`, `organizations.slug`, `knowledge_items.source`, `knowledge_items.source_id`, `knowledge_items.raw_text_sha256`).

- Security & PII
  - Ensure future exports avoid leaking sensitive fields. Current export includes `user_email` solely for lookup; consider hashing or a mapping file if needed.

