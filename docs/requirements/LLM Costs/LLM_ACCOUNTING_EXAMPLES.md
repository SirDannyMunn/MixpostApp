# Example: Updating Call Sites for LLM Accounting

This document shows real examples of how to update existing code to use the new accounting system.

## Example 1: ContentGeneratorService

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
        'user_id' => $context->userId ?? auth()->id(),
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Example 2: Validation and Repair

### Before
```php
$repaired = $this->llm->call(
    purpose: 'repair',
    system: $repairPrompt,
    user: $draft,
    schema: $schema
);
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$repaired = $this->llm->call(
    purpose: 'repair',
    system: $repairPrompt,
    user: $draft,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::REPAIR,
        'request_type' => LlmRequestType::REPAIR,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Example 3: Knowledge Normalization

### Before
```php
$normalized = $this->llm->call(
    purpose: 'normalize_knowledge_item',
    system: $systemPrompt,
    user: $content,
    schema: $schema
);
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$normalized = $this->llm->call(
    purpose: 'normalize_knowledge_item',
    system: $systemPrompt,
    user: $content,
    schema: $schema,
    meta: [
        'organization_id' => $knowledgeItem->organization_id,
        'user_id' => null, // Background job
        'pipeline_stage' => LlmPipelineStage::INGESTION,
        'request_type' => LlmRequestType::NORMALIZE,
        'related_entity_type' => 'knowledge_item',
        'related_entity_id' => $knowledgeItem->id,
    ]
);
```

## Example 4: Classification

### Before
```php
$classification = $this->llm->call(
    purpose: 'classify',
    system: $classificationPrompt,
    user: $content,
    schema: $schema
);
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$classification = $this->llm->call(
    purpose: 'classify',
    system: $classificationPrompt,
    user: $content,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'pipeline_stage' => LlmPipelineStage::INGESTION,
        'request_type' => LlmRequestType::CLASSIFY,
        'related_entity_type' => 'knowledge_chunk',
        'related_entity_id' => $chunk->id,
    ]
);
```

## Example 5: Reflexion (Critique & Refine)

### Before - Critique
```php
$critique = $this->llm->call(
    purpose: 'reflexion_critique',
    system: $critiquePrompt,
    user: $draft,
    schema: $schema
);
```

### After - Critique
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$critique = $this->llm->call(
    purpose: 'reflexion_critique',
    system: $critiquePrompt,
    user: $draft,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::REFLEXION_CRITIQUE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

### Before - Refine
```php
$refined = $this->llm->call(
    purpose: 'reflexion_refine',
    system: $refinePrompt,
    user: $draft,
    schema: $schema
);
```

### After - Refine
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$refined = $this->llm->call(
    purpose: 'reflexion_refine',
    system: $refinePrompt,
    user: $draft,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::REFLEXION_REFINE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Example 6: Replay from Context

### Before
```php
$regenerated = $this->llm->call(
    purpose: 'replay_generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema
);
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$regenerated = $this->llm->call(
    purpose: 'replay_generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::REPLAY,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);
```

## Example 7: With callWithMeta (Get Provider Response)

### Before
```php
$result = $this->llm->callWithMeta(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema
);

$draft = $result['data'];
$tokens = $result['meta']['usage']['total_tokens'] ?? null;
```

### After
```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$result = $this->llm->callWithMeta(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    options: [],
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        'related_entity_type' => 'generation_snapshot',
        'related_entity_id' => $snapshot->id,
    ]
);

$draft = $result['data'];
$tokens = $result['meta']['usage']['total_tokens'] ?? null;
// Provider metadata is automatically logged!
```

## Pattern: Background Jobs

When calling from a background job without user context:

```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

// Get organization from job context
$organizationId = $this->ingestionSource->organization_id;

$result = $this->llm->call(
    purpose: 'normalize',
    system: $systemPrompt,
    user: $content,
    schema: $schema,
    meta: [
        'organization_id' => $organizationId,
        'user_id' => null, // No user in background job
        'pipeline_stage' => LlmPipelineStage::INGESTION,
        'request_type' => LlmRequestType::NORMALIZE,
        'related_entity_type' => 'knowledge_item',
        'related_entity_id' => $item->id,
    ]
);
```

## Pattern: When You Don't Have Entity ID Yet

Sometimes entity is created after the call:

```php
use App\Enums\LlmPipelineStage;
use App\Enums\LlmRequestType;

