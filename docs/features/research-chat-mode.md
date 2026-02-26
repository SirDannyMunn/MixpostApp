# Research Chat Mode

Research Chat Mode provides a read-only, analytical path for market and creative intelligence. It is implemented as a **shared research service** used by both the CLI command and chat API. No publishable content is created.

## Purpose and scope

Research Mode provides vector-backed market and creative intelligence grounded in real social content and research fragments. It is designed for learning and decision-making, not copywriting.

Hard boundaries:
- No drafts, posts, or CTA-style writing.
- No knowledge base promotion.
- No NotebookLM-style reasoning assistant behavior.
- Output is a strict JSON report schema (per stage).

## Architecture (January 2026 Refactor)

Research execution is now unified in a **shared core service** (`ResearchExecutor`) that both CLI and Chat call:

```
           ┌────────────────────┐
           │  ResearchExecutor  │  (Shared Core)
           │                    │
           │  - Deep Research   │
           │  - Angle & Hooks   │
           │  - Trend Discovery │
           └──────────┬─────────┘
                      │
              ┌───────┴────────┐
              │                │
         CLI Command        Chat API
              │                │
      CliFormatter    ChatFormatter
```

**Key principle:** Research behavior is defined once. Output formatting is separate.

### Entry points

**1) CLI Command** (Developer/Admin)
```bash
php artisan ai:research:ask "question" [options]
```

**2) Chat API** (User-Facing)
```
POST /api/v1/ai/chat
{
  "message": "question",
  "options": {
    "mode": "research",
    "research_stage": "deep_research|angle_hooks|trend_discovery"
  }
}
```

Both entry points call `ResearchExecutor::run()` and use stage-specific formatters.

### Classification (Single Source of Truth)

Research mode and stage classification is handled by **ResearchStageClassifier** - a config-driven service that automatically determines:
1. Whether a message requires research mode (vs generation)
2. Which research stage applies (deep_research, angle_hooks, or trend_discovery)

**Classification Flow:**
```
User Message
     ↓
ResearchStageClassifier
     ↓
{ isResearch, stage, confidence, matchedSignals }
     ↓
ResearchExecutor (if research)
     OR
ContentGeneratorService (if generation)
```

**Key files:**
- `config/research_stages.php` - Declarative stage definitions with keywords and signals
- `app/Services/Ai/Classification/ResearchStageClassifier.php` - Classification service
- `app/Services/Ai/Classification/ResearchStageDecision.php` - Classification result DTO

**Principles:**
- **Declarative over procedural** - Rules live in config, not if/else chains
- **Single classification call** - Fast and deterministic
- **Explainable** - Logs matched signals and confidence scores
- **Extensible** - New stages require config updates, not code rewrites

Users never manually select modes - the classifier auto-detects research intent from natural language.

### Key files

**Core Service:**
- `app/Services/Ai/Research/ResearchExecutor.php` - Unified research execution logic

**Data Transfer Objects:**
- `app/Services/Ai/Research/DTO/ResearchOptions.php` - Research configuration
- `app/Services/Ai/Research/DTO/ResearchResult.php` - Structured research output
- `app/Enums/ResearchStage.php` - Stage enum (deep_research, angle_hooks, trend_discovery)

**Formatters:**
- `app/Services/Ai/Research/Formatters/CliResearchFormatter.php` - CLI text output
- `app/Services/Ai/Research/Formatters/ChatResearchFormatter.php` - Chat JSON output

**Integration:**
- `app/Console/Commands/AiResearchCommand.php` - CLI command
- `app/Services/Ai/ContentGeneratorService.php` - Chat API integration

**Supporting Services:**
- `app/Services/Ai/Research/TrendDiscoveryService.php` - Trend detection
- `app/Services/Ai/Research/HookGenerationService.php` - Hook generation
- `app/Services/Ai/Research/ResearchReportComposer.php` - Deep research reports


## Research Stage Classification

### Overview

The **ResearchStageClassifier** is the single source of truth for determining research mode and stage. It automatically detects research intent from natural language without requiring manual mode selection.

### How it works

**1. Message Analysis**
- User sends a message (e.g., "analyze AI trends in marketing")
- Classifier scores the message against all configured research stages
- Each stage has keywords, question forms, imperatives, and phrases defined in config
- Scoring uses exact and partial matching with configurable weights

**2. Stage Selection**
- Highest-scoring stage above its confidence threshold wins
- If no stage qualifies, defaults to generation mode
- Classification decision includes matched signals for observability

**3. Automatic Routing**
- Research mode → `ResearchExecutor::run()`
- Generation mode → `ContentGeneratorService::generate()`
- No downstream overrides allowed (hard rule)

### Configuration

Stage definitions live in `config/research_stages.php`:

```php
'deep_research' => [
    'intent' => 'research',
    'description' => 'Evidence-based analysis and synthesis',
    'signals' => [
        'keywords' => ['research', 'analyze', 'investigate', ...],
        'question_forms' => ['why', 'how', 'whether'],
    ],
    'confidence_threshold' => 0.6,
],
```

