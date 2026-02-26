<?php

namespace App\Console\Commands;

use App\Models\GenerationSnapshot;
use App\Services\Ai\ContentGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReplaySnapshot extends Command
{
    protected $signature = 'ai:snapshots:replay {snapshot_id}
        {--platform=}
        {--max-chars=}
        {--emoji=}
        {--tone=}
        {--folder-ids= : Comma-separated folder UUIDs to scope knowledge retrieval (e.g. uuid,uuid)}
        {--budget=}
        {--model= : Default model for all stages (overridden by per-stage models)}
        {--models= : JSON object mapping stage=>model (e.g. {"generate":"x-ai/grok-4-fast"})}
        {--model-classify= : Model for classification stage (PostClassifier/OpenRouter classify)}
        {--model-generate= : Model for main generation stage (call: generate)}
        {--model-replay-generate= : Model for replay generation stage (call: replay_generate)}
        {--model-repair= : Model for repair stage (call: repair)}
        {--model-generate-fallback= : Model for fallback regeneration (call: generate_fallback)}
        {--model-reflexion-critique= : Model for reflexion critique (call: reflexion_critique)}
        {--model-reflexion-refine= : Model for reflexion refine (call: reflexion_refine)}
        {--prompt-only : Build and print prompts without LLM or persistence}
        {--reflexion : Enable Critic-Refiner loop during generation}
        {--reflection : Alias of --reflexion}
        {--no-report}
        {--via-generate : Run through generate() with snapshot-derived options}
        {--no-overrides : Do not inject snapshot chunks/facts/swipes as VIP overrides (use live retrieval instead)}';

    protected $aliases = [
        'ai:replay-snapshot',
    ];

    protected $description = 'Replay a generation snapshot and print new output + scores as JSON. Use --via-generate to run through generate() instead of replay. Add --reflexion to enable the critic/refiner loop.';

    public function handle(ContentGeneratorService $generator)
    {
        $id = (string) $this->argument('snapshot_id');
        if (!Str::isUuid($id)) {
            $this->error('Invalid snapshot_id. Provide a full UUID (e.g., 550e8400-e29b-41d4-a716-446655440000).');
            $this->line('Tip: run php artisan ai:list-snapshots to find recent IDs.');
            return 1;
        }
        $snap = GenerationSnapshot::findOrFail($id);

        $options = [];
        if ($v = $this->option('max-chars')) { $options['max_chars'] = (int) $v; }
        if ($v = $this->option('emoji')) { $options['emoji'] = (string) $v; }
        if ($v = $this->option('tone')) { $options['tone'] = (string) $v; }
        if ($v = $this->option('folder-ids')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn($s) => $s !== ''));
            $options['folder_ids'] = array_values(array_filter($parts, fn($id) => Str::isUuid($id)));
        }
        if ($v = $this->option('budget')) { $options['context_token_budget'] = (int) $v; }
        if ($this->option('reflexion') || $this->option('reflection')) { $options['enable_reflexion'] = true; }

        // Model overrides
        $cliDefaultModel = trim((string) ($this->option('model') ?? ''));
        $cliStageModels = [];
        $stageFlagMap = [
            'classify' => 'model-classify',
            'generate' => 'model-generate',
            'replay_generate' => 'model-replay-generate',
            'repair' => 'model-repair',
            'generate_fallback' => 'model-generate-fallback',
            'reflexion_critique' => 'model-reflexion-critique',
            'reflexion_refine' => 'model-reflexion-refine',
        ];
        foreach ($stageFlagMap as $stage => $flag) {
            $val = trim((string) ($this->option($flag) ?? ''));
            if ($val !== '') {
                $cliStageModels[$stage] = $val;
            }
        }
        $modelsJson = trim((string) ($this->option('models') ?? ''));
        if ($modelsJson !== '') {
            $decoded = json_decode($modelsJson, true);
            if (!is_array($decoded)) {
                $this->error('Invalid --models JSON. Expected an object like {"generate":"model"}.');
                return 1;
            }
            foreach ($decoded as $k => $v) {
                if (!is_string($k)) { continue; }
                $m = trim(is_string($v) ? $v : '');
                if ($m !== '') {
                    $cliStageModels[$k] = $m;
                }
            }
        }

        // Prompt-only: run through ContentGeneratorService (build-only mode)
        if ($this->option('prompt-only') && !$this->option('via-generate')) {
            $platform = (string) ($this->option('platform') ?: ($snap->platform ?? 'generic'));
            $orgId = (string) $snap->organization_id;
            $userId = (string) $snap->user_id;
            $prompt = (string) $snap->prompt;

            $useOverrides = !$this->option('no-overrides');
            // Build overrides from snapshot (optional)
            $overrideKnowledge = [];
            $overrideFacts = [];
            $overrideSwipes = [];
            if ($useOverrides) {
                foreach ((array) $snap->chunks as $c) {
                    $text = (string) ($c['chunk_text'] ?? ($c['text'] ?? ''));
                    if ($text !== '') {
                        $overrideKnowledge[] = [
                            'id' => $c['id'] ?? null,
                            'type' => 'reference',
                            'content' => $text,
                        ];
                    }
                }
                $overrideFacts = array_values(array_filter(array_map(fn($f) => $f['id'] ?? null, (array) $snap->facts)));
                $overrideSwipes = array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, (array) $snap->swipes)));
            }

            $genOptions = array_merge((array) $snap->options, $options, [
                'template_id' => $snap->template_id ?: null,
                'voice_profile_id' => $snap->voice_profile_id ?? (($snap->options['voice_profile_id'] ?? null) ?: null),
                'voice_source' => $snap->voice_source ?? ($snap->options['voice_source'] ?? null),
                'user_context' => (string) ($snap->user_context ?? ''),
                'mode' => 'prompt_only',
            ]);
            if ($useOverrides) {
                $genOptions['swipe_mode'] = 'strict';
                $genOptions['swipe_ids'] = $overrideSwipes;
                $genOptions['overrides'] = [
                    'template_id' => $snap->template_id ?: null,
                    'knowledge' => $overrideKnowledge,
                    'facts' => $overrideFacts,
                    'swipes' => $overrideSwipes,
                ];
            } else {
                // Ensure snapshot-stored VIP overrides do not leak through via $snap->options
                $genOptions['overrides'] = [];
                // Reset swipe strictness to allow live selection (optional)
                $genOptions['swipe_mode'] = (string) ($options['swipe_mode'] ?? 'auto');
                $genOptions['swipe_ids'] = array_values(array_filter((array) ($options['swipe_ids'] ?? []), fn($v) => (string) $v !== ''));
            }
            if ($cliDefaultModel !== '') {
                $genOptions['model'] = $cliDefaultModel;
            }
            $existingModels = is_array($genOptions['models'] ?? null) ? (array) $genOptions['models'] : [];
            $mergedModels = array_merge($existingModels, $cliStageModels);
            if (!empty($mergedModels)) {
                $genOptions['models'] = $mergedModels;
            }
            if (!array_key_exists('use_retrieval', $genOptions)) { $genOptions['use_retrieval'] = true; }
            if (!isset($genOptions['retrieval_limit']) || (int) $genOptions['retrieval_limit'] <= 0) { $genOptions['retrieval_limit'] = 3; }

            $res = $generator->generate($orgId, $userId, $prompt, $platform, $genOptions);
            $out = [
                'mode' => 'prompt_only',
                'snapshot_id' => $id,
                'system' => (string) ($res['system'] ?? ''),
                'user' => (string) ($res['user'] ?? ''),
                'meta' => (array) ($res['meta'] ?? []),
                'context_summary' => (array) ($res['context_summary'] ?? []),
            ];
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }// Via-generate path: can also support prompt-only by setting mode=prompt_only
        if ($this->option('via-generate')) {
            $platform = (string) ($this->option('platform') ?: ($snap->platform ?? 'generic'));
            $orgId = (string) $snap->organization_id;
            $userId = (string) $snap->user_id;
            $prompt = (string) $snap->prompt;

            $useOverrides = !$this->option('no-overrides');
            // Build overrides from snapshot (optional)
            $overrideKnowledge = [];
            $overrideFacts = [];
            $overrideSwipes = [];
            if ($useOverrides) {
                foreach ((array) $snap->chunks as $c) {
                    $text = (string) ($c['chunk_text'] ?? ($c['text'] ?? ''));
                    if ($text !== '') {
                        $overrideKnowledge[] = [
                            'id' => $c['id'] ?? null,
                            'type' => 'reference',
                            'content' => $text,
                        ];
                    }
                }
                $overrideFacts = array_values(array_filter(array_map(fn($f) => $f['id'] ?? null, (array) $snap->facts)));
                $overrideSwipes = array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, (array) $snap->swipes)));
            }

            $genOptions = array_merge((array) $snap->options, $options, [
                'template_id' => $snap->template_id ?: null,
                'voice_profile_id' => $snap->voice_profile_id ?? (($snap->options['voice_profile_id'] ?? null) ?: null),
                'voice_source' => $snap->voice_source ?? ($snap->options['voice_source'] ?? null),
                'user_context' => (string) ($snap->user_context ?? ''),
            ]);
            if ($useOverrides) {
                $genOptions['swipe_mode'] = 'strict';
                $genOptions['swipe_ids'] = $overrideSwipes;
                $genOptions['overrides'] = [
                    'template_id' => $snap->template_id ?: null,
                    'knowledge' => $overrideKnowledge,
                    'facts' => $overrideFacts,
                    'swipes' => $overrideSwipes,
                ];
            } else {
                // Ensure snapshot-stored VIP overrides do not leak through via $snap->options
                $genOptions['overrides'] = [];
                // Reset swipe strictness to allow live selection (optional)
                $genOptions['swipe_mode'] = (string) ($options['swipe_mode'] ?? 'auto');
                $genOptions['swipe_ids'] = array_values(array_filter((array) ($options['swipe_ids'] ?? []), fn($v) => (string) $v !== ''));
            }

            if ($cliDefaultModel !== '') {
                $genOptions['model'] = $cliDefaultModel;
            }
            $existingModels = is_array($genOptions['models'] ?? null) ? (array) $genOptions['models'] : [];
            $mergedModels = array_merge($existingModels, $cliStageModels);
            if (!empty($mergedModels)) {
                $genOptions['models'] = $mergedModels;
            }

            if (!array_key_exists('use_retrieval', $genOptions)) {
                $genOptions['use_retrieval'] = true;
            }
            if (!isset($genOptions['retrieval_limit']) || (int) $genOptions['retrieval_limit'] <= 0) {
                $genOptions['retrieval_limit'] = 3;
            }
            if ($this->option('prompt-only')) {
                $genOptions['mode'] = 'prompt_only';
            }

            $genResult = $generator->generate($orgId, $userId, $prompt, $platform, $genOptions);

            if ($this->option('prompt-only')) {
                $out = [
                    'mode' => 'via_generate_prompt_only',
                    'snapshot_id' => $id,
                    'system' => (string) ($genResult['system'] ?? ''),
                    'user' => (string) ($genResult['user'] ?? ''),
                    'meta' => (array) ($genResult['meta'] ?? []),
                    'context_summary' => (array) ($genResult['context_summary'] ?? []),
                ];
                $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return 0;
            }

            $out = [
                'mode' => 'via_generate',
                'snapshot_id' => $id,
                'metadata' => [
                    'intent' => $genResult['metadata']['intent'] ?? null,
                    'platform' => $platform,
                    'template_id' => $genResult['metadata']['template_id'] ?? null,
                ],
                'output' => [
                    'content' => $genResult['content'] ?? '',
                    'validation' => $genResult['validation'] ?? [],
                ],
                'context_used' => $genResult['context_used'] ?? [],
            ];
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        // Default: classic replay path
        $result = $generator->replayFromSnapshot($snap, [
            'platform' => $this->option('platform') ?: null,
            'options' => (function () use ($snap, $options, $cliDefaultModel, $cliStageModels) {
                $out = $options;
                if ($cliDefaultModel !== '') {
                    $out['model'] = $cliDefaultModel;
                }
                $existing = is_array(($snap->options['models'] ?? null)) ? (array) $snap->options['models'] : [];
                $provided = is_array(($out['models'] ?? null)) ? (array) $out['models'] : [];
                $merged = array_merge($existing, $provided, $cliStageModels);
                if (!empty($merged)) {
                    $out['models'] = $merged;
                }
                return $out;
            })(),
            'store_report' => $this->option('no-report') ? false : true,
        ]);

        $out = [
            'mode' => 'replay',
            'snapshot_id' => $id,
            'metadata' => [
                'model_used' => $result['metadata']['model_used'] ?? null,
                'total_tokens' => $result['metadata']['total_tokens'] ?? null,
                'processing_time_ms' => $result['metadata']['processing_time_ms'] ?? null,
                'intent' => $result['metadata']['intent'] ?? null,
                'platform' => $result['metadata']['platform'] ?? null,
            ],
            'input_snapshot' => $result['input_snapshot'] ?? [],
            'output' => [
                'content' => $result['content'] ?? '',
                'validation' => $result['validation'] ?? [],
            ],
            'quality_report' => [
                'overall_score' => $result['quality']['overall_score'] ?? null,
                'breakdown' => $result['quality']['scores'] ?? [],
            ],
            'context' => $result['context'] ?? [],
            'debug_links' => $result['debug_links'] ?? [],
        ];
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return 0;
    }
}


