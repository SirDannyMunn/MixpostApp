# Engineering Spec — SwipeStructure Matching + Ephemeral Generation (using IngestionSource)

## Purpose

Implement a deterministic backend pipeline that:

1. selects a **canonical SwipeStructure** for a generation request using **structural-fit-first** ranking, with **user override** support, and
2. falls back to **ephemeral, LLM-generated structure** when no canonical match is found, and
3. optionally **promotes** successful ephemeral structures into canonical structures.

This spec replaces the term **SwipeItem** with **IngestionSource**.

---

## Goals

* High match rate without needing a massive structure library.
* Deterministic outcomes (same inputs → same structure choice) when canonical structures exist.
* Explicit user control (manual selection overrides matching).
* Fallback coverage (always have a structure) via ephemeral generation.
* Prevent library pollution (ephemeral ≠ canonical unless promoted).

## Non-goals

* Building a full ML recommendation system.
* Blending multiple structures in one generation.
* Deep semantic similarity or embedding search (optional future).

---

## Definitions

* **SwipeStructure (canonical):** Stored, reusable structure blueprint (ordered section purposes + metadata + confidence). Can be attached to an IngestionSource.
* **EphemeralStructure:** Generated on-the-fly by an LLM for a specific request. Not persisted as canonical by default.
* **IngestionSource:** A captured content source record (bookmark/import/scrape/etc.) that may optionally have an associated canonical SwipeStructure.
* **GenerationRequest:** Backend request representing a user’s prompt + constraints.

---

## Data Model Changes

### 1) `swipe_structures` (existing / canonical)

Add/ensure the following columns:

* `id` (uuid)
* `tenant_id` (uuid)
* `title` (string, nullable)
* `intent` (enum: educational|story|persuasive|contrarian|emotional, nullable)
* `funnel_stage` (enum: tof|mof|bof, nullable)
* `hook_type` (string, nullable)
* `cta_type` (enum: none|soft|hard, nullable)
* `structure` (jsonb) — array of `{section, purpose}`
* `confidence` (int 0–100)
* `is_ephemeral` (bool, default false)
* `origin` (enum: ingestion_source|manual|ephemeral_promoted|system_seed)
* `created_by_user_id` (uuid, nullable)
* `last_used_at` (timestamp, nullable)
* `use_count` (int, default 0)
* `success_count` (int, default 0)
* `failure_count` (int, default 0)
* `deleted_at` (timestamp, nullable)

Indexes:

* `(tenant_id, confidence desc)`
* `(tenant_id, is_ephemeral)`
* `(tenant_id, last_used_at desc)`

### 2) `ingestion_sources`

Add/ensure:

* `id` (uuid)
* `tenant_id` (uuid)
* `type` (enum: bookmark|scrape|import|manual|api)
* `source_url` (text, nullable)
* `raw_text` (text)
* `meta` (jsonb)
* `swipe_structure_id` (uuid, nullable) — **optional canonical structure derived from this source**
* `structure_status` (enum: none|pending|generated|failed) default none
* `structure_confidence` (int, nullable)

Indexes:

* `(tenant_id, type)`
* `(tenant_id, swipe_structure_id)`

### 3) `generation_requests`

Add:

* `selected_swipe_structure_id` (uuid, nullable) — explicit user override
* `resolved_swipe_structure_id` (uuid, nullable) — canonical chosen OR promoted canonical
* `ephemeral_structure` (jsonb, nullable) — structure used if no canonical selected
* `structure_resolution` (enum: user_selected|auto_matched|ephemeral_fallback) not null
* `structure_fit_score` (int 0–100, nullable)
* `requested_intent` (enum, nullable) — soft guess
* `requested_length_band` (enum: short|medium|long, nullable)
* `requested_shape_hint` (string, nullable) — e.g. story|list|breakdown|argument

### 4) `generation_snapshots` (optional, if exists)

Add:

* `resolved_structure_payload` (jsonb) — persisted structure actually used for the run (canonical structure json + metadata OR ephemeral)

---

## LLM Stages Tracking (align with your existing “LLM stages” column)

If you already track LLM stages used per run, add these stage keys:

* `structure_match` (non-LLM unless you choose to use LLM for fit scoring)
* `structure_fallback_generate` (LLM)
* `structure_promote` (optional LLM normalization)

Each stage object should include:

* `model`
* `prompt_version`
* `tokens_in` / `tokens_out` (if available)
* `latency_ms` (if available)

---

## Backend Services

### A) `StructureResolver`

Responsible for selecting a structure for a GenerationRequest.

