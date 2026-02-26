# **Business Context Intelligence — Engineering Spec**

Backend only
Integrates into existing:

* `ContentGeneratorService`
* `Retriever`
* `ContextAssembler`
* Snapshot + Debugging stack

---

## **Purpose**

Provide high-signal business context to AI outputs without dumping raw organization JSON.
Adds:

* Distilled Business Profile Snapshot
* Intelligent Retrieval Rules
* Token Budget Governance
* Stable Injection Ordering
* Failure-safe behavior
* Debugging + Replay visibility

No schema breaking. MVP but future-proof.

---

# **Core Behavior Summary**

When generating content:

1. Load Organization JSON (`organizations.settings`)
2. Build/refresh a distilled **Business Profile Snapshot**
3. Inject snapshot into context (VIP, non-prunable)
4. Optionally retrieve deeper business info when relevant
5. Respect a strict token budget
6. Log + snapshot everything for replay/debug
7. Never block generation if something fails

---

# **New Components**

---

## **1️⃣ BusinessProfileDistiller**

`app/Services/Ai/BusinessProfileDistiller.php`

### Responsibilities

* Convert messy org JSON into structured snapshot
* Compress to high-signal summaries
* Apply fallbacks if data missing
* Assign deterministic fields
* Version output for future upgrades

### Public API

```
BusinessProfileDistiller::build($organization): array
BusinessProfileDistiller::ensureSnapshot($organization): array
```

Returns canonical snapshot schema.

---

## **2️⃣ Snapshot Storage**

No migration required.
Stored here:

```
organizations.settings.business_profile_snapshot
```

### Snapshot Schema

```
{
  "snapshot_version": "v1",
  "summary": "...",
  "audience_summary": "...",
  "offer_summary": "...",
  "tone_signature": {
      "formality": "casual|neutral|formal",
      "energy": "high|medium|low",
      "emoji": true,
      "slang": false,
      "constraints": ["text"]
  },
  "positioning": ["points"],
  "key_beliefs": ["points"],
  "proof_points": ["points"],
  "safe_examples": [{ "short": "..." }],
  "bad_examples": [{ "short": "..." }],
  "facts": ["optional"]
}
```

---

## **3️⃣ Distillation Trigger Logic**

### Run distillation:

* When org settings updated
* OR lazily on first use
* OR missing snapshot
* OR snapshot_version mismatch

### Caching Rules

```
if snapshot missing -> generate
if snapshot_version != v1 -> regenerate
else use cached
```

---

# **Integration Into Existing Pipeline**

---

## **4️⃣ ContentGeneratorService Integration**

Modify pipeline:

```
- Classification
- Retrieval
- Template selection
- Voice selection
+ Business Profile Integration  <-- NEW HERE
- Context Assembly
- LLM
- Validation
```

### Add inside `ContentGeneratorService::generate`

```
$businessProfile = BusinessProfileService::resolveForOrg($orgId);
$options['business_profile'] = $businessProfile;
```

Also store in GenerationSnapshot.

---

## **5️⃣ Retriever Enhancements**

Modify `Retriever` (no breaking change):

Add:

```
public function businessProfileSnapshot($orgId)
```

Optionally:

```
public function deepBusinessContext($orgId, $intent, $funnel)
```

Rules described later.

---

## **6️⃣ ContextAssembler Changes**

Modify `ContextAssembler::build`

### New Category

```
business_profile (VIP, never pruned)
```

### Always inject:

* summary
* audience_summary
* offer_summary
* tone_signature

### Inject conditionally:

* positioning
* beliefs
* proof_points
* facts
* safe/bad examples (only long form)

---

# **Retrieval Decision Logic**

---

## **7️⃣ Default Behavior**

Always include:

* summary
* audience_summary
* offer_summary
* tone_signature

Cheap. High value.

---

## **8️⃣ Enable Business Facts When**

If:

* intent = persuasive
  OR
* funnel_stage = mof/bof
  OR
* options.use_business_facts=true

---

## **9️⃣ Enable Deep Retrieval When**

If:

* longform
* email
* landing page
* explicitly toggled
* snapshot lacks enough detail

Deep retrieval allowed to pull:

* positioning
* objections
* proof
* beliefs

---

# **Token Budget Governance**

---

## **10️⃣ Token Budget Rules**

Hard cap inside ContextAssembler:

```
max 400–800 tokens
```

Order of retention priority:

1️⃣ snapshot core
2️⃣ audience
3️⃣ tone signature
4️⃣ offer summary
5️⃣ positioning
6️⃣ beliefs
7️⃣ proof points
8️⃣ examples
9️⃣ extra facts

When budget exhausted → stop.

---

# **Prompt Integration Rules**

---

## **11️⃣ Injection Ordering**

Inside prompt:

```
SYSTEM
Template
Swipe Style
BUSINESS PROFILE SNAPSHOT   <-- HERE
Retrieved Knowledge
User Context
User Request
```

Template remains authoritative.
Swipes = rhythm only.
Snapshot = context guidance.
Never overrides user intent.

---

# **Failure Strategy**

---

## **12️⃣ Failure Handling**

If anything breaks:

* DO NOT FAIL GENERATION
* Log structured warning
* Skip feature
* Continue generating

Fallback content:

```
"business profile unavailable"
```

Still works.

---

# **Snapshot & Replay Logging**

---

## **13️⃣ GenerationSnapshot Additions**

Add fields:

```
business_profile_snapshot: { ... }
business_profile_used: true|false
business_profile_version: "v1"
business_context_tokens: int
business_retrieval_level: shallow|deep
```

---

# **Configuration**

---

## **14️⃣ New Config**

`config/business_context.php`

```
return [
  'snapshot_version' => 'v1',
  'token_cap' => 600,
  'auto_enable_facts' => true,
  'deep_context_intents' => ['persuasive','story'],
  'never_prune_fields' => ['summary','audience_summary','tone_signature'],
];
```

---

# **Safety & Guards**

* never blindly dump JSON
* never exceed budget
* never fail generation
* snapshot versioned
* traceable in replay
* deterministic output
* MVP ready, scalable later

---

# **Outcome**

This plugs into your system cleanly and gives you:

* Business-aware AI
* Token efficiency
* Deterministic structure
* Clean architecture
* Backward compatible
* Instant UX upgrade
* No DB migration required now

Exactly what you want.