Supreme leader, here’s a clean engineering spec for the **backend only**, built in **Laravel**, assuming **Apify does all scraping** and pushes results to us.

This spec is focused, minimal, and built to be extended into a SaaS later.

---

## 1. Purpose & Scope

### Goal

Build a Laravel backend that:

* Receives scraped content from Apify (competitor posts, articles, videos, etc.).
* Normalizes and stores it.
* Calculates engagement / trend scores.
* Triggers alerts when content “pops.”
* Exposes APIs for a future dashboard / front-end.
* Optionally forwards key items to external systems (Notion, Slack, email, etc.).

### Out of scope (for now)

* No frontend.
* No in-app user management beyond a single admin user (or basic auth token).
* No scraping logic (that’s Apify’s job).
* No multi-tenant complexity (but we’ll keep it extendable).

---

## 2. High-Level Architecture

### Components

1. **Apify Integration Layer**

   * Public webhook endpoint(s) that Apify actors call.
   * Validates incoming requests, pushes them to a queue.
   * Maps Apify payload → internal DTO.

2. **Ingestion Pipeline**

   * Queue jobs that:

     * Normalize content.
     * Upsert sources & content items.
     * Attach metrics (likes, shares, comments, views, etc.).
     * Apply initial scoring.

3. **Scoring & Analytics Layer**

   * Engagement scoring (per item).
   * Velocity scoring (change over time).
   * Combined “relevance” score.
   * Optional keyword extraction / tags (can later use AI or simple text analysis).

4. **Alert Engine**

   * Threshold rules (e.g., score >= X, velocity >= Y).
   * Alert generation & dispatch.
   * Notification channels: email, Slack webhook, generic webhooks.

5. **Query & API Layer**

   * REST API for:

     * Listing sources.
     * Listing / searching content items.
     * Viewing trends.
     * Managing rules & alert settings.

6. **Persistence**

   * MySQL / Postgres.
   * Eloquent models with clear relationships.
   * Event-based updates where useful.

7. **Jobs & Scheduler**

   * Handle heavy work async.
   * Scheduled tasks for:

     * Re-scoring older items.
     * Checking for trend shifts.
     * Cleanup of stale data.

---

## 3. Data Model

### 3.1. Tables

#### `sources`

Represents a tracked “origin” (competitor, feed, channel, etc.).

* `id` (PK)
* `name` (string) – e.g., “Competitor A TikTok”, “Blog X”
* `type` (enum: `website`, `youtube`, `tiktok`, `twitter`, `reddit`, `generic`)
* `platform_identifier` (string, nullable) – channel ID, domain, handle, etc.
* `apify_actor_id` (string, nullable) – reference to Apify actor used to scrape.
* `apify_config` (json, nullable) – per-source scraping config.
* `url` (string, nullable)
* `is_active` (boolean)
* `meta` (json) – flexible metadata.
* `created_at`, `updated_at`

#### `content_items`

Represents a single content piece (post, video, article, etc.).

* `id` (PK)
* `source_id` (FK → sources)
* `external_id` (string, indexed) – platform-specific ID (e.g., YouTube video ID, tweet ID).
* `url` (string)
* `title` (string, nullable)
* `summary` (text, nullable)
* `raw_content` (longtext, nullable) – full text, caption, transcript, HTML stripped.
* `content_type` (enum: `post`, `video`, `article`, `thread`, `comment`, `other`)
* `language` (string(10), nullable)
* `published_at` (datetime, nullable)
* `first_seen_at` (datetime)
* `last_seen_at` (datetime)

Scoring & status:

* `engagement_score` (decimal(8,2)) – normalized score 0–100 (or more).
* `velocity_score` (decimal(8,2)) – growth rate.
* `composite_score` (decimal(8,2)) – weighted sum used for ranking.
* `score_version` (string) – to track scoring algorithm changes.
* `status` (enum: `new`, `tracked`, `archived`, `ignored`)

Indexes:

