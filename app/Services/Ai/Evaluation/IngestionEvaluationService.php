<?php

namespace App\Services\Ai\Evaluation;

use App\Models\IngestionEvaluation;
use App\Models\IngestionSource;
use App\Models\KnowledgeItem;
use App\Services\Ai\LLMClient;
use App\Services\Ai\Retriever;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngestionEvaluationService
{
    public function startEvaluation(array $params): IngestionEvaluation
    {
        $store = (bool) ($params['store'] ?? true);
        $evaluation = new IngestionEvaluation();
        $evaluation->organization_id = $params['organization_id'];
        $evaluation->user_id = $params['user_id'];
        $evaluation->title = $params['title'] ?? null;
        $evaluation->status = 'running';
        $evaluation->format = (string) ($params['format'] ?? 'both');
        $evaluation->options = $params['options'] ?? [];
        if ($store) {
            $evaluation->save();
        } else {
            // Ensure an ID for non-persistent runs so downstream can use it
            $evaluation->id = (string) ($evaluation->id ?: Str::uuid());
        }
        return $evaluation;
    }

    public function runFaithfulnessAudit(IngestionEvaluation $evaluation, array $report): array
    {
        try {
            $norm = $report['artifacts']['normalized_claims'] ?? null;
            $item = $report['knowledge_item'] ?? null;
            if (!$norm || !$item) {
                $report['evaluation']['faithfulness'] = ['status' => 'skipped', 'reason' => 'no_normalized_claims'];
                return $report;
            }

            $rawText = KnowledgeItem::find($item['id'])?->raw_text ?? '';
            if (trim($rawText) === '') {
                $report['evaluation']['faithfulness'] = ['status' => 'skipped', 'reason' => 'no_raw_text'];
                return $report;
            }

            $system = $this->loadPrompt('faithfulness_audit')
                ?: ("You are performing a faithfulness audit of normalized knowledge claims.\n"
                    . "Identify any claim that is unsupported, contradictory, or overstated relative to the source.\n"
                    . "Return STRICT JSON only: {status, score, violations:[{claim_index, type, rationale, severity}]}.\n"
                    . "- status: pass|fail; fail if any violation of type 'unsupported' or 'contradiction'.\n"
                    . "- score: 0..1 (1 = fully faithful).\n"
                    . "- severity: low|medium|high.");
            $claims = is_array($norm['claims'] ?? null) ? $norm['claims'] : [];
            $user = json_encode([
                'source' => mb_substr($rawText, 0, (int) ($evaluation->options['max_doc_chars'] ?? 12000)),
                'claims' => array_map(fn($c) => $c['text'] ?? '', $claims),
            ], JSON_UNESCAPED_UNICODE);

            $llm = app(LLMClient::class);
            // Enforce strict JSON + retry malformed outputs up to 3 times
            $schemaHint = '{"status":"pass|fail","score":0.0,"violations":[{"claim_index":0,"type":"unsupported|contradiction|overstated","rationale":"...","severity":"low|medium|high"}]}' ;
            $attempts = 0;
            $data = [];
            while ($attempts < 3) {
                $attempts++;
                $res = $llm->call('faithfulness_audit', $system, $user, 'faithfulness_v1', [
                    'temperature' => 0,
                    'json_schema_hint' => $schemaHint,
                ]);
                $data = is_array($res) ? $res : [];
                $ok = is_array($data)
                    && isset($data['status'])
                    && in_array((string) $data['status'], ['pass','fail'], true)
                    && array_key_exists('score', $data)
                    && isset($data['violations']) && is_array($data['violations']);
                if ($ok) { break; }
            }
            // Basic shape fallback after retries
            $status = in_array((string) ($data['status'] ?? ''), ['pass','fail'], true) ? (string) $data['status'] : 'unknown';
            $score = isset($data['score']) ? (float) $data['score'] : null;
            $violations = is_array($data['violations'] ?? null) ? $data['violations'] : [];
            $report['evaluation']['faithfulness'] = [
                'status' => $status,
                'score' => $score,
                'violations' => $violations,
                'attempts' => $attempts,
            ];
            return $report;
        } catch (\Throwable $e) {
            Log::warning('eval.faithfulness.error', ['error' => $e->getMessage()]);
            $report['evaluation']['faithfulness'] = ['status' => 'error', 'reason' => $e->getMessage()];
            return $report;
        }
    }

    public function runSyntheticQATest(IngestionEvaluation $evaluation, array $report): array
    {
        try {
            $chunks = $report['artifacts']['chunks'] ?? [];
            if (empty($chunks)) {
                $report['evaluation']['synthetic_qa'] = ['status' => 'skipped', 'reason' => 'no_chunks'];
                return $report;
            }
            // Build a compact chunk list for LLM
            $inputs = array_map(function ($c) {
                return [
                    'id' => (string) $c['id'],
                    'text' => mb_substr((string) $c['chunk_text'], 0, 500),
                    'role' => (string) ($c['chunk_role'] ?? 'other'),
                ];
            }, array_slice($chunks, 0, 40));

            $system = $this->loadPrompt('synthetic_qa_min')
                ?: ("Generate 2-3 factual questions answerable from exactly one chunk.\n"
                    . "Return STRICT JSON array under key 'items'.\n"
                    . "Each item: {question, expected_answer_summary, target_chunk_ids:[id]}.\n"
                    . "expected_answer_summary may be either a short string OR an object {type:'contains', terms:[string,...]} representing key terms that must appear in a correct answer.");
            $user = json_encode(['chunks' => $inputs], JSON_UNESCAPED_UNICODE);
            $llm = app(LLMClient::class);
            $res = $llm->call('synthetic_qa_min', $system, $user, 'synthetic_qa_v1', ['temperature' => 0]);
            $data = is_array($res) ? $res : [];
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $k = 3;

            // Run retrieval for each question using Retriever
            $retriever = app(Retriever::class);
            $org = (string) $evaluation->organization_id;
            $userId = (string) $evaluation->user_id;
            $details = [];
            $hits = 0;
            // Deterministic scoping for eval runs
            $isolation = (string) ($evaluation->options['isolation'] ?? 'none');
            $kiId = (string) ($report['knowledge_item']['id'] ?? '');
            foreach ($items as $qa) {
                $q = (string) ($qa['question'] ?? '');
                $targetIds = array_values(array_map('strval', (array) ($qa['target_chunk_ids'] ?? [])));
                $filters = [];
                $scopeToKi = (bool) config('ai.eval.scope_to_knowledge_item', true);
                if ($kiId !== '' && ($isolation === 'strict' || $scopeToKi)) {
                    $filters['knowledge_item_id'] = $kiId;
                }
                // Log exact retriever options for observability
                try {
                    \Log::info('eval.synthetic_qa.retrieval_call', [
                        'org' => $org,
                        'user' => $userId,
                        'query' => $q,
                        'intent' => 'educational',
                        'limit' => $k,
                        'filters' => $filters,
                    ]);
                } catch (\Throwable $e) {}
                $res = $retriever->knowledgeChunks($org, $userId, $q, 'educational', $k, $filters);
                $rank = -1;
                // Support both payload shapes:
                // 1) Flat array of chunks (current Retriever default)
                // 2) Structured array with trace: ['final' => [...], 'trace' => ['topK'=>[], 'final'=>[]]]
                $trace = [];
                $traceFinal = [];
                $traceTopK = [];
                $retrieved = [];
                if (is_array($res) && array_is_list($res)) {
                    // Flat list
                    $retrieved = array_values(array_filter(array_map(fn($r) => (string) ($r['id'] ?? ''), $res), fn($id) => $id !== ''));
                    $traceFinal = array_map(fn($r) => ['id' => (string) ($r['id'] ?? '')], $res);
                    $traceTopK = $traceFinal;
                } else {
                    $trace = is_array($res['trace'] ?? null) ? $res['trace'] : [];
                    $traceFinal = is_array($trace['final'] ?? null) ? $trace['final'] : [];
                    $traceTopK = is_array($trace['topK'] ?? null) ? $trace['topK'] : [];
                    $retrieved = array_map(fn($r) => (string) ($r['id'] ?? ''), $traceFinal);
                }
                foreach ($targetIds as $tid) {
                    $idx = array_search($tid, $retrieved, true);
                    if ($idx !== false) { $rank = (int) $idx; break; }
                }
                if ($rank >= 0 && $rank < $k) { $hits++; }
                $entry = [
                    'question' => $q,
                    'target_chunk_ids' => $targetIds,
                    'retrieved' => $retrieved,
                    'rank' => $rank,
                    'diagnostics' => [
                        'trace' => [
                            'topK' => array_slice($traceTopK, 0, max($k, 3)),
                            'final' => $traceFinal,
                        ],
                    ],
                ];

                // Simple top3 derived from trace.topK for consistency
                $entry['diagnostics']['top3'] = array_slice(
                    array_map(fn($r) => ['id' => (string) ($r['id'] ?? '')], $traceTopK),
                    0,
                    3
                );

                // If missed in final but present in topK, flag reason (likely cap/dedup)
                if ($rank < 0 && !empty($targetIds)) {
                    $inTopK = false;
                    foreach ($targetIds as $tid) {
                        foreach ($traceTopK as $tk) { if (($tk['id'] ?? '') === $tid) { $inTopK = true; break; } }
                        if ($inTopK) break;
                    }
                    if ($inTopK) {
                        $entry['diagnostics']['missed_retrieval'] = true;
                        $entry['diagnostics']['miss_reason'] = 'capped_or_dedup';
                    }
                }

                // Hard assert: if we computed a top3 from trace but retrieved is empty, this is inconsistent
                if (!empty($entry['diagnostics']['top3']) && empty($retrieved)) {
                    throw new \RuntimeException('SyntheticQA inconsistency: computed top3 but retriever returned empty. Check retriever call path / opts.');
                }
                $details[] = $entry;
            }
            $report['evaluation']['synthetic_qa'] = [
                'status' => 'ok',
                'k' => $k,
                'items' => $items,
                'details' => $details,
                'metrics' => [
                    'total' => count($items),
                    'hits_at_k' => $hits,
                ],
                'notes' => 'Retrieval diagnostics are observational; see details[].diagnostics when missed',
            ];
            return $report;
        } catch (\Throwable $e) {
            Log::warning('eval.synthetic_qa.error', ['error' => $e->getMessage()]);
            $report['evaluation']['synthetic_qa'] = ['status' => 'error', 'reason' => $e->getMessage()];
            return $report;
        }
    }

    private function loadPrompt(string $key): ?string
    {
        try {
            $map = (array) config('ai.prompts', []);
            $path = (string) ($map[$key] ?? '');
            if ($path === '') return null;
            $full = base_path($path);
            if (!File::exists($full)) return null;
            return (string) File::get($full);
        } catch (\Throwable) {
            return null;
        }
    }
    public function markFailed(IngestionEvaluation $evaluation, string $reason, string $message): void
    {
        $evaluation->status = 'failed';
        $evaluation->issues = [['code' => $reason, 'message' => $message]];
        $evaluation->save();
    }

    public function markCompleted(IngestionEvaluation $evaluation, array $paths): void
    {
        $evaluation->status = 'completed';
        $evaluation->report_paths = $paths;
        $evaluation->save();
    }

    public function buildPhaseAReport(IngestionEvaluation $evaluation, array $run): array
    {
        $orgId = (string) $evaluation->organization_id;
        $source = IngestionSource::find($run['source_id']);
        $item = KnowledgeItem::find($run['knowledge_item_id']);
        $norm = $run['artifacts']['normalized_claims'] ?? null;
        $chunks = $run['artifacts']['chunks'] ?? [];

        $configSnapshot = [
            'ai' => config('ai'),
            'vector' => config('vector')
        ];

        $summary = [
            'chunks' => $run['stats']['chunks_total'] ?? 0,
            'embedding_coverage' => $run['stats']['embedding_coverage'] ?? 0.0,
            'normalized_claims' => $run['stats']['normalized_claims_count'] ?? 0,
        ];

        // Normalization audit block for observability
        $raw = (string) ($item?->raw_text ?? '');
        $minChars = (int) config('ai.normalization.min_chars', 400);
        $minQuality = (float) config('ai.normalization.min_quality', 0.55);
        $eligibleSources = (array) config('ai.normalization.eligible_sources', ['bookmark','text','file','transcript']);
        $sourceTypeForNorm = $source?->source_type ?: ($item?->source === 'manual' ? 'text' : (string) ($item?->source ?? 'text'));
        $qualityScore = $source?->quality_score;
        $eligible = (mb_strlen(trim($raw)) >= $minChars)
            && in_array($sourceTypeForNorm, $eligibleSources, true)
            && ($qualityScore === null || (float) $qualityScore >= $minQuality);
        $executed = is_array($norm) && (isset($norm['normalization_hash']) || isset($norm['claims']));
        $skipReason = null;
        if (!$executed) {
            if (mb_strlen(trim($raw)) < $minChars) { $skipReason = 'short_text'; }
            elseif (!in_array($sourceTypeForNorm, $eligibleSources, true)) { $skipReason = 'ineligible_source_type'; }
            elseif ($qualityScore !== null && (float) $qualityScore < $minQuality) { $skipReason = 'low_quality'; }
            elseif (($source?->dedup_reason ?? null) !== null) { $skipReason = 'deduplicated'; }
        }

        return [
            'version' => 1,
            'phase' => 'A',
            'evaluation_id' => $evaluation->id,
            'organization_id' => $orgId,
            'user_id' => (string) $evaluation->user_id,
            'inputs' => [
                'title' => $evaluation->title,
                'source_type' => $evaluation->options['source_type'] ?? 'text',
                'force' => (bool) ($evaluation->options['force'] ?? false),
                'max_doc_chars' => (int) ($evaluation->options['max_doc_chars'] ?? 12000),
            ],
            'source' => $source ? [
                'id' => $source->id,
                'status' => $source->status,
                'quality_score' => $source->quality_score,
                'dedup_reason' => $source->dedup_reason,
                'error' => $source->error,
            ] : null,
            'knowledge_item' => $item ? [
                'id' => $item->id,
                'type' => $item->type,
                'source' => $item->source,
                'title' => $item->title,
                'confidence' => $item->confidence,
                'ingested_at' => optional($item->ingested_at)->toISOString(),
                'raw_text_sha256' => $item->raw_text_sha256,
            ] : null,
            'artifacts' => [
                'normalized_claims' => $norm,
                'chunks' => $chunks,
            ],
            'normalization' => [
                'eligible' => $eligible,
                'executed' => $executed,
                'skip_reason' => $skipReason,
                'source_type' => $sourceTypeForNorm,
                'min_chars' => $minChars,
                'min_quality' => $minQuality,
            ],
            'metrics' => $run['stats'] ?? [],
            'config_snapshot' => $configSnapshot,
            'summary' => $summary,
            'evaluation' => [
                'faithfulness' => null,
                'synthetic_qa' => null,
            ],
            'created_at' => now()->toISOString(),
        ];
    }

    public function writeReports(IngestionEvaluation $evaluation, array $report, string $format = 'both'): array
    {
        $id = (string) ($evaluation->id ?? '');
        if ($id === '') { $id = (string) \Illuminate\Support\Str::uuid(); }
        $base = rtrim((string) ($evaluation->options['out'] ?? ''), '/');
        $dir = $base !== '' ? $base : ('ai/ingestion-evals/' . $id);

        // Ensure directory under storage/app
        if (!str_starts_with($dir, 'ai/')) {
            $dir = 'ai/ingestion-evals/' . $id;
        }
        Storage::disk('local')->makeDirectory($dir . '/artifacts');

        $paths = ['dir' => $dir];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Storage::disk('local')->put($dir . '/report.json', $json);
        $paths['json'] = storage_path('app/' . $dir . '/report.json');

        // Dump artifacts for debugging
        $artifacts = $report['artifacts'] ?? [];
        Storage::disk('local')->put($dir . '/artifacts/chunks.json', json_encode($artifacts['chunks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put($dir . '/artifacts/claims.json', json_encode($artifacts['normalized_claims'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($format === 'md' || $format === 'both') {
            $md = $this->renderMarkdownSummary($report);
            Storage::disk('local')->put($dir . '/report.md', $md);
            $paths['md'] = storage_path('app/' . $dir . '/report.md');
        }
        return $paths;
    }

    public function runGenerationProbe(IngestionEvaluation $evaluation, array $report): array
    {
        try {
            $kiId = (string) ($report['knowledge_item']['id'] ?? '');
            if ($kiId === '') {
                $report['evaluation']['generation'] = ['status' => 'skipped', 'reason' => 'no_knowledge_item'];
                return $report;
            }
            $qaItems = (array) ($report['evaluation']['synthetic_qa']['items'] ?? []);
            if (empty($qaItems)) {
                $report['evaluation']['generation'] = ['status' => 'skipped', 'reason' => 'no_qa_items'];
                return $report;
            }

            /** @var \App\Services\Ai\Evaluation\Probes\GenerationProbe $probe */
            $probe = app(\App\Services\Ai\Evaluation\Probes\GenerationProbe::class);
            $platform = (string) ($evaluation->options['platform'] ?? 'linkedin');
            $res = $probe->run((string) $evaluation->organization_id, (string) $evaluation->user_id, $kiId, $qaItems, $platform);
            $report['evaluation']['generation'] = $res;
            return $report;
        } catch (\Throwable $e) {
            Log::warning('eval.generation_probe.error', ['error' => $e->getMessage()]);
            $report['evaluation']['generation'] = ['status' => 'error', 'reason' => $e->getMessage()];
            return $report;
        }
    }

    private function renderMarkdownSummary(array $report): string
    {
        $summary = $report['summary'] ?? [];
        $issues = $report['issues'] ?? [];
        $faith = $report['evaluation']['faithfulness'] ?? null;
        $qa = $report['evaluation']['synthetic_qa'] ?? null;
        $chunks = $report['artifacts']['chunks'] ?? [];

        $lines = [];
        $lines[] = '# Ingestion Evaluation Summary';
        $lines[] = '';
        $lines[] = '- Chunks: ' . ($summary['chunks'] ?? 0);
        $lines[] = '- Embedding coverage: ' . ($summary['embedding_coverage'] ?? 0.0);
        $lines[] = '- Normalized claims: ' . ($summary['normalized_claims'] ?? 0);
        $lines[] = '';
        if (!empty($issues)) {
            $lines[] = '## Issues';
            foreach (array_slice($issues, 0, 5) as $i) {
                $lines[] = '- ' . ((string) ($i['message'] ?? json_encode($i)));
            }
            $lines[] = '';
        }
        if (is_array($faith)) {
            $lines[] = '## Faithfulness';
            $lines[] = '- Status: ' . ((string) ($faith['status'] ?? 'unknown'));
            $lines[] = '- Score: ' . ((string) ($faith['score'] ?? 'n/a'));
            $viol = (int) (is_array($faith['violations'] ?? null) ? count($faith['violations']) : 0);
            $lines[] = '- Violations: ' . $viol;
            $lines[] = '';
        }
        if (is_array($qa)) {
            $lines[] = '## Synthetic QA';
            $lines[] = '- k=3 hits: ' . (int) ($qa['metrics']['hits_at_k'] ?? 0) . ' / ' . (int) ($qa['metrics']['total'] ?? 0);
            $lines[] = '- Details: see JSON report';
            $lines[] = '';
        }
        $gen = $report['evaluation']['generation'] ?? null;
        if (is_array($gen)) {
            $lines[] = '## Generation Probe';
            if (isset($gen['retrieval_on'])) {
                $rm = (array) ($gen['retrieval_on']['metrics'] ?? []);
                $lines[] = '- Retrieval-On: ' . (int) ($rm['passes'] ?? 0) . ' / ' . (int) ($rm['total'] ?? 0)
                    . ' (rate ' . number_format((float) ($rm['pass_rate'] ?? 0), 2) . ')';
            }
            if (isset($gen['vip_forced'])) {
                $vm = (array) ($gen['vip_forced']['metrics'] ?? []);
                $lines[] = '- VIP-Forced: ' . (int) ($vm['passes'] ?? 0) . ' / ' . (int) ($vm['total'] ?? 0)
                    . ' (rate ' . number_format((float) ($vm['pass_rate'] ?? 0), 2) . ')';
            }
            if (isset($gen['verdict'])) {
                $lines[] = '- Verdict: ' . (string) $gen['verdict'];
            }
            $lines[] = '';
        }
        $lines[] = '## Chunks (grouped by role)';
        $byRole = [];
        foreach ($chunks as $c) {
            $role = (string) ($c['chunk_role'] ?? 'other');
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;
        }
        foreach ($byRole as $role => $count) {
            $lines[] = "- $role: $count";
        }
        return implode("\n", $lines) . "\n";
    }
}