**Tuning:**
- Add keywords to increase stage detection
- Adjust `confidence_threshold` to control sensitivity
- Modify `exact_match_weight` and `partial_match_weight` in defaults section
- Add new stages by creating new config blocks

### API Usage

**Automatic classification (recommended):**
```json
POST /api/v1/ai/chat
{
  "message": "what are people saying about AI tools?"
}
```
Classifier detects deep_research automatically.

**Explicit override (when needed):**
```json
{
  "message": "any message",
  "options": {
    "mode": "research",
    "research_stage": "trend_discovery"
  }
}
```

**Force generation mode:**
```json
{
  "message": "analyze this topic",
  "options": {
    "mode": "generate"
  }
}
```

### Classification Decision DTO

```php
ResearchStageDecision {
    isResearch: bool,
    stage: ?ResearchStage,
    confidence: float,
    matchedSignals: array,
    reason: string
}
```

**Example:**
```json
{
  "is_research": true,
  "stage": "deep_research",
  "confidence": 0.82,
  "matched_signals": ["research", "analyze", "what are people saying"],
  "reason": "Classified as deep_research based on signals: research, analyze, what are people saying"
}
```

### Logging

All classification decisions are logged to `ai.research.stage_decision`:

```php
Log::info('ai.research.stage_decision', [
    'message' => 'what trends are emerging?',
    'stage' => 'trend_discovery',
    'confidence' => 0.78,
    'signals' => ['trends', 'emerging'],
]);
```

Use these logs to:
- Monitor classification accuracy
- Tune confidence thresholds
- Identify misclassified queries
- Track research usage patterns

### Adding New Research Stages

**1. Define in config:**
```php
'custom_stage' => [
    'intent' => 'research',
    'description' => 'Your stage description',
    'signals' => [
        'keywords' => ['keyword1', 'keyword2'],
    ],
    'confidence_threshold' => 0.6,
],
```

**2. Add enum value:**
```php
// app/Enums/ResearchStage.php
case CUSTOM_STAGE = 'custom_stage';
```

**3. Implement executor method:**
```php
// app/Services/Ai/Research/ResearchExecutor.php
protected function runCustomStage(...): ResearchResult {
    // Implementation
}
```

**4. Update formatters:**
```php
// Add formatting logic for the new stage
protected function formatCustomStage(ResearchResult $result): mixed {
    // Format logic
}
```

### Benefits

**For Users:**
- Natural language interaction (no mode selection)
- Consistent research detection
- Clear reasoning for classifications

**For Developers:**
- Single source of truth (no scattered conditionals)
- Easy to tune and extend
- Version-controlled configuration
- Full audit trail

**For System:**
- Fast (single classification call)
- Deterministic (same input → same output)
- Debuggable (explicit matched signals)
- Type-safe (DTO-based)


## CLI Command Reference

The `ai:research:ask` command provides direct access to the research executor without HTTP overhead.

### Basic usage

```bash
php artisan ai:research:ask "your question here"
```

### Command signature

```bash
php artisan ai:research:ask {question}
  {--stage=deep_research : Stage: trend_discovery|deep_research|angle_hooks}
  {--industry= : Industry/topic seed for trend discovery}
  {--platforms= : Comma list of platforms (x,youtube,linkedin,instagram)}
  {--trend-limit=10 : Max trend candidates to return}
  {--hooks=5 : Hook count for angle_hooks stage}
  {--limit=40 : Max retrieved items for deep research}
  {--include-kb=false : Include knowledge base chunks (deep research only)}
  {--sources= : Filter sources: post,research_fragment}
  {--dump= : Diagnostic output: raw|clusters|snapshot|report}
  {--json : Output raw JSON instead of formatted text}
  {--trace : Verbose execution trace with timings}
```

### Examples

**Deep Research:**
```bash
# Basic research query
php artisan ai:research:ask "AI replacing SEO teams"

# With knowledge base and trace
php artisan ai:research:ask "content marketing trends" \
  --include-kb=true \
  --limit=60 \
  --trace
```

**Trend Discovery:**
```bash
# Discover trends in an industry
php artisan ai:research:ask "SaaS pricing" \
  --stage=trend_discovery \
  --industry="B2B SaaS" \
  --platforms=x,linkedin

# Recent platform-specific trends
php artisan ai:research:ask "video marketing" \
  --stage=trend_discovery \
  --platforms=youtube,instagram \
  --trend-limit=15
```

**Angle & Hooks:**
```bash
# Generate creative hooks
php artisan ai:research:ask "productivity tools for developers" \
  --stage=angle_hooks \
  --hooks=10
```

### Output formats

**Default (Formatted Text):**
```
Question: AI replacing SEO teams

Dominant claims:
  - AI tools are automating keyword research
  - Content quality still requires human oversight

Emerging angles:
  - Focus on AI-assisted, not AI-replaced workflows
```

**JSON Output:**
```bash
php artisan ai:research:ask "question" --json
```

Returns the structured report object as JSON.

**Diagnostic Dumps:**
```bash
# Raw retrieval data
--dump=raw

# Cluster analysis
--dump=clusters

# Snapshot metadata
--dump=snapshot

# Report only (JSON)
--dump=report
```

### Organization & User Resolution

