

# Engineering Spec – Topic Intelligence v1.1 (Promotion Filters + Embeddings + Semantic Clustering)

## Goal

Upgrade Topic Intelligence from “plumbing works” to “briefs are useful” by:

1. enforcing strict **promotion eligibility** (noise filtering)
2. generating **embeddings** for promoted TI items
3. clustering using **semantic similarity** (not repetition)
4. tightening **cluster → brief** guardrails so spam can’t produce briefs

This spec is written to be implemented in the Social Watcher package.

---

## Non‑Goals

* Replacing all classification with LLMs
* Embedding raw comments
* Real-time social listening / alerting
* Multi-language support (English-only for v1.1)

---

## Current State (Problem)

* `sw_ti_items.embedding` is NULL for all items.
* Promotion to `sw_ti_items` is too permissive; link-only and promo spam are being promoted.
* Clustering does not use semantic similarity; repeated strings dominate clusters.
* Topic briefs can be generated from tiny clusters (e.g., 3 items, 1 commenter), resulting in unusable briefs.

---

## Desired Outcomes

1. **Only market-relevant comments** become TI items.
2. Each TI item has a vector embedding.
3. Clusters group items by meaning.
4. Topic briefs are generated only when demand signals are real.

Success criteria:

* Link-only / promo content does not appear in `sw_ti_items`.
* `sw_ti_items.embedding` is populated for newly created items.
* `social-watcher:run-topic-intelligence` produces clusters that are semantically coherent.
* Topic briefs require minimum demand thresholds (no briefs from 1 commenter or 0 days).

---

## Architecture Changes

### New Components

1. **PromotionEligibilityService**
2. **EmbeddingService** (provider-agnostic)
3. **EmbedTopicIntelligenceItem Job**
4. **SemanticClustering implementation** inside TopicClusteringService

### Updated Components

* `CreateTopicIntelligenceItem` job: enforce eligibility and dispatch embedding job.
* `TopicClusteringService`: use embeddings + cosine similarity clustering.
* `TopicBriefGenerationService`: enforce guardrails; skip low-quality clusters.

---

## Data Model Changes

### 1) `sw_ti_items`

Add fields:

* `embedding_status` ENUM/string: `pending|ready|failed` (default `pending`)
* `embedding_model` string nullable
* `embedded_at` timestamp nullable
* `text_hash` string nullable (dedupe)

Keep existing:

* `embedding` (JSON/array/blob) — already present but currently NULL.

Indexes:

* index on `embedding_status`
* unique index on (`variant`, `text_hash`) optional (dedupe)

### 2) `sw_comments`

Add fields:

* `question_intent` string nullable: `topic|creator|promo|unknown`
* `promotion_reason` string nullable (why accepted/rejected)

Optional but recommended for debugging:

* `is_promotable` boolean nullable

---

## Configuration

Add to `config/social-watcher.php` (or a new `topic-intelligence.php`):

```php
return [
  'promotion' => [
    'min_signal' => 6.0,
    'min_length' => 20,
    'max_url_ratio' => 0.60,
    'allowed_types' => ['problem','experience','question'],
    'blocked_types' => ['praise','echo','unknown'],
    'blocked_question_intents' => ['creator','promo'],
    'promo_phrases' => [
      'guaranteed', 'check out', 'dm me', 'link in bio', 'join my', 'course', 'newsletter'
    ],
    'blocked_domains_keywords' => ['onlyfans', 'crypto', 'airdrop'],
  ],

  'embedding' => [
    'provider' => 'openai', // openai|cohere|local
    'model' => 'text-embedding-3-small',
    'queue' => 'default',
    'batch_size' => 50,
    'timeout_seconds' => 30,
    'retry' => 3,
  ],

  'brief_guardrails' => [
    'min_distinct_commenters' => 3,
    'min_problem_items' => 2,
    'min_persistence_days' => 7,
    'min_topic_score' => 25,
  ],

  'clustering' => [
    'algorithm' => 'dbscan',
    'min_cluster_size' => 3,
    'eps' => 0.25, // cosine distance threshold
  ],
];
```

---

## Detailed Behavior

### A) Question Intent Classification (Rule-based v1)

Add a rule-based classifier to set `sw_comments.question_intent` when type=`question`.

**topic** (allow):

* questions about the subject domain (e.g., “Is SEO worth it?”, “How do I rank?”)

**creator** (block):

* questions referencing the author’s life/business (e.g., “What are YOU doing on…”, “How did you…”)

**promo** (block):

* promotional bait disguised as question (“Want to get…”, “Check out…”, contains URL + sales language)

Implementation:

* `QuestionIntentService::classify($text, $raw_content)` returns intent.
* Called inside `ProcessComment` after base type classification.

---

### B) Promotion Eligibility Rules (Hard Gates)

Implement `PromotionEligibilityService::evaluate(Comment $comment): PromotionDecision`.

`PromotionDecision` fields:

* `eligible` bool
* `reason_code` string (e.g., `blocked_type`, `too_short`, `link_only`, `promo_phrase`, `creator_question`, `low_signal`, `passed`)

