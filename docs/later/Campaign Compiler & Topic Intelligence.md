# Campaign Compiler & Topic Intelligence — Requirements Document

## 1. Purpose

Define a system that transforms a user-stated **goal** into a fully researched, planned, and draft-generated **social media campaign**, equivalent to the preparatory and execution work of a competent human social media manager.

The system removes the research, strategy, and planning burden from users. Prompting is reduced to a final execution step against a pre-built intelligence layer.

---

## 2. Core Value Proposition

* Convert **"I have a goal" → "I have a campaign ready to approve"**
* Automate research, pattern discovery, planning, and context assembly
* Generate drafts grounded in real-world signals, facts, and proven structures
* Maintain human voice, emotional appropriateness, and narrative continuity

This system is not an AI writer. It is a **campaign compiler**.

---

## 3. Scope

### In Scope

* Workspace-level goal capture
* Automated external research (signals, not truth)
* Topic Intelligence pipelines
* Voice Profile creation and attachment
* Campaign Plan generation (multi-post, phased)
* Template and SwipeStructure selection
* Draft post generation
* Human approval, editing, and scheduling

### Out of Scope

* Autonomous publishing without approval
* Treating scraped competitor content as authoritative knowledge
* Real-time BI analytics
* Platform ToS enforcement guarantees

---

## 4. Key Concepts & Definitions

### WorkspaceGoal

Canonical, structured representation of what the user is trying to achieve.

### Topic Intelligence (TI)

Signal extraction system that turns Social Watcher data into topics, problems, angles, and hooks.

### Campaign Plan

A structured, time-ordered plan of posts (types, intent, CTA, channel) that defines what should be posted and when.

### Campaign Compiler

The orchestrated pipeline that produces campaign context, plans, and drafts from a WorkspaceGoal.

---

## 5. High-Level System Flow

```
Workspace Onboarding
  → Goal Definition
  → Automated Research (Social Watcher + TI)
  → Research Review (user curation)
  → Voice Profile Assembly
  → Campaign Plan Generation
  → Draft Post Generation
  → Human Review & Scheduling
```

---

## 6. Workspace Goal Capture

### Requirements

* Goal captured **once** per workspace or campaign
* Plain-language, outcome-focused UI
* Stored as structured data, not prompt text

### Required Fields

* Goal type (e.g. fundraising, product launch)
* Primary objective (e.g. donations, signups)
* Deadline
* Target platforms
* Target audience
* Optional personal motivation / context

### Non-Functional

* Changing the goal re-triggers the compiler pipeline
* Goal is never re-requested unless explicitly edited

---

## 7. Automated Research Phase

### 7.1 External Signal Collection

**Sources**

* Apify (Google SERP, LinkedIn, X, Facebook Groups)
* Optional: LLM-assisted search summaries (e.g. Perplexity-style)

**Output**

* `sw_normalized_content`
* Referenced URLs + excerpts only

### 7.2 Topic Intelligence Pipelines

Pipelines executed:

* Topic Demand Discovery
* Problem Mining
* Angle & Framing Analysis
* Format & Hook Pattern Detection

Outputs stored in `ti_*` tables as per Topic Intelligence spec.

---

## 8. Research Review (User Curation)

### UI Requirements

* Display ranked topics (clusters)
* Show:

  * Topic summary
  * Example posts (links + excerpts)
  * Problem statements
  * Dominant angles

### User Actions

* Approve / reject topics
* Exclude irrelevant signals

### Constraints

* No raw text editing
* No prompt exposure

Approved signals become eligible campaign context.

---

## 9. Voice Profile Assembly

### Modes

1. **Auto-suggested**

   * Past posts
   * Website content
   * Emails / documents

2. **Manual**

   * User-selected posts or pasted content

### Requirements

* Voice Profile is campaign-scoped by default
* Can be promoted to workspace-global
* Used as a hard constraint during generation

---

## 10. Campaign Plan Generation

### Purpose

Define *what* will be posted, *when*, and *why* before any text is generated.

### Output

A structured Campaign Plan containing:

* Duration
* Number of posts
* Per-post:

  * Day / order
  * Funnel stage (TOF / MOF / BOF)
  * Post type
  * Topic
  * Primary emotion
  * CTA
  * Channel

### UI

* Editable order and cadence
* Logic and structure are not freely editable

---

## 11. Template & Swipe Selection

### Automated Selection

For each campaign post:

* Select best-fit template (goal + stage matched)
* Attach 1–3 SwipeStructures
* Attach:

  * Voice Profile
  * Approved TopicBriefs
  * Business context
  * Prior campaign posts (continuity)

### User Interaction

* None required

---

## 12. Draft Post Generation

### Generation Requirements

* JSON-only LLM output
* Strict validation and repair
* Snapshot persisted (inputs, outputs, context)

### Outputs

* Draft posts created in Mixpost
* Status: `draft`

---

## 13. Draft Review & Scheduling

### UI

* Edit post text
* Regenerate individual posts
* Approve or discard
* Schedule via Mixpost

### Constraints

* No bulk auto-publish
* All posts require human approval

---

## 14. Topic Intelligence Integration

### Export Options

* Export TopicBrief → KnowledgeItem (note)
* Used as planning context, not factual truth

### Generator Usage

When generating posts:

* Retrieve relevant TopicBrief KnowledgeItems
* Combine with trusted KnowledgeItems
* Apply campaign and voice constraints

---

## 15. Data Integrity & Guardrails

* Scraped content treated as **signal only**
* No verbatim reuse of competitor content
* Excerpts + references stored, not full copies
* Emotional and CTA constraints enforced per goal

---

## 16. Non-Functional Requirements

* All pipelines idempotent
* Background jobs fully traceable
* Incremental refresh supported
* Clear separation:

  * Signal (Social Watcher / TI)
  * Truth (Knowledge ingestion)
  * Synthesis (Generator)

---

## 17. Success Criteria

* User can go from goal → approved campaign in under 30 minutes
* Campaign drafts show:

  * Consistent voice
  * Phase-aware structure
  * Domain-specific insight
* No prompt engineering required from user

---

## 18. Future Extensions (Not Required for MVP)

* Cross-campaign learning
* Trend alerts
* Performance feedback loops
* Multi-campaign orchestration

---

## 19. Summary

This system encodes the research, planning, and judgment of a competent social media manager into software.

Users provide goals. The system does the thinking. Generation becomes execution.