The CLI command automatically resolves organization and user from your database:
1. Uses the most recent `OrganizationMember` record
2. Falls back to the first `Organization` and first `User`

For production use, you can extend the command to accept explicit `--org-id` and `--user-id` flags.

### Snapshot Storage

Deep research snapshots are automatically written to:
```
storage/app/ai-research/snapshot-{snapshot_id}.json
```

This enables post-analysis and debugging without database queries.


## Research Execution Flow

The research executor follows this unified flow for all stages:

### 1) Stage Routing

`ResearchExecutor::run()` routes to the appropriate stage handler:

- **Deep Research** → `runDeepResearch()` - Cluster analysis over existing content
- **Angle & Hooks** → `runAngleHooks()` - Creative hook generation
- **Trend Discovery** → `runTrendDiscovery()` - Platform trend detection

All stages:
- Create a unique run ID (`ULID`)
- Set batch logging mode
- Persist a generation snapshot
- Return a structured `ResearchResult` DTO

### 2) Classification (Deep Research & Angle Hooks only)

Uses the existing `PostClassifier` to identify:
- **Intent:** educational, persuasive, awareness, etc.
- **Funnel stage:** tof (top of funnel), mof, bof

This ensures consistency with content generation mode and shared observability.

Trend Discovery skips classification (not applicable to trend detection).

### 3) Retrieval

**Deep Research:**
- Calls `Retriever::researchItems()` for social posts and research fragments
- Optionally includes knowledge base chunks via `Retriever::knowledgeChunks()`
- Vector similarity search (with keyword fallback)
- Default limit: 40 items (configurable)

**Angle & Hooks:**
- Delegates to `HookGenerationService`
- Uses same retrieval + Creative Intelligence signals

**Trend Discovery:**
- Queries `sw_normalized_content` directly
- Time-windowed analysis (recent vs baseline)
- Keyword extraction and frequency analysis

Data sources:
- `sw_normalized_content` (posts and research fragments)
- `sw_normalized_content_embeddings` (vector embeddings)
- `sw_creative_units` (Creative Intelligence signals)
- `knowledge_chunks` (optional, deep research only)

### 4) Analysis

**Deep Research:**
- Clusters items by embedding similarity
- Extracts dominant claims, disagreements, saturated/emerging angles
- Selects representative excerpts

**Angle & Hooks:**
- Filters hooks by business relevance
- Applies Creative Intelligence recommendation logic
- Returns hooks with archetypes

**Trend Discovery:**
- Computes velocity ratios (recent vs baseline frequency)
- Ranks by velocity, engagement, and confidence
- Returns trend labels with evidence

### 5) Report Composition

All stages use dedicated prompt composers:
- `ResearchPromptComposer` (deep research)
- `HookGenerationService` internal prompts (angle hooks)
- No LLM call for trend discovery (statistical only)

Output is validated against strict JSON schemas.

### 6) Snapshot Persistence

All stages persist a `GenerationSnapshot` with:
- Original question
- Retrieved item IDs
- Classification (if applicable)
- Final report JSON
- Token usage and performance metrics
- Snapshot ID for replay

Snapshots enable:
- Audit trail
- Performance analysis
- Replay/regeneration
- Debugging

### 7) Response Formatting

The executor returns a `ResearchResult` DTO containing:
- Stage identifier
- Question
- Stage-specific data (claims, hooks, trends)
- Snapshot ID
- Metadata (run ID, intent, funnel stage)
- Debug info (if requested)

Formatters transform this into final output:
- **CLI:** Human-readable text via `CliResearchFormatter`
- **Chat:** JSON blocks via `ChatResearchFormatter`


## Research Stages

### Stage 1: Deep Research

**Purpose:** Cluster analysis of existing content to identify patterns, claims, and angles.

**Input:**
- Question/topic
- Optional: knowledge base inclusion
- Optional: media type filters (post, research_fragment)

**Process:**
1. Retrieve similar content via vector search
2. Cluster by semantic similarity
3. Extract dominant claims from clusters
4. Identify points of disagreement
5. Classify angles as saturated or emerging
6. Select representative excerpts

**Output Schema:**
```json
{
  "formatted_report": "## Dominant Claims\n\n- Claim 1\n- Claim 2\n\n## Points of Disagreement\n\n- Point 1\n\n## Emerging Angles\n\n- Angle 1\n\n## Saturated Angles\n\n- Angle 1\n\n## Sample Excerpts\n\n**[youtube]** _(confidence: 0.85)_\n\nExcerpt text here.\n\n",
  "raw": {
    "dominant_claims": ["string"],
    "points_of_disagreement": ["string"],
    "saturated_angles": ["string"],
    "emerging_angles": ["string"],
    "sample_excerpts": [
      {
        "text": "string",
        "source": "youtube|x|linkedin|instagram|other",
        "confidence": 0.85
      }
    ]
  }
}
```

**Formatted report structure:**
```markdown
## Dominant Claims

- Claim 1
- Claim 2

## Points of Disagreement

- Point 1

## Emerging Angles

- Angle 1
- Angle 2

## Saturated Angles

- Angle 1

## Sample Excerpts

**[youtube]** _(confidence: 0.85)_

Excerpt text here.

**[x]** _(confidence: 0.72)_

Another excerpt.
```

