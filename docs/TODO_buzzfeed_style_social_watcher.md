Supreme leader, here’s a **clear, implementation-ready Backend Requirements Specification** for building a BuzzSumo-style intelligence engine in **Laravel**, assuming **Apify handles scraping + data ingestion** and Laravel is the intelligence + storage + API brain.

This is **backend only**. No UI. No fluff. Built for a real system.

---

# **Project: Competitive Content Intelligence Backend**

Backend Requirements Specification
Framework: **Laravel 11+**
Purpose: Central intelligence engine to ingest scraped content signals, score performance, detect trends, generate alerts, and expose data via API.

---

# **1️⃣ Core Objectives**

Backend must support:

1. Receive scraped content from Apify
2. Store normalized content data + engagement metrics
3. Track engagement changes over time
4. Compute performance score + velocity score
5. Detect best performing content per:

   * platform
   * source
   * topic
   * timeframe
6. Generate alerts for spikes
7. Provide REST API for:

   * querying content
   * analytics
   * competitor feeds
8. Support scheduled processing + recalculations

---

# **2️⃣ System Architecture Overview**

### **External**

* Apify crawlers extract data
* Apify sends results → Laravel via Webhook (JSON payloads)

### **Backend (Laravel)**

Modules required:

1. Webhook ingestion module
2. Data normalization pipeline
3. Content storage + indexing
4. Metrics + historical snapshots
5. Scoring engine
6. Alerts engine
7. Analytics engine
8. REST API layer
9. Background jobs + scheduling
10. Authentication + rate limiting

Database: MySQL or Postgres
Queue: Redis recommended

---

# **3️⃣ Supported Platforms (Initial Scope)**

Backend must be **platform-agnostic**, but MVP supports:

* YouTube
* TikTok
* Blogs (RSS or direct scrape)
* Reddit threads
* Twitter/X
* Facebook/IG optional

Each platform can be on/off dynamically.

---

# **4️⃣ Data Model Requirements**

## **4.1 Entities**

### **Competitors**

Represents tracked domains/accounts.

```
competitors
- id
- name
- type (brand | creator | publication)
- source_type (youtube | twitter | blog | reddit | generic)
- handle / identifier
- active (bool)
- created_at
- updated_at
```

---

### **Content Items**

```
content_items
- id
- competitor_id (nullable)
- platform
- url (unique)
- title
- description (nullable)
- published_at
- author
- language
- raw_json (full payload store)
- created_at
- updated_at
```

---

### **Engagement Metrics (time based)**

Each check stores metrics snapshot.

```
content_metrics
- id
- content_item_id
- likes
- comments
- shares
- views
- saves
- engagement_rate
- collected_at (timestamp Apify scraped)
```

---

### **Computed Scores**

```
content_scores
- id
- content_item_id
- performance_score
- velocity_score
- evergreen_score
- trend_direction (up | down | stable)
- computed_at
```

---

### **Alerts**

```
alerts
- id
- content_item_id
- type (spike | viral | breakout | competitor_milestone)
- message
- severity (low | medium | high)
- triggered_at
- resolved_at (nullable)
```

---

---

# **5️⃣ Webhook Ingestion Requirements**

### Endpoint

```
POST /webhooks/apify/content
```

### Auth

* API token header required
* Reject if missing
* Log every hit

### Payload Example

```
{
  "source": "youtube",
  "competitor": "MrBeast",
  "url": "...",
  "title": "...",
  "metrics": {
      "views": 120393,
      "likes": 2302,
      "comments": 392,
      "shares": 120
  },
  "timestamp": "2025-01-02T10:03:00Z"
}
```

### Required Behavior

1️⃣ Validate
2️⃣ Store / update content item
3️⃣ Store metrics snapshot
4️⃣ Dispatch scoring job

Must handle **deduping by URL**.

If content already exists:

* update metrics
* recalculate scores
* detect spike

Must be resilient to:

* retries
* missing fields
* malformed json

Log all failures.

---

# **6️⃣ Scoring Engine Requirements**

Must compute:

### **Performance Score**

Weighting idea:

```
performance = weighted(views, likes, comments, shares)
```

Weights configurable in DB or config.

---

### **Velocity Score**

How fast engagement grows.
Requires comparing last snapshot to previous.

```
velocity = (current_total - previous_total) / hours_between
```

---

### **Evergreen Score**

Measures sustained relevance:

* average engagement after 7+ days

---

### **Trend Direction**

Compare rolling averages:

* Up
* Down
* Stable

Must store in `content_scores`.

---

# **7️⃣ Alert Engine Requirements**

### Trigger Rules

* Large engagement spike
* High velocity score
* Top X percentile in niche
* New competitor hit

Alerts push to:

* DB record
* Webhook out (future)
* Email support (optional)
* Slack webhook (optional)

Thresholds configurable.

---

# **8️⃣ Analytics Engine Requirements**

Provide aggregated insights:

### Queries backend must support

* top content last 24h
* top content last 7d
* top per competitor
* fastest velocity posts
* trending topics (keyword frequency)
* content decay tracking
* competitor comparison

These are backend only,
UI is a future concern.

---

# **9️⃣ API Requirements**

### Authentication

* Token based
* Laravel Sanctum or Passport

---

### Endpoints

#### Get competitors

```
GET /api/competitors
```

#### Get content

```
GET /api/content?platform=youtube&sort=performance&limit=50
```

#### Get best content

```
GET /api/content/top
```

#### Get trending

```
GET /api/content/trending
```

#### Get alerts

```
GET /api/alerts
```

Pagination required.

---

# **🔟 Scheduling Requirements**

Cron jobs:

```
php artisan schedule:run
```

Required scheduled tasks:

* recompute stale scores daily
* cleanup old raw logs
* archive old metrics older than X
* anomaly detection pass hourly

---

# **1️⃣1️⃣ Reliability Requirements**

### Error Handling

* Apify webhook failures logged
* Failed jobs retry
* Soft deletes on records
* Rate limit webhook endpoint
* Database indexes on:

  * url
  * platform
  * published_at
  * competitor_id

---

# **1️⃣2️⃣ Dev / Ops Requirements**

### Environment

* Laravel 11+
* PHP 8.3+
* Redis
* MySQL / Postgres

### Logging

* Laravel log
* Separate ingestion log channel recommended

### Environments

* local
* staging
* production

---

# **1️⃣3️⃣ MVP Phase Priorities**

### Phase 1 (2–3 weeks)

* Webhook ingestion
* Basic models
* Metrics tracking
* Scoring engine v1
* Basic alerts
* Top content endpoints

### Phase 2

* Trend direction
* Evergreen analysis
* Keyword topic clustering
* More analytics

---

# **Final Reality Check**

This backend is:

* lean
* powerful
* scalable
* realistic

BuzzSumo-like intelligence without BuzzSumo baggage.

---

If you want next:

* database schema SQL
* Laravel folder structure
* code architecture (services, actions, jobs)
* API documentation format
* ER diagram
* task breakdown + sprint plan
