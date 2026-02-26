# LLM Calls Complete Accounting System

**Status**: ✅ Implemented  
**Version**: 1.0  
**Date**: January 7, 2026

## Quick Start

### 1. Deploy
```bash
# Run migration
php artisan migrate

# Test the system
php artisan tinker-debug:run test_llm_accounting

# Check status
php artisan llm:accounting-status
```

### 2. Use in Code
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$client = app(\App\Services\Ai\LLMClient::class);

$result = $client->call(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    options: ['model' => 'gpt-4o'],
    meta: [
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

### 3. Monitor
```bash
# Overall health
php artisan llm:accounting-status

# Detailed breakdown
php artisan llm:accounting-status --detailed

# Last 30 days
php artisan llm:accounting-status --days=30
```

## What's New

### Database Changes
- ✅ 11 new columns for complete tracking
- ✅ Column renames: `input_tokens` → `prompt_tokens`, `output_tokens` → `completion_tokens`
- ✅ Indexes for fast queries
- ✅ Completeness flag for data quality

### New Services
- **LlmCallLogger** - Central logging (single source of truth)
- **LlmPricingTable** - 40+ models with pricing
- **LlmAccountingStatus** - Health monitoring command

### Updated Services
- **LLMClient** - Now accepts `$meta` parameter for context
- **LlmCall Model** - Updated with new fields and casts

### New Enums
- **LlmPipelineStage** - 5 stages (ingestion, retrieval, generation, repair, embedding)
- **LlmRequestType** - 15 types (normalize, generate, classify, etc.)

## Files Created

### Core System
- `app/Services/Ai/LlmCallLogger.php` - Central logger
- `app/Services/Ai/LlmPricingTable.php` - Pricing table
- `app/Enums/LlmPipelineStage.php` - Pipeline stage enum
- `app/Enums/LlmRequestType.php` - Request type enum
- `app/Console/Commands/LlmAccountingStatus.php` - Status command

### Documentation
- `docs/LLM_ACCOUNTING_GUIDE.md` - Usage guide with examples
- `docs/LLM_ACCOUNTING_IMPLEMENTATION.md` - Implementation details
- `docs/LLM_ACCOUNTING_DEPLOYMENT.md` - Deployment checklist
- `docs/LLM_ACCOUNTING_README.md` - This file

### Testing
- `Scratch/test_llm_accounting.php` - Test script

### Migration
- `database/migrations/2026_01_07_153001_add_complete_accounting_to_llm_calls_table.php`

## Key Features

### 1. Complete Cost Tracking
- ✅ Per-call token usage (prompt, completion, total)
- ✅ Accurate cost in USD
- ✅ Provider cost vs fallback pricing
- ✅ Unit cost tracking

### 2. Full Attribution
- ✅ Organization-level costs
- ✅ User-level tracking
- ✅ Pipeline stage breakdown
- ✅ Request type classification

### 3. Entity Linkage
- ✅ Link to knowledge_item
- ✅ Link to knowledge_chunk
- ✅ Link to generation_snapshot
- ✅ Enables debugging and optimization

### 4. Data Quality
- ✅ Completeness flag
- ✅ Partial data acceptance
- ✅ Best-effort capture
- ✅ Graceful error handling

### 5. Performance
- ✅ Indexed for fast queries
- ✅ Non-blocking writes
- ✅ Immutable records

## Architecture Principles

1. **One row = one external LLM call** (no retries merged)
2. **Numerical + categorical only** (no prompts or responses)
3. **Write-once, immutable** (no updates except backfill)
4. **Best-effort capture** (partial data allowed, but flagged)
5. **Low coupling** (logging must not break generation paths)

## Usage Examples

### Basic Call
```php
$result = $client->call(
    purpose: 'normalize',
    system: $system,
    user: $user,
    meta: ['organization_id' => $org->id]
);
```

### With Full Metadata
```php
$result = $client->callWithMeta(
    purpose: 'generate',
    system: $system,
    user: $user,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => auth()->id(),
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Reporting Queries

### Cost by Organization
```sql
SELECT 
    organization_id,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as total_cost
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY organization_id;
```

### Cost by Pipeline Stage
```sql
SELECT 
    pipeline_stage,
    request_type,
    COUNT(*) as calls,
    ROUND(SUM(cost_usd), 2) as total_cost
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
    AND record_complete = true
GROUP BY pipeline_stage, request_type
ORDER BY total_cost DESC;
```

### Daily Cost Trend
```sql
SELECT 
    DATE(created_at) as date,
    ROUND(SUM(cost_usd), 2) as daily_cost
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

### Incomplete Records
```sql
SELECT COUNT(*) 
FROM llm_calls 
WHERE record_complete = false;
```

## Completeness Rules

A record is marked `record_complete = true` **only if**:
- `model` is present
- `total_tokens > 0`
- `cost_usd IS NOT NULL`
- `organization_id IS NOT NULL`

Everything else is partial but still logged.

## Cost Calculation Priority

1. **Provider cost** (from API response) → `pricing_source = 'provider'`
2. **Local fallback** (from pricing table) → `pricing_source = 'local_fallback'`
3. **NULL** (if model unknown)

## Adding New Models to Pricing Table

Edit `app/Services/Ai/LlmPricingTable.php`:

```php
protected static function getPricingTable(): array
{
    return [
        // ... existing models
        'your-new-model' => 0.001, // per 1K tokens (blended rate)
    ];
}
```

## Monitoring Commands

```bash
# Basic status
php artisan llm:accounting-status

# Detailed breakdown (includes pipeline stages and request types)
php artisan llm:accounting-status --detailed

# Custom time range
php artisan llm:accounting-status --days=30
```

## Health Indicators

### Excellent (Production Ready)
- ✅ >95% completeness
- ✅ >95% organization attribution
- ✅ <5% error rate
- ✅ All models in pricing table

### Good (Acceptable)
- ⚠️ 80-95% completeness
- ⚠️ 80-95% organization attribution
- ⚠️ 5-10% error rate
- ⚠️ Most models in pricing table

### Poor (Action Needed)
- ❌ <80% completeness
- ❌ <80% organization attribution
- ❌ >10% error rate
- ❌ Many models missing pricing

## Migration Plan

### Week 1: Core Deployment
- [x] Run migration
- [x] Test system
- [ ] Update high-traffic call sites (ContentGeneratorService, etc.)
- [ ] Monitor completeness ratio

### Week 2-3: Full Rollout
- [ ] Update remaining call sites
- [ ] Review incomplete records
- [ ] Add missing models to pricing table
- [ ] Optimize high-cost operations

### Week 4: Backfill & Optimize
- [ ] Backfill historical data
- [ ] Create cost dashboards
- [ ] Set up alerts/budgets
- [ ] Document learnings

## Rollback Plan

If issues arise:

```bash
# Code rollback
git revert <commit>

# Migration rollback (if needed)
php artisan migrate:rollback
```

## Support

- **Full Guide**: `docs/LLM_ACCOUNTING_GUIDE.md`
- **Implementation**: `docs/LLM_ACCOUNTING_IMPLEMENTATION.md`
- **Deployment**: `docs/LLM_ACCOUNTING_DEPLOYMENT.md`
- **Test Script**: `Scratch/test_llm_accounting.php`

## Success Metrics

After deployment:
- Know exact cost per pipeline stage ✅
- Identify retry and repair waste ✅
- Compare model economics objectively ✅
- Set hard budgets with confidence ✅
- Optimize with data, not intuition ✅

---

**This is production-ready, scalable LLM accounting.**

**Next Step**: `php artisan migrate`
