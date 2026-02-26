<?php

namespace App\Console\Commands;

use App\Models\GenerationSnapshot;
use Illuminate\Console\Command;

class ShowPrompt extends Command
{
    protected $signature = 'ai:prompts:show {snapshot_id}';

    protected $aliases = [
        'ai:show-prompt',
    ];
    protected $description = 'Show the system instruction and raw prompt string that would be sent for a snapshot';

    public function handle(): int
    {
        $id = (string) $this->argument('snapshot_id');
        $snap = GenerationSnapshot::findOrFail($id);

        $template = null;
        if (!empty($snap->template_id)) {
            try { $template = \App\Models\Template::find($snap->template_id); } catch (\Throwable) {}
        }
        if (!$template && is_array($snap->template_data)) {
            $template = (object) ['id' => null, 'template_data' => $snap->template_data];
        }

        // Prefer stored final prompts if available
        $system = (string) ($snap->final_system_prompt ?? '') ?: "You are an expert social writer. Return STRICT JSON only with a single field 'content' containing the post text.";
        if ($system !== '' && empty($snap->final_system_prompt) && !empty($snap->creative_intelligence)) {
            $ciBlock = $this->formatCiBlock((array) $snap->creative_intelligence);
            if ($ciBlock !== '') {
                $system .= "\n" . $ciBlock;
            }
        }
        $user = (string) ($snap->final_user_prompt ?? '');
        if ($user === '') {
            $user = "PROMPT: " . (string) $snap->prompt;
        }
        $chunks = (array) $snap->chunks;
        $facts = (array) $snap->facts;
        $swipes = (array) $snap->swipes;
        if (!empty($chunks)) { $user .= "\n\nKNOWLEDGE:\n" . json_encode($chunks); }
        if (!empty($facts)) { $user .= "\n\nFACTS:\n" . json_encode($facts); }
        if (!empty($template?->template_data)) { $user .= "\n\nTEMPLATE_DATA:\n" . json_encode($template->template_data); }
        if (!empty($swipes)) { $user .= "\n\nSWIPE_STRUCTURES:\n" . json_encode($swipes); }
        if (!empty($snap->user_context)) { $user .= "\n\nUSER_CONTEXT:\n" . (string) $snap->user_context; }

        $this->line(json_encode([
            'snapshot_id' => $id,
            'system_instruction' => $system,
            'raw_prompt_sent' => $user,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    private function formatCiBlock(array $ci): string
    {
        if (empty($ci)) { return ''; }
        $lines = ['CREATIVE_INTELLIGENCE:'];
        $policy = is_array($ci['policy'] ?? null) ? $ci['policy'] : [];
        if (!empty($policy)) {
            $lines[] = '- Policy: mode=' . ($policy['mode'] ?? 'auto') .
                ', hook=' . ($policy['hook'] ?? 'fill') .
                ', emotion=' . ($policy['emotion'] ?? 'fill') .
                ', audience=' . ($policy['audience'] ?? 'fill');
        }
        $resolved = is_array($ci['resolved'] ?? null) ? $ci['resolved'] : [];
        if (!empty($resolved)) {
            $bits = [];
            if (!empty($resolved['audience_persona'])) { $bits[] = 'audience_persona=' . $resolved['audience_persona']; }
            if (!empty($resolved['sophistication_level'])) { $bits[] = 'sophistication=' . $resolved['sophistication_level']; }
            if (!empty($bits)) {
                $lines[] = '- Resolved: ' . implode(', ', $bits);
            }
        }
        return implode("\n", $lines);
    }
}
