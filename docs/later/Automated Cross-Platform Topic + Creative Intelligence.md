## Engineering Spec: Automated Cross-Platform Topic + Creative Intelligence → Content Briefs (X, YouTube, TikTok)

### 0) Context (what already exists)

You already have:

* **Raw-first ingestion** + canonical normalization into `sw_normalized_content` (cross-platform) 
* Comment fetching + classification/sentiment/signal scoring + Topic Intelligence pipeline ending in **TopicBrief v2**  
* API surfaces for clusters, conversations, briefs 
* Scheduler patterns for daily comment fetch + periodic topic runs 
  Known gap: **real embeddings + clustering** (currently placeholder) 

This spec adds: (1) real embedding/clustering, (2) a **Creative Intelligence** layer (hooks/formats/angles/offers), and (3) **Content Brief generation** that merges Demand (TopicBrief) + Packaging (Creative patterns).

---

## 1) Goals

1. **Automated collection** from X search queries + YouTube + TikTok, on a schedule.
2. Extract and aggregate:

   * repeating **hooks**
   * common **post/video formats**
   * **content angles** and claims
   * **offers / CTAs** being pushed
3. Produce **actionable Content Briefs**:

   * “Write a post about X”
   * recommended format(s), hook options, key points, objections, proof suggestions
   * CTA left as a user-editable slot
4. System runs incrementally; avoids reprocessing everything; outputs are queryable via API.

---

## 2) Non-Goals (explicit)

* No auto-posting, no outreach/DM features, no CRM. (Aligns with existing Topic Intelligence stance.) 
* No “full BI dashboard”; focus is research + briefs.
* No exporting raw comments as “knowledge.” 

---

## 3) High-Level Architecture

### 3.1 Data flow

**A. Content Ingest (posts/videos)**

1. Apify actors push/pull → `sw_content_items` (raw) → normalize → `sw_normalized_content`  
2. Select “eligible” items for:

* Comment fetch (demand extraction)
* Creative extraction (packaging extraction)

**B. Comment/Demand (existing)**

* Fetch comments → classify/sentiment/signal → `sw_ti_items` → cluster → mine problems → TopicBrief v2  

**C. Creative Intelligence (new)**

* From high-performing normalized content (X posts, YouTube transcripts, TikTok captions/transcripts):

  * extract CreativeUnit features (hook/format/angle/offer/CTA/value promises/proof)
  * embed hook text + angle/value text
  * cluster hooks and angles by semantic similarity
  * aggregate “what repeats” with engagement weighting

**D. Brief Generator (new)**

* Merge:

  * TopicBrief v2 (demand + language) 
  * Creative aggregates (packaging winners)
* Output: ContentBrief objects via API + UI export

---

## 4) Key Enhancements Required

### 4.1 Real embeddings + clustering for Topic Intelligence

Replace placeholder clustering (keyword grouping, distance=0.0) with embedding-based clustering. 

**Requirements**

* Embedding service integration (OpenAI/Cohere/Sentence Transformers) 
* Cosine similarity distance calculations
* Clustering algorithm: DBSCAN or HDBSCAN preferred (unknown K; noisy text)
* Store embedding vectors (pgvector recommended) OR store externally in a vector DB

**Acceptance criteria**

* Clusters form without keyword dependence
* Similar comments/posts co-locate across platforms
* Distance values are non-zero, meaningful, persisted

### 4.2 Creative Intelligence layer

A new pipeline that operates on **posts/videos**, not comments.

---

## 5) Data Model Changes

### 5.1 New tables (proposed)

1. `sw_creative_units`

* `id` (uuid)
* `normalized_content_id` (uuid, FK to `sw_normalized_content`)
* `platform` (string)
* `author_username`
* `published_at`
* `raw_text` (longtext) — post text or transcript slice
* `hook_text` (text)
* `format_type` (enum)
* `angle` (text)
* `value_promises` (json array of strings)
* `proof_elements` (json array)
* `offer` (json: type, name, url?, price_hint?)
* `cta` (json: type, text, trigger_keyword?)
* `language` (string, default en)
* `confidence` (float)
* `created_at`, `updated_at`

2. `sw_embeddings`

* `id` (uuid)
* `object_type` (enum: `ti_item`, `creative_hook`, `creative_angle`, `creative_unit`)
* `object_id` (uuid)
* `model` (string)
* `embedding` (vector/pgvector or json array)
* `created_at`

3. `sw_creative_clusters`

* `id` (uuid)
* `cluster_type` (enum: `hook`, `angle`, `offer_pitch`)
* `label` (text)
* `platform_entropy` (float)
* `item_count` (int)
* `engagement_total` (float)
* `first_seen_at`, `last_seen_at`
* `created_at`, `updated_at`

