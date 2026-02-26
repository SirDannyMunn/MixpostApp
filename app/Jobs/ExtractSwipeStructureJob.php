<?php

namespace App\Jobs;

use App\Models\SwipeItem;
use App\Models\SwipeStructure;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractSwipeStructureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $swipeItemId) {}

    public function handle(OpenRouterService $ai): void
    {
        $swipe = SwipeItem::find($this->swipeItemId);
        if (!$swipe || empty($swipe->raw_text)) return;

        // 1. Prepare Text
        $text = substr((string) $swipe->raw_text, 0, 3000);

        // 2. Construct Prompt for "Style DNA"
        $prompt = "Analyze the *structure* and *pacing* of the following social media post. Ignore the specific topic/content.
        
        Extract the underlying template (DNA) so I can reuse this format for a different topic.
        
        Return ONLY valid JSON with this structure:
        {
            \"intent\": \"educational|contrarian|promotional|story\",
            \"funnel_stage\": \"tof|mof|bof\",
            \"hook_type\": \"question|statement|statistic|negative\",
            \"cta_type\": \"soft|hard|none\",
            \"structure\": [
                { \"section\": \"Hook\", \"purpose\": \"Grab attention\" },
                { \"section\": \"Body\", \"purpose\": \"Explain problem\" }
            ],
            \"confidence\": 0-100
        }

        Text to analyze:
        " . $text;

        // 3. Call AI
        $response = $ai->chatJSON([
            ['role' => 'user', 'content' => $prompt]
        ], [
            'temperature' => 0.2, // Low temp for consistent JSON
            'max_tokens' => 1000,
        ]);

        if (empty($response) || !is_array($response)) return;

        // 4. Save to Database
        SwipeStructure::create([
            'swipe_item_id' => $swipe->id,
            'intent'        => $response['intent'] ?? 'educational',
            'funnel_stage'  => $response['funnel_stage'] ?? 'tof',
            'hook_type'     => $response['hook_type'] ?? 'statement',
            'cta_type'      => $response['cta_type'] ?? 'none',
            'structure'     => $response['structure'] ?? [], 
            'language_signals' => [], // Optional: Could extract specific words later
            'confidence'    => (float) (($response['confidence'] ?? 80) / 100), // Normalize 0-100 to 0.0-1.0
            'created_at'    => now(),
        ]);
    }
}