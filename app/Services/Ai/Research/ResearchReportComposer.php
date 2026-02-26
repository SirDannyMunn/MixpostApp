<?php

namespace App\Services\Ai\Research;

use App\Enums\LlmStage;
use App\Services\Ai\LLMClient;
use App\Services\Ai\LlmStageTracker;
use App\Services\Ai\SchemaValidator;

class ResearchReportComposer
{
    public function __construct(
        protected LLMClient $llm,
        protected SchemaValidator $schemaValidator,
        protected ResearchPromptComposer $promptComposer,
    ) {}

    /**
     * Compose a research report from pre-clustered items.
     * Clustering should be done by ResearchExecutor before calling this.
     *
     * @return array{report:array,meta:array,prompt:array}
     */
    public function composeFromClusters(
        string $question,
        array $clusterSummaries,
        array $clusteredItems,
        array $options = [],
        ?LlmStageTracker $tracker = null
    ): array {
        $prompt = $this->promptComposer->composeReport($question, $clusterSummaries, $clusteredItems, $options);
        $res = $this->llm->callWithMeta('research_report', $prompt->system, $prompt->user, $prompt->schemaName, $prompt->llmParams);
        $data = $res['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($data) || !$this->schemaValidator->validate('research_report', $data)) {
            $data = $this->emptyReport($question);
        }

        $modelUsed = trim((string) (($res['meta']['model'] ?? '') ?: ''));
        if ($tracker && $modelUsed !== '') {
            $tracker->record(LlmStage::GENERATE, $modelUsed);
        }

        return [
            'report' => $data,
            'meta' => [
                'model' => $modelUsed,
                'usage' => (array) ($res['meta']['usage'] ?? []),
                'latency_ms' => (int) ($res['latency_ms'] ?? 0),
            ],
            'prompt' => [
                'system' => $prompt->system,
                'user' => $prompt->user,
            ],
        ];
    }

    /**
     * Legacy method for backward compatibility.
     * New code should use composeFromClusters() instead.
     *
     * @deprecated Use composeFromClusters() after clustering in ResearchExecutor
     * @return array{report:array,clusters:array,meta:array,items:array,prompt:array}
     */
    public function compose(string $question, array $items, array $options = [], ?LlmStageTracker $tracker = null): array
    {
        $threshold = (float) ($options['cluster_similarity'] ?? config('ai.research.cluster_similarity', 0.82));
        $clustered = $this->clusterItems($items, $threshold);
        $clusters = $this->summarizeClusters($clustered['clusters']);
        $items = $clustered['items'];

        $result = $this->composeFromClusters($question, $clusters, $items, $options, $tracker);

        return [
            'report' => $result['report'],
            'clusters' => $clusters,
            'meta' => $result['meta'],
            'items' => $items,
            'prompt' => $result['prompt'],
        ];
    }

    private function emptyReport(string $question): array
    {
        return [
            'question' => $question,
            'dominant_claims' => [],
            'points_of_disagreement' => [],
            'saturated_angles' => [],
            'emerging_angles' => [],
            'example_excerpts' => [],
        ];
    }

    /**
     * @return array{clusters:array,items:array}
     */
    private function clusterItems(array $items, float $threshold): array
    {
        $clusters = [];
        $miscItems = [];

        foreach ($items as $item) {
            $vector = $item['embedding'] ?? null;
            if (!is_array($vector) || empty($vector)) {
                $miscItems[] = $item;
                continue;
            }

            $assigned = false;
            foreach ($clusters as &$cluster) {
                $centroid = $cluster['centroid'] ?? null;
                if (!is_array($centroid) || empty($centroid)) {
                    continue;
                }
                $sim = $this->cosineSimilarity($vector, $centroid);
                if ($sim >= $threshold) {
                    $cluster['items'][] = $item;
                    $cluster['centroid'] = $this->averageVectors($cluster['centroid'], $vector, count($cluster['items']));
                    $cluster['avg_similarity'] = $this->recalculateAvgSimilarity($cluster['items'], $cluster['centroid']);
                    $assigned = true;
                    break;
                }
            }
            unset($cluster);

            if (!$assigned) {
                $clusters[] = [
                    'id' => 'c' . (count($clusters) + 1),
                    'items' => [$item],
                    'centroid' => $vector,
                    'avg_similarity' => 1.0,
                ];
            }
        }

        if (!empty($miscItems)) {
            $clusters[] = [
                'id' => 'misc',
                'items' => $miscItems,
                'centroid' => null,
                'avg_similarity' => 0.0,
            ];
        }

        $itemsWithCluster = [];
        foreach ($clusters as $cluster) {
            foreach ((array) $cluster['items'] as $item) {
                $item['cluster_id'] = (string) ($cluster['id'] ?? '');
                $itemsWithCluster[] = $item;
            }
        }

        return ['clusters' => $clusters, 'items' => $itemsWithCluster];
    }

    private function summarizeClusters(array $clusters): array
    {
        $summaries = [];
        foreach ($clusters as $cluster) {
            $items = (array) ($cluster['items'] ?? []);
            $centroid = $cluster['centroid'] ?? null;
            $bySimilarity = $items;

            if (is_array($centroid) && !empty($centroid)) {
                usort($bySimilarity, function ($a, $b) use ($centroid) {
                    $simA = $this->cosineSimilarity((array) ($a['embedding'] ?? []), $centroid);
                    $simB = $this->cosineSimilarity((array) ($b['embedding'] ?? []), $centroid);
                    return $simB <=> $simA;
                });
            }

            $representative = [];
            foreach (array_slice($bySimilarity, 0, 2) as $item) {
                $representative[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'source' => (string) ($item['source'] ?? 'other'),
                    'text' => (string) ($item['text'] ?? ''),
                ];
            }

            $angles = $this->topCreativeField($items, 'angle');
            $hooks = $this->topCreativeField($items, 'hook_text');

            $summaries[] = [
                'id' => (string) ($cluster['id'] ?? ''),
                'size' => count($items),
                'avg_similarity' => (float) ($cluster['avg_similarity'] ?? 0.0),
                'dominant_angles' => $angles,
                'dominant_hooks' => $hooks,
                'representative_excerpts' => $representative,
            ];
        }

        return $summaries;
    }

    private function topCreativeField(array $items, string $field): array
    {
        $counts = [];
        foreach ($items as $item) {
            $val = (string) ($item['creative'][$field] ?? '');
            $val = trim($val);
            if ($val === '') {
                continue;
            }
            $counts[$val] = ($counts[$val] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 3);
    }

    private function averageVectors(array $centroid, array $vector, int $count): array
    {
        $dim = min(count($centroid), count($vector));
        if ($dim === 0 || $count <= 0) {
            return $centroid;
        }
        $out = $centroid;
        for ($i = 0; $i < $dim; $i++) {
            $out[$i] = (($centroid[$i] * ($count - 1)) + $vector[$i]) / $count;
        }
        return $out;
    }

    private function recalculateAvgSimilarity(array $items, array $centroid): float
    {
        if (empty($items) || empty($centroid)) {
            return 0.0;
        }
        $sum = 0.0;
        $count = 0;
        foreach ($items as $item) {
            $vec = $item['embedding'] ?? null;
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $sum += $this->cosineSimilarity($vec, $centroid);
            $count++;
        }
        return $count > 0 ? ($sum / $count) : 0.0;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        for ($i = 0; $i < $len; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }
        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
