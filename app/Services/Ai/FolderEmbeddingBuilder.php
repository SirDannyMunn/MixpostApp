<?php

namespace App\Services\Ai;

use App\Models\Folder;
use App\Models\IngestionSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FolderEmbeddingBuilder
{
    /**
     * @return array{text:string,sources:array<int,array<string,mixed>>}
     */
    public function buildRepresentation(Folder $folder): array
    {
        $meta = is_array($folder->metadata) ? $folder->metadata : [];
        $systemName = trim((string) ($folder->system_name ?? $folder->name));
        $contextType = trim((string) ($meta['context_type'] ?? ''));
        $primaryEntity = trim((string) ($meta['primary_entity'] ?? ''));
        $description = trim((string) ($meta['description'] ?? ''));

        $lines = [];
        $lines[] = 'Folder: ' . ($systemName !== '' ? $systemName : 'Folder');
        if ($contextType !== '') {
            $lines[] = 'Type: ' . $contextType;
        }
        if ($primaryEntity !== '') {
            $lines[] = 'Primary entity: ' . $primaryEntity;
        }
        if ($description !== '') {
            $lines[] = 'Summary: ' . $description;
        }

        $sources = $this->sampleSources($folder);
        $evidence = $this->buildEvidenceSummary($sources);
        if ($evidence !== '') {
            $lines[] = $evidence;
        }

        $text = trim(implode("\n", $lines));

        return [
            'text' => $text,
            'sources' => $sources,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function sampleSources(Folder $folder): array
    {
        if (!Schema::hasTable('ingestion_source_folders')) {
            return [];
        }

        $latestLimit = (int) config('ai.folder_scope.sample_latest', 20);
        $topLimit = (int) config('ai.folder_scope.sample_top', 20);

        $base = $folder->ingestionSources()
            ->whereNull('ingestion_sources.deleted_at')
            ->select([
                'ingestion_sources.id',
                'ingestion_sources.title',
                'ingestion_sources.platform',
                'ingestion_sources.raw_text',
                'ingestion_sources.metadata',
                'ingestion_sources.source_type',
                'ingestion_sources.quality_score',
                'ingestion_sources.confidence_score',
                'ingestion_sources.created_at',
            ]);

        $latest = (clone $base)
            ->orderByDesc('ingestion_sources.created_at')
            ->limit($latestLimit)
            ->get();

        $signalExpr = DB::raw('COALESCE(ingestion_sources.quality_score, ingestion_sources.confidence_score, 0)');
        $top = (clone $base)
            ->orderByDesc($signalExpr)
            ->orderByDesc('ingestion_sources.created_at')
            ->limit($topLimit)
            ->get();

        return $latest
            ->merge($top)
            ->unique('id')
            ->values()
            ->map(function (IngestionSource $src) {
                return [
                    'id' => (string) $src->id,
                    'title' => (string) ($src->title ?? ''),
                    'platform' => (string) ($src->platform ?? ''),
                    'source_type' => (string) ($src->source_type ?? ''),
                    'raw_text' => (string) ($src->raw_text ?? ''),
                    'metadata' => is_array($src->metadata) ? $src->metadata : null,
                ];
            })
            ->all();
    }

    protected function buildEvidenceSummary(array $sources): string
    {
        $maxChars = (int) config('ai.folder_scope.evidence_max_chars', 1000);
        $itemMax = (int) config('ai.folder_scope.evidence_item_max_chars', 160);

        if (empty($sources)) {
            return '';
        }

        $lines = ['Evidence:'];
        $total = strlen($lines[0]);

        foreach ($sources as $src) {
            $title = trim((string) ($src['title'] ?? ''));
            $platform = trim((string) ($src['platform'] ?? ''));
            $rawText = trim((string) ($src['raw_text'] ?? ''));
            $meta = is_array($src['metadata'] ?? null) ? $src['metadata'] : [];
            $metaDesc = trim((string) ($meta['description'] ?? $meta['summary'] ?? ''));

            $snippet = $metaDesc !== '' ? $metaDesc : $rawText;
            $snippet = $this->collapseWhitespace($snippet);
            if ($snippet !== '') {
                $snippet = mb_substr($snippet, 0, $itemMax);
            }

            $label = $title !== '' ? $title : ($snippet !== '' ? mb_substr($snippet, 0, 60) : '');
            if ($label === '') {
                continue;
            }

            $line = '- ';
            if ($platform !== '') {
                $line .= '[' . $platform . '] ';
            }
            $line .= $label;
            if ($snippet !== '' && $snippet !== $label) {
                $line .= ' — ' . $snippet;
            }

            $lineLen = strlen($line) + 1;
            if (($total + $lineLen) > $maxChars) {
                break;
            }

            $lines[] = $line;
            $total += $lineLen;
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    protected function collapseWhitespace(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }
        return preg_replace('/\s+/u', ' ', $t) ?? $t;
    }
}
