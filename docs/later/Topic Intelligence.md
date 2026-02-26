Supreme leader,

# Engineering Spec: Topic Intelligence Module (built on Social Watcher)

## 0. Summaryaza

Add a **Topic Intelligence** subsystem that consumes `sw_normalized_content` (Social Watcher) and produces **Topic Briefs** and **Signals** that drive content ideation and planning. It extracts **topics, problems, angles, and hooks** from scraped competitor profiles, groups, and discussion sources (optionally Reddit) without treating scraped text as canonical knowledge.

This module outputs:

* A ranked list of **Topics** (clusters)
* **Problem statements** (pain + questions)
* **Angle maps** (complaint/contrarian/tutorial/etc.)
* **Format/hook patterns**
* A final artifact: **TopicBrief** (ready for your content generator + your own knowledge ingestion)

---

## 1. Goals

1. Convert Social Watcher raw/normalized items into **durable, aggregated signals**.
2. Provide a **repeatable pipeline** (batch + incremental) with clear artifacts and traceability.
3. Produce a **TopicBrief** object your production generator can consume as “ideation context”.
4. Keep strict separation:

   * Social Watcher content = **signal**
   * Your Knowledge ingestion = **truth**
   * Generator = **synthesis**

## 2. Non-goals

* Not generating final posts automatically (that’s ContentGeneratorService’s job).
* Not storing competitor content as “business facts” or authoritative knowledge.
* Not building a full analytics BI suite.
* Not guaranteeing platform compliance/ToS enforcement (but we’ll design to minimize risk).

---

## 3. Architecture Overview

### 3.1 High-level data flow

```
Apify → Social Watcher (raw) → sw_normalized_content
  ↓
Topic Intelligence Ingestion (TI pipeline)
  ↓
Signals + Clusters + Briefs (TI tables)
  ↓
Content Planning UI + Generator integration
```

### 3.2 Pipelines (core)

You’ll implement 4 pipelines first (covers 80% value):

1. **Topic Demand Discovery**

* Cluster posts by semantic similarity and rank topics.

2. **Problem Mining**

* Extract explicit questions/pains from posts + comments and aggregate.

3. **Angle & Framing**

* Classify each item into angle types and compute “dominant vs underserved”.

4. **Format & Hook Patterns**

* Identify post structures and recurring hook patterns.

Later pipelines (phase 2):

* Cross-platform validation
* Belief/narrative mapping
* Trend detection and alerts into Slack/webhooks (can reuse Social Watcher alerts infra)

---

## 4. Data Model

Add a new package or module namespace: `App/Services/TopicIntelligence/*` (or `packages/topic-intelligence`).

### 4.1 New tables (prefix `ti_`)

**ti_runs**

* `id` (uuid)
* `org_id`, `user_id`
* `started_at`, `finished_at`, `status`
* `config_snapshot` (json)
* `source_scope` (json) — filters used (platforms, sources, date window)
* `stats` (json) — counts, timings

**ti_items** (projection of `sw_normalized_content` into TI space)

* `id` (uuid)
* `org_id`, `user_id`
* `normalized_content_id` (fk to `sw_normalized_content.id`)
* `platform`, `source_id`, `author_username`, `published_at`
* `text_hash` (sha256)
* `text` (optional; can reference SW table instead, but storing a trimmed version is useful)
* `metrics` (json) — likes/comments/shares/views/velocity/engagement
* `features` (json) — extracted features (hook type, angle, problem flags, etc.)
* Index: `(org_id, published_at)`, `(org_id, platform)`, `(org_id, source_id)`

**ti_embeddings**

* `ti_item_id` (fk)
* `model` (string)
* `embedding_vec` (pgvector)
* `variant` (string) — e.g. `post_text`, `comment_text` later
* Unique: `(ti_item_id, model, variant)`

**ti_clusters**