**CLI Example:**
```bash
php artisan ai:research:ask "AI content creation tools" --stage=deep_research
```

**Chat Example:**
```json
{
  "message": "AI content creation tools",
  "options": {
    "mode": "research",
    "research_stage": "deep_research",
    "retrieval_limit": 50,
    "include_kb": true
  }
}
```

### Stage 2: Angle & Hooks

**Purpose:** Generate creative hooks based on the question and Creative Intelligence signals.

**Input:**
- Question/prompt
- Hook count (default: 5)
- Business relevance filters

**Process:**
1. Classify intent and funnel stage
2. Retrieve relevant hooks from Creative Units
3. Filter by business relevance, noise risk
4. Apply persona and sophistication matching
5. Return top-ranked hooks with archetypes

**Output Schema:**
```json
{
  "formatted_report": "## Creative Hooks\n\n### Hook 1 - curiosity_gap\n\nHook text here.\n\n_Confidence: 0.85_\n\n### Hook 2 - problem_agitation\n\nAnother hook text.\n\n_Confidence: 0.78_\n\n",
  "raw": {
    "hooks": [
      {
        "hook_text": "Hook text here",
        "archetype": "curiosity_gap",
        "confidence": 0.85
      },
      {
        "hook_text": "Another hook text",
        "archetype": "problem_agitation",
        "confidence": 0.78
      }
    ]
  }
}
```

**Formatted report structure:**
```markdown
## Creative Hooks

### Hook 1 - curiosity_gap

Hook text here.

_Confidence: 0.85_

### Hook 2 - problem_agitation

Another hook text.

_Confidence: 0.78_
```

**CLI Example:**
```bash
php artisan ai:research:ask "productivity for remote teams" \
  --stage=angle_hooks \
  --hooks=10
```

**Chat Example:**
```json
{
  "message": "productivity for remote teams",
  "options": {
    "mode": "research",
    "research_stage": "angle_hooks",
    "hooks_count": 10
  }
}
```

### Stage 3: Trend Discovery

**Purpose:** Detect emerging trends by analyzing content frequency patterns over time.

**Input:**
- Query/topic
- Industry (optional, inferred if missing)
- Platforms (default: x, youtube)
- Time window (recent_days=7, days_back=30)

**Process:**
1. Extract keywords from query and industry
2. Query content within time window
3. Compute recent vs baseline frequency
4. Calculate velocity ratios
5. Rank by velocity and engagement
6. Return trend candidates with evidence

**Output Schema:**
```json
{
  "formatted_report": "## Trending Topics\n\n_Industry: Marketing Technology_\n\n### 1. AI video generation\n\nRecent posts (45 in last 7 days) outpaced baseline (12 in prior 23 days).\n\n**Evidence:**\n- Recent Posts: 45\n- Baseline Posts: 12\n- Velocity Ratio: 3.75\n- Avg Engagement: 156.5\n\n_Confidence: 0.85_\n\n",
  "raw": {
    "query": "string",
    "industry": "string",
    "trends": [
      {
        "trend_label": "string",
        "why_trending": "string",
        "evidence": {
          "recent_posts": 45,
          "baseline_posts": 12,
          "velocity_ratio": 3.75,
          "avg_engagement": 156.5
        },
        "confidence": 0.85
      }
    ]
  }
}
```

**Formatted report structure:**
```markdown
## Trending Topics

_Industry: Marketing Technology_

### 1. AI video generation

Recent posts (45 in last 7 days) outpaced baseline (12 in prior 23 days).

**Evidence:**
- Recent Posts: 45
- Baseline Posts: 12
- Velocity Ratio: 3.75
- Avg Engagement: 156.5

_Confidence: 0.85_
```

**CLI Example:**
```bash
php artisan ai:research:ask "AI video generation" \
  --stage=trend_discovery \
  --industry="Marketing Technology" \
  --platforms=youtube,x \
  --trend-limit=15
```

**Chat Example:**
```json
{
  "message": "AI video generation",
  "options": {
    "mode": "research",
    "research_stage": "trend_discovery",
    "research_industry": "Marketing Technology",
    "trend_platforms": ["youtube", "x"],
    "trend_limit": 15
  }
}
```


### Stage 4: Saturation & Opportunity

**Purpose:** Assess topic saturation and identify white space opportunities for content creation.

**Input:**
- Question/topic
- Platforms (default: x, youtube, linkedin)
- Time windows (recent_days=14, baseline_days=90)
- Retrieval limit (default: 60)

**Process:**
1. Retrieve content via vector search
2. Compute volume & velocity metrics
3. Analyze fatigue and repetition patterns
4. Calculate diversity indices (angles, hooks)
5. Aggregate quality signals
6. Identify saturated patterns (top 5)
7. Identify white space opportunities (top 7)
8. Compute opportunity score (0-100)
9. Generate recommendation (go/cautious_go/avoid)

