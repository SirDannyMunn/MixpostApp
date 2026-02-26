Below is a **clean, implementation-ready engineering spec** you can hand directly to a backend developer.
It is opinionated, cost-aware, and aligned with your existing **Social Watcher** architecture.

---

# Engineering Spec: Source Ingestion Strategy & Scheduler

## Objective

Implement a **controlled, predictable ingestion system** for Social Watcher that:

* Pulls competitor data from Apify
* Avoids user-triggered cost explosions
* Ensures fresh data via scheduled jobs
* Provides immediate value on source creation
* Scales safely across tenants and platforms

---

## Core Principles

1. **Users declare intent, not execution**
2. **All ingestion is centrally controlled**
3. **Costs are capped and predictable**
4. **No user-triggered fetches in v1**
5. **All ingestion is observable and auditable**

---

## High-Level Flow

```
User adds source
  ↓
Create sw_sources record
  ↓
Bootstrap ingest (one-time, capped)
  ↓
Scheduled cron ingestion (daily/hourly)
  ↓
Scoring → Alerts → Normalization
```

---

## Data Model Changes

### `sw_sources` (additions)

Add the following fields:

| Field                 | Type               | Purpose                                 |
| --------------------- | ------------------ | --------------------------------------- |
| `last_fetched_at`     | timestamp nullable | Last ingestion attempt                  |
| `last_success_at`     | timestamp nullable | Last successful ingestion               |
| `failure_count`       | int default 0      | Consecutive failures                    |
| `paused_at`           | timestamp nullable | Auto/manual pause                       |
| `ingestion_profile`   | string             | Apify profile name (e.g. `x_profile`)   |
| `ingestion_overrides` | json nullable      | Profile overrides (limits, identifiers) |

---

## Source Creation Behavior

### API / UI Behavior

When a user adds a source:

* Validate platform + identifier
* Persist source configuration only
* Trigger **exactly one** bootstrap ingestion

### Bootstrap Ingestion (Mandatory)

#### Purpose

* Give immediate data
* Avoid waiting for cron
* Strictly capped cost

#### Implementation

Dispatch an Apify Actor Runner job with **hard limits**.

Example:

```php
ApifyFetchJob::dispatch(
  profile: $source->ingestion_profile,
  overrides: array_merge(
    $source->ingestion_overrides ?? [],
    ['max_items' => 20]
  )
);
```

#### Constraints

* `max_items` enforced at profile level
* No retries beyond standard queue retry
* Failure does **not** block source creation

### Updated Source Creation Behavior

When a user adds a source via the API:

1. The source configuration is validated and persisted in the `sw_sources` table.
2. If the source is active (`is_active = true`) and has a `platform_identifier`, the system automatically dispatches an `ApifyFetchJob` to fetch content from Apify.
   - The job uses the source's `ingestion_profile` and applies any overrides (e.g., `platform_identifier` as `username`).
   - The job is dispatched to the default queue.

#### Example Code

```php
ApifyFetchJob::dispatch(
  profile: $source->ingestion_profile,
  overrides: array_filter([
    'username' => $source->platform_identifier,
  ])
)->onQueue('default');
```

#### Notes

- This ensures immediate data ingestion without waiting for scheduled jobs.
- The job respects the configured limits and retries as per the queue settings.
- Failure to dispatch the job does not block source creation; errors are logged for debugging.

---

## Scheduled Ingestion System (Primary Mechanism)

### Cron Entry

```bash
php artisan social-watcher:run-scheduled
```

Frequency:

* Daily by default
* Can be platform-specific later (e.g. X = hourly)

---

## Scheduled Ingestion Command

### Command

`social-watcher:run-scheduled`

### Responsibilities

1. Load all active, non-paused sources
2. Apply budget and rate limits
3. Dispatch ingestion jobs per source
4. Record execution metadata

---

## Source Selection Rules

A source is eligible if:

* `paused_at IS NULL`
* `failure_count < MAX_FAILURES`
* `last_fetched_at` older than configured interval

---

## Job Dispatch Strategy

### One Job = One Source

Do **not** batch multiple sources into one Apify run in v1.

```php
foreach ($sources as $source) {
    ApifyFetchJob::dispatch(
        $source->ingestion_profile,
        $this->buildOverrides($source)
    );
}
```

---

## Delta / Incremental Fetching

### Required Overrides

Each scheduled fetch must include:

* `since_id` **or**
* `since_timestamp`

Derived from:

* Most recent `external_id`
* Or most recent `published_at`

Example:

```php
[
  'username' => $source->platform_identifier,
  'since_timestamp' => $source->last_success_at,
  'max_items' => 10,
]
```

---

## Cost & Budget Enforcement

### Global Limits (Config)

```php
'ingestion' => [
  'daily_max_runs' => 500,
  'per_source_max_items' => 10,
  'max_failures' => 3,
]
```

### Enforcement Rules

* Abort scheduling if global max reached
* Skip source if it would exceed per-source cap
* Log skipped reasons explicitly

---

## Failure Handling

### On Job Failure

* Increment `failure_count`
* Leave `last_success_at` unchanged
* Log platform + source + error

### Auto-Pause Rule

If `failure_count >= max_failures`:

* Set `paused_at`
* Emit internal alert (Slack / logs)

---

## Success Handling

On successful ingest:

* Update `last_fetched_at`
* Update `last_success_at`
* Reset `failure_count` to 0

---

## Manual Refresh (Explicitly Excluded)

### v1 Decision

* **No manual refresh endpoint**
* **No UI trigger**
* **No public API**

Reason:

* Unbounded cost
* Abuse risk
* Predictability loss

### Future (Optional, Paid)

Manual refresh may be added later as:

* Credit-based
* Rate-limited
* Tier-gated

Not part of this spec.

---

## Observability & Logging

### Required Logs

Every ingestion attempt must log:

* source_id
* platform
* profile
* overrides
* item_count
* duration
* cost estimate (if available)

### Metrics (Optional but Recommended)

* Items ingested per day
* Failed jobs per platform
* Cost per tenant

---

## Security Considerations

* No ingestion endpoints exposed to users
* All execution server-side
* Apify token never leaves backend
* Overrides validated against profile schema

---

## Acceptance Criteria

* ✅ User sees data immediately after adding a source
* ✅ Ingestion runs automatically without user action
* ✅ Costs are capped and predictable
* ✅ Failed sources auto-pause
* ✅ No user-triggered fetch exists
* ✅ Scheduler is the single execution choke point

---

## Summary

This design:

* Matches your current Social Watcher architecture
* Prevents runaway costs
* Keeps ingestion deterministic
* Leaves room for monetization later
* Is production-safe by default