* `id` (uuid)
* `org_id`, `user_id`
* `label` (string, nullable) — generated name
* `summary` (text, nullable)
* `centroid_vec` (pgvector, nullable)
* `cluster_version` (string)
* `topic_score` (float)
* `platform_distribution` (json)
* `time_window` (daterange or json {start,end})
* `created_at`, `updated_at`

**ti_cluster_items**

* `cluster_id`
* `ti_item_id`
* `distance` (float)
* `rank_in_cluster` (int)
* Primary: `(cluster_id, ti_item_id)`

**ti_problem_statements**

* `id` (uuid)
* `org_id`, `user_id`
* `cluster_id` (nullable)
* `text` (string) — normalized problem statement
* `frequency` (int)
* `score` (float)
* `examples` (json) — list of ti_item_id + short excerpts
* `created_at`, `updated_at`

**ti_briefs**

* `id` (uuid)
* `org_id`, `user_id`
* `cluster_id`
* `title` (string)
* `brief` (json) — full artifact (see §7)
* `status` (enum: draft/approved/archived)
* `created_at`, `updated_at`

**ti_brief_exports**

* `id` (uuid)
* `brief_id`
* `export_type` (enum: `knowledge_item`, `markdown`, `webhook`)
* `export_payload` (json)
* `created_at`

---

## 5. Core Algorithms (practical, v1)

### 5.1 Item selection (scope)

Source scope is essential to avoid noise.

* Inputs: `org_id`, optional `source_ids`, `platforms`, `date_range`, `min_views`, `min_engagement_score`, `media_type`
* Default: last 30 days, order by `engagement_score desc`, cap to N (e.g. 5k items)

### 5.2 Embeddings

Reuse your existing `EmbeddingsService::embedOne(...)`, with a new embedding type:

* model: `text-embedding-3-small` (consistent with your system)
* vector stored in `ti_embeddings`

Text to embed:

* `normalized_content.text` (trim to max tokens; store trimmed version into `ti_items.text`)
* Optional: append lightweight context: platform + author (not required)

### 5.3 Clustering (Topic Demand)

V1 recommendation: **two-stage clustering** (fast and stable).

**Stage A: Candidate grouping**

* Use pgvector ANN to find nearest neighbors for each item within a distance ceiling.
* Build clusters via greedy union-find:

  * For each item, find top K neighbors (K=10..20)
  * If distance < `cluster_distance_threshold` (e.g. 0.18–0.25), union
* This gives robust clusters without heavy ML infra.

**Stage B: Cluster refinement**

* Compute centroid as average vector of cluster items (optional)
* Rank items by distance to centroid

### 5.4 Topic scoring (cluster ranking)

Compute a topic_score that favors:

* Many items (not one viral spike)
* High engagement density
* Persistence over time (appearing across days)
* Cross-platform presence (optional weight)

Example:

```
topic_score =
  w1 * log(1 + item_count)
+ w2 * log(1 + total_engagement)
+ w3 * log(1 + total_velocity)
+ w4 * platform_entropy
+ w5 * persistence_days
```

Where:

* total_engagement = sum(engagement_score)
* platform_entropy favors multi-platform topics
* persistence_days = number of distinct days represented

### 5.5 Angle classification (per item)

Start with rules + light LLM classification (optional flag).

**Rule features:**

* Question mark + interrogatives → `question`
* “Hot take”, “unpopular opinion”, “controversial” → `contrarian`
* “How to”, “step-by-step”, numbered lists → `tutorial`
* “I tried”, “I learned”, “mistake” → `case_study`
* Negative sentiment terms → `complaint`

Store as `ti_items.features.angle = ...` and `angle_confidence`.

### 5.6 Problem mining

Extract candidate problem statements from item text using patterns:

* “How do I …”
* “Anyone else …”
* “Why is …”
* “I can’t …”
* “Struggling with …”

Normalize problems:

* remove platform slang
* normalize entities (optional)
* store canonical `ti_problem_statements.text`

Score problems:

* frequency in scope
* comment density proxy (if you ingest comment items later)
* engagement-weighted frequency

### 5.7 Hook/format patterns

Compute:

