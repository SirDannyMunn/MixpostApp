<?php

namespace App\Console\Commands;

use App\Services\Ingestion\IngestionRunner;
use App\Services\Ai\Evaluation\IngestionEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AiIngestionEval extends Command
{
    protected $signature = 'ai:ingestion:evaluate
        {--org= : Organization UUID}
        {--user= : User UUID}
        {--input= : Path to input file}
        {--text= : Raw text input}
        {--title= : Optional title}
        {--source-type=text : Source type: text|file|transcript|bookmark}
        {--force : Bypass dedup}
        {--no-force : Do not bypass dedup (default is bypass)}
        {--cleanup : Remove prior eval-harness artifacts for this input}
        {--run-generation : Also run generation probes (retrieval-on and VIP-forced)}
        {--prompt= : Optional probe prompt}
        {--platform=generic : Platform for generation}
        {--retrieval-limit=3 : Retrieval limit}
        {--isolation=none : Retrieval isolation}
        {--no-llm : Disable LLM-based evaluation}
        {--log-files : Print output file paths for this run}
        {--store : Persist DB record}
        {--format=both : json|md|both}
        {--output= : Output directory}
        {--out= : Deprecated (use --output)}
        {--model-eval= : Override eval model}
        {--model-gen= : Override gen model}
        {--seed= : Seed}
        {--max-doc-chars=12000 : Max chars of doc included in eval prompt}';

    protected $aliases = [
        'ai:ingestion:eval',
    ];

    protected $description = 'Run ingestion on a single input and produce a quality evaluation report (Phase A/B/C).';

    # php artisan ai:ingestion:eval --org=019b31e7-8ff9-73e4-ac74-f9b72214bc31 --user=019b31e7-8f8c-71fd-9539-3e647ad91d6a --input=docs/fixtures/ingestion/factual_short.txt --title="Fixture: factual_short" --format=both --log-files --cleanup --run-generation

    public function handle(IngestionRunner $runner, IngestionEvaluationService $svc)
    {
        $orgId = (string) $this->option('org');
        $userId = (string) $this->option('user');
        $title = $this->option('title');
        $sourceType = (string) ($this->option('source-type') ?: 'text');
        // Default to bypass dedup for eval harness unless explicitly disabled
        $force = ((bool) $this->option('force')) || !((bool) $this->option('no-force'));
        $format = (string) ($this->option('format') ?: 'both');
        $out = $this->option('output') ?? $this->option('out');
        $store = (bool) ($this->option('store') !== false); // default true
        $noLlm = (bool) $this->option('no-llm');
        $logFiles = (bool) $this->option('log-files');

        if ($orgId === '' || $userId === '') {
            $this->error('Missing required --org and/or --user');
            return 2;
        }

        $inputText = (string) ($this->option('text') ?: '');
        $inputPath = (string) ($this->option('input') ?: '');
        if ($inputText === '' && $inputPath === '') {
            $this->error('Provide either --text or --input');
            return 2;
        }

        if ($inputText === '' && $inputPath !== '') {
            if (!is_file($inputPath)) {
                $this->error('Input file not found: ' . $inputPath);
                return 2;
            }
            $inputText = (string) file_get_contents($inputPath);
        }

        try {
            // Optional test cleanup to allow repeatable runs with identical input
            if ((bool) $this->option('cleanup')) {
                $this->warn('Cleaning previous eval-harness artifacts for this input...');
                $norm = preg_replace('/\s+/u', ' ', trim((string) $inputText)) ?? trim((string) $inputText);
                $rawSha = hash('sha256', $norm);
                $dedupHash = \App\Models\IngestionSource::dedupHashFromText($inputText);

                // Collect knowledge items created via eval_harness with same raw_text hash
                $kiIds = \App\Models\KnowledgeItem::query()
                    ->where('organization_id', $orgId)
                    ->where('raw_text_sha256', $rawSha)
                    ->whereHas('ingestionSource', function ($q) {
                        $q->where('origin', 'eval_harness');
                    })
                    ->pluck('id')
                    ->all();

                if (!empty($kiIds)) {
                    \Illuminate\Support\Facades\DB::table('knowledge_chunks')->whereIn('knowledge_item_id', $kiIds)->delete();
                    \Illuminate\Support\Facades\DB::table('knowledge_items')->whereIn('id', $kiIds)->delete();
                }

                // Remove prior ingestion_sources for this input created by eval harness
                \Illuminate\Support\Facades\DB::table('ingestion_sources')
                    ->where('organization_id', $orgId)
                    ->where('origin', 'eval_harness')
                    ->where('dedup_hash', $dedupHash)
                    ->delete();

                $this->info('Cleanup complete.');
            }

            $evaluation = $svc->startEvaluation([
                'organization_id' => $orgId,
                'user_id' => $userId,
                'title' => $title,
                'format' => $format,
                'store' => $store,
                'out' => $out,
                'options' => [
                    'source_type' => $sourceType,
                    'force' => $force,
                    'max_doc_chars' => (int) $this->option('max-doc-chars'),
                    'platform' => (string) $this->option('platform'),
                    'retrieval_limit' => (int) $this->option('retrieval-limit'),
                    'isolation' => (string) $this->option('isolation'),
                    'no_llm' => $noLlm,
                    'model_eval' => $this->option('model-eval'),
                    'model_gen' => $this->option('model-gen'),
                    'seed' => $this->option('seed'),
                ],
            ]);
            $this->line('Store flag: ' . var_export($store, true));
            $this->line('Evaluation ID: ' . (string) ($evaluation->id ?? 'null'));

            $this->info('Starting controlled ingestion...');
            $run = $runner->ingestText(
                organizationId: $orgId,
                userId: $userId,
                text: $inputText,
                title: $title,
                sourceType: $sourceType,
                force: $force,
                evaluationId: (string) ($evaluation->id ?? '')
            );

            if (!$run['success']) {
                $svc->markFailed($evaluation, 'ingestion_failed', $run['error'] ?? 'Unknown error');
                $this->error('Ingestion failed: ' . ($run['error'] ?? 'unknown'));
                return 2;
            }

            $this->info('Collecting artifacts...');
            $report = $svc->buildPhaseAReport($evaluation, $run);

            // Guard: abort evaluation if ingestion deduplicated (strict per 1.1.2)
            $dedupReason = (string) ($report['source']['dedup_reason'] ?? '');
            $sourceError = (string) ($report['source']['error'] ?? '');
            $dedupDetected = ($dedupReason !== '') || (stripos($sourceError, 'duplicate key value') !== false);
            if ($dedupDetected) {
                $svc->markFailed($evaluation, 'deduplicated', 'Evaluation aborted: ingestion was deduplicated');
                $this->error('Evaluation aborted: ingestion was deduplicated');
                return 3;
            }

            // Phase 0 hard invariants (Ingestion Eval Harness 1.2.7)
            $metrics = (array) ($report['metrics'] ?? []);
            $normStats = (array) ($report['normalization'] ?? []);
            $normCount = (int) ($metrics['normalized_claims_count'] ?? 0);
            $chunksTotal = (int) ($metrics['chunks_total'] ?? 0);
            $embedded = (int) ($metrics['embedded'] ?? -1);

            // A. Fail early if normalization executed but emitted zero claims
            if ((bool) ($normStats['executed'] ?? false) && $normCount === 0) {
                $svc->markFailed($evaluation, 'normalization_empty', 'Evaluation aborted: normalization emitted zero claims');
                $this->error('Evaluation aborted: normalization emitted zero claims');
                return 1;
            }
            // B. Stop evaluation if claims exist but chunks are fewer than claims
            if ($normCount > 0 && $chunksTotal < $normCount) {
                $svc->markFailed($evaluation, 'chunks_less_than_claims', 'Evaluation aborted: chunks_total < normalized_claims_count');
                $this->error('Evaluation aborted: chunks_total < normalized_claims_count');
                return 1;
            }
            // C. Stop if embeddings incomplete (embedded != total)
            if ($embedded !== -1 && $chunksTotal !== -1 && $embedded !== $chunksTotal) {
                $svc->markFailed($evaluation, 'artifacts_incomplete', 'Evaluation aborted: embedded != chunks_total');
                $this->error('Evaluation aborted: embedded != chunks_total');
                return 1;
            }

            // Normalization diagnostic: warn (do not fail) if eligible but not executed
            $norm = $report['normalization'] ?? [];
            if (($norm['eligible'] ?? false) && !($norm['executed'] ?? false)) {
                $this->warn('Normalization eligible but not executed; continuing');
            }

            if (!$noLlm) {
                $this->info('Running faithfulness audit...');
                $report = $svc->runFaithfulnessAudit($evaluation, $report);

                $this->info('Running synthetic QA (retrieval diagnostics)...');
                $report = $svc->runSyntheticQATest($evaluation, $report);

                if ((bool) $this->option('run-generation')) {
                    $this->info('Running generation probe...');
                    // If user provided a custom prompt, create an ad-hoc QA item and run only that
                    $customPrompt = (string) ($this->option('prompt') ?: '');
                    if ($customPrompt !== '') {
                        $report['evaluation']['synthetic_qa']['items'] = [[
                            'question' => $customPrompt,
                            'expected_answer_summary' => '',
                            'target_chunk_ids' => [],
                        ]];
                    }
                    $report = $svc->runGenerationProbe($evaluation, $report);
                }
            } else {
                $this->warn('LLM disabled via --no-llm; skipping Phase B & QA');
            }

            $paths = $svc->writeReports($evaluation, $report, $format);
            $svc->markCompleted($evaluation, $paths);

            $this->line('Report JSON: ' . ($paths['json'] ?? '(not written)'));
            if (isset($paths['md'])) {
                $this->line('Report Markdown: ' . $paths['md']);
            }

            $topline = $report['summary'] ?? [];
            $this->info('Artifacts: ' . json_encode($topline));

            if ($logFiles) {
                $dir = (string) ($paths['dir'] ?? '');
                if ($dir !== '') {
                    $files = Storage::disk('local')->allFiles($dir);
                    // Ensure main reports are first
                    usort($files, function ($a, $b) use ($dir) {
                        $priority = [
                            $dir . '/report.json' => 0,
                            $dir . '/report.md' => 1,
                        ];
                        $pa = $priority[$a] ?? 10;
                        $pb = $priority[$b] ?? 10;
                        if ($pa === $pb) return strcmp($a, $b);
                        return $pa <=> $pb;
                    });
                    $this->line('==== Begin run outputs ====');
                    foreach ($files as $f) {
                        $abs = storage_path('app/' . $f);
                        $this->line('----- ' . $abs . ' -----');
                        try {
                            $this->line((string) Storage::disk('local')->get($f));
                        } catch (\Throwable $e) {
                            $this->warn('[unreadable] ' . $e->getMessage());
                        }
                    }
                    $this->line('==== End run outputs ====');
                }
            }
            // --- CI/CD Exit Code Policy (v1.2.2) ---
            $exitCode = 0;

            // 1) Artifacts integrity: embedded must equal total
            $metrics = (array) ($report['metrics'] ?? []);
            $embedded = (int) ($metrics['embedded'] ?? -1);
            $totalChunks = (int) ($metrics['chunks_total'] ?? -1);
            if ($embedded !== -1 && $totalChunks !== -1 && $embedded !== $totalChunks) {
                $this->error('Artifacts integrity failed: embedded != total');
                $exitCode = 1;
            }

            // 2) Faithfulness: fail if any violation or score < 1.0
            $faith = (array) ($report['evaluation']['faithfulness'] ?? []);
            $faithStatus = (string) ($faith['status'] ?? 'unknown');
            $faithScore = isset($faith['score']) ? (float) $faith['score'] : null;
            $faithViolations = is_array($faith['violations'] ?? null) ? count($faith['violations']) : null;
            if (in_array($faithStatus, ['pass','fail','ok','unknown'], true)) {
                if ($faithStatus === 'fail' || ($faithScore !== null && $faithScore < 1.0) || (($faithViolations ?? 0) > 0)) {
                    $this->error('Faithfulness check failed');
                    $exitCode = 1;
                }
            }

            // 3) Generation Probe: fail if pass_rate < 0.66 when present
            $gen = (array) ($report['evaluation']['generation'] ?? []);
            $genMetrics = (array) ($gen['metrics'] ?? []);
            if (!empty($genMetrics)) {
                $passRate = (float) ($genMetrics['pass_rate'] ?? 0.0);
                if ($passRate < 0.66) {
                    $this->error('Generation probe threshold failed: pass_rate < 0.66');
                    $exitCode = 1;
                }
            }

            // 4) Retrieval competitiveness messaging (Optimization Harness 1.2.6)
            $gen = (array) ($report['evaluation']['generation'] ?? []);
            if (!empty($gen)) {
                $verdict = (string) ($gen['verdict'] ?? 'unknown');
                $rt = (array) (($gen['retrieval_on'] ?? [])['metrics'] ?? []);
                $rtPassRate = (float) ($rt['pass_rate'] ?? 0.0);
                $metrics = (array) ($report['metrics'] ?? []);
                $chunkCount = (int) ($metrics['chunks_total'] ?? ($report['summary']['chunks'] ?? 0));
                if ($chunkCount <= 2) { $rtThreshold = 0.66; }
                elseif ($chunkCount <= 6) { $rtThreshold = 0.33; }
                else { $rtThreshold = 0.50; }

                if ($verdict === 'pass' && $rtPassRate <= $rtThreshold) {
                    $this->warn('Retrieval competitiveness borderline — optimization recommended');
                } elseif ($verdict !== 'pass' && $rtPassRate < $rtThreshold) {
                    $this->warn('Retrieval recall below target (< ' . number_format($rtThreshold, 2) . '). Tune ranking.');
                }
            }

            return $exitCode;
        } catch (\Throwable $e) {
            $this->error('Unexpected error: ' . $e->getMessage());
            return 3;
        }
    }
}