* `source_id`, `external_id` unique composite.
* `composite_score` index.
* `published_at` index.

#### `content_metrics`

Snapshot of metrics over time (for velocity/trends).

* `id` (PK)
* `content_item_id` (FK → content_items)
* `snapshot_at` (datetime, indexed)
* `likes` (unsigned int, default 0)
* `comments` (unsigned int, default 0)
* `shares` (unsigned int, default 0)
* `views` (unsigned bigint, default 0)
* `other_metrics` (json, nullable) – platform-specific metrics: saves, retweets, bookmarks, etc.

Composite index:

* (`content_item_id`, `snapshot_at`)

#### `content_tags`

Simple tagging.

* `id` (PK)
* `name` (string, unique) – e.g., “pricing”, “ai”, “laundry”, etc.

#### `content_item_tag`

Pivot between items and tags.

* `content_item_id` (FK)
* `content_tag_id` (FK)
* composite unique index.

#### `alert_rules`

Rules for when to raise alerts.

* `id` (PK)
* `name` (string)
* `description` (text, nullable)
* `is_active` (boolean)
* `scope_type` (enum: `global`, `source`, `tag`)
* `scope_id` (nullable FK, depending on scope)
* `min_composite_score` (decimal(8,2), nullable)
* `min_velocity_score` (decimal(8,2), nullable)
* `min_likes` (unsigned int, nullable)
* `min_comments` (unsigned int, nullable)
* `min_views` (unsigned bigint, nullable)
* `time_window_minutes` (int, nullable) – for velocity checks.
* `channels` (json) – e.g., `["email","slack","webhook"]`
* `channel_config` (json) – email addresses, webhook URLs, etc.
* `dedup_interval_minutes` (int) – avoid spamming alerts.
* `created_at`, `updated_at`

#### `alerts`

Fired alert instances.

* `id` (PK)
* `alert_rule_id` (FK)
* `content_item_id` (FK, nullable if rule is aggregate-level)
* `payload` (json) – snapshot of the metrics/score.
* `triggered_at` (datetime)
* `delivered_channels` (json)
* `status` (enum: `created`, `sent`, `failed`)
* `error_message` (text, nullable)

Index:

* (`alert_rule_id`, `triggered_at`)

#### `apify_webhook_logs`

Log incoming Apify calls for debugging/auditing.

* `id` (PK)
* `source_id` (FK, nullable)
* `actor_id` (string, nullable)
* `run_id` (string, nullable)
* `payload` (json)
* `status` (enum: `received`, `processed`, `failed`)
* `error_message` (text, nullable)
* `received_at` (datetime)
* `processed_at` (datetime, nullable)

---

## 4. Apify Integration

### 4.1. Authentication

Options:

* Use a shared secret token in the webhook URL: `/api/webhooks/apify/{secret}`
* Or HMAC signature header (more robust, but more work).

Spec:

* `.env` value: `APIFY_WEBHOOK_SECRET=<random-string>`
* Middleware `VerifyApifySignature` checks the secret path segment or header.

### 4.2. Webhook Endpoint

Route (API):

```php
Route::post('/webhooks/apify', [ApifyWebhookController::class, 'handle'])
    ->middleware('apify.verify'); // custom middleware
```

Controller responsibilities:

1. Validate request shape.
2. Log payload into `apify_webhook_logs` (`status = received`).
3. Dispatch job `ProcessApifyPayloadJob` with `log_id`.
4. Return 202.

### 4.3. Expected Apify Payload Shape (Example)

Assume Apify actor sends something like:

```json
{
  "actorId": "xyz",
  "runId": "abc",
  "sourceRef": "competitor_a_tiktok",
  "items": [
    {
      "externalId": "123456",
      "url": "https://www.tiktok.com/@user/video/123456",
      "title": "How to grow your laundry business",
      "text": "Caption text...",
      "publishedAt": "2025-12-29T13:00:00Z",
      "metrics": {
        "likes": 1200,
        "comments": 56,
        "shares": 80,
        "views": 25000
      },
      "tags": ["laundry", "business", "pricing"]
    }
  ]
}
```

