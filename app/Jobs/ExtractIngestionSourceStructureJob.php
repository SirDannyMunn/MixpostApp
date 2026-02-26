<?php

namespace App\Jobs;

use App\Models\IngestionSource;
use App\Services\OpenRouterService;
use App\Services\SwipeStructures\SwipeStructureService;
use App\Services\Ingestion\IngestionContentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractIngestionSourceStructureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $ingestionSourceId,
        public ?string $triggeredByUserId = null,
    ) {}

    public function handle(OpenRouterService $ai, SwipeStructureService $service): void
    {
        $src = IngestionSource::find($this->ingestionSourceId);
        if (!$src) {
            return;
        }

        $text = '';
        // For bookmark sources, resolve content from internal models (no HTTP)
        if ((string) ($src->source_type ?? '') === 'bookmark') {
            try {
                $text = (string) (app(IngestionContentResolver::class)->resolve($src) ?? '');
            } catch (\Throwable) {
                $text = '';
            }
        } else {
            $text = (string) ($src->raw_text ?? '');
        }

        $text = trim($text);
        if ($text === '') {
            $src->structure_status = 'failed';
            $src->save();
            return;
        }

        // Track status on the ingestion source.
        try {
            $src->structure_status = 'pending';
            $src->save();
        } catch (\Throwable) {
            // ignore
        }

        $excerpt = mb_substr($text, 0, 3500);

        $prompt = "Analyze the *structure* and *pacing* of the following content. Ignore the specific topic/content.\n\n" .
            "Extract the underlying template so I can reuse this format for a different topic.\n\n" .
            "Return ONLY valid JSON with this exact shape:\n" .
            "{\n" .
            "  \"title\": \"short human label\",\n" .
            "  \"intent\": \"educational|persuasive|story|contrarian|emotional\",\n" .
            "  \"funnel_stage\": \"tof|mof|bof\",\n" .
            "  \"hook_type\": \"question|statement|statistic|negative\",\n" .
            "  \"cta_type\": \"none|soft|hard\",\n" .
            "  \"structure\": [\n" .
            "    { \"section\": \"Hook\", \"purpose\": \"Grab attention\" }\n" .
            "  ],\n" .
            "  \"confidence\": 0\u2013100\n" .
            "}\n\n" .
            "Text to analyze:\n" . $excerpt;

        try {
            $response = $ai->chatJSON([
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.2,
                'max_tokens' => 1000,
            ]);

            if (empty($response) || !is_array($response)) {
                $src->structure_status = 'failed';
                $src->save();
                return;
            }

            $structure = $response['structure'] ?? [];
            if (!is_array($structure)) {
                $structure = [];
            }

            $swipe = $service->createFromIngestionSource([
                'organization_id' => (string) $src->organization_id,
                'swipe_item_id' => null,
                'title' => $response['title'] ?? null,
                'intent' => $response['intent'] ?? null,
                'funnel_stage' => $response['funnel_stage'] ?? null,
                'hook_type' => $response['hook_type'] ?? null,
                'cta_type' => $response['cta_type'] ?? 'none',
                'structure' => $structure,
                'confidence' => $response['confidence'] ?? 50,
                'created_by_user_id' => $this->triggeredByUserId ?: (string) ($src->user_id ?? ''),
            ]);

            $src->swipe_structure_id = (string) $swipe->id;
            $src->structure_status = 'generated';
            $src->structure_confidence = (int) max(0, min(100, (int) ($response['confidence'] ?? 50)));
            $src->save();
        } catch (\Throwable $e) {
            try {
                $src->structure_status = 'failed';
                $src->save();
            } catch (\Throwable) {
                // ignore
            }
            Log::warning('ingestion_source.extract_structure_failed', [
                'ingestion_source_id' => (string) $src->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
