<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FolderEmbeddingRepository
{
    /**
     * @return array<int,array{folder_id:string,score:float,distance:float}>
     */
    public function search(string $orgId, array $queryEmbedding, int $limit = 10): array
    {
        if (empty($queryEmbedding) || !Schema::hasTable('folder_embeddings') || !Schema::hasColumn('folder_embeddings', 'embedding')) {
            return [];
        }

        $literal = $this->vectorLiteral($queryEmbedding);
        if ($literal === '') {
            return [];
        }

        $rows = DB::select(
            "SELECT folder_id, (embedding <=> CAST(? AS vector)) AS distance
             FROM folder_embeddings
             WHERE org_id = ? AND embedding IS NOT NULL
             ORDER BY embedding <=> CAST(? AS vector)
             LIMIT ?",
            [$literal, $orgId, $literal, $limit]
        );

        $out = [];
        foreach ($rows as $row) {
            $distance = (float) ($row->distance ?? 1.0);
            $score = max(0.0, min(1.0, 1.0 - $distance));
            $out[] = [
                'folder_id' => (string) ($row->folder_id ?? ''),
                'score' => $score,
                'distance' => $distance,
            ];
        }

        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    protected function vectorLiteral(array $vec): string
    {
        $vals = [];
        foreach ($vec as $f) {
            $vals[] = rtrim(sprintf('%.8F', (float) $f), '0');
        }
        $literal = '[' . implode(',', $vals) . ']';
        return $literal === '[]' ? '' : $literal;
    }
}
