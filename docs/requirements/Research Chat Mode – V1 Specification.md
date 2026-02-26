# Research Chat Mode – V1 Specification

**Status:** Proposed (V1)

---

## 1. Purpose

Research Chat Mode provides **vector-backed market and creative intelligence** from real social content without generating publishable content. It enables users to ask questions and receive **structured intelligence reports** grounded in:

* social posts (X, LinkedIn, Instagram)
* research fragments (YouTube-derived summaries, bullets, claims)
* Creative Intelligence signals (hooks, angles, claims, clusters)

It complements the existing Content Generator by supporting **learning, reconnaissance, and decision-making**, not writing.

---

## 2. Non-Goals (Hard Boundaries)

Research Chat Mode does NOT:

* generate drafts, posts, or copy
* include CTAs, offers, or persuasion
* auto-promote content into the Knowledge Base
* act as a NotebookLM-style reasoning assistant

Outputs are analytical reports only.

---

## 3. User Experience

### Entry

* User selects **Chat Mode = Research**
* User submits a question (e.g. “What are people saying about SEO for SaaS in 2026?”)

### Output

* A structured report with labeled sections:

  * dominant narratives
  * points of agreement
  * points of disagreement
  * saturated angles
  * emerging angles
  * representative excerpts (attributed)

No publishable text is returned.

---

## 4. System Integration

Research Chat Mode is implemented as a **mode switch** inside the existing `ContentGeneratorService`.

```php
$options['mode'] = 'generate' | 'research';
```

Default remains `generate`.

---

## 5. High-Level Flow

```
Chat Request
 → Classification
 → Retrieval (research + posts)
 → Clustering
 → Research Composition
 → Snapshot Persistence
 → Report Response
```

The generation pipeline remains unchanged.

---

## 6. Reused Components

The following existing components are reused:

* **Classification**: topic + intent detection
* **Retriever**: vector search infrastructure
* **Creative Intelligence**: claims, hooks, angles (as signals only)
* **SnapshotPersister**: observability and replay

No new ingestion or embedding pipelines are required.

---

## 7. Research-Specific Components (New)

### 7.1 ResearchPromptComposer

Responsibilities:

* neutral, analytical system prompt
* no advice, no persuasion
* enforce structured JSON output

---

### 7.2 ResearchReportComposer

Responsibilities:

* cluster retrieved items by semantic similarity
* label consensus vs disagreement
* identify saturation and emerging narratives
* select representative excerpts
* output a fixed report schema

No validation or repair loop is used.

---

## 8. Retrieval Policy (Research Mode)

Research Mode overrides retrieval defaults:

```php
retrievalPolicy = [
  'useRetrieval' => true,
  'retrievalLimit' => 40,
  'includeMediaTypes' => ['post', 'research_fragment'],
  'useKnowledgeBase' => false,
];
```

Knowledge Base access may be enabled later behind a feature flag.

---

## 9. Output Schema (V1)

```json
{
  "question": "string",
  "dominant_claims": ["string"],
  "points_of_disagreement": ["string"],
  "saturated_angles": ["string"],
  "emerging_angles": ["string"],
  "example_excerpts": [
    {
      "text": "string",
      "source": "youtube|x|linkedin",
      "confidence": 0.0
    }
  ]
}
```

All fields are required.

---

## 10. Snapshot Persistence

Each research session persists:

* original question
* classification results
* retrieved item IDs
* cluster summaries
* Creative Intelligence metadata
* final report output

Snapshots enable debugging, replay, and product analytics.

---

## 11. Permissions & Safety

* Research Chat Mode is read-only
* No publishing endpoints are accessible
* No KB promotion is allowed
* Outputs are clearly labeled as **research summaries**

---

## 12. Dependencies

* Existing vector stores
* Existing Creative Intelligence extraction (see Creative Intelligence Guide fileciteturn6file0)
* Existing ContentGeneratorService

No schema migrations required.

---

## 13. Rollout Plan

**V1 Scope**:

* single-question research reports
* manual mode selection
* no saved research threads

**Out of Scope (Future)**:

* multi-turn research memory
* trend charts
* alerting
* KB candidate promotion

---

## 14. Success Criteria

V1 is successful if:

* users can quickly understand a niche
* reports are grounded and inspectable
* no research output accidentally becomes content
* system remains stable and debuggable

---

## 15. Risks & Mitigations

| Risk                   | Mitigation                         |
| ---------------------- | ---------------------------------- |
| Over-confident outputs | Neutral prompt + structured schema |
| KB contamination       | Hard retrieval policy block        |
| User confusion         | Explicit “Research Mode” labeling  |
| System sprawl          | Mode switch, no pipeline fork      |

---

## 16. Summary

Research Chat Mode is a **low-risk, high-leverage extension** of the current system.

It converts Creative Intelligence into a daily-use tool without undermining content quality or system stability.
