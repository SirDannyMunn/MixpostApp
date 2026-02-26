
[<img src="./art/logo.svg" alt="Logo Mixpost" />](https://mixpost.app)

# MixpostApp — Standalone Mixpost Lite + AI Generation/Retrieval

This repository is a standalone Laravel application that runs Mixpost Lite and adds a Postgres+pgvector backed ingestion/retrieval system plus an OpenRouter-backed AI content generation pipeline.

If you’re looking for the upstream Mixpost package (not the standalone app), see https://github.com/inovector/mixpost.

## What’s in this app

- Mixpost Lite (self-hosted social media scheduling/management)
- AI content generation system (prompting, templates, retrieval, strict JSON validation/repair, snapshots + replay)
- Content ingestion pipeline (text/bookmarks/transcripts → knowledge items/chunks → embeddings)
- Retrieval + evaluation harness (ingestion quality reports + generation probes)
- Voice Profiles (derive a voice from selected source posts and apply it during generation)
- Social Watcher package (ingest/normalize social content via Apify; used as a post source for Voice Profiles)
- Billing package (local package providing billing endpoints/workflows)

## Tech overview

- Backend: Laravel (PHP)
- DB: PostgreSQL (with `pgvector` extension)
- Frontend assets: Vite
- AI provider: OpenRouter (chat/classification/embeddings/image defaults configured in `config/services.php`)
- Jobs: Laravel queue (required for ingestion + some AI tasks)

## Quick start (local dev)

Prereqs:

- PHP + Composer
- Node.js + npm
- Docker (for Postgres+pgvector)

Install dependencies:

```bash
composer install
npm install
```

Configure env:

```bash
copy .env.example .env
php artisan key:generate
```

Start Postgres+pgvector (see `docker-compose.yml`):

```bash
docker compose up -d
php artisan db:verify-pgvector
```

Run migrations:

```bash
php artisan migrate
```

Run the queue worker (required for ingestion + some AI flows):

```bash
php artisan queue:work
```

Run the web app + frontend assets:

```bash
php artisan serve
npm run dev
```

## Configuration (high-signal)

All defaults live in `.env.example`. The most important additions for this app:

### Database + pgvector

- You must use PostgreSQL for the retrieval stack.
- Ensure the `vector` extension is installed in your DB.
- Sanity check: `php artisan db:verify-pgvector`

Vector knobs:

- `config/vector.php` controls similarity thresholds and per-intent retrieval caps.

### OpenRouter (AI)

Configured in `config/services.php` under the `openrouter` key.

Common env vars:

- `OPENROUTER_API_KEY`
- `OPENROUTER_API_URL` (default: `https://openrouter.ai/api/v1`)
- `OPENROUTER_MODEL` (chat/generation)
- `OPENROUTER_CLASSIFIER_MODEL`
- `OPENROUTER_EMBED_MODEL`
- `OPENROUTER_DEFAULT_MODEL` (image generation default)

AI behavior knobs:

- `config/ai.php` (model selection by stage, retrieval weights/heuristics, evaluation options)

## AI system (how it fits together)

The AI stack is designed around a strict, debuggable pipeline:

1) classify intent / task
2) retrieve relevant knowledge (pgvector similarity + heuristics)
3) assemble context (business facts, swipes, voice profile, retrieved chunks)
4) render/parse the selected template
5) call the LLM (often in JSON-only mode)
6) validate + repair output (no partial/truncated JSON)
7) persist a snapshot (inputs, options, retrieved items, outputs, scores)
8) optionally replay snapshots for debugging and regression checks

Primary implementation entrypoint:

- `app/Services/Ai/ContentGeneratorService.php`

CLI tooling for debugging:

- `php artisan ai:replay-snapshot {snapshot_id}` (see `app/Console/Commands/ReplaySnapshot.php`)
- `php artisan ai:list-snapshots` (see `app/Console/Commands/ListSnapshots.php`)
- `php artisan ai:show-prompt ...` (see `app/Console/Commands/ShowPrompt.php`)

Detailed docs:

- `docs/features/content-generator-service.md`
- `docs/features/ai_content_generation_chat_system.md`
- `docs/features/ai-controller-generate-chat-response.md`
- `docs/features/ai_content_generation_and_template_parsing.md`
- `docs/ai_content_generation_refactor_overview.md`

## Ingestion + retrieval

The ingestion system turns internal content into searchable, embedded knowledge.

High-level flow:

1) Create an ingestion source (text/file/bookmark/transcript)
2) Normalize + dedupe
3) Create a knowledge item
4) Chunk content into knowledge chunks
5) Classify chunks (so retrieval can filter/weight)
6) Embed chunks into `knowledge_chunks.embedding_vec`

Docs:

- `docs/features/ingestion-pipeline.md`
- `docs/features/ingestion-eval-and-retrieval.md`

### Evaluation harness

The eval harness runs ingestion on a single input and produces a structured report (and can optionally run generation probes).

Command:

```bash
php artisan ai:ingestion:eval --org=<ORG_UUID> --user=<USER_UUID> --input=<path> --title="..." --format=both --cleanup --log-files --run-generation
```

See `app/Console/Commands/AiIngestionEval.php` for options.

### Hydrate derived context (facts/swipes)

This dispatches jobs to extract Business Facts and Swipe Structures from existing content:

```bash
php artisan ai:hydrate --type=all
```

See `app/Console/Commands/HydrateAiContext.php`.

## Voice profiles

Voice Profiles let you build a “voice” from example posts and apply it during generation.

- Data lives in `voice_profiles` and `voice_profile_posts`.
- Voice profiles often use Social Watcher normalized posts as training material.

Docs:

- `docs/features/voice_profiles.md`

CLI helper to attach source posts:

```bash
php artisan voice:attach-posts --profile=<VOICE_PROFILE_ID> --posts=<comma_separated_normalized_content_ids> --rebuild
```

See `app/Console/Commands/VoiceAttachPosts.php`.

## Social Watcher (package)

Social Watcher lives in `packages/social-watcher` and provides:

- ingestion from Apify
- normalization into a consistent “NormalizedContent” shape
- API routes (separate from the core `/api/v1` routes)

See `packages/social-watcher/README.md`.

## Billing (package)

Billing lives in `packages/laravel-billing-new` and provides backend billing endpoints/workflows.

- See `packages/laravel-billing-new/README.md`
- Quick start: `packages/laravel-billing-new/QUICKSTART.md`

## API surface

Most API routes are under `/api/v1`.

- Authoritative map: `routes/api.php`
- AI endpoints: `app/Http/Controllers/Api/V1/AiController.php`

Social Watcher routes are typically exposed under their own prefix; see the package README for details.

## Troubleshooting

### pgvector not found

- Run `php artisan db:verify-pgvector`
- Ensure your Postgres container has the extension installed (see the image used in `docker-compose.yml`).

### Queue not running

- Ingestion and several AI workflows rely on queued jobs.
- Start a worker: `php artisan queue:work`

### OpenRouter errors

- Confirm `OPENROUTER_API_KEY` in `.env`
- Confirm the API base URL is `https://openrouter.ai/api/v1` (default in `config/services.php`)

