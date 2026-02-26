# LLM Calls Accounting System - Usage Guide

## Overview

The complete LLM calls accounting system tracks every external LLM API call with full cost, token usage, ownership, and pipeline context.

## Architecture

### Core Components

1. **LlmCallLogger** - Central logging service (single source of truth)
2. **LlmPricingTable** - Model pricing for fallback cost calculation
3. **LLMClient** - Updated to accept metadata and use logger
4. **LlmCall Model** - Updated with new fields and casts
5. **Enums** - LlmPipelineStage, LlmRequestType

### Data Flow

```
LLM Call → LLMClient → OpenRouter → Response
                ↓
         LlmCallLogger (normalize, compute, validate)
                ↓
         LlmCall (database write)
```

## Usage Examples

### Basic Call with Metadata

```php
use App\Services\Ai\LLMClient;
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$client = app(LLMClient::class);

$result = $client->call(
    purpose: 'normalize_knowledge_item',
    system: 'You are a knowledge normalizer...',
    user: 'Normalize this content...',
    schema: null,
    options: ['model' => 'gpt-4o-mini'],
    meta: [
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'pipeline_stage' => LlmPipelineStage::INGESTION,
        'request_type' => LlmRequestType::NORMALIZE,
        'related_entity_type' => 'knowledge_item',
        'related_entity_id' => $item->id,
    ]
);
```

### Call with Full Metadata

```php
$result = $client->callWithMeta(
    purpose: 'generate',
    system: 'Generate social media content...',
    user: 'Create a post about...',
    schema: $jsonSchema,
    options: ['temperature' => 0.7],
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => auth()->id(),
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);

// Returns: ['data' => result, 'meta' => provider_meta, 'latency_ms' => int]
```

### Direct Logger Usage (Advanced)

```php
use App\Services\Ai\LlmCallLogger;

$logger = app(LlmCallLogger::class);

$logger->log([
    'organization_id' => $org->id,
    'user_id' => $user->id,
    'purpose' => 'embed',
    'model' => 'text-embedding-3-small',
    'provider' => 'openai',
    'pipeline_stage' => LlmPipelineStage::EMBEDDING,
    'request_type' => LlmRequestType::EMBED,
    'prompt_tokens' => 150,
    'completion_tokens' => 0,
    'total_tokens' => 150,
    'cost_usd' => 0.00003,
    'latency_ms' => 245,
    'status' => 'ok',
]);
```

## Metadata Fields

### Required
- `purpose` - Human-readable operation description

### Strongly Recommended
- `organization_id` - For cost attribution
- `user_id` - For user-level tracking
- `pipeline_stage` - Use LlmPipelineStage enum
- `request_type` - Use LlmRequestType enum

### Optional but Valuable
- `related_entity_type` - 'knowledge_item' | 'knowledge_chunk' | 'generation_snapshot'
- `related_entity_id` - UUID of related entity
- `model` - Override default model selection
- `request_hash` - For deduplication

## Enums

### LlmPipelineStage
- `INGESTION` - Knowledge ingestion and normalization
- `RETRIEVAL` - RAG and search operations
- `GENERATION` - Content generation
- `REPAIR` - Validation and repair
- `EMBEDDING` - Vector embedding generation

### LlmRequestType
- `INFER_CONTEXT` - Context folder inference
- `NORMALIZE` - Content normalization
- `CHUNK_EXTRACT` - Chunk extraction
- `EMBED` - Embedding generation
- `GENERATE` - Primary generation
- `REPAIR` - Repair invalid output
- `CLASSIFY` - Classification tasks
- `REPLAY` - Regeneration from context
- `SCORE_FOLDER_CANDIDATES` - Folder scoring
- `TEMPLATE_PARSE` - Template parsing
- `FAITHFULNESS_AUDIT` - Faithfulness checking
- `SYNTHETIC_QA` - QA generation
- `GENERATION_GRADER` - Output grading
- `REFLEXION_CRITIQUE` - Reflexion critique
- `REFLEXION_REFINE` - Reflexion refinement

## Completeness Rules

A record is marked `record_complete = true` only if:
- `model` is present
- `total_tokens > 0`
- `cost_usd IS NOT NULL`
- `organization_id IS NOT NULL`

Partial records are still logged but flagged as incomplete.

## Cost Calculation

### Priority
1. **Provider cost** (from API response) - `pricing_source = 'provider'`
2. **Local fallback** (from LlmPricingTable) - `pricing_source = 'local_fallback'`
3. **NULL** (if model unknown)

### Pricing Table
Located in `LlmPricingTable::getPricingTable()`. Contains blended rates (average of input/output) per 1K tokens.

To add a new model:
```php
protected static function getPricingTable(): array
{
    return [
        // ... existing models
        'new-model-name' => 0.001, // per 1K tokens
    ];
}
```

## Reporting Queries

### Cost by Pipeline Stage
```sql
SELECT 
    pipeline_stage,
    request_type,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as total_cost,
    ROUND(AVG(latency_ms), 0) as avg_latency_ms
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY pipeline_stage, request_type
ORDER BY total_cost DESC;
```

### Cost by Organization
```sql
SELECT 
    organization_id,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as total_cost,
    SUM(total_tokens) as total_tokens
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY organization_id
ORDER BY total_cost DESC;
```

### Incomplete Records
```sql
SELECT 
    purpose,
    COUNT(*) as incomplete_count,
    COUNT(*) FILTER (WHERE organization_id IS NULL) as missing_org,
    COUNT(*) FILTER (WHERE total_tokens IS NULL OR total_tokens = 0) as missing_tokens,
    COUNT(*) FILTER (WHERE cost_usd IS NULL) as missing_cost
FROM llm_calls
WHERE record_complete = false
    AND created_at > NOW() - INTERVAL '7 days'
GROUP BY purpose;
```

### Model Usage and Cost
```sql
SELECT 
    model,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as total_cost,
    ROUND(AVG(cost_usd), 4) as avg_cost_per_call,
    ROUND(AVG(latency_ms), 0) as avg_latency_ms
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY model
ORDER BY total_cost DESC;
```

### Daily Cost Trend
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as daily_cost,
    SUM(total_tokens) as tokens
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

## Migration

Run the migration:
```bash
php artisan migrate
```

This will:
- Add new columns (non-breaking)
- Rename `input_tokens` → `prompt_tokens`
- Rename `output_tokens` → `completion_tokens`
- Add indexes for performance

## Best Practices

1. **Always pass metadata** when calling LLMClient
2. **Use enums** for pipeline_stage and request_type (type safety)
3. **Link entities** when possible (aids debugging and optimization)
4. **Monitor completeness** regularly to ensure quality data
5. **Update pricing table** when adding new models
6. **Review costs** weekly by pipeline stage to optimize

## Troubleshooting

### Records Not Complete
Check:
1. Is `organization_id` being passed?
2. Are tokens coming back from provider?
3. Is model in pricing table?

### High Costs
1. Query by `request_type` to find expensive operations
2. Check `latency_ms` for retry patterns
3. Review model selection per purpose

### Missing Token Data
1. Check if provider returns usage in metadata
2. Verify OpenRouterService extracts usage correctly
3. Consider token estimation fallback if needed

## Future Enhancements

### Planned
- [ ] Separate input/output pricing (vs blended)
- [ ] Retry correlation (link retries to original requests)
- [ ] Cost alerts and budgets
- [ ] Streaming token accounting
- [ ] Batch operation cost tracking
- [ ] Historical pricing versions (audit trail)
