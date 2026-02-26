# Main App Package Decomposition Plan

> **Status:** Planning  
> **Created:** January 29, 2026  
> **Author:** AI Assistant  
> **Prerequisite:** Complete `social-watcher` decomposition first (see `packages/social-watcher/docs/PACKAGE_DECOMPOSITION_PLAN.md`)

## Overview

This document outlines the plan to extract domain-specific packages from the main Laravel `app/` directory. The goal is to create focused, reusable packages while keeping the main app as a thin orchestration layer.

## Refactoring Approach

### ⚠️ CRITICAL: Copy-and-Update Strategy

**DO NOT rewrite or regenerate files.** The refactoring approach is:

1. **Copy files exactly as they are** to their new package location
2. **Update namespaces only** using find-and-replace
3. **Update use statements** in copied files
4. **Create backward-compatible aliases** in the main app for gradual migration
5. **Keep route URLs unchanged** to avoid frontend breaking changes

### Find-and-Replace Patterns

For each package extraction, apply these replacements:

```
# Example for knowledge-manager package
Find:    namespace App\Services\Ingestion;
Replace: namespace KnowledgeManager\Services\Ingestion;

Find:    namespace App\Services\Chunking;
Replace: namespace KnowledgeManager\Services\Chunking;

Find:    namespace App\Models;
Replace: namespace KnowledgeManager\Models;

Find:    use App\Services\Ingestion\
Replace: use KnowledgeManager\Services\Ingestion\

Find:    use App\Models\KnowledgeItem;
Replace: use KnowledgeManager\Models\KnowledgeItem;
```

### Route URL Preservation

All extracted packages must keep existing route URLs:

```php
// In package routes/api.php
// Keep: /api/ingestion-sources, /api/knowledge-items, etc.
// NOT: /api/knowledge-manager/ingestion-sources
```

---

## Target Package Structure

```
backend/packages/
├── llm-client/                 # Foundation: LLM infrastructure
├── knowledge-manager/          # Ingestion, chunking, embeddings, retrieval
├── voice-profile/              # Voice extraction and traits analysis
├── swipe-analyzer/             # Structure extraction from example posts
├── image-generator/            # Multi-provider AI image generation
└── research-engine/            # Trend discovery, competitive analysis (FUTURE)
```

---

## Package 0: `llm-client` (Foundation)

**Purpose:** Shared LLM infrastructure used by all packages.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/OpenRouterService.php` | `llm-client/src/Services/OpenRouterService.php` |
| `app/Services/OpenAIService.php` | `llm-client/src/Services/OpenAIService.php` |
| `app/Services/Ai/LLMClient.php` | `llm-client/src/Services/LLMClient.php` |
| `app/Services/Ai/LlmCallLogger.php` | `llm-client/src/Services/LlmCallLogger.php` |
| `app/Services/Ai/LlmPricingTable.php` | `llm-client/src/Services/LlmPricingTable.php` |
| `app/Services/Ai/LlmStageTracker.php` | `llm-client/src/Services/LlmStageTracker.php` |
| `app/Services/Ai/SchemaValidator.php` | `llm-client/src/Services/SchemaValidator.php` |

### Enums to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Enums/LlmPipelineStage.php` | `llm-client/src/Enums/LlmPipelineStage.php` |
| `app/Enums/LlmRequestType.php` | `llm-client/src/Enums/LlmRequestType.php` |
| `app/Enums/LlmStage.php` | `llm-client/src/Enums/LlmStage.php` |

### Models to Extract

| Model | Purpose |
|-------|---------|
| `LlmCall` | LLM call logging and tracking |

### Contracts to Create

```php
// llm-client/src/Contracts/LlmClientInterface.php
interface LlmClientInterface
{
    public function chat(array $messages, array $options = []): LlmResponse;
    public function embed(string|array $input): array;
}
```

### Config

- `config/llm-client.php` (merge from `config/ai.php` relevant sections)

### Dependencies

- None (foundation package)

### Depended On By

- All other packages

---

## Package 1: `knowledge-manager`

