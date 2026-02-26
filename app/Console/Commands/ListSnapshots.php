<?php

namespace App\Console\Commands;

use App\Models\GenerationSnapshot;
use Illuminate\Console\Command;

class ListSnapshots extends Command
{
    protected $signature = 'ai:snapshots:list {--org=} {--intent=} {--limit=20} {--json}';

    protected $aliases = [
        'ai:list-snapshots',
    ];
    protected $description = 'List recent generation snapshots with IDs and metadata';

    public function handle(): int
    {
        $org = $this->option('org');
        $intent = $this->option('intent');
        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 200));

        $q = GenerationSnapshot::query()
            ->when($org, fn($qq) => $qq->where('organization_id', $org))
            ->when($intent, fn($qq) => $qq->where('classification->intent', $intent))
            ->orderByDesc('created_at')
            ->limit($limit);

        $rows = $q->get(['id','organization_id','user_id','platform','classification','created_at','prompt'])->map(function ($s) {
            return [
                'id' => (string) $s->id,
                'org' => (string) $s->organization_id,
                'user' => (string) $s->user_id,
                'platform' => (string) ($s->platform ?? ''),
                'intent' => (string) (($s->classification['intent'] ?? '') ?: ''),
                'created_at' => optional($s->created_at)->toDateTimeString(),
                'prompt_preview' => mb_substr((string) $s->prompt, 0, 80),
            ];
        })->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->table(['id','org','user','platform','intent','created_at','prompt_preview'], $rows);
        $this->line('Tip: replay with php artisan ai:replay-snapshot {id} --platform=twitter --max-chars=240');
        return 0;
    }
}

