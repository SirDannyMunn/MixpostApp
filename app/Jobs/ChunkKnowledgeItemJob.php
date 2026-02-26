<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ingestion\KnowledgeCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChunkKnowledgeItemJob implements ShouldQueue
{
    /**
     * Minimum word count for a persisted knowledge claim.
     * NOTE: "tokens" here means roughly whitespace-delimited words, not model tokens.
     */
    private const MIN_CHUNK_WORDS_DEFAULT = 12;

    private function minWordsForRole(string $role): int
    {
        return match ($role) {
            'metric' => 6,
            'definition' => 8,
            'heuristic' => 8,
            'instruction' => 10,
            'strategic_claim' => 12,
            'causal_claim' => 12,
            default => self::MIN_CHUNK_WORDS_DEFAULT,
        };
    }

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $knowledgeItemId) {}

    private function sanitizeAuthority(string $value): string
    {
        $val = strtolower(trim($value));
        $allowed = ['high', 'medium', 'low'];
        if (in_array($val, $allowed, true)) {
            return $val;
        }
        if (str_contains($val, 'high')) return 'high';
        if (str_contains($val, 'medium')) return 'medium';
        if (str_contains($val, 'low')) return 'low';
        return 'medium';
    }

    private function estimateTokenCount(string $text): int
    {
        // Approximate word count; not a model token count.
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }

    private function mapTimeHorizon(string $timeframe): string
    {
        $tf = strtolower(trim($timeframe));
        if ($tf === '' || $tf === 'unknown') return 'unknown';

        // Simple heuristic mapping.
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

    public function handle(): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('ChunkKnowledgeItemJob:' . $this->knowledgeItemId, [
            'knowledge_item_id' => $this->knowledgeItemId,
        ]);
        $item = KnowledgeItem::find($this->knowledgeItemId);
        if (!$item) { $logger->flush('not_found'); return; }

        try {
            $coordinator = app(\App\Services\Chunking\ChunkingCoordinator::class);
            $result = $coordinator->processItem($item);
            
            $logger->flush('completed', [
                'status' => $result['status'],
                'chunks_created' => $result['chunks_created'] ?? 0,
                'strategy' => $result['strategy'] ?? $result['reason'] ?? null,
                'metrics' => $result['metrics'] ?? [],
            ]);
        } catch (\Throwable $e) {
            // Update item with error
            $item->chunking_status = 'failed';
            $item->chunking_error_code = 'unknown';
            $item->chunking_error_message = substr($e->getMessage(), 0, 1000);
            $item->save();
            
            $logger->flush('error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
