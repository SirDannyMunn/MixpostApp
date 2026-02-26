# Engineering Spec — Creative Intelligence Integration into ContentGeneratorService

**Owner:** TBD
**Status:** Draft
**Target version:** vNext
**Updated:** 2026-01-09

## Goal

Integrate **Creative Intelligence (CI)** into `ContentGeneratorService` so generation automatically retrieves and applies **hooks, angles, emotional drivers, and audience targeting** when the user prompt does not explicitly provide them.

Key principle: **User intent wins.** CI fills gaps and biases generation, but must not override explicit instructions.

## Non-goals

* Building a full CI dashboard UI.
* Rewriting the CI extraction/clustering pipeline.
* Auto-copying hooks verbatim into outputs (unless explicitly requested via option).

## Success criteria

* For generic prompts (e.g., “Write a post about SEO”), generated posts show improved:

  * hook relevance
  * angle clarity
  * emotional coherence
  * audience alignment
* For prompts that specify hook/emotion/audience, CI does not conflict or dilute the user’s directive.
* Snapshot persistence records what CI inputs were used.

---

## High-level design

Add a new stage inside `ContentGeneratorService::generate()` between **classification** and **retrieval/context assembly**:

1. **Prompt Signal Extraction** (detect what the user already provided)
2. **CI Retrieval & Recommendation** (retrieve only missing dimensions)
3. **CI Context Injection** (store structured CI block in Context)
4. **Prompt Composition** updated to reference CI block with precedence rules
5. **Persistence**: store CI usage in generation snapshots and metadata

This mirrors existing pipeline style (classifier → retriever → assembler → composer → runner → validator → snapshot).

---

## Data model changes

### 1) Add `creative_intelligence` JSON column to generation snapshots

**Table:** `generation_snapshots` (or your current snapshots table; name per codebase)

* `creative_intelligence JSON NULL`

**Purpose:** Persist CI recommendations used in the run for replay/debug.

**Example stored value**

```json
{
  "policy": {"mode":"auto","hook":"fill","emotion":"fill","audience":"respect"},
  "signals": {"hook_provided":false,"emotion_provided":false,"audience_provided":true,"format_provided":false},
  "resolved": {
    "topic":"seo",
    "platform":"twitter",
    "audience_persona":"seo_agency_owner",
    "sophistication_level":"intermediate",
    "emotional_target":{"primary":"fear","secondary":"relief","intensity":0.7}
  },
  "recommendations": {
    "hooks":[{"id":"cu_123","hook_text":"40 hours → 4 hours","hook_archetype":"compression","score":0.81}],
    "angles":[{"label":"AI-driven SEO automation","score":0.73}],
    "formats":[{"format_type":"thread","score":0.62}]
  },
  "source_refs": {"creative_unit_ids":["..."],"cluster_ids":["..."]}
}
```

### 2) Optional: Add structured type column(s) later

Not required for v1. Keep JSON for speed.

---

## New service + interfaces

### `PromptSignalExtractor`

**Responsibility:** Determine whether the user prompt already includes:

* hook/opening line
* emotional target
* audience persona
* sophistication level
* format

**Output**

```php
class PromptSignals {
  public bool $hookProvided;
  public bool $emotionProvided;
  public bool $audienceProvided;
  public bool $sophisticationProvided;
  public bool $formatProvided;
  public array $explicit; // parsed explicit values when confidently detected
  public array $debug;    // regex hits / LLM response summary
}
```

**Implementation v1 (fast):**

* Rule-based detection + optional light LLM classification behind a feature flag.
* Regex/heuristics examples:

  * Hook: quoted first line, “Hook: …”, “Opening: …”
  * Emotion: “fear”, “fomo”, “make people feel…”, “emotional trigger”
  * Audience: “for agency owners”, “target: founders”, “persona:”
  * Sophistication: “beginner/intermediate/advanced”
  * Format: “thread”, “carousel”, “LinkedIn post”, etc.

### `CreativeIntelligenceRecommender`

**Responsibility:** Given prompt, classification, platform, org/user, and `PromptSignals`, retrieve CI recommendations **only** for missing dimensions.

**Inputs**

* `orgId`, `userId`
* `prompt`
* `platform`
* `classification` (`intent`, `funnel_stage`)
* `PromptSignals`
* `CiPolicy` (config/options)

**Output**

```php
class CiRecommendation {
  public array $policy;
  public array $signals;
  public array $resolved;         // resolved topic, persona, sophistication, emotion targets
  public array $recommendations;  // hooks/angles/formats
  public array $sourceRefs;       // creative_unit_ids, cluster_ids
  public array $debug;
}
```

**Retrieval strategy (v1):**

* Query `sw_creative_units` with filters:

  * `is_business_relevant = true`
  * `noise_risk <= config('social-watcher.quality_filters.max_noise_risk')`
  * `buyer_quality_score >= config('social-watcher.quality_filters.min_buyer_quality')`
  * `platform` match when available
  * optionally `audience_persona` when known
  * optionally `format_type` when user specified
* Rank by:

  * recency bias (newer better)
  * engagement_score (if stored/accessible via join)
  * hook_novelty high
  * fatigue_score low (from clusters when available)

**Important:** if your CI units currently have many nulls for persona/emotion/archetype, do not block retrieval; degrade gracefully.

### Extend existing `Context` to include CI

Update `ContextFactory::fromParts([...])` to accept:

* `creative_intelligence` (nullable array)

Expose in `Context::debug()` and `Context::snapshotIds()`.

---

## Options & Policy

### New option keys for generation requests

In `GenerationRequest` normalize:

* `ciPolicy`:

  * `mode`: `auto|none|strict`
  * `hook`: `fill|respect|force`
  * `emotion`: `fill|respect|force`
  * `audience`: `fill|respect|force`
  * `max_hooks`: int (default 5)
  * `max_angles`: int (default 3)
  * `allow_verbatim_hooks`: bool (default false)