**Output Schema:**
```json
{
  "formatted_report": "## Decision\n\n**Recommendation:** CAUTIOUS GO\n\nModerate opportunity...\n\n## Opportunity Score\n\n**68/100** _(confidence: 0.74)_\n\n...",
  "raw": {
    "topic": "string",
    "decision": {
      "recommendation": "go|cautious_go|avoid",
      "opportunity_score": 68,
      "confidence": 0.74,
      "summary": "string"
    },
    "signals": {
      "volume": {
        "recent_posts": 120,
        "baseline_posts": 430,
        "velocity_ratio": 1.8
      },
      "fatigue": {
        "avg_fatigue": 0.62,
        "repeat_rate_30d": 0.55
      },
      "diversity": {
        "angle_diversity": 0.41,
        "hook_diversity": 0.33
      },
      "quality": {
        "avg_noise_risk": 0.18,
        "avg_buyer_quality": 0.71
      },
      "persona_fit": {
        "top_personas": ["founder", "indie_hacker"],
        "mismatch_risk": 0.22
      }
    },
    "saturated_patterns": [
      {
        "pattern_type": "hook",
        "label": "curiosity_gap: AI replaces marketing teams",
        "evidence": {
          "item_count": 48,
          "repeat_rate_30d": 0.61,
          "fatigue_score": 0.78
        },
        "why_saturated": "High repetition and declining engagement",
        "example_ids": ["..."]
      }
    ],
    "white_space_opportunities": [
      {
        "angle": "operational playbooks",
        "why_open": "Low volume but high buyer quality",
        "recommended_formats": ["thread", "short_video"],
        "recommended_hook_archetypes": ["framework_reveal"],
        "risks": ["Requires specificity"],
        "evidence": {
          "item_count": 9,
          "avg_buyer_quality": 0.82
        },
        "example_ids": ["..."]
      }
    ],
    "risks": [
      {
        "risk": "algorithm fatigue",
        "severity": "medium",
        "mitigation": "Pair with new proof elements"
      }
    ],
    "evidence": {
      "representative_excerpts": [
        {
          "source": "youtube",
          "confidence": 0.81,
          "text": "...",
          "content_id": "..."
        }
      ]
    }
  }
}
```

**Formatted report structure:**
```markdown
## Decision

**Recommendation:** CAUTIOUS GO

Moderate opportunity but dominant patterns are saturated. Focus on white space angles.

## Opportunity Score

**68/100** _(confidence: 0.74)_

### Key Metrics
- Volume: 120 recent posts (velocity: 1.8x baseline)
- Fatigue: 62%
- Angle Diversity: 41%
- Avg Buyer Quality: 71%

## Why it's saturated

**Top saturated patterns:**

1. **curiosity_gap: AI replaces marketing teams**
   - High repetition and declining engagement
   - Evidence: 48 instances, 61% repeat rate

## White space to exploit

**Opportunities for differentiation:**

### 1. operational playbooks

Low volume but high buyer quality among posts

**Recommended hooks:** framework_reveal, authority_shortcut

_Evidence: 9 posts, 82% buyer quality_

## Risks & mitigations

**algorithm fatigue** _MEDIUM_
- Pair with new proof elements and specificity
```

**CLI Example:**
```bash
php artisan ai:research:ask "Is AI video content saturated?" \
  --stage=saturation_opportunity \
  --platforms=x,youtube \
  --limit=60
```

**Chat Example:**
```json
{
  "message": "Is AI video content saturated for SaaS founders?",
  "options": {
    "mode": "research",
    "research_stage": "saturation_opportunity",
    "retrieval_limit": 60,
    "platforms": ["x", "youtube", "linkedin"],
    "time_windows": {
      "recent_days": 14,
      "baseline_days": 90
    }
  }
}
```

**See also:** [docs/features/saturation-opportunity-implementation.md](saturation-opportunity-implementation.md) for detailed implementation notes.


## Chat API Integration

### Request format

```json
POST /api/v1/ai/chat
{
  "message": "your research question",
  "options": {
    "mode": "research",
    "research_stage": "deep_research|angle_hooks|trend_discovery|saturation_opportunity",
    
    // Deep research options
    "retrieval_limit": 40,
    "include_kb": false,
    "research_media_types": ["post", "research_fragment"],
    
    // Angle & hooks options
    "hooks_count": 5,
    
    // Trend discovery options
    "research_industry": "optional industry name",
    "trend_platforms": ["x", "youtube"],
    "trend_limit": 10,
    "trend_recent_days": 7,
    "trend_days_back": 30,
    
    // Saturation & opportunity options
    "platforms": ["x", "youtube", "linkedin"],
    "time_windows": {
      "recent_days": 14,
      "baseline_days": 90
    },
    "cluster_similarity": 0.75,
    "max_examples": 6,
    
    // Debug options
    "return_debug": false,
    "trace": false
  }
}
```

### Response format

```json
{
  "response": "Here is your research report",
  "report": {
    "formatted_report": "## Dominant Claims\n\n- AI is transforming...\n\n## Points of Disagreement\n\n- Traditional SEO...",
    "raw": {
      "dominant_claims": ["..."],
      "points_of_disagreement": ["..."],
      "emerging_angles": ["..."],
      "saturated_angles": ["..."],
      "sample_excerpts": []
    }
  },
  "metadata": {
    "mode": {
      "type": "research",
      "subtype": "deep_research"
    },
    "research_stage": "deep_research",
    "snapshot_id": "01JGXXX...",
    "run_id": "01JGYYY...",
    "intent": "research_analysis",
    "funnel_stage": "tof"
  }
}
```

