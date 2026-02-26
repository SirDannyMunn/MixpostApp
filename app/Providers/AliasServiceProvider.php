<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Provides backward-compatible class aliases for extracted packages.
 * 
 * This allows existing code to continue using the old namespaces
 * while gradually migrating to the new package namespaces.
 * 
 * @deprecated These aliases exist for backward compatibility only.
 *             Use the new package namespaces for all new code.
 */
class AliasServiceProvider extends ServiceProvider
{
    /**
     * Register class aliases for backward compatibility.
     */
    public function register(): void
    {
        // ============================================================
        // Phase 0: llm-client package aliases
        // ============================================================
        
        // Services
        if (class_exists(\LlmClient\Services\LLMClient::class)) {
            class_alias(\LlmClient\Services\LLMClient::class, \App\Services\Ai\LLMClient::class);
        }
        
        if (class_exists(\LlmClient\Services\LlmCallLogger::class)) {
            class_alias(\LlmClient\Services\LlmCallLogger::class, \App\Services\Ai\LlmCallLogger::class);
        }
        
        if (class_exists(\LlmClient\Services\LlmPricingTable::class)) {
            class_alias(\LlmClient\Services\LlmPricingTable::class, \App\Services\Ai\LlmPricingTable::class);
        }
        
        if (class_exists(\LlmClient\Services\LlmStageTracker::class)) {
            class_alias(\LlmClient\Services\LlmStageTracker::class, \App\Services\Ai\LlmStageTracker::class);
        }
        
        if (class_exists(\LlmClient\Services\SchemaValidator::class)) {
            class_alias(\LlmClient\Services\SchemaValidator::class, \App\Services\Ai\SchemaValidator::class);
        }
        
        if (class_exists(\LlmClient\Services\OpenRouterService::class)) {
            class_alias(\LlmClient\Services\OpenRouterService::class, \App\Services\OpenRouterService::class);
        }
        
        if (class_exists(\LlmClient\Services\OpenAIService::class)) {
            class_alias(\LlmClient\Services\OpenAIService::class, \App\Services\OpenAIService::class);
        }
        
        // Models
        if (class_exists(\LlmClient\Models\LlmCall::class)) {
            class_alias(\LlmClient\Models\LlmCall::class, \App\Models\LlmCall::class);
        }
        
        // Enums
        if (class_exists(\LlmClient\Enums\LlmPipelineStage::class)) {
            class_alias(\LlmClient\Enums\LlmPipelineStage::class, \App\Enums\LlmPipelineStage::class);
        }
        
        if (class_exists(\LlmClient\Enums\LlmRequestType::class)) {
            class_alias(\LlmClient\Enums\LlmRequestType::class, \App\Enums\LlmRequestType::class);
        }
        
        if (class_exists(\LlmClient\Enums\LlmStage::class)) {
            class_alias(\LlmClient\Enums\LlmStage::class, \App\Enums\LlmStage::class);
        }
        
        // ============================================================
        // Phase 1: knowledge-manager package aliases
        // ============================================================
        
        // Ingestion Services
        if (class_exists(\KnowledgeManager\Services\Ingestion\IngestionRunner::class)) {
            class_alias(\KnowledgeManager\Services\Ingestion\IngestionRunner::class, \App\Services\Ingestion\IngestionRunner::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Ingestion\ContentResolver::class)) {
            class_alias(\KnowledgeManager\Services\Ingestion\ContentResolver::class, \App\Services\Ingestion\IngestionContentResolver::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Ingestion\KnowledgeCompiler::class)) {
            class_alias(\KnowledgeManager\Services\Ingestion\KnowledgeCompiler::class, \App\Services\Ingestion\KnowledgeCompiler::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Ingestion\QualityScorer::class)) {
            class_alias(\KnowledgeManager\Services\Ingestion\QualityScorer::class, \App\Services\Ingestion\QualityScorer::class);
        }
        
        // Chunking Services
        if (class_exists(\KnowledgeManager\Services\Chunking\ChunkingCoordinator::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\ChunkingCoordinator::class, \App\Services\Chunking\ChunkingCoordinator::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\ChunkingPreflight::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\ChunkingPreflight::class, \App\Services\Chunking\ChunkingPreflight::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\ChunkingStrategyRouter::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\ChunkingStrategyRouter::class, \App\Services\Chunking\ChunkingStrategyRouter::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\ContentFormatDetector::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\ContentFormatDetector::class, \App\Services\Chunking\ContentFormatDetector::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\ChunkKindResolver::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\ChunkKindResolver::class, \App\Services\Ai\ChunkKindResolver::class);
        }
        
        // Chunking Strategies
        if (class_exists(\KnowledgeManager\Services\Chunking\Strategies\ChunkingStrategy::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\Strategies\ChunkingStrategy::class, \App\Services\Chunking\Strategies\ChunkingStrategy::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\Strategies\FallbackSentenceStrategy::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\Strategies\FallbackSentenceStrategy::class, \App\Services\Chunking\Strategies\FallbackSentenceStrategy::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\Strategies\ListToDataPointsStrategy::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\Strategies\ListToDataPointsStrategy::class, \App\Services\Chunking\Strategies\ListToDataPointsStrategy::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Chunking\Strategies\ShortPostClaimStrategy::class)) {
            class_alias(\KnowledgeManager\Services\Chunking\Strategies\ShortPostClaimStrategy::class, \App\Services\Chunking\Strategies\ShortPostClaimStrategy::class);
        }
        
        // Embeddings Services
        if (class_exists(\KnowledgeManager\Services\Embeddings\EmbeddingsService::class)) {
            class_alias(\KnowledgeManager\Services\Embeddings\EmbeddingsService::class, \App\Services\Ai\EmbeddingsService::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Embeddings\FolderEmbeddingBuilder::class)) {
            class_alias(\KnowledgeManager\Services\Embeddings\FolderEmbeddingBuilder::class, \App\Services\Ai\FolderEmbeddingBuilder::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Embeddings\FolderEmbeddingRepository::class)) {
            class_alias(\KnowledgeManager\Services\Embeddings\FolderEmbeddingRepository::class, \App\Services\Ai\FolderEmbeddingRepository::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Embeddings\FolderEmbeddingScheduler::class)) {
            class_alias(\KnowledgeManager\Services\Embeddings\FolderEmbeddingScheduler::class, \App\Services\Ai\FolderEmbeddingScheduler::class);
        }
        
        // Retrieval & Folders Services
        if (class_exists(\KnowledgeManager\Services\Retrieval\Retriever::class)) {
            class_alias(\KnowledgeManager\Services\Retrieval\Retriever::class, \App\Services\Ai\Retriever::class);
        }
        
        if (class_exists(\KnowledgeManager\Services\Folders\FolderScopeResolver::class)) {
            class_alias(\KnowledgeManager\Services\Folders\FolderScopeResolver::class, \App\Services\Ai\FolderScopeResolver::class);
        }
        
        // Models
        if (class_exists(\KnowledgeManager\Models\KnowledgeItem::class)) {
            class_alias(\KnowledgeManager\Models\KnowledgeItem::class, \App\Models\KnowledgeItem::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\KnowledgeChunk::class)) {
            class_alias(\KnowledgeManager\Models\KnowledgeChunk::class, \App\Models\KnowledgeChunk::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\IngestionSource::class)) {
            class_alias(\KnowledgeManager\Models\IngestionSource::class, \App\Models\IngestionSource::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\Folder::class)) {
            class_alias(\KnowledgeManager\Models\Folder::class, \App\Models\Folder::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\FolderEmbedding::class)) {
            class_alias(\KnowledgeManager\Models\FolderEmbedding::class, \App\Models\FolderEmbedding::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\Bookmark::class)) {
            class_alias(\KnowledgeManager\Models\Bookmark::class, \App\Models\Bookmark::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\IngestionEvaluation::class)) {
            class_alias(\KnowledgeManager\Models\IngestionEvaluation::class, \App\Models\IngestionEvaluation::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\IngestionSourceFolder::class)) {
            class_alias(\KnowledgeManager\Models\IngestionSourceFolder::class, \App\Models\IngestionSourceFolder::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\KnowledgeChunkEvent::class)) {
            class_alias(\KnowledgeManager\Models\KnowledgeChunkEvent::class, \App\Models\KnowledgeChunkEvent::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\KnowledgeLlmOutput::class)) {
            class_alias(\KnowledgeManager\Models\KnowledgeLlmOutput::class, \App\Models\KnowledgeLlmOutput::class);
        }
        
        if (class_exists(\KnowledgeManager\Models\BusinessFact::class)) {
            class_alias(\KnowledgeManager\Models\BusinessFact::class, \App\Models\BusinessFact::class);
        }
        
        // Jobs
        if (class_exists(\KnowledgeManager\Jobs\ProcessIngestionSourceJob::class)) {
            class_alias(\KnowledgeManager\Jobs\ProcessIngestionSourceJob::class, \App\Jobs\ProcessIngestionSourceJob::class);
        }
        
        if (class_exists(\KnowledgeManager\Jobs\ChunkKnowledgeItemJob::class)) {
            class_alias(\KnowledgeManager\Jobs\ChunkKnowledgeItemJob::class, \App\Jobs\ChunkKnowledgeItemJob::class);
        }
        
        if (class_exists(\KnowledgeManager\Jobs\EmbedKnowledgeChunksJob::class)) {
            class_alias(\KnowledgeManager\Jobs\EmbedKnowledgeChunksJob::class, \App\Jobs\EmbedKnowledgeChunksJob::class);
        }
        
        if (class_exists(\KnowledgeManager\Jobs\RebuildFolderEmbeddingJob::class)) {
            class_alias(\KnowledgeManager\Jobs\RebuildFolderEmbeddingJob::class, \App\Jobs\RebuildFolderEmbeddingJob::class);
        }
        
        if (class_exists(\KnowledgeManager\Jobs\ClassifyKnowledgeChunksJob::class)) {
            class_alias(\KnowledgeManager\Jobs\ClassifyKnowledgeChunksJob::class, \App\Jobs\ClassifyKnowledgeChunksJob::class);
        }
        
        // ============================================================
        // Phase 2: content-generation aliases
        // ============================================================
        
        // Voice Services
        if (class_exists(\ContentGeneration\Services\Voice\VoiceProfileBuilderService::class)) {
            class_alias(\ContentGeneration\Services\Voice\VoiceProfileBuilderService::class, \App\Services\Voice\VoiceProfileBuilderService::class);
        }
        if (class_exists(\ContentGeneration\Services\Voice\VoiceTraitsMerger::class)) {
            class_alias(\ContentGeneration\Services\Voice\VoiceTraitsMerger::class, \App\Services\Voice\VoiceTraitsMerger::class);
        }
        if (class_exists(\ContentGeneration\Services\Voice\VoiceTraitsValidator::class)) {
            class_alias(\ContentGeneration\Services\Voice\VoiceTraitsValidator::class, \App\Services\Voice\VoiceTraitsValidator::class);
        }
        if (class_exists(\ContentGeneration\Services\Voice\Prompts\VoiceProfileExtractV2Prompt::class)) {
            class_alias(\ContentGeneration\Services\Voice\Prompts\VoiceProfileExtractV2Prompt::class, \App\Services\Voice\Prompts\VoiceProfileExtractV2Prompt::class);
        }
        
        // Swipe Services
        if (class_exists(\ContentGeneration\Services\Swipes\SwipeStructureService::class)) {
            class_alias(\ContentGeneration\Services\Swipes\SwipeStructureService::class, \App\Services\SwipeStructures\SwipeStructureService::class);
        }
        if (class_exists(\ContentGeneration\Services\Swipes\EphemeralStructureGenerator::class)) {
            class_alias(\ContentGeneration\Services\Swipes\EphemeralStructureGenerator::class, \App\Services\Ai\SwipeStructures\EphemeralStructureGenerator::class);
        }
        
        // Generation Services
        if (class_exists(\ContentGeneration\Services\Generation\ContentGeneratorService::class)) {
            class_alias(\ContentGeneration\Services\Generation\ContentGeneratorService::class, \App\Services\Ai\ContentGeneratorService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\ContextAssembler::class)) {
            class_alias(\ContentGeneration\Services\Generation\ContextAssembler::class, \App\Services\Ai\ContextAssembler::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\PostValidator::class)) {
            class_alias(\ContentGeneration\Services\Generation\PostValidator::class, \App\Services\Ai\PostValidator::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\PostClassifier::class)) {
            class_alias(\ContentGeneration\Services\Generation\PostClassifier::class, \App\Services\Ai\PostClassifier::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\PostQualityEvaluator::class)) {
            class_alias(\ContentGeneration\Services\Generation\PostQualityEvaluator::class, \App\Services\Ai\PostQualityEvaluator::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\SnapshotService::class)) {
            class_alias(\ContentGeneration\Services\Generation\SnapshotService::class, \App\Services\Ai\SnapshotService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\QualityReportService::class)) {
            class_alias(\ContentGeneration\Services\Generation\QualityReportService::class, \App\Services\Ai\QualityReportService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\ContentGenBatchLogger::class)) {
            class_alias(\ContentGeneration\Services\Generation\ContentGenBatchLogger::class, \App\Services\Ai\Generation\ContentGenBatchLogger::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DecisionTraceCollector::class)) {
            class_alias(\ContentGeneration\Services\Generation\DecisionTraceCollector::class, \App\Services\Ai\Generation\DecisionTraceCollector::class);
        }
        
        // Template Services
        if (class_exists(\ContentGeneration\Services\Templates\TemplateSelector::class)) {
            class_alias(\ContentGeneration\Services\Templates\TemplateSelector::class, \App\Services\Ai\TemplateSelector::class);
        }
        
        // Generation Steps
        if (class_exists(\ContentGeneration\Services\Generation\Steps\GenerationRunner::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\GenerationRunner::class, \App\Services\Ai\Generation\Steps\GenerationRunner::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\PromptComposer::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\PromptComposer::class, \App\Services\Ai\Generation\Steps\PromptComposer::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\TemplateService::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\TemplateService::class, \App\Services\Ai\Generation\Steps\TemplateService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\ValidationAndRepairService::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\ValidationAndRepairService::class, \App\Services\Ai\Generation\Steps\ValidationAndRepairService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\BusinessProfileResolver::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\BusinessProfileResolver::class, \App\Services\Ai\Generation\Steps\BusinessProfileResolver::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\CreativeIntelligenceRecommender::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\CreativeIntelligenceRecommender::class, \App\Services\Ai\Generation\Steps\CreativeIntelligenceRecommender::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\EmojiSanitizer::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\EmojiSanitizer::class, \App\Services\Ai\Generation\Steps\EmojiSanitizer::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\OverrideResolver::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\OverrideResolver::class, \App\Services\Ai\Generation\Steps\OverrideResolver::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\PromptInsightSelector::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\PromptInsightSelector::class, \App\Services\Ai\Generation\Steps\PromptInsightSelector::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\PromptSignalExtractor::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\PromptSignalExtractor::class, \App\Services\Ai\Generation\Steps\PromptSignalExtractor::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\ReflexionService::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\ReflexionService::class, \App\Services\Ai\Generation\Steps\ReflexionService::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\RelevanceGate::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\RelevanceGate::class, \App\Services\Ai\Generation\Steps\RelevanceGate::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Steps\SnapshotPersister::class)) {
            class_alias(\ContentGeneration\Services\Generation\Steps\SnapshotPersister::class, \App\Services\Ai\Generation\Steps\SnapshotPersister::class);
        }
        
        // CI Services
        if (class_exists(\ContentGeneration\Services\Generation\Ci\CiHybridRanker::class)) {
            class_alias(\ContentGeneration\Services\Generation\Ci\CiHybridRanker::class, \App\Services\Ai\Generation\Ci\CiHybridRanker::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Ci\CiQueryBuilder::class)) {
            class_alias(\ContentGeneration\Services\Generation\Ci\CiQueryBuilder::class, \App\Services\Ai\Generation\Ci\CiQueryBuilder::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\Ci\CiVectorRepository::class)) {
            class_alias(\ContentGeneration\Services\Generation\Ci\CiVectorRepository::class, \App\Services\Ai\Generation\Ci\CiVectorRepository::class);
        }
        
        // DTO Classes
        if (class_exists(\ContentGeneration\Services\Generation\DTO\GenerationRequest::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\GenerationRequest::class, \App\Services\Ai\Generation\DTO\GenerationRequest::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\Prompt::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\Prompt::class, \App\Services\Ai\Generation\DTO\Prompt::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\PromptBuildResult::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\PromptBuildResult::class, \App\Services\Ai\Generation\DTO\PromptBuildResult::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\PromptSignals::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\PromptSignals::class, \App\Services\Ai\Generation\DTO\PromptSignals::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\Constraints::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\Constraints::class, \App\Services\Ai\Generation\DTO\Constraints::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\CiQuery::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\CiQuery::class, \App\Services\Ai\Generation\DTO\CiQuery::class);
        }
        if (class_exists(\ContentGeneration\Services\Generation\DTO\CiRecommendation::class)) {
            class_alias(\ContentGeneration\Services\Generation\DTO\CiRecommendation::class, \App\Services\Ai\Generation\DTO\CiRecommendation::class);
        }
        
        // Factories
        if (class_exists(\ContentGeneration\Services\Generation\Factories\ContextFactory::class)) {
            class_alias(\ContentGeneration\Services\Generation\Factories\ContextFactory::class, \App\Services\Ai\Generation\Factories\ContextFactory::class);
        }
        
        // Policy
        if (class_exists(\ContentGeneration\Services\Generation\Policy\GenerationPolicy::class)) {
            class_alias(\ContentGeneration\Services\Generation\Policy\GenerationPolicy::class, \App\Services\Ai\Generation\Policy\GenerationPolicy::class);
        }
        
        // Models
        if (class_exists(\ContentGeneration\Models\VoiceProfile::class)) {
            class_alias(\ContentGeneration\Models\VoiceProfile::class, \App\Models\VoiceProfile::class);
        }
        if (class_exists(\ContentGeneration\Models\VoiceProfilePost::class)) {
            class_alias(\ContentGeneration\Models\VoiceProfilePost::class, \App\Models\VoiceProfilePost::class);
        }
        if (class_exists(\ContentGeneration\Models\SwipeItem::class)) {
            class_alias(\ContentGeneration\Models\SwipeItem::class, \App\Models\SwipeItem::class);
        }
        if (class_exists(\ContentGeneration\Models\SwipeStructure::class)) {
            class_alias(\ContentGeneration\Models\SwipeStructure::class, \App\Models\SwipeStructure::class);
        }
        if (class_exists(\ContentGeneration\Models\Template::class)) {
            class_alias(\ContentGeneration\Models\Template::class, \App\Models\Template::class);
        }
        if (class_exists(\ContentGeneration\Models\GeneratedPost::class)) {
            class_alias(\ContentGeneration\Models\GeneratedPost::class, \App\Models\GeneratedPost::class);
        }
        if (class_exists(\ContentGeneration\Models\GenerationSnapshot::class)) {
            class_alias(\ContentGeneration\Models\GenerationSnapshot::class, \App\Models\GenerationSnapshot::class);
        }
        if (class_exists(\ContentGeneration\Models\GenerationQualityReport::class)) {
            class_alias(\ContentGeneration\Models\GenerationQualityReport::class, \App\Models\GenerationQualityReport::class);
        }
        
        // Jobs
        if (class_exists(\ContentGeneration\Jobs\RebuildVoiceProfileJob::class)) {
            class_alias(\ContentGeneration\Jobs\RebuildVoiceProfileJob::class, \App\Jobs\RebuildVoiceProfileJob::class);
        }
        if (class_exists(\ContentGeneration\Jobs\ExtractVoiceTraitsJob::class)) {
            class_alias(\ContentGeneration\Jobs\ExtractVoiceTraitsJob::class, \App\Jobs\ExtractVoiceTraitsJob::class);
        }
        if (class_exists(\ContentGeneration\Jobs\TranscribeVoiceRecordingJob::class)) {
            class_alias(\ContentGeneration\Jobs\TranscribeVoiceRecordingJob::class, \App\Jobs\TranscribeVoiceRecordingJob::class);
        }
        if (class_exists(\ContentGeneration\Jobs\ExtractSwipeStructureJob::class)) {
            class_alias(\ContentGeneration\Jobs\ExtractSwipeStructureJob::class, \App\Jobs\ExtractSwipeStructureJob::class);
        }
        if (class_exists(\ContentGeneration\Jobs\ExtractIngestionSourceStructureJob::class)) {
            class_alias(\ContentGeneration\Jobs\ExtractIngestionSourceStructureJob::class, \App\Jobs\ExtractIngestionSourceStructureJob::class);
        }
        
        // ============================================================
        // Future phases will add more aliases here:
        // - Phase 3: image-generator
        // - Phase 4: research-engine
        // ============================================================
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