**Defaults**

* `mode=auto`
* `hook=fill`
* `emotion=fill`
* `audience=fill`
* `allow_verbatim_hooks=false`

**Precedence**

* Explicit user prompt values override CI.
* `force` overrides prompt only when the caller explicitly requests it (advanced usage/admin).

---

## Pipeline changes in `ContentGeneratorService::generate()`

### Insert after Step 1 (Classification)

Add:

#### Step 1.5 — Prompt Signal Extraction

* `signals = $promptSignalExtractor->extract($prompt, $platform, $options);`

#### Step 1.6 — CI Recommendation (optional)

Condition:

* if `ciPolicy.mode !== 'none'` then:

  * `ci = $ciRecommender->recommend($orgId, $userId, $prompt, $platform, $classification, $signals, $ciPolicy);`
* else `ci = null`

### Integrate into Step 4 (Context Assembly)

Pass into context factory:

* `creative_intelligence => $ci`

### Integrate into Step 5 (Prompt Composition)

Update `PromptComposer::composePostGeneration()` to include a structured CI block:

**Rules for the model (system message):**

* If user provided a hook/opening line: use it.
* Else select a hook inspired by CI recommendations; do not copy verbatim unless `allow_verbatim_hooks=true`.
* If user specified audience/persona/sophistication: respect it.
* Else adopt CI-resolved audience persona/sophistication.
* If user specified emotional goal: respect it.
* Else bias toward CI emotional target.

**Representation in prompt**

* Provide CI block as JSON (or bullet list) inside the system message, clearly labeled `CREATIVE_INTELLIGENCE`.
* Keep it short: top N hooks/angles only.

### Persist CI in Step 7 (Snapshot)

Extend snapshot persistence:

* store `creative_intelligence` JSON
* store derived usage flags:

  * `ci_used: bool`
  * `ci_mode`
  * `ci_hook_applied: bool`
  * `ci_emotion_applied: bool`
  * `ci_audience_applied: bool`

(These can live inside existing `options` JSON if you don’t want new columns.)

---

## Database queries

### Recommended minimal CI query (v1)

```sql
SELECT cu.*
FROM sw_creative_units cu
WHERE cu.is_business_relevant = 1
  AND (cu.noise_risk IS NULL OR cu.noise_risk <= :max_noise)
  AND (cu.buyer_quality_score IS NULL OR cu.buyer_quality_score >= :min_buyer_quality)
  AND (cu.platform = :platform OR cu.platform IS NULL)
  AND (:persona IS NULL OR cu.audience_persona = :persona)
ORDER BY cu.hook_novelty DESC NULLS LAST,
         cu.extracted_at DESC NULLS LAST
LIMIT :limit;
```

Then separately compute scores with PHP if needed (recency, fatigue).

---

## Error handling & fallbacks

* CI recommendation failure must not fail generation.

  * Log warning with `runId`.
  * Proceed with generation without CI.
* If CI returns empty recommendations, proceed normally.
* If CI returns conflicting signals (rare), prefer user prompt.

---

## Observability

Add diagnostic fields to snapshot metadata (or log context):

* `ci.signals`
* `ci.sourceRefs`
* `ci.recommendations.counts`
* `ci.debug.query_time_ms`

Add a `php artisan ai:show-prompt {snapshot_id}` view to include CI block.

---

## Security / privacy

* CI retrieval uses org/user scoping if CI is multi-tenant.

  * If `sw_creative_units` is global across tenants, ensure no private customer data is stored.
* Do not store raw third-party content beyond what you already store in normalized content.

---

## Implementation plan

### Phase 1 — Plumbing (1 PR)

* Add `creative_intelligence` JSON column to snapshots.
* Implement `PromptSignalExtractor` (heuristics only).
* Implement `CreativeIntelligenceRecommender` (basic query + ranking).
* Wire into `ContentGeneratorService`.
* Update `ContextFactory` + `Context`.
* Update `PromptComposer` to include CI block + precedence rules.

### Phase 2 — Quality improvements (optional)

* Add fatigue-aware ranking via `sw_creative_clusters`.
* Add topic/keyword mapping for CI retrieval (simple keyword extraction).
* Add lightweight LLM-based signal extraction behind flag.

### Phase 3 — Controls (optional)

* Expose `ciPolicy` in UI/API.
* Add “Use my hook verbatim” toggle.

---

## Test plan

### Unit tests

1. **Signal extraction**

   * Detect hook provided: `Hook: ...`
   * Detect audience provided: `for agency owners`
   * Detect format: `make this a thread`

2. **CI gating**

   * When `hookProvided=true`, recommender does not fetch hooks (or marks them unused).
   * When `audienceProvided=true`, recommender filters by provided persona.

3. **Prompt composition**

   * System prompt contains CI block only when `ci != null`.
   * Precedence instruction present.

### Integration tests

* Generate with prompt `Write a post about SEO` → CI block present in debug prompt.
* Generate with prompt containing explicit hook → CI hook not applied.
* Replay from snapshot retains CI block.

### Regression checks

* Ensure no change when `ciPolicy.mode=none`.

---

## Open questions (safe defaults)

* **Where to store persona/sophistication if missing?**

  * Default to business profile (if present), else null.
* **Do we embed CI items in vector search?**

  * Not required for v1; SQL filters + ranking is enough. Add embeddings later.

---

## Deliverables

* Migration for snapshots
* `PromptSignalExtractor`
* `CreativeIntelligenceRecommender`
* `CiPolicy` normalization in `GenerationRequest`
* Context + PromptComposer updates
* Snapshot persistence updates
* Tests (unit + integration)
