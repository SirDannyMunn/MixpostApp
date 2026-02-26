<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Services\Ai\EmbeddingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Models\KnowledgeItem;

class EmbedKnowledgeChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Set to a higher value since this job may need to wait for chunking to complete.
     *
     * @var int
     */
    public $tries = 25;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 10;

    public function __construct(public string $knowledgeItemId) {}

    public function handle(EmbeddingsService $embeddings): void
    {
        Log::info('EmbedKnowledgeChunksJob.start', [
            'knowledge_item_id' => $this->knowledgeItemId,
            'attempt' => $this->attempts(),
        ]);
        
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('EmbedKnowledgeChunksJob:' . $this->knowledgeItemId, [
            'knowledge_item_id' => $this->knowledgeItemId,
        ]);
        $chunksQuery = KnowledgeChunk::where('knowledge_item_id', $this->knowledgeItemId)
            ->where('source_variant', 'normalized')
            ->whereNotIn('chunk_role', ['quote', 'other']);

        Log::info('EmbedKnowledgeChunksJob.query_built', [
            'knowledge_item_id' => $this->knowledgeItemId,
            'total_chunks' => $chunksQuery->count(),
        ]);

        // If chunking hasn't completed yet, requeue with a small delay
        if (!$chunksQuery->exists()) {
            Log::info('EmbedKnowledgeChunksJob.no_chunks', [
                'knowledge_item_id' => $this->knowledgeItemId,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);
            
            // If chunking completed with zero valid artifacts, do not requeue indefinitely.
            try {
                $item = KnowledgeItem::find($this->knowledgeItemId);
                $nc = is_array($item?->normalized_claims ?? null) ? $item->normalized_claims : [];
                $chunking = is_array($nc['chunking'] ?? null) ? $nc['chunking'] : [];
                
                // Check if chunking failed or resulted in no valid artifacts
                $chunkingStatus = $chunking['status'] ?? '';
                if ($chunkingStatus === 'no_valid_artifacts' || $chunkingStatus === 'failed') {
                    Log::info('EmbedKnowledgeChunksJob.chunking_completed_no_artifacts', [
                        'knowledge_item_id' => $this->knowledgeItemId,
                        'chunking_status' => $chunkingStatus,
                    ]);
                    $logger->flush('completed_no_chunks');
                    return;
                }
                
                // Check if we've been waiting too long (more than 15 attempts)
                if ($this->attempts() >= 15) {
                    Log::warning('EmbedKnowledgeChunksJob.max_wait_exceeded', [
                        'knowledge_item_id' => $this->knowledgeItemId,
                        'attempt' => $this->attempts(),
                        'chunking_status' => $item->chunking_status ?? 'unknown',
                    ]);
                    // Don't fail the job, just return - chunks may never arrive
                    $logger->flush('max_wait_exceeded');
                    return;
                }
            } catch (\Throwable $e) {
                Log::error('EmbedKnowledgeChunksJob.check_failed', [
                    'knowledge_item_id' => $this->knowledgeItemId,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to requeue.
            }

            Log::info('EmbedKnowledgeChunksJob.requeuing', [
                'knowledge_item_id' => $this->knowledgeItemId,
                'delay_seconds' => 10,
                'attempt' => $this->attempts(),
            ]);
            $this->release(10);
            $logger->flush('waiting_for_chunks');
            return;
        }

        // Process in batches of 100; if more remain, re-dispatch self
        $processed = 0;
        $batchSize = 100;
        // Run a single batch to keep job duration predictable
        $toEmbed = $chunksQuery->whereRaw('embedding_vec IS NULL')
            ->limit($batchSize)
            ->get(['id', 'chunk_text', 'tags', 'source_variant', 'chunk_role']);

        Log::info('EmbedKnowledgeChunksJob.chunks_to_embed', [
            'knowledge_item_id' => $this->knowledgeItemId,
            'count' => $toEmbed->count(),
        ]);

        if ($toEmbed->isNotEmpty()) {
            $texts = $toEmbed->pluck('chunk_text')->all();
            
            Log::info('EmbedKnowledgeChunksJob.calling_embedMany', [
                'knowledge_item_id' => $this->knowledgeItemId,
                'text_count' => count($texts),
                'sample_text_lengths' => array_slice(array_map('strlen', $texts), 0, 5),
            ]);
            
            try {
                $vectors = $embeddings->embedMany($texts);
                Log::info('EmbedKnowledgeChunksJob.embedMany_success', [
                    'knowledge_item_id' => $this->knowledgeItemId,
                    'vector_count' => count($vectors),
                    'first_vector_dim' => isset($vectors[0]) ? count($vectors[0]) : 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('EmbedKnowledgeChunksJob.embedMany_failed', [
                    'knowledge_item_id' => $this->knowledgeItemId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
            
            $logger->capture('embedding.batch', ['count' => count($texts), 'vector_dim' => isset($vectors[0]) ? count($vectors[0]) : 0]);

            DB::beginTransaction();
            try {
                Log::info('EmbedKnowledgeChunksJob.starting_db_updates', [
                    'knowledge_item_id' => $this->knowledgeItemId,
                    'chunks_to_update' => $toEmbed->count(),
                ]);
                
                foreach ($toEmbed->values() as $idx => $row) {
                    $vec = $vectors[$idx] ?? [];
                    if (!is_array($vec) || count($vec) === 0) {
                        Log::warning('EmbedKnowledgeChunksJob.empty_vector', [
                            'knowledge_item_id' => $this->knowledgeItemId,
                            'chunk_id' => $row->id,
                            'index' => $idx,
                        ]);
                        continue;
                    }
                    // Build vector literal for pgvector: '[v1,v2,...]'
                    $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float)$f), '0'), $vec)) . ']';
                    
                    Log::debug('EmbedKnowledgeChunksJob.updating_chunk', [
                        'knowledge_item_id' => $this->knowledgeItemId,
                        'chunk_id' => $row->id,
                        'vector_length' => strlen($literal),
                    ]);
                    
                    DB::update(
                        "UPDATE knowledge_chunks SET embedding_vec = CAST(? AS vector), embedding_model = ? WHERE id = ?",
                        [$literal, 'text-embedding-3-small', $row->id]
                    );
                    $processed++;
                    // Tag embedding metadata including variant
                    try {
                        /** @var \App\Models\KnowledgeChunk|null $kc */
                        $kc = \App\Models\KnowledgeChunk::find($row->id);
                        if ($kc) {
                            $tags = is_array($kc->tags) ? $kc->tags : [];
                            $tags['embedding_meta'] = [
                                'variant' => (string) ($kc->source_variant ?? 'raw'),
                                'model' => 'text-embedding-3-small',
                            ];
                            $kc->tags = $tags;
                            $kc->save();
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal; continue
                    }
                }
                DB::commit();
                Log::info('embeddings.chunks.updated', ['count' => $processed, 'knowledge_item_id' => $this->knowledgeItemId]);
                $logger->capture('embedding.persisted', ['processed' => $processed]);
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('embeddings.chunks.update_failed', ['error' => $e->getMessage()]);
                // Retry later
                $this->release(30);
                $logger->flush('error', ['error' => $e->getMessage()]);
                return;
            }
        }

        // If more rows remain, re-dispatch for the next batch
        $remaining = $chunksQuery->whereRaw('embedding_vec IS NULL')->limit(1)->exists();
        if ($remaining) {
            Log::info('EmbedKnowledgeChunksJob.more_batches_remain', [
                'knowledge_item_id' => $this->knowledgeItemId,
                'processed_this_batch' => $processed,
            ]);
            dispatch(new self($this->knowledgeItemId))->delay(now()->addSeconds(1));
            $logger->flush('requeued', ['processed' => $processed]);
            return;
        }
        
        Log::info('EmbedKnowledgeChunksJob.completed', [
            'knowledge_item_id' => $this->knowledgeItemId,
            'total_processed' => $processed,
        ]);
        $logger->flush('completed', ['processed' => $processed]);
    }
}