**Purpose:** Content ingestion, chunking, embeddings, and retrieval for RAG.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/Ingestion/IngestionRunner.php` | `knowledge-manager/src/Services/Ingestion/IngestionRunner.php` |
| `app/Services/Ingestion/IngestionContentResolver.php` | `knowledge-manager/src/Services/Ingestion/ContentResolver.php` |
| `app/Services/Ingestion/KnowledgeCompiler.php` | `knowledge-manager/src/Services/Ingestion/KnowledgeCompiler.php` |
| `app/Services/Ingestion/QualityScorer.php` | `knowledge-manager/src/Services/Ingestion/QualityScorer.php` |
| `app/Services/Chunking/ChunkingCoordinator.php` | `knowledge-manager/src/Services/Chunking/ChunkingCoordinator.php` |
| `app/Services/Chunking/ChunkingPreflight.php` | `knowledge-manager/src/Services/Chunking/ChunkingPreflight.php` |
| `app/Services/Chunking/ChunkingStrategyRouter.php` | `knowledge-manager/src/Services/Chunking/StrategyRouter.php` |
| `app/Services/Chunking/ContentFormatDetector.php` | `knowledge-manager/src/Services/Chunking/ContentFormatDetector.php` |
| `app/Services/Chunking/Strategies/*` | `knowledge-manager/src/Services/Chunking/Strategies/*` |
| `app/Services/Ai/Retriever.php` | `knowledge-manager/src/Services/Retrieval/Retriever.php` |
| `app/Services/Ai/EmbeddingsService.php` | `knowledge-manager/src/Services/Embeddings/EmbeddingsService.php` |
| `app/Services/Ai/FolderEmbeddingBuilder.php` | `knowledge-manager/src/Services/Embeddings/FolderEmbeddingBuilder.php` |
| `app/Services/Ai/FolderEmbeddingRepository.php` | `knowledge-manager/src/Services/Embeddings/FolderEmbeddingRepository.php` |
| `app/Services/Ai/FolderEmbeddingScheduler.php` | `knowledge-manager/src/Services/Embeddings/FolderEmbeddingScheduler.php` |
| `app/Services/Ai/FolderScopeResolver.php` | `knowledge-manager/src/Services/Folders/FolderScopeResolver.php` |
| `app/Services/Ai/ChunkKindResolver.php` | `knowledge-manager/src/Services/Chunking/ChunkKindResolver.php` |
| `app/Services/Ai/Evaluation/*` | `knowledge-manager/src/Services/Evaluation/*` |

### Models to Extract

| Model | Purpose |
|-------|---------|
| `KnowledgeItem` | Source documents for knowledge base |
| `KnowledgeChunk` | Chunked text segments |
| `KnowledgeChunkEvent` | Chunk processing events |
| `KnowledgeLlmOutput` | LLM outputs during processing |
| `IngestionSource` | External content sources |
| `IngestionSourceFolder` | Source-folder mappings |
| `IngestionEvaluation` | Ingestion quality evaluations |
| `Folder` | Knowledge organization folders |
| `FolderEmbedding` | Folder-level embeddings |
| `Bookmark` | User bookmarks → knowledge items |

### Jobs to Extract

- `ProcessIngestionSourceJob`
- `ChunkKnowledgeItemJob`
- `EmbedKnowledgeChunksJob`
- `ClassifyKnowledgeChunksJob`
- `NormalizeKnowledgeItemJob`
- `BookmarkToKnowledgeItemJob`
- `InferContextFolderJob`
- `RebuildFolderEmbeddingJob`
- `ScoreFolderCandidatesJob`

### Controllers to Extract

- `IngestionController`
- `IngestionSourceController`
- `KnowledgeItemController`
- `KnowledgeChunkController`
- `FolderController`
- `BookmarkController`
- `SearchController`

### Events to Publish

- `KnowledgeItemCreated`
- `KnowledgeItemChunked`
- `ChunksEmbedded`
- `IngestionSourceProcessed`

### Route URLs (Unchanged)

```
GET/POST   /api/ingestion-sources
GET/POST   /api/knowledge-items
GET/POST   /api/knowledge-chunks
GET/POST   /api/folders
GET/POST   /api/bookmarks
GET        /api/search
```

### Dependencies

- `llm-client` (for embeddings, chunk classification)

### Depended On By

- `voice-profile`, `swipe-analyzer`, `research-engine`, main app

---

## Package 2: `voice-profile`

**Purpose:** Writing voice extraction, traits analysis, and profile building.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/Voice/VoiceProfileBuilderService.php` | `voice-profile/src/Services/VoiceProfileBuilder.php` |
| `app/Services/Voice/VoiceTraitsMerger.php` | `voice-profile/src/Services/VoiceTraitsMerger.php` |
| `app/Services/Voice/VoiceTraitsValidator.php` | `voice-profile/src/Services/VoiceTraitsValidator.php` |
| `app/Services/Voice/Prompts/*` | `voice-profile/src/Prompts/*` |

### Models to Extract

| Model | Purpose |
|-------|---------|
| `VoiceProfile` | User's writing voice profile |
| `VoiceProfilePost` | Sample posts for voice extraction |

### Jobs to Extract

- `RebuildVoiceProfileJob`
- `ExtractVoiceTraitsJob`
- `TranscribeVoiceRecordingJob`

### Controllers to Extract

- `VoiceProfileController`

### Route URLs (Unchanged)

```
GET/POST/PUT/DELETE  /api/voice-profiles
POST                 /api/voice-profiles/{id}/rebuild
```

### Dependencies

- `llm-client` (for trait extraction)
- `social-watcher` or `content-ingestion` (for ContentNode samples - optional)

### Depended On By

- Main app (Content Generation)

---

## Package 3: `swipe-analyzer`

**Purpose:** Structural analysis and template extraction from example posts.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/SwipeStructures/SwipeStructureService.php` | `swipe-analyzer/src/Services/SwipeStructureService.php` |
| `app/Services/Ai/SwipeStructures/EphemeralStructureGenerator.php` | `swipe-analyzer/src/Services/EphemeralStructureGenerator.php` |

### Models to Extract

| Model | Purpose |
|-------|---------|
| `SwipeItem` | Example posts ("swipe files") |
| `SwipeStructure` | Extracted structural templates |

### Jobs to Extract

- `ExtractSwipeStructureJob`
- `ExtractIngestionSourceStructureJob`

### Controllers to Extract

- `SwipeItemController`
- `SwipeStructureController`

### Route URLs (Unchanged)

```
GET/POST/PUT/DELETE  /api/swipe-items
GET/POST             /api/swipe-structures
```

### Dependencies

- `llm-client` (for structure extraction)

### Depended On By

- Main app (Content Generation uses structures as templates)

---

## Package 4: `image-generator`

**Purpose:** Multi-provider AI image generation.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/AIImageGenerationService.php` | `image-generator/src/Services/ImageGenerationService.php` |
| `app/Services/MediaImageService.php` | `image-generator/src/Services/MediaImageService.php` |
| `app/Services/ImageGeneration/ImageGeneratorRouter.php` | `image-generator/src/Services/ImageGeneratorRouter.php` |
| `app/Services/ImageGeneration/ImageProviderInterface.php` | `image-generator/src/Contracts/ImageProviderInterface.php` |
| `app/Services/ImageGeneration/Providers/*` | `image-generator/src/Providers/*` |

### Models to Extract

| Model | Purpose |
|-------|---------|
| `MediaImage` | Generated images |
| `MediaPack` | Image collections |

### Controllers to Extract

- `MediaImageController`
- `MediaPackController`

### Route URLs (Unchanged)

```
GET/POST/PUT/DELETE  /api/media-images
GET/POST             /api/media-packs
```

### Dependencies

- `llm-client` (for API clients to image providers)

### Depended On By

- Main app (optional image generation in content)

---

## Package 5: `research-engine` (FUTURE)

> ⚠️ **BLOCKED:** Wait until `social-watcher` decomposition is complete and `content-ingestion` package is stable.

**Purpose:** Trend discovery, competitive analysis, and content research.

### Services to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Services/Ai/Research/ResearchExecutor.php` | `research-engine/src/Services/ResearchExecutor.php` |
| `app/Services/Ai/Research/ResearchReportComposer.php` | `research-engine/src/Services/ResearchReportComposer.php` |
| `app/Services/Ai/Research/ResearchPromptComposer.php` | `research-engine/src/Services/ResearchPromptComposer.php` |
| `app/Services/Ai/Research/TrendDiscoveryService.php` | `research-engine/src/Services/TrendDiscoveryService.php` |
| `app/Services/Ai/Research/HookGenerationService.php` | `research-engine/src/Services/HookGenerationService.php` |
| `app/Services/Ai/Research/IndustryClassifier.php` | `research-engine/src/Services/IndustryClassifier.php` |
| `app/Services/Ai/Research/DTO/*` | `research-engine/src/DTO/*` |
| `app/Services/Ai/Research/Embeddings/*` | `research-engine/src/Services/Embeddings/*` |
| `app/Services/Ai/Research/Sources/*` | `research-engine/src/Services/Sources/*` |
| `app/Services/Ai/Research/Formatters/*` | `research-engine/src/Services/Formatters/*` |
| `app/Services/Ai/Research/Mappers/*` | `research-engine/src/Services/Mappers/*` |

### Enums to Extract

| Current Location | New Location |
|------------------|--------------|
| `app/Enums/ResearchStage.php` | `research-engine/src/Enums/ResearchStage.php` |

### Controllers to Extract

- `ResearchController`

### Route URLs (Unchanged)

```
POST  /api/research
GET   /api/research/{id}
```

### Dependencies

- `llm-client`
- `content-ingestion` (from social-watcher decomposition)
- `knowledge-manager`

### Extraction Prerequisites

1. ✅ `social-watcher` decomposition plan complete
2. ⏳ `content-ingestion` package extracted and stable
3. ⏳ Event-driven integration between packages working
4. ⏳ All dependent packages using new namespaces

---

## What Stays in Main App

The following remain in `app/` as the orchestration layer:

### Content Generation (Core Business Logic)

- `app/Services/Ai/ContentGeneratorService.php`
- `app/Services/Ai/ContextAssembler.php`
- `app/Services/Ai/TemplateSelector.php`
- `app/Services/Ai/PostValidator.php`
- `app/Services/Ai/PostClassifier.php`
- `app/Services/Ai/PostQualityEvaluator.php`
- `app/Services/Ai/SnapshotService.php`
- `app/Services/Ai/QualityReportService.php`
- `app/Services/Ai/Generation/*`
- Related models: `GeneratedPost`, `GenerationSnapshot`, `GenerationQualityReport`, `Template`
- Controllers: `AiController`, `TemplateController`, `LibraryItemController`

### Content Planning

- `app/Services/ContentPlannerService.php`
- Models: `ContentPlan`, `ContentPlanStage`, `ContentPlanPost`
- Controllers: `ContentPlanController`

### AI Canvas

- Models: `AiCanvasConversation`, `AiCanvasMessage`, `AiCanvasDocumentVersion`
- Controllers: `AiCanvasConversationController`, `AiCanvasMessageController`, `AiCanvasVersionController`

### Business Context

- `app/Services/Ai/BusinessProfileService.php`
- `app/Services/Ai/BusinessProfileDistiller.php`
- Models: `BusinessFact`
- Controllers: `BusinessFactController`

### Scheduling & Publishing

- Models: `ScheduledPost`, `ScheduledPostAccount`, `SocialAccount`, `SocialAnalytics`
- Controllers: `ScheduledPostController`, `SocialAccountController`, `AnalyticsController`

### Core Infrastructure

- Models: `User`, `Organization`, `OrganizationMember`, `Account`, `Project`, `ActivityLog`, `Tag`
- Controllers: `AuthController`, `OrganizationController`, `OrganizationMemberController`, `ProjectController`, `TagController`

---

## Extraction Order

```
Phase 0: llm-client (foundation - no dependencies)
    ↓
Phase 1: knowledge-manager (depends on llm-client)
    ↓
Phase 2: voice-profile (depends on llm-client)
    ↓
Phase 3: swipe-analyzer (depends on llm-client)
    ↓
Phase 4: image-generator (depends on llm-client)
    ↓
[WAIT: Complete social-watcher decomposition]
    ↓
Phase 5: research-engine (depends on llm-client, content-ingestion, knowledge-manager)
```

---

## Namespace Changes Summary

### `llm-client`

```
App\Services\OpenRouterService      → LlmClient\Services\OpenRouterService
App\Services\Ai\LLMClient           → LlmClient\Services\LLMClient
App\Services\Ai\LlmCallLogger       → LlmClient\Services\LlmCallLogger
App\Enums\LlmStage                  → LlmClient\Enums\LlmStage
App\Models\LlmCall                  → LlmClient\Models\LlmCall
```

### `knowledge-manager`

```
App\Services\Ingestion\*            → KnowledgeManager\Services\Ingestion\*
App\Services\Chunking\*             → KnowledgeManager\Services\Chunking\*
App\Services\Ai\Retriever           → KnowledgeManager\Services\Retrieval\Retriever
App\Services\Ai\EmbeddingsService   → KnowledgeManager\Services\Embeddings\EmbeddingsService
App\Models\KnowledgeItem            → KnowledgeManager\Models\KnowledgeItem
App\Models\KnowledgeChunk           → KnowledgeManager\Models\KnowledgeChunk
App\Models\Folder                   → KnowledgeManager\Models\Folder
App\Jobs\ChunkKnowledgeItemJob      → KnowledgeManager\Jobs\ChunkKnowledgeItemJob
```

### `voice-profile`

```
App\Services\Voice\*                → VoiceProfile\Services\*
App\Models\VoiceProfile             → VoiceProfile\Models\VoiceProfile
App\Models\VoiceProfilePost         → VoiceProfile\Models\VoiceProfilePost
App\Jobs\RebuildVoiceProfileJob     → VoiceProfile\Jobs\RebuildVoiceProfileJob
```

### `swipe-analyzer`

```
App\Services\SwipeStructures\*      → SwipeAnalyzer\Services\*
App\Models\SwipeItem                → SwipeAnalyzer\Models\SwipeItem
App\Models\SwipeStructure           → SwipeAnalyzer\Models\SwipeStructure
App\Jobs\ExtractSwipeStructureJob   → SwipeAnalyzer\Jobs\ExtractSwipeStructureJob
```

### `image-generator`

```
App\Services\AIImageGenerationService    → ImageGenerator\Services\ImageGenerationService
App\Services\ImageGeneration\*           → ImageGenerator\Services\*
App\Models\MediaImage                    → ImageGenerator\Models\MediaImage
App\Models\MediaPack                     → ImageGenerator\Models\MediaPack
```

---

## Backward Compatibility

Each extraction phase creates aliases in the main app:

```php
// app/Providers/AppServiceProvider.php (or dedicated AliasServiceProvider)

public function register()
{
    // Phase 0: llm-client aliases
    class_alias(\LlmClient\Services\LLMClient::class, \App\Services\Ai\LLMClient::class);
    class_alias(\LlmClient\Models\LlmCall::class, \App\Models\LlmCall::class);
    
    // Phase 1: knowledge-manager aliases
    class_alias(\KnowledgeManager\Models\KnowledgeItem::class, \App\Models\KnowledgeItem::class);
    class_alias(\KnowledgeManager\Models\Folder::class, \App\Models\Folder::class);
    
    // ... etc
}
```

Add `@deprecated` docblocks to guide migration:

```php
/**
 * @deprecated Use \KnowledgeManager\Models\KnowledgeItem instead
 * @see \KnowledgeManager\Models\KnowledgeItem
 */
