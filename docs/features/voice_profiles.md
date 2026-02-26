**Voice Profiles**

- Purpose: deterministically generate, edit, and reuse a creator/brand voice from Social Watcher normalized posts and manual attachments.
- Scope: backend service, API endpoints, and data model to build and store a canonical traits JSON with confidence and sample size.

**Data Model**

- `voice_profiles`
  - Keys: `id (uuid)`, `organization_id (uuid)`, `user_id (uuid)`, `name (string, nullable)`, `traits (json)`, `confidence (float)`, `sample_size (int)`, `updated_at (timestamp)`, `deleted_at (soft delete)`
  - Traits schema (canonical):
    - `description` string
    - `tone` string[]
    - `persona` string|null
    - `formality` string|null
    - `sentence_length` short|medium|long|null
    - `paragraph_density` tight|normal|airy|null
    - `pacing` slow|medium|fast|null
    - `emotional_intensity` low|medium|high|null
    - `style_signatures` string[]
    - `do_not_do` string[]
    - `keyword_bias` string[]
    - `reference_examples` string[]
- `voice_profile_posts`
  - Links `voice_profiles` → `sw_normalized_content`
  - Keys: `voice_profile_id`, `normalized_content_id`, `source_type (platform)`, `weight (decimal, nullable)`, `locked (bool)`, timestamps
  - Unique on `(voice_profile_id, normalized_content_id)`

**Builder Service**

- `App\Services\Voice\VoiceProfileBuilderService`
  - Collects candidates from attached posts; if none, supports `source_id` filter against `sw_normalized_content`.
  - Cleans text (strip URLs, normalize whitespace, drop empty content).
  - Batches 20–50 posts, extracts voice signals per batch via `OpenRouterService::chatJSON`, then consolidates into canonical traits JSON.
  - Computes confidence: platform-weighted base with sample-size boost; stores `sample_size` and `confidence`.

**API Endpoints**

- Base path (org-scoped + auth): `POST/GET /api/v1/...`
  - `GET /voice-profiles`
    - Lists profiles for current organization.
  - `GET /voice-profiles/{id}`
    - Returns full profile (including `traits`, `confidence`, `sample_size`).
  - `POST /voice-profiles`
    - Body: `{ "name"?: string }`
    - Creates a new empty profile for current user in org.
  - `POST /voice-profiles/{id}/rebuild`
    - Optional body filters: `{ source_id?: number, min_engagement?: number, start_date?: date, end_date?: date, exclude_replies?: boolean, limit?: 1..500 }`
    - Recomputes `traits`, `confidence`, `sample_size`.
  - `POST /voice-profiles/{id}/posts`
    - Body: `{ normalized_content_id: string, weight?: number, locked?: boolean }`
    - Attaches a normalized content row to the profile (upsert).
  - `DELETE /voice-profiles/{id}/posts/{normalizedContentId}`
    - Detaches the normalized content from the profile.

**Typical Workflow**

- Create profile: `POST /api/v1/voice-profiles`
- Attach posts: `POST /api/v1/voice-profiles/{id}/posts` with `normalized_content_id` from Social Watcher.
- Rebuild profile: `POST /api/v1/voice-profiles/{id}/rebuild` (optionally filter via `source_id`, engagement, dates).
- Use in content generation: pass `voice_profile_id` to existing content generator APIs to include traits.

**Examples (curl)**

- Create
  - `curl -X POST -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Content-Type: application/json" \`
  - `  https://example.com/api/v1/voice-profiles -d '{"name":"Alex Hormozi Voice"}'`
- Attach post
  - `curl -X POST -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Content-Type: application/json" \`
  - `  https://example.com/api/v1/voice-profiles/$ID/posts -d '{"normalized_content_id":"UUID"}'`
- Rebuild
  - `curl -X POST -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" -H "Content-Type: application/json" \`
  - `  https://example.com/api/v1/voice-profiles/$ID/rebuild -d '{"min_engagement":25,"exclude_replies":true,"limit":200}'`
- Get profile
  - `curl -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" https://example.com/api/v1/voice-profiles/$ID`

**Failure Handling**

- No candidates or empty text → `400 { "message": "insufficient data" }`
- LLM failure → returns minimal structured defaults where possible; prefer retry.
- Few samples → traits built but `confidence` remains low.

**Operational Notes**

- Requires Social Watcher migrations and data (`sw_normalized_content`) to exist.
- Queue workers recommended if you later offload rebuild into jobs; current implementation is synchronous.
- `user_id` is required by existing schema; convert to nullable if you need org-wide shared profiles.
- Confidence formula is an initial heuristic; refine penalties for noise/diversity when metrics are available.

**Migration & Setup**

- Run: `php artisan migrate`
- Ensure OpenRouter credentials are configured: set `OPENROUTER_API_KEY` and model settings in `config/services.php`.
- Verify org context middleware is active (`X-Organization-Id` header or `organization_id` query param).

**CLI Command**

- `php artisan voice:attach-posts --organization={org_id|slug} --user={user_id|email} [--create] [--name="Profile Name"] [--profile={profile_id}] [--rebuild] [--posts=ID1,ID2,ID3] [--source-id=123] [--platform=twitter|instagram|linkedin|youtube] [--username=handle] [--limit=50]`
  - `--profile={profile_id}`: Attach to a specific profile (must belong to org).
  - `--create`: Create if no profile exists for org+user (optionally set `--name`).
  - `--posts`: Comma-separated `sw_normalized_content.id` list.
  - `--source-id`: Auto-select posts by Social Watcher `source_id` when `--posts` not provided (takes precedence over platform+username).
  - `--platform` + `--username`: Auto-select posts when `--posts` and `--source-id` are not provided. Example: `--platform=twitter --username=indexsy --limit=50`.
  - `--rebuild`: Regenerate traits immediately after attaching posts.
