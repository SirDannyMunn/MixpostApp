Knowledge ingestion POST returned 422/500 due to missing columns on ingestion_sources (Resolved)

Summary
- Error: SQLSTATE[42703] Undefined column "title" on table "ingestion_sources" when inserting.
- Endpoint: POST /api/v1/ingestion-sources (type: "text").

Root cause
- DB table existed without newer fields: title, metadata, confidence_score, quality_score, deleted_at.
- Controller attempted to insert title/metadata; insert failed.

Fix
- Create migration now includes these fields for fresh installs.
- Alter migration remains to add fields for existing DBs.
- Controller now conditionally includes title/metadata only if columns exist to avoid crashes before migration is applied.

Action
- Run: php artisan migrate
- Verify POST returns 201 and GET lists the item.

Status: Resolved

