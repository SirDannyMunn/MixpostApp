# Research Mode v2 – Trend Discovery & Angle‑to‑Hook Pipeline

## Goal

Turn **Research Mode** into an interactive, analyst‑grade workflow that supports:

1. *What’s actually trending* in a niche (not just key facts)
2. Structured follow‑ups (industry → topic → scope)
3. Angle discovery grounded in Creative Intelligence
4. Hook generation on demand (chat‑only)
5. Clean separation between **research output** and **document updates**

This spec extends existing systems rather than replacing them.

---

## High‑Level UX Flow

### Step 0 – User Intent

User enters:

> “I want to research what’s trending”

System response (assistant):

* Asks a **single narrowing question**:

  * Industry
  * Optional region / platform

No LLM free‑writing yet.

---

### Step 1 – Trend Discovery (New)

**Input:**

* Industry/topic string
* Optional platforms: Google, X, YouTube, Reddit (v1: Google + X only)

**Output (chat JSON → rendered):**

* 5–10 *trend candidates* with:

  * `trend_label`
  * `why_trending`
  * `evidence` (search spike / volume / post velocity)
  * `confidence`

User can:

* Click one trend
* Or refine the query

---

### Step 2 – Deep Research (Existing Research Mode)

When a trend is selected:

* Switch into **existing Research Chat Mode**
* Query:

  * Social‑watcher normalized content
  * Research fragments
  * Creative Units (angles/hooks)

**Output:**

* Dominant claims
* Points of disagreement
* Saturated vs emerging angles

This reuses current:

* `Retriever::researchItems()`
* Clustering
* `ResearchReportComposer`

---

### Step 3 – Angle Selection (New Bridge)

System summarizes **angles**, not posts.

Example:

* “AI replacing SEO teams” (saturated)
* “AI traffic converts better than organic” (emerging)

User actions:

* `Explain angle`
* `Give me post ideas`
* `Give me 5 hook variations`

These are **chat‑only** outputs.

---

### Step 4 – Hook Generation (New)

Hook generation is **not document mutation**.

Uses:

* Creative Intelligence hook archetypes
* Emotional driver selection
* Audience sophistication

**Output:**

```json
{
  "hooks": [
    {"text":"By 2028, AI search traffic will be worth more than organic.","archetype":"prediction"},
    {"text":"Traditional SEO just got an expiration date.","archetype":"contrarian"}
  ]
}
```

No snapshots promoted to knowledge base.

---

## System Architecture Changes

### 1. New Service: TrendDiscoveryService

**Location:**
`app/Services/Ai/Research/TrendDiscoveryService.php`

**Responsibilities:**

* Lightweight external trend probing
* No ingestion
* No persistence

**Inputs:**

* `query`
* `industry`

**Sources (v1):**

* Google Trends (indirect via SERP delta queries)
* X search velocity (recent posts vs baseline)

**Output DTO:**

```php
TrendCandidate {
  string label;
  string summary;
  array evidence;
  float confidence;
}
```

---

### 2. Research Mode Extension

Add a new sub‑mode:

```php
options.research_stage = trend_discovery | deep_research | angle_hooks
```

Handled inside `ContentGeneratorService`:

* `trend_discovery` → TrendDiscoveryService
* `deep_research` → existing Research Mode
* `angle_hooks` → Creative Intelligence hook synthesis

---

### 3. Classification Hook

Reuse existing classifier + add:

```php
intent = research.trends | research.analysis | research.ideation
```

This is metadata only (observability).

---

### 4. Creative Intelligence Reuse (No Change)

Hook generation pulls from:

* `sw_creative_units`
* `sw_creative_clusters`

Filters:

* `is_business_relevant = true`
* `noise_risk < 0.3`
* `buyer_quality_score > 0.6`

No new tables required.

---

## Chat vs Document Routing

Use existing **request classifier**:

| Request                | Action              |
| ---------------------- | ------------------- |
| “Give me 5 hooks”      | Chat only           |
| “Create a post”        | Generation pipeline |
| “Update this document” | Document mutation   |

Research Mode never mutates documents.

---

## Snapshot Policy

| Stage           | Snapshot | KB Promotion |
| --------------- | -------- | ------------ |
| Trend discovery | Optional | ❌            |
| Deep research   | Yes      | ❌            |
| Hook generation | No       | ❌            |

Snapshots are for replay/debug only.

---

## What This Fixes

* ❌ Fake “trending” lists

* ❌ Fact‑only outputs

* ❌ SEO‑era keyword thinking

* ✅ Real velocity‑based trend discovery

* ✅ Angle‑first ideation

* ✅ Hooks as a first‑class artifact

* ✅ Clean separation of research vs writing

---

## Non‑Goals (Explicit)

* No auto‑publishing
* No knowledge ingestion from trends
* No real‑time firehose ingestion (yet)

---

## Next Iterations

* Reddit + YouTube velocity adapters
* Trend decay tracking
* Angle saturation scoring
* Saved research workspaces

---

**Bottom line:**
This turns Research Mode into an analyst tool, not a gimmicky idea generator, while fully reusing your existing ingestion, Creative Intelligence, and classification infrastructure.
