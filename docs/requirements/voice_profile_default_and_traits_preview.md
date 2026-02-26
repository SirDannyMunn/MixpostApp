Supreme leader,

Here is the **rewritten Backend Requirements Document**, updated to match your new decision:

* **NO backend auto-default voice behavior**
* Default voice is **frontend-applied only**
* Backend remains strictly explicit:
  *If no voice is passed → no voice is used.*

This keeps the system predictable, transparent, and controllable.

---

# Voice Profiles – Default (Frontend-Driven) & Traits Preview

## Backend Requirements Document — Revised

---

## 1. Objectives

1. Support **organization default voice profiles**, but as a *metadata flag* only.
2. Backend **MUST NOT automatically apply default voice** during generation.
3. Default behavior is enforced **exclusively on the front-end**:

   * Frontend attaches default voice reference if applicable
   * Users can remove it to run with **no voice profile**
4. Add `traits_preview` field for quick presentation in UI.
5. Ensure system remains:

   * deterministic
   * transparent
   * backward compatible
   * easy to debug

---

## 2. Current State (Voice Profiles)

Existing relevant fields:

* `id`
* `organization_id`
* `user_id`
* `name`
* `traits` (JSON)
* `confidence`
* `sample_size`
* `updated_at`
* `deleted_at`

Currently:

* No `is_default`
* No `traits_preview`

---

## 3. Organization Default Voice Profile (Metadata Only)

### 3.1. Functional Behavior

1️⃣ Organizations may have **zero or one** voice profile marked as default.

2️⃣ Backend **does NOT automatically apply** the default.

3️⃣ Backend responsibility:

* Store `is_default`
* Enforce 1-per-org invariant
* Return `is_default` in API responses

4️⃣ Frontend responsibility:

* On chat creation, retrieve profile list
* Detect `is_default === true`
* Auto-attach as a `voice` reference **visually**
* Allow users to:

  * Remove it
  * Choose a different profile
  * Use none

5️⃣ If frontend sends **no voice profile**, backend must:

* Assume **NO VOICE**
* Do not fallback
* Do not guess
* Do not auto-apply org default

Explicit = powerful.
Implicit = dangerous.

This is explicit-only.

---

### 3.2. Database Requirements

Add field:

* `is_default` boolean (default `false`)

**Rule:**
Per organization:

* At most 1 `voice_profiles.is_default = true AND deleted_at IS NULL`

If one is toggled `true`, backend must ensure all others become `false`.

If all are `false`, org simply has no default.

If default profile is soft-deleted:

* `is_default` is ignored naturally
* No automatic reassignment

---

### 3.3. API Requirements

There must be a way to:

* Set a profile as default
* Unset a profile as default

Example behavioral contract:

`PATCH /api/v1/voice-profiles/{id}`

Payload example:

```json
{
  "is_default": true
}
```

Behavior:

* Sets this profile to default
* Unsets all other org profiles

Unset:

```json
{
  "is_default": false
}
```

Behavior:

* Removes default from this profile
* Does NOT automatically assign another

Validation rules:

* Must belong to same org
* Cannot default deleted profiles
* Must respect uniqueness per org

---

## 4. Traits Preview

### 4.1 Purpose

Expose a concise, readable summary for UI menus.

Examples:

* `"Casual • Playful • Confident"`
* `"Formal • Analytical • Precise"`
* `"Punchy • Emotional • High-energy"`

---

### 4.2 Data Requirements

Add:

* `traits_preview` (string, nullable, max ~120 chars)

---

### 4.3 Preview Generation Rules

Generate following priority:

1️⃣ Use `traits.tone`

* Take first 3 adjectives
* Capitalize
* Join with `" • "`

Example:

```
["casual","playful","confident","boastful"]
→ "Casual • Playful • Confident"
```

2️⃣ If tone missing:
Use `traits.persona` trimmed

Example:

```
"tech-savvy digital marketer/entrepreneur"
→ "Tech-savvy digital marketer"
```

3️⃣ If persona missing:
Use truncated `traits.description`

4️⃣ If everything missing:
`traits_preview = null`

Hard character cap ~120.

---

### 4.4 Lifecycle Rules

* Must be computed when:

  * profile created
  * profile updated
  * traits changed

* Backfill:

  * Either during migration
  * Or lazily on first API load and then persist

Engineering choice; behavior requirements matter more.

---

## 5. API Contract

`GET /api/v1/voice-profiles`

Must now include:

```json
{
  "data": [
    {
      "id": "019b7170-7d3c-70a7-b29c-5d565e3898cd",
      "organization_id": "019b31e7-8ff9-73e4-ac74-f9b72214bc31",
      "name": "Jacky Chou",
      "traits": { ... },
      "traits_preview": "Casual • Playful • Confident",
      "is_default": true,
      "confidence": 0.25,
      "sample_size": 19,
      "updated_at": "2025-12-31T13:38:45Z"
    }
  ]
}
```

Frontend uses:

* `name`
* `traits_preview`
* `is_default`

---

## 6. Content Generation Behavior

This is **critical clarity**:

### Backend Voice Selection Logic

```
If request includes voice profile → USE IT
If request includes NO voice profile → USE NONE
Never auto-apply org default
Never auto-substitute
Never guess
```

This preserves:

* user autonomy
* fully explicit system
* transparent UX

---

### Snapshot Logging Requirement

When a voice is used:

Log:

* `voice_profile_id`
* `voice_source = "override_reference"`

If none is used:

* `voice_profile_id = null`
* `voice_source = "none"`

No `"org_default"` state exists now.

---

## 7. Backwards Compatibility

1️⃣ If `is_default` missing:

* Assume `false`.

2️⃣ If `traits_preview` missing:

* API returns null safely.
* Frontend can choose to:

  * display nothing
  * compute a temporary preview

3️⃣ If frontend still hasn’t implemented new UI:

* Nothing breaks.
* Existing voice override logic continues as-is.

---

## 8. Acceptance Criteria

Backend is complete when:

### Data Model

* `is_default` added
* `traits_preview` added
* uniqueness enforced logically

### Behavior

* Can set default
* Can unset default
* Does not auto-apply default
* Returns `traits_preview`
* Returns `is_default`

### Generation

* If voice passed → apply
* If none passed → none applied
* Snapshot shows explicit source

### Stability

* Existing flows unaffected
* No hidden behavior added

---

## 9. Summary Position

This architecture ensures:

* complete user control
* zero surprise behavior
* frontend UX freedom
* backend simplicity
* clean debugging
* long-term maintainability

This is the correct way to run defaults.
