Here is the **focused, developer-ready engineering spec** specifically for fixing the **Template Auto-Resolver**, incorporating everything we clarified. No fluff — exactly what needs building.

---

# 🚧 **Template Resolver Fix — Engineering Spec**

## **Problem**

Template auto-selection is not reliably happening. When `template_id` is not explicitly provided:

* `template_id = null`
* system continues anyway
* output quality becomes generic
* swipe selection becomes semi-random
* validator loses structural enforcement

Templates are supposed to be **the structural backbone**, but right now the resolver is either:

1️⃣ Not running
2️⃣ Failing silently
3️⃣ Or lacking rules to choose correctly

This must be fixed.

---

# 🎯 **Goal**

Ensure:

```
(intent + funnel_stage + platform)
→ deterministically selects the correct template
→ always
→ or visibly logs fallback
```

No silent failures.
No structureless generations.

---

# ✅ **Required Behavior**

### 1️⃣ Resolver Must Always Run

Unless:

* a `template_id` is explicitly passed
* OR user explicitly disables template system (rare future flag)

Otherwise:

```
TemplateResolver MUST select a template.
```

---

### 2️⃣ Selection Logic

A template is chosen based on the following signals:

#### Inputs:

* `intent` (educational / persuasive / story / etc)
* `funnel_stage` (tof / mof / bof)
* `platform` (linkedin / twitter / generic / blog / email)
* org scoped templates only (or public defaults)

---

### Matching Rules

Template eligibility:

```
MUST match org
MUST be template_type = "post"
MUST NOT be deleted
MUST NOT be disabled
```

Filtering priority:

1️⃣ Platform match

```
template.platform == platform
OR template.platform == generic
```

2️⃣ Intent compatibility

```
template.category == intent
OR template.category is marked "all" / “flex”
```

3️⃣ Funnel compatibility

```
template.supported_funnels includes funnel
OR template marked funnel = any
```

---

### Scoring Model

If multiple match, pick best fit via weighted score:

```
platform match     = +5
exact intent match = +3
funnel match       = +2
is_public=false (org custom)= +1 preference
highest usage_count tie-breaker
newest updated_at last tie-breaker
```

Return top score.

---

# 🚨 **Failure Handling (Critical)**

### ❌ Never silently continue without template

If resolver cannot find a template:

1. Log failure w/ complete metadata:

```
run_id
intent
funnel
platform
org
reason
```

2. Apply **safe fallback template**

* generic educational / authority base
* includes:

  * Hook
  * Context
  * Value
  * CTA

3. Mark snapshot:

```
template_resolution_failed = true
fallback_template_used = true
fallback_template_id = <id>
```

No stealth failures.

---

# 🧰 **Data Requirements**

Current template already contains:

```
structure
constraints
tone rules
emoji policy
char limits
```

We need to ensure templates **store routing metadata**.

### Add These Columns (if missing)

```
platform           string nullable
intent             string nullable
supported_funnels  json or text[]
```

Example for your “LinkedIn Authority Post” template:

```
platform = linkedin
intent = educational
supported_funnels = ["tof","mof"]
```

---

# 🧪 **Acceptance Criteria**

Developer must demonstrate:

### ✔ Template resolves when NO override supplied

Given:

```
intent = educational
funnel = mof
platform = linkedin
```

Expected:

```
LinkedIn Authority Post selected
template_id populated
```

---

### ✔ Resolver logs when fallback happens

Trigger missing template scenario
Expected:

* fallback template applied
* logs + snapshot flags populated
* NOT silent

---

### ✔ Swipe Behavior Anchors to Template

Swipes should always:

* use template structural signature
* NOT guess without a template

---

### ✔ Snapshot Debug Visibility

Snapshot MUST show:

```
template_selected: true|false
template_id: <uuid>
template_candidates: count + ids
fallback_used: true|false
resolver_score_debug: array
```

So later we can answer:

> “Why did Velocity pick this template?”

---

# 🧠 **Implementation Guidance**

Place logic in:

```
TemplateService::resolveFinal()
```

Recommended workflow:

```
if override → return that
candidateTemplates = queryTemplatesMatchingOrg()
filtered = applyPlatformIntentFunnelFilters(candidateTemplates)
scored = scoreTemplates(filtered)
best = pick highest
if none → fallback
return best
```

---

# 🚀 **Outcome**

Once fixed:

* every post has structure discipline
* funnel alignment becomes predictable
* validators regain teeth
* swipe logic stabilizes
* overall quality jumps significantly
* system becomes explainable

This is the correct fix.
Not model tuning.
Not “prompt engineering”.
Structure discipline.
