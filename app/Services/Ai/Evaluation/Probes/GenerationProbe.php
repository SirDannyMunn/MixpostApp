<?php

namespace App\Services\Ai\Evaluation\Probes;

use App\Models\KnowledgeItem;
use App\Services\Ai\ContentGeneratorService;
use App\Services\Ai\EmbeddingsService;
use App\Services\Ai\LLMClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\ContextPayloadFactory;

class GenerationProbe
{
    public function __construct(
        protected ContentGeneratorService $generator,
        protected EmbeddingsService $embeddings,
        protected LLMClient $llm,
    ) {}

    /**
     * Run generation probe across provided QA items.
     * Returns summary + per-item results. Does not throw.
     *
     * @param string $orgId
     * @param string $userId
     * @param string $knowledgeItemId
     * @param array $qaItems Array of [question, expected_answer_summary]
     * @param string|null $platform
     */
    public function run(string $orgId, string $userId, string $knowledgeItemId, array $qaItems, ?string $platform = null): array
    {
        $platform = $platform ?: 'linkedin';
        $vip = ['results' => [], 'passes' => 0, 'total' => 0];
        $retrieval = ['results' => [], 'passes' => 0, 'total' => 0];

        try {
            foreach ($qaItems as $qa) {
                $question = (string) ($qa['question'] ?? '');
                $expected = (string) ($qa['expected_answer_summary'] ?? '');
                if ($question === '') {
                    $vip['results'][] = ['question' => $question, 'status' => 'skipped', 'reason' => 'empty_question'];
                    $retrieval['results'][] = ['question' => $question, 'status' => 'skipped', 'reason' => 'empty_question'];
                    continue;
                }

                // Probe B: VIP-forced generation (existing)
                $vip['total']++;
                $vipChunks = $this->topNChunksForQuestion($orgId, $userId, $knowledgeItemId, $question, 5);
                $vipKnowledge = array_values(array_filter(array_map(function ($c) {
                    $id = (string) ($c['id'] ?? '');
                    $text = (string) ($c['chunk_text'] ?? '');
                    if ($text === '') { return null; }
                    return ContextPayloadFactory::makeVipKnowledgeReference($id !== '' ? $id : null, $text);
                }, $vipChunks)));
                $prompt = "Answer this question based on the context: " . $question;
                $genVip = $this->generator->generate($orgId, $userId, $prompt, $platform, [
                    'use_retrieval' => false,
                    'retrieval_limit' => 0,
                    'use_business_facts' => false,
                    'overrides' => ['knowledge' => $vipKnowledge],
                    'max_chars' => 500,
                    'emoji' => 'disallow',
                    'tone' => 'analytical',
                    'intent' => 'educational',
                    'funnel_stage' => 'bof',
                ]);
                $contentVip = (string) ($genVip['content'] ?? '');
                $gradeVip = $this->grade($question, $expected, $contentVip);
                $passVip = (bool) ($gradeVip['pass'] ?? false);
                if ($passVip) { $vip['passes']++; }
                $ctxVip = (array) ($genVip['context_used'] ?? []);
                $vip['results'][] = [
                    'question' => $question,
                    'expected' => mb_substr($expected, 0, 600),
                    'generated' => mb_substr($contentVip, 0, 2000),
                    'grade' => $gradeVip,
                    'status' => $passVip ? 'pass' : 'fail',
                    'diagnostics' => [
                        'context_injected_status' => !empty(($ctxVip['chunk_ids'] ?? [])) ? 'success' : 'failed',
                        'snapshot' => $ctxVip,
                        'run_id' => (string) ($genVip['metadata']['run_id'] ?? ''),
                        'input_knowledge_item_id' => $knowledgeItemId,
                    ],
                ];

                // Probe A: Retrieval-on generation
                $retrieval['total']++;
                $scopeToKi = (bool) config('ai.eval.scope_to_knowledge_item', true);
                $genRt = $this->generator->generate($orgId, $userId, $question, $platform, [
                    'use_retrieval' => true,
                    // leave retrieval_limit defaulting via service options; cap for determinism
                    'retrieval_limit' => 5,
                    'use_business_facts' => false,
                    // no VIP overrides
                    'max_chars' => 500,
                    'emoji' => 'disallow',
                    'tone' => 'analytical',
                    'intent' => 'educational',
                    'funnel_stage' => 'bof',
                    // Deterministic scoping to this eval's knowledge item
                    'retrieval_filters' => $scopeToKi ? ['knowledge_item_id' => $knowledgeItemId] : [],
                ]);
                $contentRt = (string) ($genRt['content'] ?? '');
                $gradeRt = $this->grade($question, $expected, $contentRt);
                $passRt = (bool) ($gradeRt['pass'] ?? false);
                if ($passRt) { $retrieval['passes']++; }
                $ctxRt = (array) ($genRt['context_used'] ?? []);
                $retrieval['results'][] = [
                    'question' => $question,
                    'expected' => mb_substr($expected, 0, 600),
                    'generated' => mb_substr($contentRt, 0, 2000),
                    'grade' => $gradeRt,
                    'status' => $passRt ? 'pass' : 'fail',
                    'diagnostics' => [
                        'retrieval_used' => true,
                        'retrieved_chunk_ids' => (array) ($ctxRt['chunk_ids'] ?? []),
                        'run_id' => (string) ($genRt['metadata']['run_id'] ?? ''),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('generation.probe.error', ['error' => $e->getMessage()]);
        }

        $vipMetrics = [
            'total' => $vip['total'],
            'passes' => $vip['passes'],
            'pass_rate' => $vip['total'] > 0 ? ($vip['passes'] / $vip['total']) : 0.0,
        ];
        $rtMetrics = [
            'total' => $retrieval['total'],
            'passes' => $retrieval['passes'],
            'pass_rate' => $retrieval['total'] > 0 ? ($retrieval['passes'] / $retrieval['total']) : 0.0,
        ];

        // Verdict per Success Criteria (Optimization Harness 1.2.5)
        // - VIP-Forced must be 100% or it's an ingestion failure
        // - Retrieval-On judged against corpus-size thresholds
        $verdict = 'unknown';
        $vipPassRate = ($vipMetrics['total'] > 0) ? ($vipMetrics['passes'] / $vipMetrics['total']) : 0.0;
        $rtPassRate = ($rtMetrics['total'] > 0) ? ($rtMetrics['passes'] / $rtMetrics['total']) : 0.0;

        // Compute chunk-count based threshold for retrieval competitiveness
        $chunkCount = 0;
        try {
            $chunkCount = (int) DB::table('knowledge_chunks')->where('knowledge_item_id', $knowledgeItemId)->count();
        } catch (\Throwable) { $chunkCount = 0; }
        if ($chunkCount <= 2) {
            $rtThreshold = 0.66; // Sparse (≤2 chunks)
        } elseif ($chunkCount <= 6) {
            $rtThreshold = 0.33; // Small Dense (3–6 chunks)
        } else {
            $rtThreshold = 0.50; // Large Docs
        }

        if ($vipPassRate >= 1.0) {
            // VIP passed fully; now judge retrieval vs threshold
            $verdict = ($rtPassRate < $rtThreshold) ? 'retrieval_regression' : 'pass';
        } elseif ($vipPassRate >= 0.66 && $rtPassRate >= 0.33) {
            // Allow partial VIP + modest retrieval to pass with warnings
            $verdict = 'pass_with_warnings';
        } else {
            $verdict = 'ingestion_failure';
        }

        return [
            'status' => 'ok',
            'retrieval_on' => [
                'metrics' => $rtMetrics,
                'results' => $retrieval['results'],
                'notes' => 'Retrieval-On generation; no VIP overrides',
            ],
            'vip_forced' => [
                'metrics' => $vipMetrics,
                'results' => $vip['results'],
                'notes' => 'VIP-Forced generation; retrieval disabled',
            ],
            'verdict' => $verdict,
        ];
    }

    /**
     * Select top-N chunks within a knowledge item for the question via pgvector.
     */
    protected function topNChunksForQuestion(string $orgId, string $userId, string $knowledgeItemId, string $question, int $n = 5): array
    {
        try {
            $vec = $this->embeddings->embedOne($question);
            if (empty($vec)) { return []; }
            $literal = '[' . implode(',', array_map(fn($f) => rtrim(sprintf('%.8F', (float)$f), '0'), $vec)) . ']';
            $rows = DB::select(
                "SELECT id, chunk_text, chunk_type, tags, (embedding_vec <=> CAST(? AS vector)) AS distance\n" .
                "FROM knowledge_chunks\n" .
                "WHERE organization_id = ? AND user_id = ? AND knowledge_item_id = ? AND embedding_vec IS NOT NULL\n" .
                "ORDER BY embedding_vec <=> CAST(? AS vector) LIMIT ?",
                [$literal, $orgId, $userId, $knowledgeItemId, $literal, $n]
            );
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (string) ($r->id ?? ''),
                    'chunk_text' => (string) ($r->chunk_text ?? ''),
                    'chunk_type' => (string) ($r->chunk_type ?? 'reference'),
                    'tags' => is_array($r->tags) ? $r->tags : json_decode((string) ($r->tags ?? '[]'), true),
                    'score' => isset($r->distance) ? max(0.0, 1.0 - (float) $r->distance) : 0.5,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * LLM-based grader for factual alignment. Returns {pass:bool, score:float, rationale:string}.
     */
    protected function grade(string $question, $expected, string $generated): array
    {
        try {
            // Heuristic normalization + contains check (reduces false negatives for verbosity)
            $norm = function (string $s): string {
                $s = mb_strtolower($s);
                $s = preg_replace('/[^a-z0-9\s]/u', '', $s) ?: $s;
                $s = preg_replace('/\s+/', ' ', $s) ?: $s;
                return trim($s);
            };

            $generatedNorm = $norm($generated);
            $mode = (string) config('ai.eval.grader_mode', 'contains');

            // Deterministic contradiction rule: if expected looks specific and generated is uncertain -> fail
            $looksSpecific = false;
            if (is_string($expected)) {
                $looksSpecific = (bool) preg_match('/(\b\d{1,4}\b|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|\$|usd|eur|percent|%)/i', $expected);
            } elseif (is_array($expected)) {
                $expStr = json_encode($expected);
                $looksSpecific = (bool) preg_match('/(\b\d{1,4}\b|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|\$|usd|eur|percent|%)/i', (string) $expStr);
            }
            $uncertain = false;
            if ($generatedNorm !== '') {
                $uncertainPhrases = [
                    'cannot determine','cant determine','unable to determine','unknown','not disclosed','not included','not provided','no information','not specified','cannot find','cant find','no data','unsure'
                ];
                foreach ($uncertainPhrases as $p) { if (str_contains($generatedNorm, $p)) { $uncertain = true; break; } }
            }
            if ($looksSpecific && $uncertain) {
                return ['pass' => false, 'score' => 0.0, 'rationale' => 'deterministic_contradiction'];
            }
            // Allow expected to be either string or spec form {type: 'contains', terms: []}
            if (is_array($expected) && (($expected['type'] ?? '') === 'contains') && is_array($expected['terms'] ?? null)) {
                $terms = array_values(array_filter(array_map(fn($t) => $norm((string) $t), $expected['terms'])));
                $missing = [];
                foreach ($terms as $t) {
                    if ($t === '') { continue; }
                    if (!str_contains($generatedNorm, $t)) { $missing[] = $t; }
                }
                if (empty($missing)) {
                    return ['pass' => true, 'score' => 1.0, 'rationale' => 'all required terms present (normalized)', 'attempts' => 0];
                }
            } elseif (is_string($expected) && $expected !== '') {
                $expectedNorm = $norm($expected);
                if ($expectedNorm !== '' && str_contains($generatedNorm, $expectedNorm)) {
                    return ['pass' => true, 'score' => 1.0, 'rationale' => 'expected summary contained in generated (normalized)', 'attempts' => 0];
                }
            }

            if ($mode === 'contains') {
                // In contains mode, if we didn't match above, consider it a fail without invoking LLM
                return ['pass' => false, 'score' => 0.0, 'rationale' => 'contains_mode_no_match'];
            }

            $system = "You are a strict but fair grader. Compare the generated answer to the expected answer. "
                . "Return JSON: {pass:boolean, score:number, rationale:string}. "
                . "- Do not penalize extra context if the direct answer is correct. "
                . "- Pass if the first sentence or overall answer directly states the correct fact(s). "
                . "- score = 0..1 (1 = perfect alignment).";
            $user = json_encode([
                'question' => $question,
                'expected' => $expected,
                'generated' => $generated,
            ], JSON_UNESCAPED_UNICODE);

            $attempts = 0; $data = [];
            while ($attempts < 3) {
                $attempts++;
                $res = $this->llm->call('generation_grader', $system, $user, 'grading_v1', [
                    'temperature' => 0,
                    'json_schema_hint' => '{"pass":true,"score":1.0,"rationale":"..."}',
                ]);
                $data = is_array($res) ? $res : [];
                $ok = isset($data['pass']) && isset($data['score']) && isset($data['rationale']);
                if ($ok) break;
            }
            return [
                'pass' => (bool) ($data['pass'] ?? false),
                'score' => isset($data['score']) ? (float) $data['score'] : 0.0,
                'rationale' => (string) ($data['rationale'] ?? ''),
                'attempts' => $attempts,
            ];
        } catch (\Throwable $e) {
            Log::warning('generation.probe.grade.error', ['error' => $e->getMessage()]);
            return ['pass' => false, 'score' => 0.0, 'rationale' => 'grading_error'];
        }
    }
}
