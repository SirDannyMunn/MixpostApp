<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ChunkKnowledgeItemJob;
use App\Jobs\EmbedKnowledgeChunksJob;
use App\Jobs\ExtractBusinessFactsJob;
use App\Jobs\ExtractVoiceTraitsJob;
use App\Models\KnowledgeItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class KnowledgeItemController extends Controller
{
    // POST /api/v1/knowledge-items
    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            // Expanded semantics per implementation guide
            'type' => 'required|string|in:note,idea,draft,excerpt,fact,transcript,email,doc,url,offer,post,custom',
            'source' => 'required|string|in:manual,upload,chrome_extension,integration,bookmark,transcript,notion,import,post',
            'title' => 'sometimes|nullable|string|max:500',
            'raw_text' => 'required|string|min:50|max:200000',
            'metadata' => 'sometimes|array',
            'source_id' => 'sometimes|nullable|string',
            'source_platform' => 'sometimes|nullable|string|max:100',
        ]);

        $hash = hash('sha256', $data['raw_text']);

        // Soft dedup: prevent identical ingests from creating multiple items
        $dupQuery = \App\Models\KnowledgeItem::query()
            ->where('organization_id', $organization->id)
            ->where('raw_text_sha256', $hash)
            ->where('source', $data['source']);
        if (!empty($data['source_id'])) {
            $dupQuery->where('source_id', $data['source_id']);
        }
        if ($existing = $dupQuery->first()) {
            return response()->json([
                'id' => $existing->id,
                'status' => 'duplicate',
            ]);
        }
        // Default confidence by source (can be tuned later)
        $confidence = match ($data['source']) {
            'bookmark' => 0.3,
            'manual' => 0.6,
            'post' => 0.8,
            default => 0.5,
        };

        $item = KnowledgeItem::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'source' => $data['source'],
            'title' => $data['title'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'source_platform' => $data['source_platform'] ?? null,
            'raw_text' => $data['raw_text'],
            'raw_text_sha256' => $hash,
            'metadata' => $data['metadata'] ?? null,
            'confidence' => $confidence,
            'ingested_at' => now(),
        ]);

        // Dispatch async pipeline with strict ordering for data quality
        Bus::chain([
            new \App\Jobs\NormalizeKnowledgeItemJob($item->id),
            new ChunkKnowledgeItemJob($item->id),
            new EmbedKnowledgeChunksJob($item->id),
            new ExtractVoiceTraitsJob($item->id),
            new ExtractBusinessFactsJob($item->id),
        ])->dispatch();

        return response()->json(['id' => $item->id, 'status' => 'ingested']);
    }
}
