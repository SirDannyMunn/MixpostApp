# CI Opportunity Classifier

## Purpose

Automatically evaluate incoming Creative Intelligence signals and answer a single question:

> **“Given my goals and content strategy, is this something I should talk about?”**

This system does *not* generate content. It **filters, ranks, and explains opportunities** so the creator only reacts to high‑signal ideas.

---

## Why This Is a Good Idea (Strategic Fit)

You already have:

* Social signal ingestion (Social Watcher)
* Normalized content
* CreativeUnit extraction
* Creative Intelligence (CI) inference

What’s missing is **goal alignment**.

This feature closes the loop:

```
Signal → CI → Goal Fit → Opportunity
```

Instead of asking users to browse feeds or dashboards, the system answers:

* Relevant vs noise
* On‑strategy vs off‑strategy
* Now vs later

---

## Core Concept

### CI Opportunity

A CI Opportunity is a **CreativeUnit + Fit Evaluation**.

It represents *potential* content, not output.

---

## High‑Level Flow

```
New NormalizedContentItem
 → CreativeUnit extracted
 → CI analysis (existing)
 → Opportunity Classification
 → Ranked Opportunity Feed
```

---

## Inputs

### 1. Creative Intelligence (existing)

* Hook archetype
* Angle
* Format
* Emotion
* Offer / CTA type
* Engagement signals

### 2. Workspace Strategy Profile (new)

Structured, not prompt‑text.

```json
{
  "primary_goals": ["authority", "leads"],
  "core_topics": ["SEO", "AI workflows"],
  "disallowed_topics": ["celebrity", "sports"],
  "preferred_formats": ["thread", "long-form"],
  "preferred_angles": ["contrarian", "framework"],
  "cta_rules": {
    "allow_hard_sell": false
  },
  "tone_constraints": ["educational", "authoritative"]
}
```

---

## Classification Output

### Opportunity Evaluation

```json
{
  "is_relevant": true,
  "fit_score": 0.82,
  "reasons": [
    "Matches core topic: SEO",
    "High-performing hook archetype",
    "Format aligns with thread preference",
    "Angle matches contrarian strategy"
  ],
  "risks": [
    "CTA too aggressive for authority-first goal"
  ],
  "suggested_action": "add_to_idea_queue"
}
```

This is explainable and auditable.

---

## UI Manifestation

### 1. Inbox-Style Opportunity Feed

Each item shows:

* Hook (normalized)
* Angle + format badges
* Fit score
* “Why this matters” (1–2 lines)

Actions:

* ✅ Approve
* ❌ Ignore
* 🕒 Save for later

---

### 2. CI Dashboard Integration

Add a new tab:

> **Opportunities for You**

Sorted by:

* Fit score
* Engagement velocity
* Recency

---

## Engineering Notes

### Storage

New table:

`ci_opportunities`

* `creative_unit_id`
* `workspace_id`
* `fit_score`
* `classification`
* `reasons`
* `risks`
* `status`

---

### Job Execution

* Runs async on new CreativeUnit
* Re‑runs when:

  * Workspace strategy changes
  * CI model updates

Idempotent by `(creative_unit_id, workspace_id)`

---

## Guardrails

* This system **never publishes or generates content**
* It only recommends
* User always decides

---

## Why This Is Powerful (No Hype)

This turns your product into:

> **A personal content radar**

Instead of:

* “What should I post today?”

The user gets:

* “Here are 3 things worth talking about this week.”

---

## Relationship to Campaign Compiler

* Approved Opportunities → Campaign Brief candidates
* Campaign Compiler consumes *only approved opportunities*

This prevents garbage-in, garbage-out.

---

## Verdict

This idea is:

* Aligned with your architecture
* Low risk
* High leverage
* Natural extension of CI

It adds **judgment**, not automation.

That’s exactly what your system should do.
