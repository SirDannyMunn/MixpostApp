<?php

namespace App\Services\Chunking;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeChunk;
use App\Services\Chunking\ChunkingPreflight;
use App\Services\Chunking\ContentFormatDetector;
use App\Services\Chunking\ChunkingStrategyRouter;
use App\Services\Ingestion\KnowledgeCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChunkingCoordinator
{
    public function __construct(
        protected ChunkingPreflight $preflight,
        protected ContentFormatDetector $formatDetector,
        protected ChunkingStrategyRouter $router,
        protected KnowledgeCompiler $compiler
    ) {}

    public function processItem(KnowledgeItem $item): array
    {
        $startTime = microtime(true);
        $config = config('chunking', []);
        $now = now();

        // Step 1: Preflight gating
        $preflightResult = $this->preflight->check($item, $config);
        
        if (!$preflightResult['eligible']) {
            $this->persistSkipDiagnostics($item, $preflightResult);
            return [
                'status' => 'skipped',
                'reason' => $preflightResult['skip_reason'],
                'metrics' => $preflightResult['metrics'],
            ];
        }

        $metrics = $preflightResult['metrics'];
        $rawText = trim((string) $item->raw_text);

        // Step 2: Format detection
        $format = $this->formatDetector->detect($rawText);
        $metrics['detected_format'] = $format;

        // Step 3: Strategy selection
        $tokenCount = $metrics['clean_tokens_est'];
        
        // Try LLM extraction first for suitable content
        if ($this->router->shouldUseLLMExtraction($format, $tokenCount)) {
            $llmChunks = $this->tryLLMExtraction($item);
            
            if (!empty($llmChunks)) {
                // LLM succeeded
                $metrics['strategy_used'] = 'llm_claim_extractor';
                $metrics['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                
                $result = $this->persistChunks($item, $llmChunks, $metrics, 'llm_claim_extractor', $now);
                return $result;
            }
        }

        // Fallback to deterministic strategies
        $strategy = $this->router->selectStrategy($format, $tokenCount);
        $strategyName = class_basename(get_class($strategy));
        $metrics['strategy_used'] = $strategyName;

        try {
            $chunks = $strategy->generateChunks($item, $rawText);
            
            if (empty($chunks)) {
                $metrics['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                $this->persistFailedDiagnostics($item, 'extractor_returned_empty', null, $metrics);
                return [
                    'status' => 'failed',
                    'error_code' => 'extractor_returned_empty',
                    'metrics' => $metrics,
                ];
            }

            $metrics['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $result = $this->persistChunks($item, $chunks, $metrics, $strategyName, $now);
            return $result;

        } catch (\Throwable $e) {
            $metrics['duration_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $this->persistFailedDiagnostics($item, 'parser_error', $e->getMessage(), $metrics);
            return [
                'status' => 'failed',
                'error_code' => 'parser_error',
                'error_message' => $e->getMessage(),
                'metrics' => $metrics,
            ];
        }
    }

    private function tryLLMExtraction(KnowledgeItem $item): array
    {
        // Check if we have normalized artifacts from the LLM normalization step
        $normalized = is_array($item->normalized_claims ?? null)
            && ($item->normalized_claims['schema_version'] ?? '') === 'knowledge_compiler_v1'
            && isset($item->normalized_claims['artifacts'])
            && is_array($item->normalized_claims['artifacts'])
            && count($item->normalized_claims['artifacts']) > 0;

        if (!$normalized) {
            return [];
        }

        $artifacts = (array) $item->normalized_claims['artifacts'];
        $chunks = [];

        foreach (array_slice($artifacts, 0, 200) as $a) {
            if (!is_array($a)) continue;

            $text = trim((string) ($a['claim'] ?? ''));
            if ($text === '') continue;

            $ctx = is_array($a['context'] ?? null) ? $a['context'] : [];
            $domain = $this->compiler->normalizeDomain((string) ($ctx['domain'] ?? ''));
            $actor = trim((string) ($ctx['actor'] ?? ''));
            $timeframe = trim((string) ($ctx['timeframe'] ?? 'unknown'));

            $role = trim((string) ($a['role'] ?? 'strategic_claim'));
            $authority = strtolower(trim((string) ($a['authority'] ?? 'medium')));
            $confidence = max(0.0, min(1.0, (float) ($a['confidence'] ?? 0.6)));

            $chunks[] = [
                'text' => $text,
                'role' => $role,
                'authority' => $authority,
                'confidence' => $confidence,
                'domain' => $domain,
                'actor' => $actor,
                'timeframe' => $timeframe,
                'token_count' => $this->estimateTokens($text),
                'source_text' => null, // LLM artifacts don't have direct source text
                'source_spans' => null,
                'transformation_type' => 'normalized',
            ];
        }

        return $chunks;
    }

    private function persistChunks(KnowledgeItem $item, array $chunks, array $metrics, string $strategy, $now): array
    {
        $sourceType = $item->source === 'manual' ? 'text' : $item->source;
        $rowsToInsert = [];

        foreach ($chunks as $chunk) {
            $rowsToInsert[] = [
                'id' => (string) Str::uuid(),
                'knowledge_item_id' => $item->id,
                'organization_id' => $item->organization_id,
                'user_id' => $item->user_id,
                'chunk_text' => $chunk['text'],
                'chunk_type' => 'normalized_knowledge',
                'chunk_role' => $chunk['role'],
                'authority' => $chunk['authority'],
                'confidence' => $chunk['confidence'],
                'time_horizon' => $this->mapTimeHorizon($chunk['timeframe'] ?? 'unknown'),
                'domain' => $chunk['domain'] ?? null,
                'actor' => $chunk['actor'] ?? null,
                'source_type' => $sourceType,
                'source_variant' => 'normalized',
                'source_ref' => json_encode([
                    'ingestion_source_id' => $item->ingestion_source_id,
                    'knowledge_item_id' => $item->id,
                ], JSON_UNESCAPED_UNICODE),
                'tags' => json_encode($chunk['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'token_count' => $chunk['token_count'],
                'source_text' => $chunk['source_text'] ?? null,
                'source_spans' => isset($chunk['source_spans']) ? json_encode($chunk['source_spans'], JSON_UNESCAPED_UNICODE) : null,
                'transformation_type' => $chunk['transformation_type'] ?? 'extractive',
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($item, $rowsToInsert, $metrics, $strategy) {
            // Delete existing chunks
            KnowledgeChunk::where('knowledge_item_id', $item->id)->delete();
            
            // Insert new chunks
            if (!empty($rowsToInsert)) {
                KnowledgeChunk::insert($rowsToInsert);
            }

            // Update item with success diagnostics
            $item->chunking_status = 'created';
            $item->chunking_skip_reason = null;
            $item->chunking_error_code = null;
            $item->chunking_error_message = null;
            $item->chunking_metrics = array_merge($metrics, [
                'chunks_created' => count($rowsToInsert),
                'strategy' => $strategy,
            ]);
            $item->save();
        });

        return [
            'status' => 'created',
            'chunks_created' => count($rowsToInsert),
            'metrics' => $metrics,
            'strategy' => $strategy,
        ];
    }

    private function persistSkipDiagnostics(KnowledgeItem $item, array $preflightResult): void
    {
        $item->chunking_status = 'skipped';
        $item->chunking_skip_reason = $preflightResult['skip_reason'];
        $item->chunking_error_code = null;
        $item->chunking_error_message = null;
        $item->chunking_metrics = $preflightResult['metrics'];
        $item->save();
    }

    private function persistFailedDiagnostics(KnowledgeItem $item, string $errorCode, ?string $errorMessage, array $metrics): void
    {
        $item->chunking_status = 'failed';
        $item->chunking_skip_reason = null;
        $item->chunking_error_code = $errorCode;
        $item->chunking_error_message = $errorMessage ? substr($errorMessage, 0, 1000) : null;
        $item->chunking_metrics = $metrics;
        $item->save();
    }

    private function mapTimeHorizon(string $timeframe): string
    {
        $tf = strtolower(trim($timeframe));
        if ($tf === '' || $tf === 'unknown') return 'unknown';

        if (preg_match('/\b(20\d{2})\b/', $tf, $m)) {
            $year = (int) $m[1];
            $nowYear = (int) now()->format('Y');
            $diff = $year - $nowYear;
            if ($diff <= 0 && $diff >= -2) return 'current';
            if ($diff === 1) return 'near_term';
            if ($diff >= 2) return 'long_term';
        }

        if (str_contains($tf, 'next') || str_contains($tf, 'soon')) return 'near_term';
        if (str_contains($tf, 'long') || str_contains($tf, 'year')) return 'long_term';
        return 'unknown';
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