**Inputs:**

* GenerationRequest (prompt, constraints, tenant_id, optional selected_swipe_structure_id)

**Outputs:**

* `StructureResolutionResult`:

  * `structure_resolution` (user_selected|auto_matched|ephemeral_fallback)
  * `resolved_swipe_structure_id` (nullable)
  * `ephemeral_structure` (nullable)
  * `structure_fit_score` (nullable)

#### Resolution Algorithm (canonical-first, one structure only)

1. **User override**

   * If `selected_swipe_structure_id` present and belongs to tenant:

     * set `resolved_swipe_structure_id`
     * `structure_resolution = user_selected`
     * stop.

2. **Candidate retrieval** (max 10)

   * Query `swipe_structures` where:

     * `tenant_id = ?`
     * `deleted_at is null`
     * `is_ephemeral = false`
   * Order by:

     * `confidence desc`, `success_count desc`, `last_used_at desc`
   * Apply **light prefilter** (NOT intent gate):

     * if `requested_length_band` known:

       * short → prefer 3–5 sections
       * medium → 4–7 sections
       * long → 6–10 sections

3. **Structural fit scoring** (primary)

   * Compute a score using deterministic heuristics based on structure shape:

     * section count compatibility
     * presence of CTA section if request implies CTA (optional)
     * presence of narrative pivot sections if requested_shape_hint == story
     * penalty for overly complex structures for short band
   * Add soft bias boosts:

     * intent match (+small)
     * funnel stage match (+small)
   * Select top 1 structure if score >= `STRUCTURE_MIN_FIT_SCORE`.

4. **Fallback to ephemeral**

   * If no candidate exceeds min score:

     * call `EphemeralStructureGenerator` (LLM)
     * `structure_resolution = ephemeral_fallback`

Config:

* `STRUCTURE_CANDIDATE_LIMIT = 10`
* `STRUCTURE_MIN_FIT_SCORE = 55` (tunable)

### B) `EphemeralStructureGenerator`

Generates a minimal structure from the user prompt.

**Inputs:**

* prompt text
* requested_intent (optional)
* requested_length_band (optional)
* requested_shape_hint (optional)

**Output:**

* `ephemeral_structure` json:

  * same shape as canonical `structure` array
  * plus metadata: `confidence` (default 20–40), `origin=ephemeral`

**Hard constraints:**

* Output 3–6 sections only.
* Each section: `{section, purpose}`
* No content wording, no examples, no platform labels.

**LLM prompt contract:**

* Response must be valid JSON.
* Follow your existing schema.

### C) `StructurePromotionService` (optional but recommended)

Promotes an ephemeral structure to canonical when “earned”.

**Promotion triggers (any):**

* User clicks “Save structure”
* Same ephemeral structure reused N times (e.g., 3)
* Generation result marked successful (manual or inferred)

**Promotion steps:**

1. Normalize labels (optional LLM pass):

   * limit section count 3–8
   * normalize section names: Hook, Context, Breakdown/List, Reframe, Conclusion, CTA
2. Create new `swipe_structures` record:

   * `is_ephemeral=false`
   * `origin=ephemeral_promoted`
   * `confidence=50` initial
3. Update the associated `generation_request.resolved_swipe_structure_id`

---

## Request Flow (Backend Pipeline — **Aligned to `/api/v1/ai/chat`**)

This section **replaces** the previous request-flow description and aligns the SwipeStructure logic with the existing `ContentGeneratorService` and `DocumentContext.references` contract.

### Entry Point

`POST /api/v1/ai/chat`

SwipeStructure overrides and auto-matching **do not introduce a new endpoint** and **do not create a new generation_requests table dependency**. All structure behavior is resolved **inside the existing generation pipeline**.

### Where SwipeStructures Live in the Request

* Explicit SwipeStructure overrides are passed via:

  * `document_context.references[]`
  * Reference shape:

    * `{ id, type: "swipe_structure" | "template", title?, content? }`

* This mirrors existing behavior for templates, knowledge, and facts.

### Revised Flow (Mapped to Existing Steps)

**Step 0 — Resolve VIP Overrides (existing)**

* `OverrideResolver::resolveVipSwipes(...)`
* If a SwipeStructure reference exists in `DocumentContext.references`:

  * Treat as **hard override**
  * Bypass auto-matching entirely
  * Result: `structure_resolution = user_selected`

**Step 1 — Classification (unchanged)**

* Intent/funnel classified or overridden as today.
* Classification is **not used to gate structure selection**.

**Step 2 — Retrieval + Template Resolution (existing)**

