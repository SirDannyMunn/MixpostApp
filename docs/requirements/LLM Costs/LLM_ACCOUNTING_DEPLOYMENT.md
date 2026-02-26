# LLM Accounting System - Deployment Checklist

## Pre-Deployment

- [ ] Review migration file: `database/migrations/2026_01_07_153001_add_complete_accounting_to_llm_calls_table.php`
- [ ] Backup `llm_calls` table
- [ ] Test migration on development database
- [ ] Verify no errors in new PHP files

## Deployment Steps

### 1. Run Migration
```bash
php artisan migrate
```

**Expected Output**:
- Migration runs successfully
- New columns added
- Indexes created
- Existing data preserved (column renames)

### 2. Test Core Functionality
```bash
php artisan tinker-debug:run test_llm_accounting
```

**Expected Results**:
- ✅ Pricing table returns costs for known models
- ✅ Complete record logged successfully
- ✅ Fallback pricing calculated correctly
- ✅ Incomplete records flagged properly
- ✅ Statistics show complete/incomplete counts

### 3. Verify Schema
```sql
-- Check new columns exist
SELECT 
    provider, 
    pipeline_stage, 
    request_type, 
    prompt_tokens, 
    completion_tokens, 
    total_tokens,
    unit_cost_usd,
    pricing_source,
    related_entity_type,
    related_entity_id,
    model_version,
    record_complete
FROM llm_calls 
LIMIT 1;
```

### 4. Check Indexes
```sql
-- Verify indexes created
SELECT 
    indexname, 
    indexdef 
FROM pg_indexes 
WHERE tablename = 'llm_calls'
ORDER BY indexname;
```

Expected indexes:
- `llm_calls_org_stage_idx`
- `llm_calls_created_at_idx`

## Post-Deployment Validation

### Immediate (Day 1)

- [ ] Monitor for logging errors in Laravel logs
- [ ] Check `record_complete` ratio:
  ```sql
  SELECT 
      COUNT(*) FILTER (WHERE record_complete = true) as complete,
      COUNT(*) FILTER (WHERE record_complete = false) as incomplete,
      ROUND(100.0 * COUNT(*) FILTER (WHERE record_complete = true) / COUNT(*), 1) as complete_pct
  FROM llm_calls
  WHERE created_at > NOW() - INTERVAL '1 day';
  ```
  
- [ ] Verify new calls have metadata:
  ```sql
  SELECT 
      COUNT(*) FILTER (WHERE organization_id IS NOT NULL) as with_org,
      COUNT(*) FILTER (WHERE pipeline_stage IS NOT NULL) as with_stage,
      COUNT(*) FILTER (WHERE request_type IS NOT NULL) as with_type,
      COUNT(*) as total
  FROM llm_calls
  WHERE created_at > NOW() - INTERVAL '1 day';
  ```

### Week 1

- [ ] Review incomplete records:
  ```sql
  SELECT 
      purpose,
      COUNT(*) as count,
      COUNT(*) FILTER (WHERE organization_id IS NULL) as missing_org,
      COUNT(*) FILTER (WHERE total_tokens IS NULL) as missing_tokens,
      COUNT(*) FILTER (WHERE cost_usd IS NULL) as missing_cost
  FROM llm_calls
  WHERE record_complete = false
      AND created_at > NOW() - INTERVAL '7 days'
  GROUP BY purpose
  ORDER BY count DESC;
  ```

- [ ] Analyze cost by pipeline stage:
  ```sql
  SELECT 
      pipeline_stage,
      request_type,
      COUNT(*) as calls,
      ROUND(SUM(cost_usd), 2) as cost,
      ROUND(AVG(latency_ms), 0) as avg_latency
  FROM llm_calls
  WHERE created_at > NOW() - INTERVAL '7 days'
      AND record_complete = true
  GROUP BY pipeline_stage, request_type
  ORDER BY cost DESC;
  ```

- [ ] Identify missing models in pricing table:
  ```sql
  SELECT DISTINCT model
  FROM llm_calls
  WHERE pricing_source = 'local_fallback'
      AND model NOT IN (
          -- Add models from LlmPricingTable
          'gpt-4o', 'claude-3-5-sonnet', 'gemini-2.0-flash-exp'
          -- etc.
      );
  ```

## Code Update Plan

### Phase 1: High-Priority Call Sites (Week 1)
Update these critical paths first:

1. **ContentGeneratorService** - Primary generation
   - Add metadata to all LLMClient calls
   - Include: org_id, user_id, pipeline_stage, request_type, snapshot_id
   