$result = $this->llm->call(
    purpose: 'generate',
    system: $systemPrompt,
    user: $userPrompt,
    schema: $schema,
    meta: [
        'organization_id' => $context->organizationId,
        'user_id' => $context->userId,
        'pipeline_stage' => LlmPipelineStage::GENERATION,
        'request_type' => LlmRequestType::GENERATE,
        // Don't have snapshot ID yet - that's OK!
        'related_entity_type' => null,
        'related_entity_id' => null,
    ]
);

// Create entity after
$snapshot = GenerationSnapshot::create([...]);

// Optional: Update the LlmCall record if you need the linkage
// (But usually not necessary - the call is already logged)
```

## Pattern: Minimal Metadata (When in Doubt)

If you're not sure about all the fields, just provide what you know:

```php
$result = $this->llm->call(
    purpose: 'some_operation',
    system: $systemPrompt,
    user: $userPrompt,
    meta: [
        'organization_id' => $org->id, // ALWAYS include this if possible
    ]
);

// Logger will:
// - Accept partial data
// - Mark record as incomplete
// - Still provide value for cost tracking
```

## Quick Reference: Meta Fields

### Always Include (If Available)
```php
'organization_id' => string, // For cost attribution
```

### Strongly Recommended
```php
'user_id' => string|null,           // User tracking
'pipeline_stage' => LlmPipelineStage, // Pipeline visibility
'request_type' => LlmRequestType,     // Operation classification
```

### Optional but Valuable
```php
'related_entity_type' => string|null, // 'knowledge_item' | 'knowledge_chunk' | 'generation_snapshot'
'related_entity_id' => string|null,   // UUID of entity
```

## Enum Quick Reference

### LlmPipelineStage
```php
LlmPipelineStage::INGESTION   // Knowledge ingestion
LlmPipelineStage::RETRIEVAL   // RAG/search
LlmPipelineStage::GENERATION  // Content generation
LlmPipelineStage::REPAIR      // Repair/validation
LlmPipelineStage::EMBEDDING   // Vector embeddings
```

### LlmRequestType
```php
LlmRequestType::INFER_CONTEXT         // Context inference
LlmRequestType::NORMALIZE             // Content normalization
LlmRequestType::CHUNK_EXTRACT         // Chunk extraction
LlmRequestType::EMBED                 // Embedding
LlmRequestType::GENERATE              // Primary generation
LlmRequestType::REPAIR                // Repair
LlmRequestType::CLASSIFY              // Classification
LlmRequestType::REPLAY                // Replay/regeneration
LlmRequestType::SCORE_FOLDER_CANDIDATES
LlmRequestType::TEMPLATE_PARSE
LlmRequestType::FAITHFULNESS_AUDIT
LlmRequestType::SYNTHETIC_QA
LlmRequestType::GENERATION_GRADER
LlmRequestType::REFLEXION_CRITIQUE
LlmRequestType::REFLEXION_REFINE
```

## Testing Your Changes

After updating a call site:

```php
// Make a test call
$result = $service->yourMethod();

// Check the log
$lastCall = \App\Models\LlmCall::orderBy('created_at', 'desc')->first();

dd([
    'has_org' => !is_null($lastCall->organization_id),
    'has_stage' => !is_null($lastCall->pipeline_stage),
    'has_type' => !is_null($lastCall->request_type),
    'has_cost' => !is_null($lastCall->cost_usd),
    'has_tokens' => $lastCall->total_tokens > 0,
    'is_complete' => $lastCall->record_complete,
]);
```

## Common Mistakes to Avoid

### ❌ Wrong: Passing string instead of enum
```php
'pipeline_stage' => 'generation', // String
```

### ✅ Right: Use enum
```php
'pipeline_stage' => LlmPipelineStage::GENERATION,
```

### ❌ Wrong: Forgetting organization_id
```php
meta: [
    'user_id' => $user->id, // Missing org!
]
```

### ✅ Right: Always include organization
```php
meta: [
    'organization_id' => $org->id,
    'user_id' => $user->id,
]
```

### ❌ Wrong: Hardcoding entity type
```php
'related_entity_type' => 'snapshots', // Wrong format
```

### ✅ Right: Use canonical names
```php
'related_entity_type' => 'generation_snapshot', // Singular, snake_case
```

---

**Remember**: The meta parameter is optional. Old code will work, but with incomplete records. Update high-traffic call sites first!