Rules (in order):

1. Require normalized fields / canonical platform mapping.
2. Block if `type` in blocked list.
3. If `type=question` then block if `question_intent` in blocked list.
4. Block if text length < min_length.
5. Block if url_ratio > max_url_ratio.
6. Block if contains promo_phrases.
7. Block if contains blocked_domains_keywords.
8. Require `signal_score >= min_signal` OR `type=problem`.
9. If passes, return eligible.

Persist:

* Set `sw_comments.is_promotable`, `promotion_reason`.

---

### C) TI Item Creation

Update `CreateTopicIntelligenceItem` job:

* Load comment
* Evaluate eligibility
* If not eligible: exit
* If eligible:

  * Create `sw_ti_items` row with:

    * `variant = comment_text`
    * `source_comment_id`
    * cleaned `text` (strip leading @mentions, trim)
    * `metadata` includes platform, type, signal, published_at
    * `embedding_status = pending`
    * `text_hash = sha1(normalized_text)`
  * Dispatch `EmbedTopicIntelligenceItem($tiItemId)`

Dedupe:

* If (`variant`, `text_hash`) exists, skip creation.

---

### D) Embedding Generation

Implement provider-agnostic `EmbeddingService`:

Interface:

* `embed(string $text): array<float>`
* `model(): string`

Provide OpenAI implementation (default). Store secrets in env.

`EmbedTopicIntelligenceItem` job:

* Fetch TI item
* If already `ready`, exit
* Call `EmbeddingService->embed($text)`
* Persist:

  * `embedding` vector
  * `embedding_status=ready`
  * `embedding_model`
  * `embedded_at`
* On failure:

  * `embedding_status=failed`
  * log error
  * retry per config

---

### E) Semantic Clustering

Update `TopicClusteringService::clusterItems()`:

Input set:

* only `sw_ti_items` where `embedding_status=ready`
* optionally only items from the last N days

Algorithm:

* DBSCAN over cosine distance.
* Parameters from config:

  * `eps` (distance threshold)
  * `min_cluster_size`

Output:

* Create/update `sw_ti_clusters`
* Attach items via `sw_ti_cluster_items` with `distance`

Notes:

* If embeddings not ready, do not cluster. Emit a clear console warning.

---

### F) Brief Guardrails (Prevent Junk Briefs)

In `TopicBriefGenerationService`:

Before generating a brief for a cluster, require:

* `distinct_commenters >= min_distinct_commenters`
* `distinct_problem_comments/items >= min_problem_items`
* `persistence_days >= min_persistence_days`
* `topic_score >= min_topic_score`

If not met:

* skip brief generation for this cluster
* optionally store a debug reason (not required)

---

## CLI / Ops

### New command (recommended)

Add:

* `php artisan social-watcher:embed-ti-items --pending --limit=500`

Purpose:

* backfill embeddings for existing TI items
* recover from transient provider outages

### Update `run-topic-intelligence`

Pipeline should be:

1. cluster (requires embeddings ready)
2. problems
3. briefs

If no embedded items:

* print actionable message:

  * “0 embedded TI items. Run embed job/command first.”

---

## Migration Plan

1. Create migration for new fields on `sw_ti_items` and `sw_comments`.
2. Deploy config.
3. Start queue worker.
4. Run backfill sequence:

   * regenerate/promote TI items if needed
   * embed TI items
   * run topic intelligence

---

## Acceptance Tests

### 1) Noise filtering

* Given a link-only comment, `PromotionEligibilityService` returns `eligible=false`.
* Given “Want to get your first local seo client… GUARANTEED”, `eligible=false` with `promo_phrase`.
* Given “Is SEO worth it?”, `eligible=true`.

### 2) Embeddings

* Creating a TI item enqueues `EmbedTopicIntelligenceItem`.
* After job runs, `embedding_status=ready` and `embedding` is non-null.

### 3) Clustering

* With 10 semantically similar SEO items, clusters >= 1.
* With unrelated items, clusters split accordingly.

### 4) Guardrails

* A cluster with 1 commenter produces **no brief**.
* A cluster meeting thresholds produces a brief.

---

## Implementation Checklist

* [ ] Add migrations (fields + indexes)
* [ ] Add config sections
* [ ] Implement QuestionIntentService
* [ ] Implement PromotionEligibilityService
* [ ] Update ProcessComment to populate question_intent
* [ ] Update CreateTopicIntelligenceItem to enforce eligibility + dedupe
* [ ] Implement EmbeddingService + provider implementation
* [ ] Add EmbedTopicIntelligenceItem job
* [ ] Add embed backfill command
* [ ] Update TopicClusteringService to DBSCAN cosine clustering
* [ ] Enforce brief guardrails
* [ ] Add console diagnostics for missing embeddings / skipped clusters

---

## Notes / Design Choices

* Keep rule-based gates first: cheaper and more deterministic than LLM calls.
* Embed only *promoted* TI items to control cost.
* Use LLM calls later (v1.2) for canonical problem merging and nicer brief writing.
* Ensure platform canonicalization (`twitter` → `x`) happens before eligibility checks.