* `hook_type` (hot-take, story, question, stat, list)
* `structure` (list, narrative, hybrid)
* `cta_type` (question, ask, none)

This is deterministic heuristics v1; later add LLM for better labels.

---

## 6. Jobs and Commands

### 6.1 Jobs

**`BuildTopicIntelligenceRunJob`**

* Creates `ti_runs`
* Calls sub-jobs per phase
* Accepts scope + config snapshot

**`SyncTopicIntelligenceItemsJob`**

* Pulls from `sw_normalized_content` into `ti_items` for given scope
* Dedup by `(normalized_content_id)` or `text_hash`

**`EmbedTopicIntelligenceItemsJob`**

* Finds `ti_items` without embeddings and embeds in batches

**`ClusterTopicIntelligenceItemsJob`**

* Builds `ti_clusters` + `ti_cluster_items`
* Computes scores + distributions

**`ExtractAnglesAndHooksJob`**

* Populates `ti_items.features.*`

**`ExtractProblemStatementsJob`**

* Writes `ti_problem_statements` with examples

**`BuildTopicBriefsJob`**

* Creates `ti_briefs` for top clusters
* Generates label/summary (LLM optional)

### 6.2 Commands

* `php artisan ti:run --org=... --user=... --days=30 --platforms=x,linkedin --sources=...`
* `php artisan ti:backfill --org=... --from=2025-01-01 --to=...`

---

## 7. TopicBrief Artifact Format (the output contract)

Store in `ti_briefs.brief` as JSON:

```json
{
  "topic": {
    "title": "AI SEO tools increase sameness",
    "summary": "Audience is frustrated that AI-generated content feels generic and underperforms; demand is persistent across platforms.",
    "topic_score": 12.34,
    "time_window": {"start":"2025-12-05","end":"2026-01-04"},
    "platform_distribution": {"x": 0.55, "linkedin": 0.35, "youtube": 0.10}
  },
  "signals": {
    "item_count": 48,
    "persistence_days": 16,
    "engagement_total": 12345,
    "velocity_total": 4567,
    "comment_density_hint": 0.72
  },
  "dominant_angles": [
    {"angle":"complaint","share":0.52},
    {"angle":"contrarian","share":0.31},
    {"angle":"tutorial","share":0.10}
  ],
  "underserved_angles": [
    {"angle":"systems_explanation","reason":"rare but high engagement"}
  ],
  "top_problems": [
    {"text":"My AI content ranks then dies","frequency":9,"score":4.2},
    {"text":"SEO tools all give the same advice","frequency":6,"score":3.1}
  ],
  "hook_patterns": [
    {"hook":"Hot take:", "count":12},
    {"hook":"I was wrong about...", "count":7}
  ],
  "examples": {
    "top_items": [
      {"ti_item_id":"...","platform":"x","url":"...","excerpt":"...","metrics":{"likes":...}}
    ]
  },
  "recommended_direction": {
    "positioning": "Explain sameness as retrieval/intent collapse, not writing quality",
    "do_not_do": ["paraphrase competitor claims", "reuse exact hooks verbatim"],
    "questions_to_answer": [
      "Why does sameness get suppressed?",
      "How do you systematize originality?"
    ]
  }
}
```

---

## 8. APIs (read + workflow)

Under `api/topic-intelligence`:

* `GET /runs`
* `POST /runs` (start run with scope)
* `GET /clusters?order_by=topic_score`
* `GET /clusters/{id}` (includes top items + problems + angles)
* `GET /briefs?status=draft`
* `POST /briefs/{id}/approve`
* `POST /briefs/{id}/export` (to KnowledgeItem or markdown)

---

## 9. UI (minimal but usable)

Add a module page:

### 9.1 Topic Dashboard

* Filters: platform, sources, date window, min score
* Table: topic title, score, item_count, persistence, platforms
* Click → Topic detail

### 9.2 Topic Detail

* Summary + key signals
* Angle distribution chart
* Top problems
* Top examples (links out)
* “Create brief” / “Approve brief” / “Export to generator”

