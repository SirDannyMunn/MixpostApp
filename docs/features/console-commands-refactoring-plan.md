Reviewed the jobs and artisan commands in app/Jobs and app/Console/Commands and drafted a reorg plan with consistent naming and foldering; no code changes made. The goal is to group by domain (ingestion, knowledge, AI, reports, maintenance) and apply a clear Verb+Object naming pattern.

Plan

Inventory every job/command and tag by domain (ingestion, knowledge, AI, reports, maintenance, debug).
Standardize naming: Verb + Object + Job/Command, avoid mixed prefixes (Ai*, Backfill*, Debug*, etc.).
Move into domain folders and rename classes/files for clarity.
If implemented later: update namespaces/usages, adjust Kernel.php, and fix any string references.
Naming conventions (proposal)

Jobs: Verb + Object + Job (e.g., ExtractBusinessFactsFromKnowledgeItemJob)
Commands: Verb + Object + Command (e.g., EvaluateIngestionCommand)
Domain folders: Ingestion, Knowledge, Ai, Reports, Maintenance, Debug, Swipes, Voice, Templates, Generation
Jobs — proposed folders and new names
Ingestion

BookmarkToKnowledgeItemJob.php -> IngestBookmarkToKnowledgeItemJob.php
ProcessIngestionSourceJob.php -> ProcessIngestionSourceJob.php (move only)
ConvertNormalizedContentToIngestionSourceJob.php -> CreateIngestionSourceFromNormalizedContentJob.php
ExtractIngestionSourceStructureJob.php -> ExtractSwipeStructureFromIngestionSourceJob.php
Folders

InferContextFolderJob.php -> InferIngestionSourceFolderJob.php
ScoreFolderCandidatesJob.php -> ScoreIngestionFolderCandidatesJob.php
RebuildFolderEmbeddingJob.php -> RebuildFolderEmbeddingJob.php (move only)
Knowledge

NormalizeKnowledgeItemJob.php -> NormalizeKnowledgeItemJob.php (move only)
ChunkKnowledgeItemJob.php -> ChunkKnowledgeItemJob.php (move only)
ClassifyKnowledgeChunksJob.php -> ClassifyKnowledgeChunksJob.php (move only)
EmbedKnowledgeChunksJob.php -> GenerateKnowledgeChunkEmbeddingsJob.php
ExtractBusinessFactsJob.php -> ExtractBusinessFactsFromKnowledgeItemJob.php
Voice

ExtractVoiceTraitsJob.php -> ExtractVoiceTraitsFromKnowledgeItemJob.php
Swipes

ExtractSwipeStructureJob.php -> ExtractSwipeStructureFromSwipeItemJob.php
Templates

ParseTemplateFromTextJob.php -> ParseTemplateFromTextJob.php (move only)
Generation

GeneratePostJob.php -> GeneratePostJob.php (move only)
Commands — proposed folders and new names
AI

AiIngestionEval.php -> EvaluateIngestionCommand.php
HydrateAiContext.php -> HydrateAiContextCommand.php
AiResearchCommand.php -> AskResearchCommand.php
ShowPrompt.php -> ShowPromptCommand.php
ListSnapshots.php -> ListGenerationSnapshotsCommand.php
ReplaySnapshot.php -> ReplayGenerationSnapshotCommand.php
Ingestion

ProcessIngestionSource.php -> ProcessIngestionSourceCommand.php
ConvertNormalizedContentToIngestionSource.php -> ConvertNormalizedContentToIngestionSourceCommand.php
BackfillIngestionSources.php -> BackfillIngestionSourcesCommand.php
BackfillIngestionSourceFolders.php -> BackfillIngestionSourceFoldersCommand.php
BackfillBookmarkIngestionSources.php -> BackfillBookmarkIngestionSourcesCommand.php
Social Watcher

RunNormalizeContentItemJobSync.php -> NormalizeContentItemCommand.php
Voice

VoiceAttachPosts.php -> AttachVoicePostsCommand.php
Knowledge / Swipes

ExportBusinessFacts.php -> ExportBusinessFactsCommand.php
SeedSwipesFromBookmarks.php -> SeedSwipesFromBookmarksCommand.php
Reports

ContentServiceGetReport.php -> GetContentServiceReportCommand.php
LlmAccountingStatus.php -> ShowLlmAccountingStatusCommand.php
Debug

DebugListOrgUser.php -> ListOrgUserIdsCommand.php
TinkerDebug.php -> RunTinkerDebugScriptCommand.php
Maintenance

Publish.php -> PublishAssetsCommand.php
SetupGitIgnore.php -> SetupGitIgnoreCommand.php
VerifyPgVector.php -> VerifyPgVectorCommand.php
Do you want the artisan command signatures renamed to match this convention too, or keep signatures as-is and only rename/move the files and classes?