4. `sw_creative_cluster_items`

* `id` (uuid)
* `creative_cluster_id` (uuid, FK)
* `creative_unit_id` (uuid, FK)
* `distance` (float)
* `created_at`

5. `sw_content_briefs`

* `id` (uuid)
* `cluster_id` (uuid, FK to `sw_ti_clusters`) OR nullable (support briefs without TI)
* `topic_brief_id` (uuid, FK to `sw_ti_topic_briefs`)
* `title_options` (json array)
* `recommended_formats` (json array)
* `hook_options` (json array)
* `key_points` (json array)
* `objections_to_address` (json array)
* `proof_suggestions` (json array)
* `cta_placeholder` (text)
* `sources` (json: normalized_content_ids, creative_cluster_ids)
* `priority_score` (float)
* `status` (enum: draft/approved/archived)
* `generated_at`, timestamps

### 5.2 Minimal changes to existing tables

* Add `sw_normalized_content.processing_flags` (json) OR separate table for pipeline state:

  * `creative_extracted_at`
  * `comments_fetched_at`
  * `last_scored_at`

---

## 6) Pipeline Stages & Jobs

### 6.1 Ingest / Normalize (existing)

* Apify webhook/pull inserts raw + metrics; normalization job produces `sw_normalized_content`.  

### 6.2 Candidate selection (new)

A job that selects top items per platform/query window for:

* comment fetching (demand)
* creative extraction (packaging)

**Selection heuristics (MVP)**

* `engagement_score` desc, with recency boost (e.g., last 72h)
* per-platform caps (avoid X flooding everything)
* optional allowlist creators / keyword queries

### 6.3 Comment pipeline (existing)

* `social-watcher:fetch-comments` + processing jobs 

### 6.4 Topic Intelligence run (existing, upgraded with embeddings)

* `social-watcher:run-topic-intelligence` stages: clustering → problems → briefs 

### 6.5 CreativeUnit extraction (new)

**Jobs**

* `ExtractCreativeUnit(normalized_content_id)`

  * Parse raw_text:

    * X: post text
    * YouTube: transcript text (already supported in normalized content) 
    * TikTok: caption + transcript (requires normalizer support; see §9)
  * Deterministic pass: infer hook candidate + format signals
  * LLM pass: output strict JSON matching `sw_creative_units`
  * Validate + store with confidence score

* `EmbedCreativeUnitFeatures(creative_unit_id)`

  * Embed `hook_text`
  * Embed `angle` (or normalized “angle+value” string)
  * Store embeddings

### 6.6 Creative clustering + aggregation (new)

* `ClusterCreativeFeatures(cluster_type, window)`

  * Pull embeddings for cluster_type
  * Run DBSCAN/HDBSCAN
  * Store `sw_creative_clusters` and membership with distance
  * Compute:

    * platform_entropy
    * engagement_total (sum of member normalized_content engagement)
    * “repeat rate” (items/day)

### 6.7 Brief generation (new)

* `GenerateContentBrief(topic_brief_id)`

  * Inputs:

    * TopicBrief v2 (problems/questions/quotes/keywords) 
    * Top Creative clusters matching keywords/embedding similarity
  * Output `sw_content_briefs`:

    * hook_options from top hook clusters
    * recommended_formats from top format counts
    * key_points from TopicBrief problems + questions
    * objections from TopicBrief (objection/problem statements)
    * proof_suggestions from observed proof elements + “what’s credible” patterns
    * CTA placeholder as blank template

---

## 7) LLM Interfaces (prompts/specs)

### 7.1 CreativeUnit extraction schema (hard requirement)

LLM must return JSON:

* `hook_text` (string)
* `format_type` (enum)
* `angle` (string)
* `value_promises` (array<string>, 1–7)
* `proof_elements` (array<object>)
* `offer` (object|null)
* `cta` (object|null)
* `confidence` (0–1)

**Validation rules**

* If `confidence < 0.6`, store unit but mark `needs_review=true`
* Empty/boilerplate outputs are rejected + retried once with stricter instruction
* PII stripping: do not store emails/phone numbers if present in text

### 7.2 Brief generator schema (hard requirement)

JSON:

* `title_options` (3–7)
* `recommended_formats` (1–3)
* `hook_options` (5–15)
* `key_points` (5–10)
* `objections_to_address` (3–7)
* `proof_suggestions` (3–7)
* `cta_placeholder` (string)
* `priority_score_adjustment` (float, optional)

---

## 8) APIs (new endpoints)

Keep existing Topic Intelligence endpoints as-is. 