**Response structure:**
- **`response`** - Human-readable message describing the result
- **`report.formatted_report`** - Markdown-formatted report string (ready for rendering)
- **`report.raw`** - Original structured data (stage-specific schema)
- **`metadata`** - Execution metadata and identifiers

**Frontend integration:**
```javascript
// Simple Markdown rendering - single source of truth
const markdown = response.report.formatted_report;
renderMarkdown(markdown);

// Or access raw data for custom processing
const claims = response.report.raw.dominant_claims;
```

### Integration with ContentGeneratorService

The chat API delegates to `ResearchExecutor` via `ContentGeneratorService`:

1. `ChatController` receives request with `mode=research`
2. Calls `ContentGeneratorService::generate()` with research options
3. `ContentGeneratorService::generateResearchReport()` creates `ResearchOptions` DTO
4. Calls `ResearchExecutor::run()` with question, stage, and options
5. `ResearchExecutor` returns `ResearchResult` DTO
6. `ChatResearchFormatter` formats result as Markdown + raw data
7. Response includes `formatted_report` (Markdown string) and `raw` (structured JSON)

**Single source of truth:**
All formatting logic is handled by `ChatResearchFormatter` on the backend. The frontend simply renders the `formatted_report` Markdown string without any parsing or transformation logic.

**File:** `app/Services/Ai/ContentGeneratorService.php`

### Write protection

Research mode enforces read-only behavior:
- Document edit classifier (`/api/v1/ai/classify-intent`) marks research intents as `write=false`
- Chat API blocks document mutations when `mode=research`
- No draft creation, validation, or publishing

**File:** `app/Http/Controllers/Api/V1/AiController.php`


## Data Transfer Objects (DTOs)

The refactored architecture uses DTOs for type safety and clarity:

### ResearchOptions

Encapsulates all research configuration:

```php
new ResearchOptions(
    organizationId: string,
    userId: string,
    limit: int = 40,                    // Retrieval limit
    includeKb: bool = false,            // Include knowledge base
    mediaTypes: array = [],             // Filter: post, research_fragment
    hooksCount: int = 5,                // Hook count for angle_hooks
    industry: string = '',              // Trend discovery industry
    platforms: array = [],              // Trend platforms
    trendLimit: int = 10,               // Max trend candidates
    trendRecentDays: int = 7,           // Recent window
    trendDaysBack: int = 30,            // Total lookback
    trendMinRecent: int = 3,            // Min recent posts
    returnDebug: bool = false,          // Include debug info
    trace: bool = false,                // Verbose trace
    folderIds: array = []               // Folder scope (future)
)
```

**Factory method:**
```php
ResearchOptions::fromArray($orgId, $userId, $options)
```

### ResearchResult

Structured output from research execution:

```php
new ResearchResult(
    stage: ResearchStage,               // Enum value
    question: string,
    dominantClaims: array = [],         // Deep research
    pointsOfDisagreement: array = [],   // Deep research
    emergingAngles: array = [],         // Deep research
    saturatedAngles: array = [],        // Deep research
    sampleExcerpts: array = [],         // Deep research
    hooks: array = [],                  // Angle hooks
    trends: array = [],                 // Trend discovery
    snapshotId: string = null,
    metadata: array = [],
    debug: array = []
)
```

**Conversion methods:**
- `toReport()` - Stage-specific report structure
- `toArray()` - Full serialization

### ResearchStage Enum

```php
enum ResearchStage: string
{
    case DEEP_RESEARCH = 'deep_research';
    case ANGLE_HOOKS = 'angle_hooks';
    case TREND_DISCOVERY = 'trend_discovery';
}
```

**Helper methods:**
- `ResearchStage::fromString(string)` - Parse from string
- `label()` - Human-readable label


## Formatters

Output formatting is decoupled from research logic:

### CliResearchFormatter

Formats `ResearchResult` for terminal output.

**Methods:**
- `format(ResearchResult)` - Main formatter (routes by stage)
- `formatTrace(ResearchResult, int $totalMs)` - Trace output

**Output:**
- Human-readable text
- Hierarchical structure with bullet points
- Confidence scores where applicable
- Platform/source labels for excerpts

**File:** `app/Services/Ai/Research/Formatters/CliResearchFormatter.php`

### ChatResearchFormatter

Formats `ResearchResult` for chat/API responses.

**Methods:**
- `format(ResearchResult)` - Stage-specific formatting (returns Markdown + raw data)
- `buildChatResponse(ResearchResult, string $message)` - Full chat payload

**Output:**
- **`formatted_report`** - Markdown-formatted string with headings and bullet points (single source of truth)
- **`raw`** - Original structured JSON data (for backward compatibility or programmatic access)
- Metadata for UI consumption
- Optional debug information

