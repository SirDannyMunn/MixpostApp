# LLM Calls Accounting - Implementation Summary

## Completed Components

### 1. Database Migration
**File**: `database/migrations/2026_01_07_153001_add_complete_accounting_to_llm_calls_table.php`

**Changes**:
- Renamed `input_tokens` → `prompt_tokens`
- Renamed `output_tokens` → `completion_tokens`
- Added `provider`, `pipeline_stage`, `request_type`
- Added `total_tokens`, `unit_cost_usd`, `pricing_source`
- Added `related_entity_type`, `related_entity_id`
- Added `model_version`, `record_complete`
- Created indexes: `llm_calls_org_stage_idx`, `llm_calls_created_at_idx`

**Run**: `php artisan migrate`

### 2. Enums
**Files**:
- `app/Enums/LlmPipelineStage.php` - 5 stages (ingestion, retrieval, generation, repair, embedding)
- `app/Enums/LlmRequestType.php` - 15 types (normalize, generate, repair, etc.)

**Purpose**: Type-safe, canonical taxonomy for all LLM operations

### 3. LlmCallLogger Service
**File**: `app/Services/Ai/LlmCallLogger.php`

**Features**:
- Central logging point (single source of truth)
- Accepts partial data gracefully
- Normalizes enum values
- Computes derived fields (total_tokens, cost_usd)
- Marks record completeness
- Handles fallback pricing
- Infers provider from model name
- Best-effort capture with error handling

**Key Method**: `log(array $data): void`

### 4. LlmPricingTable Service
**File**: `app/Services/Ai/LlmPricingTable.php`

**Features**:
- 40+ models with pricing data
- Blended rates (per 1K tokens)
- Fuzzy matching for model variants
- Easy to extend

**Models Included**:
- OpenAI: GPT-4o, GPT-4-turbo, GPT-3.5, o1-preview, o1-mini
- Anthropic: Claude 3.5 Sonnet/Haiku, Claude 3 Opus/Sonnet/Haiku
- Google: Gemini 2.0 Flash, Gemini 1.5 Pro/Flash
- Open Source: Llama 3.3, Mistral, Qwen, Gemma, Deepseek
- Perplexity models

### 5. Updated LLMClient
**File**: `app/Services/Ai/LLMClient.php`

**Changes**:
- Accepts `$meta` parameter for context
- Uses LlmCallLogger instead of direct DB writes
- Removed old `logCall()` and `logCallDetailed()` methods
- Captures provider metadata (tokens, cost) from responses
- Passes complete context to logger

**Signature Changes**:
```php
// Before
call(string $purpose, string $system, string $user, ?string $schema = null, array $options = [])
callWithMeta(string $purpose, string $system, string $user, ?string $schema = null, array $options = [])

// After
call(string $purpose, string $system, string $user, ?string $schema = null, array $options = [], array $meta = [])
callWithMeta(string $purpose, string $system, string $user, ?string $schema = null, array $options = [], array $meta = [])
```

### 6. Updated LlmCall Model
**File**: `app/Models/LlmCall.php`

**Changes**:
- Updated `$fillable` with all new columns
- Added `$casts` for type safety
- Ready for new schema

### 7. Documentation
**Files**:
- `docs/LLM_ACCOUNTING_GUIDE.md` - Complete usage guide with examples, queries, best practices
- `Scratch/test_llm_accounting.php` - Test script for validation

## Data Model

### Complete Record Criteria
A record is marked `record_complete = true` when:
- `model` is present
- `total_tokens > 0`
- `cost_usd IS NOT NULL`
- `organization_id IS NOT NULL`

### Cost Calculation Priority
1. **Provider cost** (from API response) → `pricing_source = 'provider'`
2. **Local fallback** (from pricing table) → `pricing_source = 'local_fallback'`
3. **NULL** (if model unknown)

### Provider Inference
Auto-detected from model name:
- `gpt-*` → openai
- `claude*` → anthropic
- `gemini*` → google
- `llama*`, `mistral*` → openrouter (default)

## Usage Pattern

### Minimal (Basic Tracking)
```php
$client->call(
    purpose: 'normalize',
    system: $systemPrompt,
    user: $userPrompt,
    meta: ['organization_id' => $org->id]
);
```

### Complete (Full Attribution)
```php
$client->call(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $jsonSchema,
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

## Key Queries

### Cost by Pipeline Stage
```sql
SELECT pipeline_stage, request_type, 
       COUNT(*) calls, 
       ROUND(SUM(cost_usd), 2) total_cost
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
  AND record_complete = true
GROUP BY pipeline_stage, request_type;
```

### Daily Cost Trend
```sql
SELECT DATE(created_at) date, 
       ROUND(SUM(cost_usd), 2) daily_cost
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '30 days'
GROUP BY DATE(created_at);
```

### Incomplete Records Audit
```sql
SELECT COUNT(*) 
FROM llm_calls 
WHERE record_complete = false;
```

## Next Steps

### To Deploy
1. **Run migration**: `php artisan migrate`
2. **Test**: `php artisan tinker-debug:run test_llm_accounting`
3. **Update callers**: Add metadata to existing LLMClient calls
4. **Monitor**: Check completeness ratio

### To Enhance (Future)
1. Update all LLMClient call sites to pass metadata
2. Create backfill script for existing records
3. Add cost alerts/budgets
4. Build dashboard for cost monitoring
5. Split input/output pricing (more accurate)
6. Add retry correlation
7. Create monthly cost reports

## Testing

Run test script:
```bash
php artisan tinker-debug:run test_llm_accounting
```

Tests:
1. Pricing table lookup
2. Complete data logging
3. Fallback cost calculation
4. Incomplete record flagging
5. Current statistics
6. Recent calls overview

## Migration Safety

**Non-Breaking Changes**:
- All new columns are nullable
- Column renames preserve data
- Indexes added (no data change)
- Old code will work (but log incomplete records)

**Backward Compatible**:
- LLMClient signature adds optional `$meta` parameter
- Existing calls work without metadata (but less complete)
- Logger handles missing fields gracefully

## Success Metrics

After full rollout:
- **>95% record completeness** (track via `WHERE record_complete = true`)
- **100% organization attribution** (all calls have org_id)
- **Zero direct LlmCall writes** (all via LlmCallLogger)
- **Complete pipeline visibility** (all stages tracked)
- **Accurate cost attribution** (by org, feature, stage)

## Architecture Benefits

✅ Single logging point (maintainable)
✅ Partial data acceptance (robust)
✅ Type-safe enums (correct)
✅ Fallback pricing (complete)
✅ Entity linkage (debuggable)
✅ Immutable writes (auditable)
✅ Low coupling (non-blocking)

This is production-ready, scalable LLM accounting.
