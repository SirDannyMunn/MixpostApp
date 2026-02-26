# Skip-Ingest Pipeline Mode - Visual Guide

## Command Flow

```
┌─────────────────────────────────────────────────────────────┐
│  php artisan social-watcher:pipeline:test --skip-ingest    │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
              ┌──────────────────────┐
              │ PipelineTestCommand  │
              │   handle() method    │
              └──────────┬───────────┘
                         │
                 ┌───────┴───────┐
                 │ skipIngest?   │
                 └───┬───────┬───┘
                     │       │
                 YES │       │ NO
                     │       │
                     ▼       ▼
        ┌─────────────────┐ ┌───────────────────┐
        │handleReprocess  │ │ runProfile()      │
        │Mode()           │ │ (normal pipeline) │
        └────────┬────────┘ └───────────────────┘
                 │
                 ▼
    ┌────────────────────────────┐
    │ ReprocessContentNodes      │
    │   reprocess() method       │
    └────────────┬───────────────┘
                 │
                 ▼
    ┌────────────────────────────┐
    │ selectContentNodes()       │
    │ • content_scope filter     │
    │ • since date filter        │
    │ • source_id filter         │
    └────────────┬───────────────┘
                 │
                 ▼
         ┌───────────────┐
         │ Content Nodes │
         └───────┬───────┘
                 │
        ┌────────┼────────┐
        │        │        │
        ▼        ▼        ▼
    ┌─────┐  ┌────────┐  ┌──────────┐
    │Posts│  │Comments│  │Transcripts│
    └──┬──┘  └───┬────┘  └────┬─────┘
       │         │            │
       ▼         ▼            ▼
    ┌──────────────────────────────────┐
    │   Job Dispatch (in order)        │
    │                                  │
    │  1. AnnotateCreative (posts)     │
    │  2. AnnotateTopic (comments)     │
    │  3. GenerateFragments (trans)    │
    │  4. GenerateEmbeddings (all)     │
    │  5. ClusterAnnotations (opt)     │
    └──────────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────┐
        │ Intelligence     │
        │ Regenerated      │
        └──────────────────┘
```

## Data Flow

```
┌──────────────────────────────────────────────────────────┐
│                    EXISTING DATA                         │
│  ┌────────────────────────────────────────────────┐    │
│  │         sw_content_nodes (canonical)           │    │
│  │  • id                                          │    │
│  │  • text                                        │    │
│  │  • content_type (post/comment/transcript)      │    │
│  │  • platform                                    │    │
│  │  • published_at                                │    │
│  └─────────────────┬──────────────────────────────┘    │
└────────────────────┼───────────────────────────────────┘
                     │
                     │ Skip-ingest reads from here
                     │ (never mutates)
                     │
                     ▼
┌──────────────────────────────────────────────────────────┐
│              INTELLIGENCE REGENERATION                   │
│                                                          │
│  ┌────────────────────────────────────────────┐        │
│  │      sw_content_annotations (new/updated)   │        │
│  │  • content_node_id → content_nodes.id       │        │
│  │  • annotation_type: 'creative' | 'topic'    │        │
│  │  • payload: { ... intelligence data ... }   │        │
│  │  • created via AnnotateCreative/Topic jobs   │        │
│  └────────────────────────────────────────────┘        │
│                                                          │
│  ┌────────────────────────────────────────────┐        │
│  │         sw_embeddings (new/updated)         │        │
│  │  • content_node_id → content_nodes.id       │        │
│  │  • vector: [float array]                    │        │
│  │  • model: text-embedding-3-small            │        │
│  │  • created via GenerateEmbedding jobs        │        │
│  └────────────────────────────────────────────┘        │
│                                                          │
│  ┌────────────────────────────────────────────┐        │
│  │      sw_annotation_clusters (optional)      │        │
│  │  • cluster_id                               │        │
│  │  • annotation_ids: [uuid array]             │        │
│  │  • created via ClusterAnnotations job        │        │
│  └────────────────────────────────────────────┘        │
└──────────────────────────────────────────────────────────┘
```

## Content Type Routing