* `TemplateService` resolves final template.
* `structureSignature` derived from template **if present**.

**Step 2.6 — SwipeStructure Resolution (UPDATED)**

* Executed inside the existing swipe phase:

  * `Retriever::swipeStructures(...)`

Resolution order:

1. **Explicit override present** → use it
2. **Auto-match canonical SwipeStructure**:

   * Filter by tenant
   * Optional bias via `structureSignature`
   * Rank by confidence + structural fit heuristics
3. **No suitable canonical match** → generate ephemeral structure

Result is injected into context as:

* `context.swipes[]` (single entry only)
* Marked internally as:

  * `user_selected` | `auto_matched` | `ephemeral_fallback`

**Step 3 — Context Assembly (existing)**

* `ContextFactory::fromParts(...)`
* Swipe structure is included as **structure guidance only**
* No wording, no tone, no examples

**Step 4 — Prompt Composition (existing)**

* `PromptComposer` receives:

  * ordered section purposes
  * hard instruction to follow structure order
  * user intent applied as a rendering constraint

**Step 5–7 — Generation, Validation, Snapshot (unchanged)**

* Structure payload used is persisted via snapshot metadata:

  * `resolved_structure_payload`
  * `structure_resolution`

---

## Matching Details (Deterministic Fit Scoring)

### Fit scoring heuristic (no LLM required)

Compute `fit_score` 0–100.

Base components:

* `count_score` (0–40)

  * best if section count in band
* `shape_score` (0–30)

  * if requested_shape_hint == story and structure contains a pivot-like purpose
  * if requested_shape_hint == list and contains breakdown/list purpose
* `cta_score` (0–10)

  * if constraints request CTA and structure includes CTA purpose
* `simplicity_score` (0–10)

  * penalize >7 sections when short

Bias boosts:

* `intent_boost` (+0–5)
* `funnel_boost` (+0–5)

Selection:

* Choose highest score.
* Must exceed `STRUCTURE_MIN_FIT_SCORE`.
* Never select more than one.

### Optional future: LLM-assisted fit scoring

You may later add an LLM stage `structure_match` that compares prompt → section purposes. If used:

* still return only 1 structure
* still keep min score threshold
* log model in LLM stages

---

## IngestionSource → Structure Extraction (Separate from Matching)

This spec focuses on matching during generation. Separately, implement extraction from IngestionSource raw text:

### `POST /api/v1/ingestion-sources/{id}/extract-structure`

* Runs structure extraction LLM against `ingestion_sources.raw_text`
* Writes canonical structure:

  * create `swipe_structures` row with `origin=ingestion_source`
  * set `ingestion_sources.swipe_structure_id`
  * set `structure_status=generated`

Auto-extraction policy:

* Default: **manual trigger only**
* Optional: auto-trigger when source is starred/boosted or passes engagement threshold

---

## Observability & Telemetry

Log events:

* `structure.resolved`:

  * request_id
  * resolution type
  * resolved_swipe_structure_id
  * fit_score
* `structure.fallback_generated`:

  * request_id
  * model used
* `structure.promoted`:

  * new structure id
  * trigger reason

Metrics:

* % user_selected vs auto_matched vs ephemeral_fallback
* average fit_score
* top structures by success

---

## Safety / Guardrails

* Never persist ephemeral structures as canonical automatically.
* Ephemeral generation must be capped to 3–6 sections.
* Matching must never blend multiple structures.
* If LLM returns invalid JSON for ephemeral generation:

  * retry once with a stricter prompt
  * if still invalid, use a hardcoded default skeleton.

Default skeleton (hardcoded fallback):

1. Hook — create tension or interest
2. Context — clarify what this is about
3. Core Point — the main idea
4. Takeaways — optional list or explanation
5. CTA — optional invite

---

## Acceptance Criteria

1. When `selected_swipe_structure_id` is provided, it is always used and recorded as `user_selected`.
2. When no selection is provided and a canonical structure scores >= min score, it is used and recorded as `auto_matched`.
3. When no canonical structure meets threshold, an ephemeral structure is generated and recorded as `ephemeral_fallback`.
4. The response includes the structure used (canonical or ephemeral).
5. Ephemeral structures are not stored as canonical unless explicitly promoted.
6. Matching produces at most one structure.
7. LLM stage tracking records `structure_fallback_generate` model when used.

---

## Rollout Plan

* Phase 1: Implement resolver + fallback, no promotion.
* Phase 2: Add extraction from IngestionSource and manual promotion.
* Phase 3: Add success tracking + auto-promotion thresholds.
