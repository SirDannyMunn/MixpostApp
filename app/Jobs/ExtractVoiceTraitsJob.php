<?php

namespace App\Jobs;

use App\Models\KnowledgeItem;
use App\Models\VoiceProfile;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Ai\Generation\ContentGenBatchLogger;

class ExtractVoiceTraitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $knowledgeItemId) {}

    public function handle(OpenRouterService $ai): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('ExtractVoiceTraitsJob:' . $this->knowledgeItemId, []);
        $item = KnowledgeItem::find($this->knowledgeItemId);
        if (!$item || empty($item->raw_text)) {
            $logger->flush('skipped', ['reason' => 'no_item_or_text']);
            return;
        }

        $text = (string) $item->raw_text;
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 1000) : substr($text, 0, 1000);

        $prompt = "Analyze the writing style of the following text.\n" .
                  "Extract 3 distinct voice traits (e.g., 'authoritative', 'whimsical', 'data-driven').\n" .
                  "Return ONLY JSON: { \"traits\": [\"trait1\", \"trait2\", \"trait3\"] }\n\n" .
                  "Text: " . $text;

        $response = $ai->chatJSON([
            ['role' => 'user', 'content' => $prompt]
        ], [
            'temperature' => 0.2,
            'max_tokens' => 300,
        ]);
        $logger->capture('llm.response', ['has_traits' => isset($response['traits']), 'traits' => $response['traits'] ?? null]);

        if (!empty($response['traits']) && is_array($response['traits'])) {
            $traits = array_values(array_filter(array_map(function ($t) {
                $s = trim((string) $t);
                return $s !== '' ? $s : null;
            }, $response['traits'])));

            if (!empty($traits)) {
                $profile = VoiceProfile::firstOrNew([
                    'organization_id' => $item->organization_id,
                    'user_id' => $item->user_id,
                ]);

                $currentTraits = [];
                if (is_array($profile->traits) && isset($profile->traits['tone']) && is_array($profile->traits['tone'])) {
                    $currentTraits = $profile->traits['tone'];
                }

                $merged = array_values(array_unique(array_merge($currentTraits, $traits)));
                $profile->traits = ['tone' => array_slice($merged, 0, 10)];
                $profile->refreshTraitsPreview();
                $profile->sample_size = (int) (($profile->sample_size ?? 0) + 1);
                $profile->confidence = min(0.95, (float) ($profile->confidence ?? 0.5) + 0.05);
                $profile->updated_at = now();
                $profile->save();
            }
        }
        $logger->flush('completed');
    }
}