```
┌──────────────────────────────────────────────────────┐
│            Content Node Selection                    │
│  (based on --content-scope flag)                     │
└──────────────────┬───────────────────────────────────┘
                   │
       ┌───────────┼───────────┐
       │           │           │
       ▼           ▼           ▼
┌──────────┐ ┌──────────┐ ┌──────────────┐
│  Posts   │ │ Comments │ │ Transcripts  │
│          │ │          │ │              │
│ content_ │ │ content_ │ │ content_type │
│ type =   │ │ type =   │ │    =         │
│ 'post'   │ │ 'comment'│ │ 'transcript' │
└────┬─────┘ └────┬─────┘ └──────┬───────┘
     │            │               │
     ▼            ▼               ▼
┌──────────┐ ┌──────────┐ ┌──────────────┐
│Annotate  │ │Annotate  │ │Generate      │
│Creative  │ │Topic     │ │Derived       │
│          │ │          │ │Fragments     │
│Job       │ │Job       │ │Job (future)  │
└────┬─────┘ └────┬─────┘ └──────┬───────┘
     │            │               │
     ▼            ▼               ▼
┌──────────────────────────────────────────┐
│         Creative         Topic    Derived│
│      Annotations     Annotations Fragments│
│                                           │
│  • Hook analysis    • Topic extract  • Summaries│
│  • Angle detection  • Sentiment     • Claims   │
│  • CTA extraction   • Signal score  • Insights │
│  • Buyer quality    • Classification         │
└───────────────────────────────────────────┘
```

## Idempotency Flow

```
┌─────────────────────────────┐
│  Annotation Job Dispatched  │
└──────────────┬──────────────┘
               │
               ▼
        ┌──────────────┐
        │ hasAnnotation│
        │   check?     │
        └──────┬───────┘
               │
       ┌───────┴────────┐
       │                │
    YES│             NO │
       │                │
       ▼                ▼
┌─────────────┐  ┌──────────────┐
│Return       │  │Create new    │
│existing     │  │annotation    │
│annotation   │  │              │
│             │  │(idempotent   │
│NO DUPLICATE │  │ upsert)      │
└─────────────┘  └──────────────┘


┌─────────────────────────────┐
│  Embedding Job Dispatched   │
└──────────────┬──────────────┘
               │
               ▼
        ┌──────────────┐
        │ embedding    │
        │  exists?     │
        └──────┬───────┘
               │
       ┌───────┴────────┐
       │                │
    YES│             NO │
       │                │
       ▼                ▼
┌─────────────┐  ┌──────────────┐
│Skip         │  │Generate new  │
│generation   │  │embedding     │
│             │  │              │
│NO DUPLICATE │  │(idempotent)  │
└─────────────┘  └──────────────┘
```

## Filter Combinations

```
┌────────────────────────────────────────────────────┐
│  Available Filters (combinable)                    │
│                                                    │
│  --content-scope    Filter by content type         │
│  --since           Filter by creation date         │
│  --source-id       Filter by source                │
│                                                    │
│  Examples:                                         │
│  • --content-scope=posts --since=2026-01-01       │
│  • --source-id=<uuid> --content-scope=comments    │
│  • --since=2026-01-10 --source-id=<uuid>          │
└────────────────────────────────────────────────────┘

Query Building:
┌──────────────────────────────────────┐
│ SELECT * FROM sw_content_nodes       │
│ WHERE 1=1                            │
│   AND content_type IN (...)  ◄─ scope│
│   AND created_at >= ?        ◄─ since│
│   AND source_id = ?          ◄─ source│
│ ORDER BY created_at ASC              │
└──────────────────────────────────────┘
```

## Comparison: Before vs After

```
BEFORE (Scraper-Dependent)
═══════════════════════════

┌─────────┐
│ Change  │
│ Logic   │
└────┬────┘
     │
     ▼
┌─────────────────┐
│ Must Re-scrape  │ ◄── API calls, quota, risk
│ Everything      │
└────┬────────────┘
     │
     ▼
┌─────────────────┐
│ Regenerate      │
│ Intelligence    │
└─────────────────┘

Time: SLOW (scraping + processing)
Cost: HIGH (API quota)
Risk: HIGH (data loss)


AFTER (Content-Driven)
═════════════════════

┌─────────┐
│ Change  │
│ Logic   │
└────┬────┘
     │
     ▼
┌─────────────────┐
│ Reprocess       │ ◄── No scraping
│ --skip-ingest   │
└────┬────────────┘
     │
     ▼
┌─────────────────┐
│ Regenerate      │
│ Intelligence    │
└─────────────────┘

Time: FAST (processing only)
Cost: LOW (CPU only)
Risk: NONE (content preserved)
```

## Exit Codes

```
┌────────────────────────────────────┐
│        Command Exit Codes          │
│                                    │
│  0  ✅ Success                     │
│     • All jobs dispatched          │
│     • No errors                    │
│     • Format verification passed   │
│                                    │
│  1  ❌ Failure                     │
│     • No content nodes found       │
│     • Job dispatch errors          │
│     • Format verification failed   │
└────────────────────────────────────┘
```

---

**Visual Guide Complete**  
Covers all major flows and architecture decisions
