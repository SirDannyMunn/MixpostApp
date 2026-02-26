Below is a **backend-only Requirements Document** that consolidates every architectural gap, UX concern, control need, and clarification we’ve discussed.
This is opinionated, pragmatic, and implementation-ready. No fluff.

---

# Backend Requirements Document

**Unified Content Generation Control & Swipe/Template Architecture**

---

## 1️⃣ Objectives

Modernise the content generator backend to:

1. Give users **explicit control** over what is used in generation.
2. Make the system **predictable and debuggable**, not a black box.
3. Formalise the relationship between:

   * **Templates (prescriptive skeleton)**
   * **Swipes (performance-influencing structural bias)**
4. Support both:

   * User-driven overrides
   * Intelligent automation defaults
5. Maintain resilience when data is missing (e.g., no matching swipes).
6. Preserve replayability, observability, and quality scoring.

This must improve:

* determinism
* UX clarity
* internal tuning speed
* product reliability

---

## 2️⃣ Core Principles

1️⃣ **Templates are authoritative**

* Always primary structure.
* Define required sections.
* Enable validation.

2️⃣ **Swipes are advisory**

* Optional performance bias.
* Never override template.
* Ignored when confidence low.

3️⃣ **User Controls Beat Automagic**

* Explicit user overrides win over classifier.
* But classifier remains valuable fallback.

4️⃣ **Graceful Degradation**

* Missing data never breaks generation.
* Output still works without swipes, facts, or chunks.

5️⃣ **Transparency**

* Everything used must be traceable.
* Everything must emit metrics.

---

## 3️⃣ Required Backend Enhancements

---

### ✅ A. Expand `options` Contract (Core Control Layer)

Extend generation request `options` to support:

#### 1) Intent / Funnel Overrides

```
options.intent = "educational|persuasive|emotional|story|contrarian"
options.funnel_stage = "tof|mof|bof"
```

Behavior:

* If user supplies → skip classifier entirely.
* If partially supplied → classifier fills missing piece.
* Store classifier result separately for analytics.

---

#### 2) Voice Profile Control

```
options.voice_profile_id = "uuid"
options.voice_inline = "text description"
```

Priority:

1. explicit voice_profile_id
2. inline voice hints
3. org default voice
4. fallback neutral

---

#### 3) Retrieval Controls

```
options.use_retrieval = true|false
options.retrieval_limit = int
```

If disabled:

* No semantic retrieval.
* Only explicit overrides + user context used.

---

#### 4) Business Facts Controls

```
options.use_business_facts = true|false
```

Default logic:

* true when persuasive or MOF/BOF
* false otherwise

---

#### 5) Swipe Behavior Controls

```
options.swipe_mode = "auto|none|strict"
options.swipe_ids = [uuid]
```

Behavior:

* `auto` → system selects swipes based on similarity
* `none` → skip swipes entirely
* `strict` → ONLY use provided swipe_ids

If strict + bad IDs → fallback = none, not failure.

---

#### 6) Template Override

Already partly supported. Formalize as:

```
options.template_id = uuid
```

Behavior:

* If provided → override selector.
* If missing → selector chooses.

---

#### 7) Token Budget

Expose:

```
options.context_token_budget = int
```

Default from config.
Required to surface for power users + tuning.

---

#### 8) Business Context Input

New field:

```
options.business_context = string
```

This:

* Is VIP context
* Never pruned
* Logged in snapshot

---

## 4️⃣ Swipe + Template Interaction Requirements

---

### Template Requirements

Template remains:

* prescriptive
* required
* deterministic

Backend enforcement:

* MUST ensure LLM follows template structure.
* MUST validate required sections exist.
* If failed → one repair attempt.

---

### Swipe Requirements

Swipe remains:

* optional
* advisory
* evidence-informed pattern

Priority guarantee:

* Must never override template section existence
* Must never change required order
* Must never conflict constraints

Prompt hierarchy MUST explicitly instruct:

```
Template structure is authoritative.
Swipe structures influence style, pacing, rhetorical rhythm only.
Do not contradict or override the template.
```

---

## 5️⃣ Swipe Selection Logic Requirements

---

### Inputs

* template section structure
* swipe.structure
* intent
* funnel stage
* platform (optional)

---

### Similarity Mechanism (Mandatory)

Do NOT use embeddings.

Use deterministic symbolic logic:

**Base**

* Extract list of section names from template + swipe.
* Compute Jaccard similarity:

```
score = intersection / union
```

**Ordering Bonus**

* weight if matching order alignment

**Critical Matches Bonus**

* additional boost if:

- Hook aligns
- CTA aligns

**Funnel/Intent Fit**

* boost if funnel matches
* boost if intent matches

**Confidence Weight**
Multiply by swipe.confidence

---

### Selection Rules

1. Compute similarity score for candidates
2. Sort descending
3. Take top N swipes (config default: 2)
4. Discard if below configurable similarity threshold
5. If nothing passes threshold → use no swipes

Requirement:

* No errors if nothing matches
* No fallback to random swipes
* No user experience change

---

## 6️⃣ Failure Mode Requirements

---

### If classifier fails

Fallback default intent/funnel.

---

### If no retrieval

Still generate with:

* template
* prompt
* voice
* business context if present

---

### If no swipe match

Proceed template-only.

---

### If template missing

This should **never** happen for runtime features.
If does, throw failure + safe API response.

---

## 7️⃣ Snapshot & Replay Requirements

Snapshots must now include:

* template_id used
* explicit options overrides
* whether swipes used
* swipe_mode settings
* swipe_ids used
* similarity scores
* why swipes rejected (if applicable)
* voice source
* retrieval enabled flag
* business facts enabled flag
* business_context
* full token breakdown per category
* final classification + note if overridden

Replay must:

* Allow override of all these values
* Reproduce result deterministically when not overridden

---

## 8️⃣ Quality & Observability Requirements

System must log:

### Metrics

* content quality score
* template adherence
* swipe influence on quality
* length compliance
* emoji compliance

### Debug Logs

* context pruning report
* missing swipe report
* similarity scores
* selected vs rejected swipes
* prompt version hash

---

## 9️⃣ Public API Stability Guarantees

Backend must ensure:

* API remains backward compatible
* New options default to existing behavior
* No breaking UX changes required in frontend

If user supplies nothing:

* System behaves as today
* But smarter + more controllable when requested

---

## 🔥 Strategic Outcomes This Delivers

After implementing:

* Product becomes **predictable**
* Developers can **experiment safely**
* Power users get **real control**
* Normal users stay **protected from complexity**
* Architecture becomes **future-proof**
* Swipes & templates coexist logically and clearly

This turns your stack into a genuinely professional AI writing engine rather than a fancy wrapper.
