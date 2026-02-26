<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Jobs\EmbedKnowledgeChunksJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeChunkEvent;
use App\Models\KnowledgeItem;
use App\Services\Ai\Retriever;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResearchController extends Controller
{
    public function __construct(protected Retriever $retriever) {}

    // POST /api/v1/research/search
    public function search(Request $request): JsonResponse
    {
        $this->assertPermission($request, 'research.search');

        $data = $request->validate([
            'query' => 'required|string|max:5000',
            'filters' => 'sometimes|array',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $org = $request->attributes->get('organization');
        $query = trim((string) $data['query']);
        $filters = is_array($data['filters'] ?? null) ? (array) $data['filters'] : [];
        $limit = (int) ($data['limit'] ?? 20);
        $limit = max(1, min(100, $limit));

        $mediaTypes = ['post', 'research_fragment'];
        if (!empty($filters['media_types']) && is_array($filters['media_types'])) {
            $mediaTypes = array_values(array_unique(array_map('strval', $filters['media_types'])));
        }

        $items = $this->retriever->researchItems((string) $org->id, $query, $limit, $mediaTypes);

        $sourceTypes = [];
        if (!empty($filters['source_types']) && is_array($filters['source_types'])) {
            $sourceTypes = array_values(array_unique(array_map('strval', $filters['source_types'])));
        }
        $publishedAfter = !empty($filters['published_after']) ? (string) $filters['published_after'] : null;
        $publishedBefore = !empty($filters['published_before']) ? (string) $filters['published_before'] : null;

        if (!empty($sourceTypes) || $publishedAfter || $publishedBefore) {
            $items = array_values(array_filter($items, function ($item) use ($sourceTypes, $publishedAfter, $publishedBefore) {
                if (!empty($sourceTypes)) {
                    $platform = (string) ($item['platform'] ?? '');
                    if (!in_array($platform, $sourceTypes, true)) {
                        return false;
                    }
                }
                $publishedAt = $item['published_at'] ?? null;
                if ($publishedAfter && $publishedAt) {
                    if (strtotime((string) $publishedAt) < strtotime($publishedAfter)) {
                        return false;
                    }
                }
                if ($publishedBefore && $publishedAt) {
                    if (strtotime((string) $publishedAt) > strtotime($publishedBefore)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        $results = array_map(function ($item) {
            $text = trim((string) ($item['text'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $snippet = $text !== '' ? $text : $title;

            return [
                'snippet_text' => $snippet,
                'score' => isset($item['similarity']) ? (float) $item['similarity'] : 0.0,
                'source_type' => (string) ($item['platform'] ?? ''),
                'source_ref' => (string) (($item['url'] ?? '') ?: ($item['raw_reference_id'] ?? '')),
                'source_title' => $title,
                'suggested_kind' => null,
                'suggested_policy' => null,
            ];
        }, $items);

        return response()->json(['results' => $results]);
    }

    // POST /api/v1/research/add-to-knowledge
    public function addToKnowledge(Request $request): JsonResponse
    {
        $this->assertPermission($request, 'research.add_to_knowledge');

        $data = $request->validate([
            'snippet_text' => 'required|string|max:10000',
            'chunk_kind' => 'required|string|in:fact,angle,example,quote',
            'usage_policy' => 'sometimes|string|in:normal,inspiration_only,never_generate',
            'source_type' => 'sometimes|nullable|string|max:32',
            'source_ref' => 'sometimes|nullable|string|max:255',
            'source_title' => 'sometimes|nullable|string|max:255',
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        $org = $request->attributes->get('organization');
        $user = $request->user();
        $snippet = trim((string) $data['snippet_text']);
        $usagePolicy = (string) ($data['usage_policy'] ?? 'normal');

        $item = KnowledgeItem::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'type' => 'fact',
            'source' => 'research',
            'title' => $data['source_title'] ?? null,
            'raw_text' => $snippet,
            'raw_text_sha256' => hash('sha256', $snippet),
            'metadata' => [
                'source_type' => $data['source_type'] ?? null,
                'source_ref' => $data['source_ref'] ?? null,
            ],
            'confidence' => 0.6,
            'ingested_at' => now(),
            'chunking_status' => 'manual',
        ]);

        $tokenCount = max(1, (int) ceil(mb_strlen($snippet) / 4));
        $chunkRole = $this->defaultRoleForKind($data['chunk_kind']);

        $chunk = KnowledgeChunk::create([
            'knowledge_item_id' => $item->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'chunk_text' => $snippet,
            'chunk_type' => 'excerpt',
            'chunk_role' => $chunkRole,
            'chunk_kind' => $data['chunk_kind'],
            'is_active' => true,
            'usage_policy' => $usagePolicy,
            'source_type' => $data['source_type'] ?? null,
            'source_ref' => $data['source_ref'] ?? null,
            'source_title' => $data['source_title'] ?? null,
            'source_variant' => 'normalized',
            'token_count' => $tokenCount,
            'created_at' => now(),
        ]);

        KnowledgeChunkEvent::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'chunk_id' => $chunk->id,
            'event_type' => 'added_from_research',
            'before' => null,
            'after' => [
                'chunk_kind' => (string) $chunk->chunk_kind,
                'usage_policy' => (string) $chunk->usage_policy,
                'is_active' => (bool) $chunk->is_active,
            ],
            'reason' => $data['reason'] ?? null,
            'created_at' => now(),
        ]);

        dispatch(new EmbedKnowledgeChunksJob($item->id));

        return response()->json([
            'knowledge_item_id' => (string) $item->id,
            'chunk_id' => (string) $chunk->id,
            'status' => 'created',
        ], 201);
    }

    private function defaultRoleForKind(string $kind): string
    {
        return match ($kind) {
            'angle' => 'strategic_claim',
            'example' => 'example',
            'quote' => 'quote',
            default => 'definition',
        };
    }

    private function assertPermission(Request $request, string $permission): void
    {
        $org = $request->attributes->get('organization');
        $user = $request->user();
        $role = OrganizationRole::from($user->roleIn($org));
        if (!$role->hasPermission($permission)) {
            abort(403, 'Forbidden');
        }
    }
}