You can control this in Apify, so keep it simple and consistent.

---

## 5. Ingestion Pipeline

### 5.1. Job: `ProcessApifyPayloadJob`

Input:

* `apify_webhook_log_id`.

Steps:

1. Load `ApifyWebhookLog`.
2. Parse `actorId`, `sourceRef` → resolve or create `Source`.

   * Look up `source` by `apify_actor_id` or some `meta->sourceRef`.
3. Iterate over `items`:

   * Normalize into DTO.
   * Dispatch `IngestContentItemJob` per item (or process in the same job if volume is low).
4. Update `apify_webhook_logs.status = processed` or `failed`.

### 5.2. Job: `IngestContentItemJob`

Steps per item:

1. Find or create `ContentItem` by (`source_id`, `external_id`).
2. Update:

   * `url`, `title`, `summary` (maybe first N chars of `text`), `raw_content`.
   * `published_at` (if provided).
   * `first_seen_at` if new.
   * `last_seen_at = now()`.
3. Create `ContentMetric` snapshot with current `metrics`.
4. Recalculate scores:

   * Call `ContentScoringService::score($contentItem)` (see below).
5. Attach tags:

   * For each incoming `tags[]`, find or create `ContentTag`.
   * Sync to pivot (don’t detach existing tags that aren’t in payload if you want to preserve manual tags).
6. Trigger evaluation of alert rules:

   * e.g., dispatch `EvaluateAlertRulesForContentJob`.

---

## 6. Scoring Engine

### 6.1. `ContentScoringService`

Class interface:

```php
class ContentScoringService
{
    public function score(ContentItem $item): ContentItem;
}
```

Inside:

1. Load all metrics for item (or only the latest + previous one).

2. Compute normalized metrics:

   Example pseudo:

   ```php
   $likesScore = min($latest->likes / 1000, 1.0) * 30;
   $commentsScore = min($latest->comments / 100, 1.0) * 30;
   $sharesScore = min($latest->shares / 100, 1.0) * 20;
   $viewsScore = min(log10($latest->views + 1) / 6, 1.0) * 20;
   ```

   Adjust weighting per platform via `Source` metadata if necessary.

3. Compute velocity:

   * Compare last two snapshots over time (`Δmetrics / Δtime`).
   * `velocity_score` scaled 0–100.

4. `composite_score = w1 * engagement + w2 * velocity` (weights configurable per `.env`).

5. Set:

   * `$item->engagement_score = $engagementScore`
   * `$item->velocity_score = $velocityScore`
   * `$item->composite_score = $compositeScore`
   * `$item->score_version = 'v1'`

6. Save item and return.

Config:

* Use `config/content_scoring.php` for weights and thresholds.

---

## 7. Alert Engine

### 7.1. Rule Evaluation

Job: `EvaluateAlertRulesForContentJob`

Steps:

1. Load `ContentItem` + latest metrics.

2. Load active `AlertRule`s that apply:

   * `scope_type = global`
   * `scope_type = source` and `scope_id = $item->source_id`
   * `scope_type = tag` and intersect with item tags.

3. For each rule:

   * Check dedup window: query recent `alerts` for that rule and item:

     * If an alert exists in last `dedup_interval_minutes`, skip.
   * Evaluate:

     * If `min_composite_score` && `item->composite_score < threshold` → fail.
     * If `min_velocity_score` && `item->velocity_score < threshold` → fail.
     * If `min_likes` && `latestMetrics->likes < threshold` → fail.
     * etc.
   * If passes → create `Alert` with payload and dispatch `SendAlertJob`.

### 7.2. Job: `SendAlertJob`

Input:

* `alert_id`.

Steps:

1. Load `Alert` + `AlertRule`.
2. For each channel in `alert_rule.channels`:

   * `email` → send using Laravel Mail to configured addresses.
   * `slack` → POST to Slack webhook.
   * `webhook` → POST JSON to external URL(s).
