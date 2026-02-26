<?php

namespace App\Services\Chunking\Strategies;

use App\Models\KnowledgeItem;
use Illuminate\Support\Str;

class ListToDataPointsStrategy implements ChunkingStrategy
{
    public function generateChunks(KnowledgeItem $item, string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

        $dataPoints = [];
        $startLine = 0;
        $endLine = 0;

        foreach ($lines as $idx => $line) {
            $parsed = $this->parseNumericLine($line);
            if ($parsed) {
                $dataPoints[] = $parsed;
                if ($startLine === 0) {
                    $startLine = $idx;
                }
                $endLine = $idx;
            }
        }

        $chunks = [];

        // Create a summary chunk
        if (!empty($dataPoints)) {
            $summaryText = $this->generateSummaryText($dataPoints);
            $sourceSpan = [
                'start' => 0,
                'end' => strlen($text),
                'basis' => 'raw_text',
            ];

            $chunks[] = [
                'text' => $summaryText,
                'role' => 'metric',
                'authority' => 'medium',
                'confidence' => 0.8,
                'token_count' => $this->estimateTokens($summaryText),
                'source_text' => substr($text, 0, 500), // First 500 chars as evidence
                'source_spans' => [$sourceSpan],
                'transformation_type' => 'normalized',
            ];
        }

        // Create individual data point chunks
        foreach ($dataPoints as $point) {
            $chunkText = $this->formatDataPoint($point);
            $chunks[] = [
                'text' => $chunkText,
                'role' => 'metric',
                'authority' => 'medium',
                'confidence' => 0.7,
                'token_count' => $this->estimateTokens($chunkText),
                'source_text' => $point['raw_line'],
                'source_spans' => null,
                'transformation_type' => 'extractive',
                'metadata' => [
                    'data_type' => 'time_series',
                    'fields' => $point['fields'],
                ],
            ];
        }

        return $chunks;
    }

    private function parseNumericLine(string $line): ?array
    {
        // Match patterns like: "2014 = $450/mo" or "2015 = $1500/mo (note)"
        if (preg_match('/^(\d{4})\s*=\s*\$?([\d,]+(?:\.\d+)?)(\/mo|\/month|k|K|m|M)?\s*(\(.*?\))?/i', $line, $matches)) {
            return [
                'year' => (int) $matches[1],
                'value' => $matches[2] . ($matches[3] ?? ''),
                'note' => isset($matches[4]) ? trim($matches[4], '()') : null,
                'raw_line' => $line,
                'fields' => [
                    'year' => (int) $matches[1],
                    'value' => $matches[2] . ($matches[3] ?? ''),
                    'period' => 'monthly',
                ],
            ];
        }

        // Match other numeric patterns
        if (preg_match('/^(\d+)[\).]?\s+(.+)$/', $line, $matches)) {
            return [
                'index' => (int) $matches[1],
                'value' => $matches[2],
                'raw_line' => $line,
                'fields' => [
                    'index' => (int) $matches[1],
                    'value' => $matches[2],
                ],
            ];
        }

        return null;
    }

    private function generateSummaryText(array $dataPoints): string
    {
        if (empty($dataPoints)) {
            return '';
        }

        // If it looks like a time series, generate appropriate summary
        $hasYears = isset($dataPoints[0]['year']);
        
        if ($hasYears) {
            $years = array_column($dataPoints, 'year');
            $minYear = min($years);
            $maxYear = max($years);
            
            return sprintf(
                "Revenue/metrics timeline from %d to %d showing %d data points across %d-year period",
                $minYear,
                $maxYear,
                count($dataPoints),
                $maxYear - $minYear + 1
            );
        }

        return sprintf("Numeric list with %d data points", count($dataPoints));
    }

    private function formatDataPoint(array $point): string
    {
        if (isset($point['year'])) {
            $text = sprintf("In %d: %s", $point['year'], $point['value']);
            if ($point['note']) {
                $text .= sprintf(" (%s)", $point['note']);
            }
            return $text;
        }

        if (isset($point['index'])) {
            return sprintf("%d. %s", $point['index'], $point['value']);
        }

        return $point['raw_line'];
    }

    private function estimateTokens(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($t) => $t !== ''));
        return count($parts);
    }
}
