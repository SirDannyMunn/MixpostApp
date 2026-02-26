<?php

namespace App\Console\Commands;

use App\Models\GenerationSnapshot;
use Illuminate\Console\Command;

class ContentServiceGetReport extends Command
{
    protected $signature = 'content-service:report:get {snapshot_id?} {--count=1 : Number of recent snapshots to export}';

    protected $aliases = [
        'content-service:get-report',
    ];
    protected $description = 'Dump a generation snapshot report to storage/logs';

    public function handle(): int
    {
        $id = (string) ($this->argument('snapshot_id') ?? '');
        $count = (int) $this->option('count');

        // If a specific snapshot ID is provided, just get that one
        if ($id !== '') {
            $snap = GenerationSnapshot::find($id);
            if (!$snap) {
                $this->error("Snapshot not found: {$id}");
                return 1;
            }
            $snapshots = [$snap];
            $fileName = 'content-service-report-' . (string) $snap->id . '.json';
        } else {
            // Otherwise, get the most recent N snapshots
            $snapshots = GenerationSnapshot::query()
                ->orderByDesc('created_at')
                ->limit($count)
                ->get();
            
            if ($snapshots->isEmpty()) {
                $this->error('No generation snapshots found.');
                return 1;
            }
            $fileName = 'content-service-report-most-recent-' . $count . '.json';
        }

        $this->info("Generating report for {$snapshots->count()} snapshot(s)...");

        $allSnapshots = [];
        foreach ($snapshots as $snap) {
            $allSnapshots[] = [
                'snapshot_id' => (string) $snap->id,
                'created_at' => optional($snap->created_at)->toDateTimeString(),
                'organization_id' => (string) $snap->organization_id,
                'user_id' => (string) $snap->user_id,
                'platform' => (string) ($snap->platform ?? ''),
                'prompt' => (string) $snap->prompt,
                'classification' => (array) ($snap->classification ?? []),
                'intent' => (string) ($snap->intent ?? ''),
                'mode' => (array) ($snap->mode ?? []),
                'template_id' => $snap->template_id,
                'template_data' => $snap->template_data,
                'voice_profile_id' => $snap->voice_profile_id,
                'voice_source' => $snap->voice_source,
                'chunks' => (array) ($snap->chunks ?? []),
                'facts' => (array) ($snap->facts ?? []),
                'swipes' => (array) ($snap->swipes ?? []),
                'structure_resolution' => $snap->structure_resolution,
                'structure_fit_score' => $snap->structure_fit_score,
                'resolved_structure_payload' => $snap->resolved_structure_payload,
                'user_context' => $snap->user_context,
                'creative_intelligence' => $snap->creative_intelligence,
                'options' => $snap->options,
                'output_content' => $snap->output_content,
                'final_system_prompt' => $snap->final_system_prompt,
                'final_user_prompt' => $snap->final_user_prompt,
                'token_metrics' => $snap->token_metrics,
                'performance_metrics' => $snap->performance_metrics,
                'repair_metrics' => $snap->repair_metrics,
                'llm_stages' => $snap->llm_stages,
            ];
        }

        $path = storage_path('logs/' . $fileName);

        $json = json_encode($allSnapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode snapshot payload.');
            return 1;
        }

        try {
            file_put_contents($path, $json . PHP_EOL);
        } catch (\Throwable $e) {
            $this->error('Failed to write report: ' . $e->getMessage());
            return 1;
        }

        $this->info('Report written: ' . $path);
        return 0;
    }
}