### 9.3 Brief Editor

* Edit recommended direction / questions
* Approve
* Export

---

## 10. Integration with your existing system

### 10.1 Export to KnowledgeItem (optional)

When exporting a brief, create a `KnowledgeItem` of type `note` with:

* `raw_text`: markdown rendering of brief
* `source`: `topic_intelligence`
* chunks will be generated/embedded via existing ingestion pipeline

This lets your production generator retrieve briefs as internal “planning knowledge”.

### 10.2 Generator usage pattern

When user prompt is “Write LinkedIn post about X”:

* Step 0: retrieve 1–3 relevant **TopicBrief KnowledgeItems** (or direct TI query)
* Step 1: retrieve trusted knowledge items (your actual ingestion system)
* Step 2: generate post

---

## 11. Alerts (optional, reusing Social Watcher infra)

Trigger alerts when:

* New cluster crosses `topic_score` threshold
* Velocity spike within a cluster
* New problem statement emerges above frequency threshold

Deliver via existing Slack/email/webhook channels.

---

## 12. Operational Concerns

### 12.1 Incremental updates

* Nightly run: last 7–30 days
* Fast refresh: last 24 hours
* Store `ti_runs` for traceability

### 12.2 Idempotency

* `ti_items` keyed by `normalized_content_id`
* Embeddings keyed by `(ti_item_id, model, variant)`
* Clusters rebuilt per run version (don’t mutate historical runs unless explicitly “latest” mode)

### 12.3 Performance

* Cap item counts per run
* Use pgvector ANN for neighbor lookup
* Batch embedding calls
* Avoid storing huge raw text blobs; store excerpt + reference ids

---

## 13. Risks and guardrails

### 13.1 Derivative content risk

Guardrails:

* Do not export raw scraped text into KnowledgeItem by default.
* Store only excerpts + links in examples.
* Briefs should summarize patterns, not rewrite content.

### 13.2 Source legality/compliance

Engineering approach:

* Store references (URLs, ids) + excerpts instead of full copies where possible.
* Keep platform adapters isolated (Apify already does fetch; you store normalized projections).
* Provide a per-source retention policy (`retain_days`).

---

## 14. Milestones

### Phase 1 (MVP: 1–2 weeks equivalent work)

* Tables: `ti_runs`, `ti_items`, `ti_embeddings`, `ti_clusters`, `ti_cluster_items`, `ti_briefs`
* Jobs: sync → embed → cluster → build briefs
* API: list clusters, detail, list briefs
* Basic UI: dashboard + detail + brief export
* Export brief → KnowledgeItem

### Phase 2 (Signal richness)

* `ti_problem_statements`
* Angle classification + hook detection
* Cross-platform validation metric
* Alerts for spikes/new topics

### Phase 3 (Quality + eval)

* Add an eval harness similar to ingestion eval:

  * cluster stability across runs
  * topic precision checks on curated fixtures
  * regression detection for scoring

---

## 15. Acceptance Criteria

1. Running `ti:run` produces clusters with non-zero scores and stable membership.
2. Each cluster has:

   * top items list
   * platform distribution
   * computed persistence days
3. `ti_briefs` created for top N clusters.
4. Exported brief becomes a `KnowledgeItem` and is retrievable by your existing `Retriever`.
5. A user can open dashboard → cluster → brief → export without manual DB work.

---

## 16. Concrete “floor diagram” for the MVP run

```
[Apify] 
  → [Social Watcher: sw_content_items + sw_normalized_content]
      → [TI Sync: ti_items]
          → [TI Embed: ti_embeddings]
              → [TI Cluster: ti_clusters + ti_cluster_items]
                  → [TI Brief Builder: ti_briefs]
                      → [Export: KnowledgeItem note]
                          → [Your ingestion pipeline]
                              → [Retriever + Generator]
```

---

If you want this implemented cleanly, the next most important decision is: **do you want TI to be a separate package (like social-watcher), or inside the main app?** The spec works either way, but packaging determines boundaries and migration management.