### 8.1 Creative Intelligence API

* `GET /creative-units`

  * filters: platform, author, date range, format_type, min_confidence
* `GET /creative-units/{id}`
* `GET /creative-clusters`

  * filters: cluster_type, min_item_count, active_days, platform
* `GET /creative-clusters/{id}`

  * returns label, stats, member examples, top hooks/angles
* `GET /creative-clusters/{id}/examples`

  * returns top N member posts/videos (urls + extracted fields)

### 8.2 Content Brief API

* `GET /content-briefs`

  * filters: status, min_priority, cluster_id, platform
* `GET /content-briefs/{id}`
* `POST /content-briefs/{id}/regenerate`
* `POST /content-briefs/{id}/approve`
* `POST /content-briefs/{id}/archive`

---

## 9) Platform Coverage Details

### 9.1 X (search results)

* Use Apify `x_search` profile to pull by query. (Already supported as bundled profile.) 
* Normalize into `sw_normalized_content`
* Comments fetched via existing comment actors (`x_post_responses`) 

### 9.2 YouTube

* Ingest transcripts via `youtube_transcript` profile (already mapped) 
* Comments actor exists per docs 

### 9.3 TikTok (gap)

This will require **either**:

* Add Apify TikTok actor(s) + implement a TikTok normalizer into `sw_normalized_content` mapping (platform, external_id, url, text/caption, transcript if available, metrics).
  Or:
* Temporarily store TikTok as `platform=generic` and accept soft failures (not recommended long-term). Normalization expects platform correctness to pick adapter. 

---

## 10) Scheduling / Operations

Baseline scheduler (extend existing pattern): 

* Every 6 hours:

  * ingest search results (X queries, YouTube/TikTok feeds)
  * normalize (queue)
  * select candidates
  * queue creative extraction
* Daily 02:00:

  * fetch comments for top items (existing command) 
* Daily 03:00:

  * run TI pipeline (now embedding-based)
* Daily 04:00:

  * cluster creative hooks/angles (rolling window 7–30 days)
  * generate/update content briefs from top TopicBriefs

**Guardrails**

* caps per post for comments (already exists) 
* embedding only for signal_score ≥ threshold (already principle exists; extend to creative) 
* queue monitoring + failed job visibility per migration checklist 

---

## 11) Ranking & Scoring

### 11.1 Creative cluster score

`creative_score = a*log(1+item_count) + b*log(1+engagement_total) + c*platform_entropy + d*recency_boost`

### 11.2 Brief priority score

Start from existing TopicBrief `priority_score` 
Adjust:

* * if matching strong hook clusters exist (more “packaging leverage”)
* * if offer patterns align with your business model
* − if topic has weak packaging signals (low creative cluster coverage)

---

## 12) Acceptance Criteria (system-level)

1. **Embedding clustering works** (no placeholder behavior) 
2. For a given niche window (e.g., last 14 days), system produces:

   * ≥ N Creative clusters (hooks/angles) with coherent examples
   * ≥ M ContentBriefs with:

     * 5+ hook options
     * 1–3 recommended formats
     * points & objections tied to TopicBrief problems/quotes 
3. Reruns are incremental (no full reprocess)
4. APIs return results within acceptable latency (define target: e.g., <500ms list, <1.5s detail)
5. Failures are diagnosable via logs/failed_jobs (align with existing normalization behavior). 

---

## 13) Implementation Plan (phased)

### Phase 1 — Foundations (must-have)

* Implement embeddings storage + service wrapper
* Replace TopicClusteringService placeholder with embedding-based clustering 
* Add CreativeUnit extraction + storage
* Add Creative clustering (hooks + angles)
* Add ContentBrief generator (TopicBrief + Creative aggregates)

### Phase 2 — Coverage + quality

* TikTok normalizer + ingest profiles
* Better format heuristics per platform (threads vs shorts vs carousels)
* Cross-post linking (same topic across multiple posts) aligns with roadmap 

### Phase 3 — Drift + alerts

* Topic stability/drift detection (also on roadmap) 
* Emerging-hook alerts (“new hook cluster spiking”)

---

## 14) Open Decisions (pick defaults if you don’t care)

1. Embedding provider/model (OpenAI vs Cohere vs local)
2. Vector storage (pgvector vs external)
3. Clustering algorithm (DBSCAN vs HDBSCAN)
4. Time windows:

   * Creative clustering window (7/14/30 days)
   * Brief generation cadence (daily vs weekly)

If you want a sharper spec, share your current stack choices (embedding provider + storage). Otherwise, default to **pgvector + DBSCAN/HDBSCAN**.
