<?php

namespace App\Jobs;

use App\Models\BusinessFact;
use App\Models\KnowledgeItem;
use App\Services\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Ai\Generation\ContentGenBatchLogger;

class ExtractBusinessFactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $knowledgeItemId) {}

    public function handle(OpenRouterService $ai): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('ExtractBusinessFactsJob:' . $this->knowledgeItemId, []);
        $item = KnowledgeItem::find($this->knowledgeItemId);
        if (!$item || empty($item->raw_text)) {
            $logger->flush('skipped', ['reason' => 'no_item_or_text']);
            return;
        }

        $text = (string) $item->raw_text;
        // Limit to reduce token usage
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 2000);
        } else {
            $text = substr($text, 0, 2000);
        }

        $prompt = "Analyze the following text and extract up to 3 atomic 'Business Facts', 'Beliefs', or 'Pain Points'.\n\n" .
                  "Rules:\n" .
                  "- Return ONLY valid JSON.\n" .
                  "- Prefer concise, standalone facts that would help marketing copy.\n" .
                  "- Ignore introductions, greetings, or fluff.\n" .
                  "- If nothing useful exists, return an empty list.\n\n" .
                  "Output format: { \"facts\": [ { \"text\": \"...\", \"type\": \"pain_point|belief|stat\", \"confidence\": 0-100 } ] }\n\n" .
                  "Text to analyze:\n" . $text;

        $response = $ai->chatJSON([
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.2,
            'max_tokens' => 800,
        ]);
        $logger->capture('llm.response', ['shape' => is_array($response) ? array_keys($response) : gettype($response)]);

        if (!is_array($response) || empty($response)) {
            return;
        }

        // Normalize possible shapes: single object or {facts: [...]}
        $items = [];
        if (isset($response['text'])) {
            $items = [$response];
        } elseif (isset($response['facts']) && is_array($response['facts'])) {
            $items = $response['facts'];
        } elseif (function_exists('array_is_list') && array_is_list($response)) {
            $items = $response;
        }

        if (empty($items)) {
            $logger->flush('completed', ['facts' => 0]);
            return;
        }

        // Cap at 3 facts to avoid noise
        $items = array_slice($items, 0, 3);

        $created = 0;
        foreach ($items as $fact) {
            $text = is_array($fact) ? trim((string) ($fact['text'] ?? '')) : '';
            if ($text === '') {
                continue;
            }

            $type = is_array($fact) ? (string) ($fact['type'] ?? 'general') : 'general';
            $type = in_array($type, ['pain_point','belief','stat','general'], true) ? $type : 'general';

            $conf = 0.8; // default 80%
            if (is_array($fact) && isset($fact['confidence'])) {
                $conf = (float) $fact['confidence'];
                // Normalize to 0..1 if provided as 0..100
                if ($conf > 1.0) {
                    $conf = $conf / 100.0;
                }
            }
            // Clamp
            $conf = max(0.0, min(1.0, (float) $conf));

            BusinessFact::create([
                'organization_id' => $item->organization_id,
                'user_id' => $item->user_id,
                'type' => $type,
                'text' => $text,
                'confidence' => $conf,
                'source_knowledge_item_id' => $item->id,
                'created_at' => now(),
            ]);
            $created++;
        }
        $logger->flush('completed', ['facts' => $created]);
    }
}
