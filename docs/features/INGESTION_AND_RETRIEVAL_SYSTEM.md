# Content Ingestion and Retrieval System Documentation

**Version:** 2.0 (Knowledge Compiler)  
**Last Updated:** January 7, 2026  
**Status:** Staged for deployment

---

## Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Ingestion Pipeline](#ingestion-pipeline)
4. [Knowledge Compilation](#knowledge-compilation)
5. [Chunking System](#chunking-system)
6. [Retrieval System](#retrieval-system)
7. [Data Models](#data-models)
8. [Configuration](#configuration)
9. [API Endpoints](#api-endpoints)
10. [Deployment Notes](#deployment-notes)

---

## Overview

The Content Ingestion and Retrieval System is a sophisticated pipeline for processing unstructured content (bookmarks, posts, documents) into semantic knowledge chunks that can be efficiently retrieved and used for AI content generation.

### Key Features

- **Semantic Normalization**: LLM-powered extraction of knowledge claims from raw text
- **Multi-Strategy Chunking**: Format-aware content parsing (lists, posts, plain text)
- **Role-Based Filtering**: Chunks classified by semantic role (strategic_claim, metric, instruction, etc.)
- **Vector Search**: pgvector-powered semantic retrieval with composite scoring
- **Domain Awareness**: Open-vocabulary domain tagging for context-specific retrieval
- **Enrichment Retrieval**: Supplementary retrieval of related metrics/instructions
- **Traceability**: Full audit trail from source → normalized claim → chunk → embedding

---

## System Architecture

```
┌─────────────────┐
│ Content Sources │ (Bookmarks, API, Manual Entry)
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│         ProcessIngestionSourceJob               │
│  - Source validation                            │
│  - Deduplication check                          │
│  - Quality scoring                              │
│  - Creates KnowledgeItem                        │
└────────┬────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│      NormalizeKnowledgeItemJob (Phase 1)        │
│  - Candidate extraction                         │
│  - Semantic gating                              │
│  - LLM normalization                            │
│  - Artifact generation                          │
│  → Stores in normalized_claims JSON field       │
└────────┬────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│       ChunkKnowledgeItemJob (Phase 2)           │
│  - Preflight validation                         │
│  - Format detection                             │
│  - Strategy selection                           │
│  - Chunk generation                             │
│  → Creates KnowledgeChunk records               │
└────────┬────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│       EmbedKnowledgeChunksJob (Phase 3)         │
│  - Vector embedding (batch processing)          │
│  - pgvector storage                             │
│  → Enables semantic search                      │
└────────┬────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│  ExtractVoiceTraitsJob & ExtractBusinessFacts   │
│  (Phase 4 - Optional)                           │
└─────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│            Retrieval System                     │
│  - Vector search                                │
│  - Domain/role filtering                        │
│  - Composite scoring                            │
│  - Enrichment retrieval                         │
└─────────────────────────────────────────────────┘
```

---

## Ingestion Pipeline

### Entry Points

#### 1. API Ingestion (`KnowledgeItemController::store`)
Direct POST to `/api/v1/knowledge-items` with validated payload:

```php
[
    'type' => 'note|idea|draft|excerpt|fact|transcript|email|doc|url|offer|post|custom',
    'source' => 'manual|upload|chrome_extension|integration|bookmark|transcript|notion|import|post',
    'title' => 'Optional title',
    'raw_text' => 'Content (50-200,000 chars)',
    'metadata' => [], // Optional
    'source_id' => 'UUID', // Optional
    'source_platform' => 'twitter|linkedin|etc' // Optional
]
```

**Pipeline**: Async job chain (normalize → chunk → embed → extract)

#### 2. Bookmark Ingestion (`ProcessIngestionSourceJob`)
Triggered when bookmarks are processed:

```php
ProcessIngestionSourceJob::dispatch($ingestionSourceId)
```

**Features**:
- Deduplication by `raw_text_sha256`
- Quality scoring
- Folder attachment
- Sync mode support (`--sync` flag for testing)

### Job Chain

```php
Bus::chain([
    new NormalizeKnowledgeItemJob($itemId),
    new ChunkKnowledgeItemJob($itemId),
    new EmbedKnowledgeChunksJob($itemId),
    new ExtractVoiceTraitsJob($itemId),
    new ExtractBusinessFactsJob($itemId),
])->dispatch();
```

**Note**: `ClassifyKnowledgeChunksJob` has been removed in this version.

---

## Knowledge Compilation

### NormalizeKnowledgeItemJob

**Purpose**: Convert raw text into structured, retrieval-ready knowledge claims.

#### Workflow

1. **Idempotency Check**
   - Hash raw text (`sha256`)
   - Skip if already normalized with same hash

2. **Candidate Extraction** (`KnowledgeCompiler::extractCandidates`)
   - Split into paragraphs
   - Handle long paragraphs (split at ~1600 chars)
   - Return up to 20 blocks

3. **Semantic Gating** (`KnowledgeCompiler::gate`)
   - Minimum 12 non-URL tokens
   - Max 50% URL/emoji content
   - Must contain verb
   - Must pass semantic sanity check (definitions, metrics, causal claims, etc.)

4. **LLM Normalization**
   ```
   Input: Array of candidate text blocks
   Output: {
     results: [
       {
         claim: "Self-contained knowledge claim",
         context: {
           domain: "SEO|Content marketing|SaaS|etc",
           actor: "author|company|product|platform",
           timeframe: "2024|near_term|unknown",
           scope: "tactical|strategic|philosophical"
         },
         role: "strategic_claim|metric|heuristic|instruction|definition|causal_claim",
         confidence: 0.0-1.0,
         authority: "high|medium|low"
       }
     ]
   }
   ```

5. **Persistence**
   - Stores artifacts in `knowledge_items.normalized_claims` JSONB field
   - Logs raw LLM output to `knowledge_llm_outputs` table for debugging
   - Schema version: `knowledge_compiler_v1`

#### Schema Structure

```php
normalized_claims: {
    'schema_version' => 'knowledge_compiler_v1',
    'normalization_hash' => 'sha256(raw_text)',
    'artifacts' => [
        [
            'claim' => 'Normalized statement',
            'context' => [
                'domain' => 'seo',
                'actor' => 'google',
                'timeframe' => '2024',
                'scope' => 'tactical'
            ],
            'role' => 'instruction',
            'confidence' => 0.8,
            'authority' => 'high'
        ]
    ],
    'gating' => [
        'candidates' => 5,
        'accepted' => 3,
        'rejected' => 2,
        'reasons' => ['too_short_tokens', 'no_verb']
    ],
    'source_stats' => [
        'original_chars' => 1200,
        'artifacts_count' => 3
    ]
}
```

### KnowledgeCompiler Service

**Location**: `app/Services/Ingestion/KnowledgeCompiler.php`

#### Key Methods

##### `extractCandidates(string $rawText, int $maxBlocks = 20): array`
Splits text into LLM-friendly blocks.

##### `gate(string $text): array`
Returns `['accepted' => bool, 'reason' => string]`.

**Rejection reasons**:
- `empty`
- `no_tokens`
- `too_short_tokens` (< 12 non-URL tokens)
- `too_much_url_or_emoji` (> 50%)
- `no_verb`
- `semantic_incoherence`

##### `normalizeDomain(string $domain): string`
Maps common domain variations to canonical forms:
- `"SEO"` → `"seo"`
- `"Content"` → `"content marketing"`
- Open vocabulary supported (stored as lowercase)

---

## Chunking System

### ChunkKnowledgeItemJob

**Purpose**: Convert normalized artifacts OR raw text into persistable `KnowledgeChunk` records.

#### Architecture (New in v2.0)

```
ChunkKnowledgeItemJob
    ↓
ChunkingCoordinator
    ↓
┌──────────────────┬──────────────────┬──────────────────┐
│  Preflight       │  Format Detect   │  Strategy Router │
└──────────────────┴──────────────────┴──────────────────┘
                           ↓
         ┌─────────────────┴─────────────────┐
         │                                   │
    LLM Extraction                  Deterministic Strategies
    (from normalized_claims)        (format-specific parsers)
         │                                   │
         └─────────────────┬─────────────────┘
                           ↓
                   Persist Chunks
```

#### Components

##### 1. ChunkingPreflight
Validates content before processing:

```php
return [
    'eligible' => bool,
    'skip_reason' => 'empty_after_clean|url_only|below_min_chars|below_min_tokens',
    'metrics' => [
        'raw_chars' => int,
        'clean_chars' => int,
        'clean_tokens_est' => int,
        'contains_url' => bool,
        'is_url_only' => bool
    ]
];
```

**Thresholds** (config/chunking.php):
- `min_clean_chars`: 80
- `min_clean_tokens_est`: 20

##### 2. ContentFormatDetector
Detects content structure:

**Formats**:
- `numeric_list`: Revenue timelines, metric sequences
  - Pattern: `2014 = $450/mo`, `1. Item`, etc.
  - Requires 3+ numeric lines, >50% of total
  
- `bullet_list`: Markdown-style lists
  - Pattern: `- Item`, `• Item`, `* Item`
  - Requires 3+ bullet lines
  
- `short_post`: Under 60 tokens
  - Sub-type: `promo_cta` (has CTA keywords + URL)
  
- `plain_text`: Default fallback

##### 3. ChunkingStrategyRouter
Routes to appropriate strategy:

```php
public function selectStrategy(string $format, int $tokenCount): ChunkingStrategy
{
    return match ($format) {
        'numeric_list' => new ListToDataPointsStrategy(),
        'bullet_list' => $tokenCount < 60 
            ? new ShortPostClaimStrategy() 
            : new FallbackSentenceStrategy(),
        'short_post', 'promo_cta' => new ShortPostClaimStrategy(),
        'plain_text' => new FallbackSentenceStrategy(),
        default => new FallbackSentenceStrategy(),
    };
}
```

**LLM Extraction Eligibility**:
- Format: `plain_text`
- Token range: 60-800
- Requires valid `normalized_claims` artifacts

#### Chunking Strategies

##### ListToDataPointsStrategy
Parses numeric data into structured chunks:

**Input**:
```
2014 = $450/mo
2015 = $1500/mo
2016 = $5k/mo (launched product)
```

**Output**:
1. Summary chunk: "Revenue timeline from 2014 to 2016 showing 3 data points across 3-year period"
2. Individual chunks: "In 2014: $450/mo", "In 2015: $1500/mo", etc.

**Chunk properties**:
- `role`: `metric`
- `authority`: `medium`
- `confidence`: 0.8 (summary), 0.7 (individual)
- `transformation_type`: `normalized` (summary), `extractive` (individual)
- `metadata`: Contains parsed `fields` (year, value, period)

##### ShortPostClaimStrategy
Extracts 1-2 meaningful sentences from short content:

**Output**:
- First sentence → `strategic_claim`
- Second sentence (if present) → `instruction`
- Filters out URLs and very short lines

##### FallbackSentenceStrategy
Scores and ranks sentences by informativeness:

**Scoring factors**:
- Contains numbers: +2.0
- Causal language (because, therefore, leads to): +1.5
- Instruction verbs (do, use, avoid, ensure): +1.0
- Medium length (12-40 tokens): +1.0

**Output**: Top 3 sentences as `heuristic` chunks

#### Chunk Schema

```php
[
    'text' => 'Chunk content',
    'role' => 'strategic_claim|metric|heuristic|instruction|definition|causal_claim',
    'authority' => 'high|medium|low',
    'confidence' => 0.0-1.0,
    'domain' => 'seo|content marketing|...', // open vocabulary
    'actor' => 'google|author|company|...', // optional
    'timeframe' => 'current|near_term|long_term|unknown',
    'token_count' => int,
    'source_text' => 'Original text segment', // for traceability
    'source_spans' => [['start' => 0, 'end' => 100, 'basis' => 'raw_text']], // optional
    'transformation_type' => 'extractive|normalized',
    'metadata' => [], // strategy-specific
]
```

#### Diagnostic Fields (KnowledgeItem)

New fields track chunking success/failure:

```php
chunking_status: 'created|skipped|failed'
chunking_skip_reason: 'empty_after_clean|url_only|below_min_chars|...'
chunking_error_code: 'parser_error|extractor_returned_empty|...'
chunking_error_message: 'Full error text (max 1000 chars)'
chunking_metrics: {
    'raw_chars' => 1500,
    'clean_tokens_est' => 200,
    'detected_format' => 'plain_text',
    'strategy_used' => 'llm_claim_extractor',
    'chunks_created' => 5,
    'duration_ms' => 1250
}
```

---

## Retrieval System

### Retriever Service

**Location**: `app/Services/Ai/Retriever.php`

#### Primary Method: `knowledgeChunks()`

```php
public function knowledgeChunks(
    string $organizationId,
    string $userId,
    string $query,
    string $intent,
    int $limit = 5,
    array $filters = []
): array
```

#### Retrieval Pipeline

1. **Intent Classification** (LLM-powered)
   ```php
   [
       'intent' => 'educational|persuasive|contrarian|story|emotional',
       'domain' => 'SEO|Content marketing|SaaS|...', // open vocabulary
       'funnel_stage' => 'awareness|consideration|decision'
   ]
   ```

2. **Query Expansion**
   - Adds domain-specific terms
   - Includes intent boosters
   - Example: `"SEO ranking"` → `["SEO ranking", "SEO", "rankings", "Google ranking signals", ...]`

3. **Vector Search** (pgvector)
   ```sql
   SELECT kc.*, (kc.embedding_vec <=> $queryEmbedding) AS distance
   FROM knowledge_chunks kc
   WHERE kc.organization_id = $orgId
     AND kc.user_id = $userId
     AND kc.source_variant = 'normalized'
     AND kc.chunk_role IN $vectorRoles
     AND kc.token_count >= $minTokenCount
     AND CHAR_LENGTH(kc.chunk_text) >= $minCharCount
   ORDER BY distance
   LIMIT $topN
   ```

4. **Composite Scoring**
   Weighted score (higher is better):
   ```
   score = (0.50 × similarity)
         + (0.15 × domain_match)
         + (0.15 × role_score)
         + (0.10 × authority_score)
         + (0.05 × confidence_score)
         + (0.05 × time_score)
   ```

   **Role scores**:
   - `definition`: 1.0
   - `strategic_claim`: 0.9
   - `heuristic`: 0.8
   - `causal_claim`: 0.7
   - `instruction`: 0.6
   - `metric`: 0.5

   **Authority scores**:
   - `high`: 1.0
   - `medium`: 0.6
   - `low`: 0.2

   **Time scores**:
   - `current`: 1.0
   - `near_term`: 0.8
   - `long_term`: 0.6
   - `unknown`: 0.5

5. **Role Boosts** (config)
   Applied as multiplier after composite scoring:
   ```php
   'role_boosts' => [
       'definition' => 1.2,
       'strategic_claim' => 1.1,
       'heuristic' => 1.0,
   ]
   ```

6. **Final Selection**
   - Sort by composite score (descending)
   - Apply folder scoping (if provided)
   - Return top N chunks

#### Enrichment Retrieval

**Purpose**: Fetch supplementary metrics/instructions from same knowledge items.

**Method**: `enrichForChunks(array $topChunks, int $limit = 10)`

**Configuration** (config/ai_chunk_roles.php):
```php
'enrichment' => [
    'enabled' => true,
    'roles' => ['metric', 'instruction'],
    'max_per_item' => 3,
    'max_total' => 10,
]
```

**Logic**:
1. Extract `knowledge_item_id`s from primary chunks
2. Fetch `metric` and `instruction` chunks from those items
3. Mark as `retrieval_tier: 'enrichment'`
4. No vector search (pure SQL lookup)

**Use Case**: When primary retrieval finds strategic claims, enrichment adds supporting metrics/instructions.

#### Filters

```php
$filters = [
    'vectorRoles' => ['strategic_claim', 'instruction'], // Override default roles
    'minTokenCount' => 12,
    'minCharCount' => 60,
    'folder_ids' => ['uuid1', 'uuid2'], // Scope to specific folders
    'knowledge_item_ids' => ['uuid'], // Restrict to specific items
]
```

#### Configuration (config/ai_chunk_roles.php)

```php
return [
    'vector_searchable_roles' => [
        'strategic_claim',
        'causal_claim',
        'definition',
        'heuristic',
    ],
    'min_token_count' => 12,
    'min_char_count' => 60,
    'role_boosts' => [
        'definition' => 1.2,
        'strategic_claim' => 1.1,
    ],
    'enrichment' => [
        'enabled' => true,
        'roles' => ['metric', 'instruction'],
        'max_per_item' => 3,
        'max_total' => 10,
    ],
];
```

---

## Data Models

### KnowledgeItem

**Table**: `knowledge_items`

```php
[
    'id' => uuid,
    'organization_id' => uuid,
    'user_id' => uuid,
    'ingestion_source_id' => uuid (nullable),
    'type' => 'note|idea|draft|excerpt|fact|transcript|...',
    'source' => 'bookmark|manual|chrome_extension|...',
    'source_id' => string (nullable),
    'source_platform' => string (nullable),
    'title' => string (nullable),
    'raw_text' => text,
    'raw_text_sha256' => string (indexed),
    'metadata' => json (nullable),
    'normalized_claims' => json (nullable), // See schema above
    'confidence' => float,
    'ingested_at' => timestamp,
    
    // Chunking diagnostics (new)
    'chunking_status' => 'created|skipped|failed' (indexed),
    'chunking_skip_reason' => string (indexed),
    'chunking_error_code' => string,
    'chunking_error_message' => text,
    'chunking_metrics' => json,
]
```

**Relationships**:
- `hasMany(KnowledgeChunk)`
- `hasMany(BusinessFact, 'source_knowledge_item_id')`
- `belongsTo(IngestionSource)`

### KnowledgeChunk

**Table**: `knowledge_chunks`

```php
[
    'id' => uuid,
    'knowledge_item_id' => uuid,
    'organization_id' => uuid,
    'user_id' => uuid,
    'chunk_text' => text,
    'chunk_type' => 'normalized_knowledge|normalized_claim|excerpt|misc',
    'chunk_role' => 'strategic_claim|metric|heuristic|instruction|definition|causal_claim|quote|other',
    'authority' => 'high|medium|low',
    'confidence' => float,
    'time_horizon' => 'current|near_term|long_term|unknown',
    'domain' => varchar(255) (indexed, nullable), // open vocabulary
    'actor' => varchar(150) (nullable),
    'source_type' => 'bookmark|text|file|...',
    'source_variant' => 'normalized|raw',
    'source_ref' => json,
    'tags' => json (nullable),
    'token_count' => int,
    'embedding_vec' => vector(1536) (nullable), // pgvector
    'embedding_model' => string (nullable),
    
    // Traceability (new)
    'source_text' => text (nullable), // Original text segment
    'source_spans' => json (nullable), // Character offsets
    'transformation_type' => 'extractive|normalized',
    
    'created_at' => timestamp,
]
```

**Indexes**:
- `(organization_id, user_id, source_variant)`
- `(organization_id, user_id, domain)`
- `(organization_id, user_id, chunk_role)`
- `(embedding_vec)` — pgvector HNSW index

### KnowledgeLlmOutput

**Table**: `knowledge_llm_outputs` (new)

**Purpose**: Debug log for LLM normalization calls

```php
[
    'id' => uuid,
    'knowledge_item_id' => uuid,
    'model' => string (nullable),
    'prompt_hash' => string (indexed), // sha1(system + user)
    'raw_output' => json, // Direct LLM response
    'parsed_output' => json (nullable), // Extracted artifacts
    'created_at' => timestamp,
]
```

---

## Configuration

### config/chunking.php (new)

```php
return [
    'min_clean_chars' => env('CHUNKING_MIN_CLEAN_CHARS', 80),
    'min_clean_tokens_est' => env('CHUNKING_MIN_CLEAN_TOKENS_EST', 20),
    'short_post_max_tokens' => env('CHUNKING_SHORT_POST_MAX_TOKENS', 60),
    'max_chunk_tokens' => env('CHUNKING_MAX_CHUNK_TOKENS', 120),
    'max_chunks_per_item' => env('CHUNKING_MAX_CHUNKS_PER_ITEM', 20),
    'enable_duplicate_skip' => env('CHUNKING_ENABLE_DUPLICATE_SKIP', false),
];
```

### config/ai_chunk_roles.php (updated)

```php
return [
    'vector_searchable_roles' => [
        'strategic_claim',
        'causal_claim',
        'definition',
        'heuristic',
    ],
    'min_token_count' => 12,
    'min_char_count' => 60,
    'role_boosts' => [
        'definition' => 1.2,
        'strategic_claim' => 1.1,
    ],
    'enrichment' => [
        'enabled' => true,
        'roles' => ['metric', 'instruction'],
        'max_per_item' => 3,
        'max_total' => 10,
    ],
];
```

### config/ai.php (retrieval section)

```php
'retriever' => [
    'near_match_distance' => 0.10,
    'sparse_recall' => [
        'enabled' => false,
        'top_k' => 50,
    ],
],

'prompt_isa' => [
    'enabled' => true,
    'max_insights' => 3,
    'max_chunk_chars' => 600,
    'min_keyword_hits' => 1,
    'drop_if_contains' => ['#', '##', '###'], // Heading markers
    'strip_markdown' => true,
    'stopwords' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for'],
    'task_keywords_max' => 12,
],
```

---

## API Endpoints

### POST /api/v1/knowledge-items

Create a new knowledge item and trigger ingestion pipeline.

**Request**:
```json
{
    "type": "excerpt",
    "source": "chrome_extension",
    "title": "SEO Best Practices",
    "raw_text": "Content quality is the #1 ranking factor. Google prioritizes sites with E-E-A-T signals...",
    "metadata": {
        "url": "https://example.com/article",
        "captured_at": "2026-01-07T10:00:00Z"
    },
    "source_id": "bookmark-uuid",
    "source_platform": "web"
}
```

**Response**:
```json
{
    "id": "019c1234-5678-9abc-def0-123456789abc",
    "status": "ingested"
}
```

**Duplicate Response**:
```json
{
    "status": "duplicate"
}
```

**Async Pipeline**:
```
NormalizeKnowledgeItemJob → ChunkKnowledgeItemJob → 
EmbedKnowledgeChunksJob → ExtractBusinessFactsJob
```

---

## Deployment Notes

### Database Migrations

**Order of execution**:
1. `2026_01_07_000100_create_knowledge_llm_outputs_table.php`
2. `2026_01_07_000110_add_domain_actor_to_knowledge_chunks.php`
3. `2026_01_07_000120_increase_knowledge_chunks_domain_length.php`
4. `2026_01_07_120000_add_chunking_diagnostics_to_knowledge_items.php`
5. `2026_01_07_120010_add_traceability_to_knowledge_chunks.php`

**Key changes**:
- New `knowledge_llm_outputs` table for debugging
- `knowledge_chunks.domain` expanded to 255 chars (open vocabulary)
- New `knowledge_chunks.actor`, `source_text`, `source_spans`, `transformation_type` fields
- New `knowledge_items.chunking_*` diagnostic fields
- Performance indexes on `(org, user, source_variant)`, `(org, user, domain)`, `(org, user, role)`

### Breaking Changes

❌ **Removed**: `ClassifyKnowledgeChunksJob` (no longer part of pipeline)

✅ **Replaced with**: LLM normalization outputs include `role` classification directly

### Environment Variables

```bash
# Chunking
CHUNKING_MIN_CLEAN_CHARS=80
CHUNKING_MIN_CLEAN_TOKENS_EST=20

# Retrieval
AI_CHUNK_ROLES_MIN_TOKEN_COUNT=12
AI_CHUNK_ROLES_MIN_CHAR_COUNT=60
```

### Queue Configuration

**Recommended**:
- Queue: `knowledge-processing`
- Workers: 2-4 (depending on LLM rate limits)
- Timeout: 120s (normalization can be slow)
- Max attempts: 2

```bash
php artisan queue:work --queue=knowledge-processing --timeout=120 --tries=2
```

### Monitoring

**Key metrics**:
- `knowledge_items.chunking_status` distribution
- `knowledge_items.chunking_skip_reason` counts
- `knowledge_chunks` creation rate by `source_variant`
- Embedding coverage: `COUNT(*) WHERE embedding_vec IS NOT NULL`
- Average chunking duration: `chunking_metrics.duration_ms`

**SQL queries**:
```sql
-- Chunking health check
SELECT 
    chunking_status,
    chunking_skip_reason,
    COUNT(*) as count
FROM knowledge_items
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY chunking_status, chunking_skip_reason;

-- Normalized vs raw chunks
SELECT 
    source_variant,
    chunk_role,
    COUNT(*) as count,
    AVG(token_count) as avg_tokens
FROM knowledge_chunks
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY source_variant, chunk_role;

-- Embedding coverage
SELECT 
    COUNT(*) as total_chunks,
    COUNT(embedding_vec) as embedded_chunks,
    ROUND(100.0 * COUNT(embedding_vec) / COUNT(*), 2) as coverage_pct
FROM knowledge_chunks
WHERE source_variant = 'normalized';
```

### Performance Optimization

**Retrieval**:
- pgvector HNSW index on `embedding_vec` (m=16, ef_construction=64)
- Composite indexes on `(org, user, source_variant)`, `(org, user, domain)`, `(org, user, role)`
- Query limit: 5-10 primary chunks + 10 enrichment chunks

**Chunking**:
- Batch embedding: 100 chunks per job
- LLM normalization: 20 blocks max per call
- Idempotency checks on `normalized_claims.normalization_hash`

### Rollback Plan

If issues arise:

1. **Disable normalization**:
   ```php
   // In NormalizeKnowledgeItemJob::handle()
   return; // Skip normalization
   ```

2. **Fallback to raw chunking**:
   ```php
   // In ChunkingCoordinator::processItem()
   $strategy = new FallbackSentenceStrategy();
   ```

3. **Revert migrations**:
   ```bash
   php artisan migrate:rollback --step=5
   ```

---

## Development & Testing

### Local Testing

```bash
# Process a single ingestion source (sync mode)
php artisan ingestion:process-ingestion-source --ids="019b97ed-..." --sync --debug

# Manual tinker-debug script
php artisan tinker-debug:run check_normalized
```

### Debugging

**LLM outputs**:
```php
$item = KnowledgeItem::find('uuid');
$outputs = KnowledgeLlmOutput::where('knowledge_item_id', $item->id)
    ->orderByDesc('created_at')
    ->get();
```

**Chunking diagnostics**:
```php
$item = KnowledgeItem::find('uuid');
dd($item->chunking_status, $item->chunking_metrics);
```

**Retrieval trace**:
```php
$result = app(Retriever::class)->knowledgeChunksTrace($orgId, $userId, $query, $intent, 5);
// Returns chunks + full scoring breakdown
```

---

## Future Enhancements

### Planned
- [ ] Multi-language support in normalization
- [ ] Custom domain taxonomies per organization
- [ ] A/B testing framework for retrieval strategies
- [ ] Real-time chunking for streaming content
- [ ] Cross-organization knowledge sharing (privacy controls)

### Under Consideration
- [ ] Graph-based chunk relationships
- [ ] Temporal decay for outdated chunks
- [ ] User feedback loop (thumbs up/down on retrieved chunks)
- [ ] Automatic re-normalization on schema updates

---

## Changelog

### v2.0 (January 7, 2026) - Knowledge Compiler
- ✅ Implemented LLM-powered normalization pipeline
- ✅ Added semantic gating with KnowledgeCompiler
- ✅ Removed ClassifyKnowledgeChunksJob (integrated into normalization)
- ✅ Introduced chunking coordinator with format-aware strategies
- ✅ Added domain/actor fields to chunks (open vocabulary)
- ✅ Enhanced retrieval with domain matching and enrichment
- ✅ Added traceability fields (source_text, source_spans, transformation_type)
- ✅ Created knowledge_llm_outputs table for debugging
- ✅ Added chunking diagnostics to knowledge_items

### v1.x (Legacy)
- Basic paragraph chunking
- Raw embedding storage
- Simple vector search

---

**Documentation Maintained By**: System Architecture Team  
**Contact**: [Your team contact]  
**Last Review**: January 7, 2026