**Markdown formatting:**
- Headings use `##` for sections, `###` for subsections
- Claims, angles, and trends use bullet lists (`-`)
- Source labels and confidence scores use bold (`**[source]**`) and italic (`_confidence: 0.85_`)
- Excerpts and hooks include proper spacing for readability

**File:** `app/Services/Ai/Research/Formatters/ChatResearchFormatter.php`

### Adding Custom Formatters

To add a new output format (e.g., Markdown, CSV):

1. Extend or create formatter class
2. Implement `format(ResearchResult)` method
3. Route by `ResearchStage` enum
4. Transform DTO fields to target format

Example stub:
```php
class MarkdownResearchFormatter
{
    public function format(ResearchResult $result): string
    {
        return match ($result->stage) {
            ResearchStage::DEEP_RESEARCH => $this->formatDeepResearch($result),
            ResearchStage::ANGLE_HOOKS => $this->formatAngleHooks($result),
            ResearchStage::TREND_DISCOVERY => $this->formatTrendDiscovery($result),
        };
    }
}
```

## Configuration

Research-mode tuning is centralized in `config/ai.php`:

```php
'research' => [
    'candidate_limit' => env('AI_RESEARCH_CANDIDATE_LIMIT', 800),
    'cluster_similarity' => env('AI_RESEARCH_CLUSTER_SIMILARITY', 0.75),
],
```

**Environment variables:**
- `AI_RESEARCH_CANDIDATE_LIMIT` - Max items considered before filtering
- `AI_RESEARCH_CLUSTER_SIMILARITY` - Similarity threshold for clustering (0-1)


## Extending Research

### Adding a new research stage

1. **Add enum value:**
```php
// app/Enums/ResearchStage.php
case NEW_STAGE = 'new_stage';
```

2. **Add executor method:**
```php
// app/Services/Ai/Research/ResearchExecutor.php
protected function runNewStage(
    string $question,
    ResearchOptions $options,
    string $platform,
    string $runId,
    float $startTime
): ResearchResult {
    // Implementation
}
```

3. **Update routing:**
```php
// In ResearchExecutor::run()
$result = match ($stage) {
    ResearchStage::NEW_STAGE => $this->runNewStage(...),
    // ...existing stages
};
```

4. **Add formatter support:**
```php
// In formatters
protected function formatNewStage(ResearchResult $result): mixed {
    // Format logic
}
```

### Customizing retrieval

Override retrieval in a stage handler:

```php
protected function runCustom(...): ResearchResult
{
    $items = $this->retriever->customRetrieval(
        $options->organizationId,
        $question,
        $customParams
    );
    
    // Process items
    // Build ResearchResult
}
```

### Custom prompts

Create a dedicated prompt composer:

```php
class CustomPromptComposer
{
    public function compose(string $question, array $items): array
    {
        return [
            'system' => 'Your custom system prompt',
            'user' => 'Your custom user prompt with: ' . $question,
        ];
    }
}
```

Register and use in executor method.

## Creative Intelligence Integration

Creative Intelligence signals (hooks, angles, archetypes, personas) are integrated into research:

**Deep Research:**
- Signals are extracted from `sw_creative_units`
- Summarized and included in snapshot metadata
- Used for angle/hook identification in clusters

**Angle & Hooks:**
- Primary data source for hook generation
- Filters applied: business relevance, noise risk, buyer quality
- Persona and sophistication matching

**Trend Discovery:**
- Not applicable (statistical analysis only)

**Signals tracked:**
- Hook archetypes (curiosity_gap, problem_agitation, etc.)
- Emotional drivers (curiosity, urgency, aspiration)
- Audience personas
- Sophistication levels

**File:** `app/Services/Ai/Research/ResearchExecutor.php` (see `summarizeCreativeSignals()`)

## Snapshot Persistence

All research stages persist a `GenerationSnapshot` for auditability:

**Fields:**
- `organization_id`, `user_id`
- `platform` - Set to `not_applicable` for research
- `prompt` - Original question
- `classification` - Intent and funnel stage (if applicable)
- `content` - JSON-encoded report
- `snapshot` - Retrieved item IDs, context metadata
- `intent` - Top-level column for filtering
- `mode` - `{"type":"research","subtype":"stage_name"}`
- `token_usage`, `performance` - Observability metrics

**Benefits:**
- Replay research runs
- Compare results over time
- Debug retrieval quality
- Performance analysis
- Audit trail

**Files:**
- `app/Services/Ai/Generation/Steps/SnapshotPersister.php`
- `app/Models/GenerationSnapshot.php`

**Query snapshots:**
```php
GenerationSnapshot::where('mode->type', 'research')
    ->where('mode->subtype', 'deep_research')
    ->latest()
    ->get();
```


## Observability & Logging

Research execution is logged via `ContentGenBatchLogger`:

**Log location:**
- `storage/logs/researchLogs.json` (research-specific)
- Separate from generation logs for clarity

**Captured events:**
- `run_start` - Initial metadata (org, user, question, stage, options)
- `classification` - Intent/funnel results
- `retrieval` - Items retrieved, sources, timing
- `research_kb_error` - Knowledge base retrieval failures
- `snapshot_persisted` - Snapshot ID and metadata
- `run_end` - Final results and totals

**Debug mode:**