3. Update:

   * `delivered_channels` (json).
   * `status` and `error_message` depending on result.

---

## 8. API Design (Backend Only)

Keep it minimal and versioned, e.g. `/api/v1`.

Use token-based auth for now.

### 8.1. Authentication

* Simple personal access token (sanctum) or static header token from `.env`.

### 8.2. Endpoints

#### Sources

* `GET /api/v1/sources`

  * Query params: `type`, `is_active`.
  * Returns paginated list.

* `POST /api/v1/sources`

  * Create or update tracking config.

* `GET /api/v1/sources/{id}`

  * Includes some stats: count of items, latest content, etc.

#### Content Items

* `GET /api/v1/content-items`

  * Filters:

    * `source_id`
    * `tag`
    * `min_score`
    * `min_velocity`
    * `date_from`, `date_to`
    * `search` (title/text LIKE)
  * Sorting:

    * by `composite_score`, `published_at`, `velocity_score`

* `GET /api/v1/content-items/{id}`

  * Details + metrics history + alerts triggered.

#### Alert Rules

* `GET /api/v1/alert-rules`
* `POST /api/v1/alert-rules`
* `PATCH /api/v1/alert-rules/{id}`
* `DELETE /api/v1/alert-rules/{id}`

#### Alerts

* `GET /api/v1/alerts`

  * Filter by rule, status, date range.

---

## 9. Scheduling & Queues

### 9.1. Queues

* Use `redis` or `database` queue.
* All heavy work:

  * `ProcessApifyPayloadJob`
  * `IngestContentItemJob`
  * `EvaluateAlertRulesForContentJob`
  * `SendAlertJob`
  * Optional `RecalculateScoresJob`

### 9.2. Scheduled Tasks (`app/Console/Kernel.php`)

* `RecalculateScoresJob` daily or hourly for items older than X but still active.
* `CleanOldApifyLogsJob` weekly (e.g., delete logs older than 90 days or archive).
* Optional: `DetectTrendingKeywordsJob` (later).

---

## 10. Config & Environment

### `.env` Keys

* `APIFY_WEBHOOK_SECRET`
* `ALERT_EMAIL_RECIPIENTS` (comma-separated)
* `SLACK_ALERT_WEBHOOK_URL` (optional)
* `CONTENT_SCORING_ENGAGEMENT_WEIGHT`
* `CONTENT_SCORING_VELOCITY_WEIGHT`

### Config Files

* `config/apify.php`

  * Default mapping of `sourceRef → source_id` or options.
* `config/alerts.php`

  * Default channel behavior.
* `config/content_scoring.php`

  * Normalization constants and weights.

---

## 11. Security & Reliability

* Webhook:

  * Verify shared secret.
  * Rate-limit endpoint.
* Queue:

  * Put ingestion and alert sending on queues with retry and backoff.
* Logging:

  * Log failed jobs with stack trace.
  * Log Apify payloads minimally (don’t store absurdly large raw HTML).
* Validation:

  * Request validation in controller (Apify payload).
  * Fallbacks for missing metrics.

---

## 12. Testing Strategy

### Unit Tests

* `ContentScoringServiceTest`
* `AlertRuleEvaluatorTest`
* `TaggingLogicTest`

### Feature Tests

* `ApifyWebhookTest`

  * Valid payload → creates items + metrics + scores.
* `AlertTriggerTest`

  * Item crosses threshold → alert created and send job dispatched.

### Integration Tests

* Simulate webhook sequence:

  * First snapshot → no alert.
  * Later snapshot with spike → alert.

---

## 13. Roadmap / Extensions

Later you can add:

* Multi-tenant: add `tenant_id` to `sources`, `content_items`, `alert_rules`, etc.
* AI-based tag extraction with OpenAI / local models.
* Full-text search via Meilisearch / Elasticsearch.
* Trend detection across tags / keywords (e.g., “topic velocity”).
* Frontend dashboard (Vue/Inertia/React).