2. **KnowledgeIngestion** - Normalization
   - Add metadata for normalize, classify, infer_context calls
   - Include: org_id, pipeline_stage, request_type, knowledge_item_id

3. **ValidationAndRepairService** - Repair operations
   - Add metadata for repair calls
   - Include: org_id, pipeline_stage, request_type, snapshot_id

### Phase 2: Secondary Call Sites (Week 2-3)
- PostClassifier
- ReflexionService
- EmbeddingService (if using LLM calls)
- Other generation utilities

### Phase 3: Backfill (Week 3-4)
Create backfill script to populate missing data for historical records:
- Infer organization_id from related entities
- Calculate costs using pricing table for old records
- Mark backfilled records appropriately

## Code Update Example

### Before
```php
$draft = $this->llm->call(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    options: ['temperature' => 0.7]
);
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$draft = $this->llm->call(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    options: ['temperature' => 0.7],
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Monitoring Dashboard Queries

### Daily Cost by Organization
```sql
SELECT 
    o.name as org_name,
    DATE(lc.created_at) as date,
    COUNT(*) as calls,
    ROUND(SUM(lc.cost_usd), 2) as daily_cost
FROM llm_calls lc
JOIN organizations o ON o.id = lc.organization_id
WHERE lc.created_at > NOW() - INTERVAL '7 days'
    AND lc.record_complete = true
GROUP BY o.name, DATE(lc.created_at)
ORDER BY date DESC, daily_cost DESC;
```

### Model Performance
```sql
SELECT 
    model,
    COUNT(*) as calls,
    ROUND(AVG(latency_ms), 0) as avg_latency,
    ROUND(AVG(cost_usd), 4) as avg_cost,
    MIN(latency_ms) as min_latency,
    MAX(latency_ms) as max_latency
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '7 days'
    AND record_complete = true
    AND status = 'ok'
GROUP BY model
ORDER BY calls DESC;
```

### Error Rate
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) FILTER (WHERE status = 'ok') as success,
    COUNT(*) FILTER (WHERE status = 'failed') as failed,
    ROUND(100.0 * COUNT(*) FILTER (WHERE status = 'failed') / COUNT(*), 2) as error_pct
FROM llm_calls
WHERE created_at > NOW() - INTERVAL '7 days'
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

## Rollback Plan

If critical issues arise:

1. **Code rollback** (does not affect data):
   ```bash
   git revert <commit>
   ```

2. **Migration rollback** (if needed):
   ```bash
   php artisan migrate:rollback
   ```
   
   This will:
   - Remove new columns
   - Rename `prompt_tokens` → `input_tokens`
   - Rename `completion_tokens` → `output_tokens`
   - Drop indexes

3. **Restore from backup** (last resort):
   ```sql
   DROP TABLE llm_calls;
   -- Restore from backup
   ```

## Success Criteria

After 1 week:
- ✅ >80% of calls have `record_complete = true`
- ✅ >90% of calls have `organization_id`
- ✅ >70% of calls have `pipeline_stage` and `request_type`
- ✅ Zero logging errors in Laravel logs
- ✅ Cost attribution works for all major features

After 1 month:
- ✅ >95% completeness ratio
- ✅ All major call sites updated with metadata
- ✅ Cost optimization decisions made from data
- ✅ Historical data backfilled

## Support Resources

- **Documentation**: `docs/LLM_ACCOUNTING_GUIDE.md`
- **Implementation Details**: `docs/LLM_ACCOUNTING_IMPLEMENTATION.md`
- **Test Script**: `Scratch/test_llm_accounting.php`
- **Migration File**: `database/migrations/2026_01_07_153001_add_complete_accounting_to_llm_calls_table.php`

## Team Notifications

Before deployment, notify:
- [ ] Backend team (code updates needed)
- [ ] Data team (new reporting capabilities)
- [ ] Finance team (cost visibility available)
- [ ] Product team (feature cost insights)

## Questions & Answers

**Q: Will old code break?**
A: No. The `$meta` parameter is optional. Old calls work but log incomplete records.

**Q: Do we need to update all calls immediately?**
A: No. Prioritize high-volume call sites first (Phase 1). Others can wait.

**Q: What if a model isn't in the pricing table?**
A: The system handles it gracefully. Cost will be NULL and `pricing_source` will be NULL. Add the model to `LlmPricingTable::getPricingTable()`.

**Q: Can we add custom fields later?**
A: Yes. Create a new migration. The system is designed to be extended.

**Q: How do we handle background jobs?**
A: Jobs should capture org_id from the job context and pass it to LLMClient.

---

**Deployment Lead**: ___________________  
**Date Deployed**: ___________________  
**Issues Encountered**: ___________________  
**Resolution**: ___________________