Enable with `--trace` (CLI) or `return_debug=true` (API):

```bash
php artisan ai:research:ask "question" --trace
```

Returns:
- Full item list with embeddings
- Cluster details
- Prompt payloads (system + user)
- Model used
- Token usage breakdown
- Timing splits (retrieval, composition, total)

**Performance monitoring:**

Key metrics tracked:
- `retrieval_ms` - Time to fetch and filter items
- `compose_ms` - Time for LLM call (deep research, angle hooks)
- `total_time_ms` - End-to-end execution
- `token_usage` - Prompt/completion tokens

**Guardrail logging:**

Each research run logs to `ai.research.guardrail`:

```php
Log::info('ai.research.guardrail', [
    'stage' => 'deep_research',
    'composer' => 'ResearchPromptComposer',
    'platforms' => ['x', 'youtube'],
    'items_considered' => 42,
    'timestamp' => '2026-01-12T14:30:00Z',
]);
```

Use for quality monitoring and alert thresholds.

## Testing & Validation

### CLI testing

Verify output consistency:

```bash
# Test deep research
php artisan ai:research:ask "test question" --stage=deep_research --trace

# Compare with snapshot
php artisan ai:research:ask "test question" --dump=snapshot
```

### Regression testing

1. Generate baseline snapshot
2. Make code changes
3. Re-run same question
4. Compare snapshot IDs and report structure

### Schema validation

All research stages validate output against strict schemas via `SchemaValidator`:
- `research_report` (deep research)
- `hook_generation` (angle hooks)
- Trend discovery (no LLM, no validation needed)

Invalid output triggers repair attempts or graceful degradation.

## Migration Notes

### From pre-January 2026 implementation

**What changed:**
- Research logic moved from `ContentGeneratorService` to `ResearchExecutor`
- New DTOs: `ResearchOptions`, `ResearchResult`
- New enum: `ResearchStage`
- Formatters separated: `CliResearchFormatter`, `ChatResearchFormatter`

**What stayed the same:**
- CLI command signature (backward compatible)
- Chat API request/response format
- Snapshot structure
- Retrieval logic
- Report schemas

**Why the refactor:**
- Eliminate duplicate logic between CLI and Chat
- Single source of truth for research behavior
- Easier testing and extension
- Type-safe configuration

**Deprecated methods** (no longer called):
- `ContentGeneratorService::handleTrendDiscovery()`
- `ContentGeneratorService::handleAngleHooks()`
- `ContentGeneratorService::mapResearchIntent()`
- `ContentGeneratorService::buildResearchSnapshotContext()`
- `ContentGeneratorService::logResearchGuardrail()`
- `ContentGeneratorService::summarizeCreativeSignals()`

These can be safely removed after migration verification.

## Troubleshooting

### Common issues

**Empty results:**
- Check `sw_normalized_content` has data for the time window
- Verify embeddings exist in `sw_normalized_content_embeddings`
- Lower similarity threshold in `config/ai.php`
- Increase `retrieval_limit`

**Trend discovery returns no trends:**
- Ensure platforms have recent content
- Check `trend_recent_days` and `trend_days_back` settings
- Verify `min_recent` threshold isn't too high
- Use `--trace` to see items considered

**Angle hooks return empty:**
- Verify `sw_creative_units` has relevant hooks
- Check business relevance filters aren't too strict
- Ensure classification matches available hooks

**Snapshot not persisted:**
- Check database connection
- Verify `generation_snapshots` table exists
- Look for `research_snapshot_error` in logs

### Debug workflow

1. Run with `--trace` flag (CLI) or `return_debug=true` (API)
2. Check `storage/logs/researchLogs.json` for errors
3. Inspect snapshot JSON in `storage/app/ai-research/`
4. Use `--dump=raw` to see retrieval results
5. Use `--dump=clusters` to verify clustering

### Performance optimization

**Slow retrieval:**
- Ensure `sw_normalized_content_embeddings` table is indexed
- Check `candidate_limit` setting (lower = faster)
- Consider reducing `retrieval_limit`

**Slow LLM calls:**
- Use faster model tier for research (`gpt-4o-mini` vs `gpt-4o`)
- Reduce retrieved items to trim prompt size
- Check network latency to LLM provider

**High costs:**
- Monitor token usage via snapshots
- Use smaller models for non-critical research
- Cache frequently-asked questions (future feature)

## Summary

Research Mode provides a unified, read-only analytical path for market intelligence:

**Architecture:**
- Shared core service (`ResearchExecutor`)
- Type-safe DTOs for configuration and results
- Decoupled formatters for CLI and Chat

**Three research stages:**
1. **Deep Research** - Cluster analysis of existing content
2. **Angle & Hooks** - Creative hook generation
3. **Trend Discovery** - Platform trend detection
4. **Saturation & Opportunity** - Topic saturation assessment and white space detection

**Dual entry points:**
- CLI command for developers/admins
- Chat API for end users

**Key principles:**
- Research logic defined once
- Output formats separated
- Full snapshot persistence
- Read-only guarantees

**Production-ready:**
- Schema validation
- Error handling
- Performance logging
- Debug modes

For implementation details, see source files referenced throughout this document.
