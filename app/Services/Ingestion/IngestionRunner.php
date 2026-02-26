<?php

namespace App\Services\Ingestion;

use App\Jobs\ProcessIngestionSourceJob;
use App\Models\IngestionSource;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestionRunner
{
    /**
     * Ingest raw text via an IngestionSource and run the pipeline, then poll for artifacts.
     * Returns an array with keys: success, source_id, knowledge_item_id, stats, artifacts, error.
     */
    public function ingestText(string $organizationId, string $userId, string $text, ?string $title = null, string $sourceType = 'text', bool $force = false, ?string $evaluationId = null): array
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return ['success' => false, 'error' => 'Empty input'];
        }

        // Prepare metadata and dedup hash; if this is invoked by the eval harness
        // and an evaluation ID is provided, include it to isolate dedup for eval runs.
        $metadata = null;
        $dedupHash = IngestionSource::dedupHashFromText($text);
        if ($evaluationId !== null && $evaluationId !== '') {
            $metadata = ['evaluation_run_id' => $evaluationId];
            $dedupHash = IngestionSource::dedupHashFromTextWithEval($text, $evaluationId);
        }

        $src = IngestionSource::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'source_type' => $sourceType ?: 'text',
            'origin' => 'eval_harness',
            'platform' => null,
            'raw_text' => $text,
            'status' => 'pending',
            'title' => $title,
            'dedup_hash' => $dedupHash,
            'metadata' => $metadata,
        ]);

        try {
            // Kick off the ingestion job chain
            (new ProcessIngestionSourceJob($src->id, $force))->handle();
        } catch (\Throwable $e) {
            Log::warning('eval.ingestion.job_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'source_id' => $src->id];
        }

        // Locate the created knowledge item (may be deduped)
        $itemId = null;
        // Prefer explicit linkage if set by the job (e.g., dedup path)
        try {
            $src->refresh();
            if (!empty($src->knowledge_item_id)) {
                $itemId = (string) $src->knowledge_item_id;
            }
        } catch (\Throwable) {}
        $start = microtime(true);
        $timeout = 90.0; // seconds
        $pollInterval = 1_000_000; // 1s in microseconds
        while ((microtime(true) - $start) < $timeout && !$itemId) {
            // Look up item by ingestion_source_id first, else by hash dedup
            $ki = KnowledgeItem::query()->where('ingestion_source_id', $src->id)->first();
            if (!$ki) {
                $ki = KnowledgeItem::query()
                    ->where('organization_id', $organizationId)
                    ->orderByDesc('created_at')
                    ->first();
            }
            if ($ki) { $itemId = $ki->id; break; }
            usleep($pollInterval);
        }

        if (!$itemId) {
            return ['success' => false, 'error' => 'KnowledgeItem not created', 'source_id' => $src->id];
        }

        // Poll for chunking finish (at least one chunk) and initial embeddings pass
        $hadChunks = false;
        $embeddedSome = false;
        $normClaims = null;
        $normCount = 0;
        $normExecuted = false;
        $start2 = microtime(true);
        while ((microtime(true) - $start2) < $timeout) {
            $ki = KnowledgeItem::find($itemId);
            $norm = is_array($ki?->normalized_claims) ? $ki->normalized_claims : null;
            if (is_array($norm) && isset($norm['claims'])) {
                $normClaims = $norm;
                $normCount = count($norm['claims']);
                $normExecuted = isset($norm['normalization_hash']) || is_array($norm['claims']);
            }
            $chunksCount = KnowledgeChunk::where('knowledge_item_id', $itemId)->count();
            $hadChunks = $chunksCount > 0;
            $embeddedSome = DB::table('knowledge_chunks')
                ->where('knowledge_item_id', $itemId)
                ->whereRaw('embedding_vec IS NOT NULL')
                ->exists();

            // Eval harness invariant: if normalization executed but produced zero claims,
            // do not wait for raw fallback chunking; break early for Phase 0 failure handling.
            try {
                $isEval = (($src->origin ?? '') === 'eval_harness');
            } catch (\Throwable) { $isEval = false; }
            if ($isEval && $normExecuted && $normCount === 0) {
                break;
            }

            if ($hadChunks && $embeddedSome) {
                break;
            }
            usleep($pollInterval);
        }

        // Gather artifacts for report
        $chunks = KnowledgeChunk::query()
            ->where('knowledge_item_id', $itemId)
            ->orderBy('created_at', 'asc')
            ->get([
                'id','chunk_text','chunk_type','chunk_role','authority','confidence','time_horizon','source_type','source_variant','tags','token_count','created_at'
            ])->toArray();

        // Compute embedding coverage
        $total = count($chunks);
        $embedded = (int) DB::table('knowledge_chunks')
            ->where('knowledge_item_id', $itemId)
            ->whereRaw('embedding_vec IS NOT NULL')
            ->count();
        $coverage = $total > 0 ? round($embedded / $total, 3) : 0.0;

        return [
            'success' => true,
            'source_id' => $src->id,
            'knowledge_item_id' => $itemId,
            'stats' => [
                'chunks_total' => $total,
                'embedded' => $embedded,
                'embedding_coverage' => $coverage,
                'normalized_claims_count' => $normCount,
            ],
            'artifacts' => [
                'normalized_claims' => $normClaims,
                'chunks' => $chunks,
            ],
        ];
    }
}
