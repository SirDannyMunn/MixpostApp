# Engineering Spec – Cross‑Platform Creative Intelligence (Posts‑First, Comments‑Preserved)

## 0) Purpose (what this spec does and does *not* do)

This spec **adds a posts‑first Creative Intelligence layer** on top of the existing Topic Intelligence (comment‑centric) system **without breaking or replacing it**.

* **Topic Intelligence (comments)** remains intact, unchanged, and first‑class.
* **Creative Intelligence (posts/videos)** is introduced as a parallel system for discovering hooks, angles, formats, and offers.
* **Content Briefs** can be generated from:

  * Demand‑backed insights (comments → TopicBrief v2), **and/or**
  * Creative‑led insights (posts/videos → Creative clusters).

> Design principle: **Posts show what works. Comments show why it works (or doesn’t).**

---

## 1) Existing system (explicitly preserved)

The following systems remain **unchanged and non‑deprecated**:

* Comment ingestion via Apify
* Comment classification, sentiment, and signal scoring
* Account profiling and commenter analysis
* Topic Intelligence pipeline:

  * `sw_ti_items` (comment‑derived)
  * `sw_ti_clusters`
  * `sw_ti_problem_statements`
  * `sw_ti_topic_briefs`
* All existing APIs, commands, and observability

**No migrations, config changes, or behavior changes** are required to keep Topic Intelligence operating exactly as documented in v1.1.

---

## 2) Motivation for Creative Intelligence (why this exists)

Topic Intelligence answers:

> “What problems are people explicitly expressing in conversations?”

Creative Intelligence answers:

> “What hooks, angles, formats, and offers are repeatedly *winning* across platforms?”

These are complementary, not competing.

Comments alone are insufficient for:

* hook discovery
* framing discovery
* format convergence
* offer pattern analysis

Posts/videos are the correct unit for that analysis.

---

## 3) High‑level architecture

```
                    ┌──────────────────────────────┐
                    │   Raw Content (Posts/Videos) │
                    │   sw_content_items           │
                    └──────────────┬───────────────┘
                                   ↓
                    ┌──────────────────────────────┐
                    │ Normalized Content            │
                    │ sw_normalized_content         │
                    └──────────────┬───────────────┘
                                   ↓
        ┌──────────────────────────┴──────────────────────────┐
        │                                                         │
        │                                                         │
┌───────────────┐                                     ┌────────────────────┐
│ Topic         │                                     │ Creative            │
│ Intelligence  │                                     │ Intelligence        │
│ (Comments)    │                                     │ (Posts/Videos)      │
│               │                                     │                     │
│ sw_comments   │                                     │ sw_creative_units   │
│ sw_ti_items   │                                     │ sw_creative_clusters│
│ sw_ti_clusters│                                     │                     │
└───────┬───────┘                                     └─────────┬──────────┘
        │                                                         │
        └──────────────────────────┬───────────────────────────┘
                                   ↓
                        ┌──────────────────────────────┐
                        │ Content Briefs                │
                        │ sw_content_briefs             │
                        └──────────────────────────────┘
```

---

## 4) Creative Intelligence (new system)

### 4.1 Source of truth

Creative Intelligence operates on **normalized posts/videos**, not comments:

* X posts
* YouTube video transcripts
* TikTok captions + transcripts

Input table:

* `sw_normalized_content`

Comments are **never promoted** into Creative Intelligence.

---

## 5) Creative Unit extraction

### 5.1 `sw_creative_units` (new)

Each CreativeUnit represents a **single analyzed post/video**.

Fields:

* `normalized_content_id`
* `platform`
* `author_username`
* `published_at`
* `raw_text`
* `hook_text`
* `format_type`
* `angle`
* `value_promises[]`
* `proof_elements[]`
* `offer {}`
* `cta {}` (informational only; not reused verbatim)
* `confidence`

### 5.2 Extraction flow

1. Select eligible normalized content
2. Deterministic pre‑pass:

   * detect obvious hooks
   * infer format (thread, short, long‑form, etc.)
3. LLM extraction pass (strict JSON)
4. Validate + store CreativeUnit

If `confidence < 0.6`, unit is stored but excluded from clustering.

---

## 6) Embeddings (shared infrastructure)

### 6.1 Reuse embedding service

The existing embedding service introduced in Topic Intelligence v1.1 is reused.

Embeddings generated for:

* Creative hooks
* Creative angles

Stored in:

* `sw_embeddings`

Object types:

* `creative_hook`
* `creative_angle`

---

## 7) Creative clustering

### 7.1 Cluster types

Separate clusters are maintained for:

* Hooks
* Angles
* Offer pitches (optional, phase 2)

### 7.2 Clustering behavior

* Algorithm: DBSCAN / HDBSCAN
* Inputs: embeddings only
* No keyword dependence
* Rolling windows (7–30 days)

Cluster metrics:

* item_count
* engagement_total
* platform_entropy
* repeat_rate

Stored in:

* `sw_creative_clusters`
* `sw_creative_cluster_items`

---

## 8) Relationship to Topic Intelligence (critical section)

Creative Intelligence **does not replace** Topic Intelligence.

| System                | Purpose             | Source       | Output                 |
| --------------------- | ------------------- | ------------ | ---------------------- |
| Topic Intelligence    | Demand discovery    | Comments     | Problems, objections   |
| Creative Intelligence | Packaging discovery | Posts/videos | Hooks, angles, formats |

They converge only at **brief generation time**.

---

## 9) Content Brief generation (unified output)

### 9.1 Brief types

Two supported brief modes:

#### A) Demand‑backed brief

Inputs:

* TopicBrief v2 (from comments)
* Relevant Creative clusters

Use when:

* problems are explicit
* tutorials, comparisons, solutions

#### B) Creative‑led brief

Inputs:

* Creative clusters only
* Optional comment objections

Use when:

* perspective content
* contrarian takes
* thought leadership

### 9.2 `sw_content_briefs`

Fields:

* `topic_brief_id` (nullable)
* `creative_cluster_ids[]`
* `title_options[]`
* `recommended_formats[]`
* `hook_options[]`
* `key_points[]`
* `objections_to_address[]`
* `proof_suggestions[]`
* `cta_placeholder`
* `priority_score`

---

## 10) Comments remain valuable (explicitly preserved)

Comments continue to be used for:

* Objection extraction
* Language validation
* Proof credibility
* Demand strength weighting

Especially important for:

* YouTube
* Reddit‑style long threads

No existing comment logic is removed or altered.

---

## 11) Scheduling

Existing schedules remain valid.

Additions:

* Creative extraction: every 6h
* Creative clustering: daily
* Brief generation: daily or weekly

All runs are **incremental** and reuse stored content.

---

## 12) Acceptance criteria

* Topic Intelligence behavior unchanged
* Creative clusters form from posts/videos
* Content briefs can be generated even when comment clusters are weak
* No duplicate scraping or data inflation
* Clear observability for both pipelines

---

## 13) Summary

This spec:

* Preserves your investment in Topic Intelligence
* Adds a posts‑first Creative Intelligence layer
* Solves the signal mismatch you observed
* Produces the kind of content briefs you described

**Topic Intelligence remains your demand microscope.**
**Creative Intelligence becomes your content radar.**

They are stronger together than either alone.
