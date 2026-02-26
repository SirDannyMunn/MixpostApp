# Voice Profile Generation using Social Watcher

**Backend Requirements Specification**

---

## 1️⃣ Conceptual Model

### Goal

Create a **reliable, reusable, editable Voice Profile** for a creator/brand, generated from:

* Scraped Social Watcher normalized posts
* Manually attached posts
* Optionally selective subsets of posts
* Manual edits

Voice Profiles must:

* Be deterministic
* Explicitly structured
* Regeneratable
* Versionable (at least last-updated)
* Confidence-scored
* Traceable to source items

---

## 2️⃣ Data Model Extensions

### A. New Table: `voice_profiles`

This already exists but we formalize expectations.

Fields must hold:

| Field                | Purpose                              |
| -------------------- | ------------------------------------ |
| `id`                 | UUID                                 |
| `organization_id`    | Owner org                            |
| `user_id`            | Creator (optional / null for shared) |
| `name`               | Human readable profile name          |
| `traits (JSON)`      | canonical structured voice profile   |
| `confidence (float)` | 0 → 1                                |
| `sample_size (int)`  | number of posts analyzed             |
| `updated_at`         | regeneration timestamp               |
| `created_at`         | standard                             |
| `deleted_at`         | soft delete                          |

**Traits JSON Format (canonical requirement)**

```json
{
  "description": "Short punchy sentences, energetic, direct second-person address.",
  "tone": ["direct", "energetic", "confident"],
  "persona": "mentor",
  "formality": "casual",
  "sentence_length": "short",
  "paragraph_density": "tight",
  "pacing": "fast",
  "emotional_intensity": "high",
  "style_signatures": [
    "uses imperative commands",
    "frequent rhetorical questions",
    "2-3 sentence micro-paragraphs"
  ],
  "do_not_do": [
    "long academic paragraphs",
    "corporate language"
  ],
  "keyword_bias": ["execution","momentum","ownership"],
  "reference_examples": [
    "Stop planning. Start doing.",
    "If you’re not moving forward, you’re slipping backward."
  ]
}
```

*This schema must be enforced by builder service so traits are always structured.*

---

### B. New Linking Table

**`voice_profile_posts`**

Purpose: Define which Social Watcher items contribute to the profile.

| Column                  | Description                                     |          |           |         |          |
| ----------------------- | ----------------------------------------------- | -------- | --------- | ------- | -------- |
| `voice_profile_id`      | FK                                              |          |           |         |          |
| `normalized_content_id` | FK to `sw_normalized_content.id`                |          |           |         |          |
| `source_type`           | `twitter                                        | linkedin | instagram | youtube | generic` |
| `weight`                | optional override weighting                     |          |           |         |          |
| `locked`                | boolean (prevents automatic removal in pruning) |          |           |         |          |

Use case:

* Attach posts automatically via scraping
* Allow user to attach/detach posts
* Allow pinning key posts

---

## 3️⃣ Voice Profile Generation Pipeline

### Service

Create:

```
App\Services\Voice\VoiceProfileBuilderService
```

### Input

* voice_profile_id
* OR generation request with:

  * organization_id
  * optional filter constraints

### Steps

#### Step 1 — Collect Candidate Posts

Source:

```
sw_normalized_content
```

Profile may select sources by:

* All posts from specific `sw_source`
* Posts manually attached
* Optionally filters:

  * min engagement score
  * date window
  * top N ranking
  * remove replies
  * remove memes / images only posts

#### Step 2 — Clean & Prepare Text

For each post:

* extract `text`
* remove URLs
* strip hashtags unless meaningful
* normalize emoji
* drop empty posts

Store text batch in memory.

---

#### Step 3 — Build Prompt

Send posts to LLM in **batched summarization + consolidation pipeline** to avoid max token blowup.

Pipeline:

1. Chunk posts into 20–50 per batch
2. Extract *voice signals* per batch
3. Merge batch results into master analysis
4. Final consolidation prompt outputs canonical traits JSON

---

#### Step 4 — Confidence Calculation

Formula:

```
confidence =
  base_platform_weight
+ log(sample_size / min_required_samples)
- linguistic_noise_penalty
- diversity_penalty
```

Where:

* Twitter = high reliability
* LinkedIn = medium
* Instagram captions = medium-low
* YouTube transcript = high

Store:

* `sample_size`
* `confidence`

---

#### Step 5 — Persist

Store structured traits JSON into:

```
voice_profiles.traits
```

Update:

* `updated_at`
* `sample_size`
* `confidence`

---

## 4️⃣ Regeneration Rules

Voice Profile must always be **regeneratable.**

### Trigger Regeneration When:

* user manually adds posts
* new scraped posts attached
* user presses “Rebuild profile”
* background scheduled refresh (optional later)

---

## 5️⃣ User Control Requirements

We explicitly support user control:

### A. Attach Posts Manually

User can:

* Search normalized content
* Attach post to voice profile
* Mark some posts as locked anchor references

API:

```
POST /voice-profiles/{id}/attach-post
DELETE /voice-profiles/{id}/detach-post
```

---

### B. Automatic Attachment Mode

Optional modes:

```
mode = auto
  attach top N posts by engagement

mode = mixed
  auto + allow manual attach

mode = manual only
  only user attached posts count
```

Store in `voice_profile.settings`.

---

### C. Manual Edit Capability

Allow updates:

```
PATCH /voice-profiles/{id}
```

Editable:

* name
* traits JSON (free form but UI should sanity limit)
* lock certain attributes (future)

When user manually edits traits:

* mark profile as `user_modified = true`
* confidence remains but note "includes manual edits"

---

## 6️⃣ API Endpoints

### Create Profile

```
POST /voice-profiles
```

Payload:

```
{
  "organization_id": "...",
  "name": "Alex Hormozi Voice",
  "source": {
      "type": "social_watcher",
      "source_id": 123
  }
}
```

---

### Generate / Rebuild

```
POST /voice-profiles/{id}/rebuild
```

---

### Attach Post

```
POST /voice-profiles/{id}/posts
```

---

### Detach Post

```
DELETE /voice-profiles/{id}/posts/{normalized_content_id}
```

---

### Get Profile

```
GET /voice-profiles/{id}
```

Returns traits + stats.

---

### List Profiles

```
GET /voice-profiles
```

---

## 7️⃣ How This Integrates With Content Generator

No changes to generator required, only:

* Generator selects profile
* Inserts traits JSON
* Backend already supports:

  * `voice_profile_id`
  * inline override fallback

Voice becomes:

```
Template = structure authority
Swipe = performance / rhythm influence
Voice = persona + tone execution
```

Clear hierarchy preserved.

---

## 8️⃣ Failure Handling

If:

* zero usable posts
  → return error “insufficient data”

If:

* fewer than threshold
  → build voice but mark confidence low

If:

* LLM extraction fails
  → fallback to default neutral voice

---

## 9️⃣ Performance & Scaling Constraints

* batching required
* pagination required
* avoid sending 300 posts in one LLM request
* store LLM intermediate outputs for debugging optionally

---

## 🔥 Result

This gives you:

* reusable branded voice engines
* attachable datasets
* tunable personality control
* structured deterministic output
* confidence-based trust signal
* long-term maintainable system

And critically:
You didn’t build a hack — you built infra.