```

---

## Checklist for Each Package Extraction

- [ ] Create package directory structure
- [ ] Create `composer.json` with dependencies
- [ ] Create service provider
- [ ] **Copy files exactly** (no refactoring)
- [ ] Find-and-replace namespaces in copied files
- [ ] Update `use` statements
- [ ] Create config file (merge relevant sections)
- [ ] Copy migrations (if any new tables)
- [ ] Copy routes (keep existing URLs)
- [ ] Register in main app's `composer.json`
- [ ] Register service provider
- [ ] Create backward-compatible aliases
- [ ] Run existing tests
- [ ] Write package-specific tests

---

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Breaking existing code | Backward-compatible aliases, no URL changes |
| Circular dependencies | Clear dependency graph, foundation package first |
| Missing imports | Thorough find-and-replace, run tests after each phase |
| Config conflicts | Prefix config keys with package name |
| Migration issues | Keep existing table names and prefixes |

---

## Open Questions

1. **Vendor prefix for packages:** Use `velocity/` or `laundryos/` as composer vendor prefix?
   - *Recommendation:* Use consistent prefix across all packages

2. **Folder model:** Keep in `knowledge-manager` or extract to shared package since it's used for organization?
   - *Recommendation:* Keep in `knowledge-manager` as primary owner

3. **ReferenceResolverService:** Where does this belong?
   - *Recommendation:* Keep in main app (used by content generation)

4. **BlueprintService:** Purpose unclear - investigate before deciding placement
   - *Action:* Review service before extraction

---

## Next Steps

1. ✅ Review and approve this plan
2. ⏳ Complete `social-watcher` decomposition first
3. ⏳ Begin Phase 0: Extract `llm-client`
4. ⏳ Continue through phases incrementally
