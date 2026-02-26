<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BookmarkController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $query = Bookmark::with(['folder:id,display_name', 'tags:id,name,color', 'creator:id,name'])
            ->where('organization_id', $organization->id);

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->input('folder_id'));
        }
        if ($request->filled('tag_id')) {
            $tagId = $request->input('tag_id');
            $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
        }
        if ($platform = $request->input('platform')) {
            $query->where('platform', $platform);
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($request->boolean('is_favorite')) {
            $query->where('is_favorite', true);
        }
        if ($request->boolean('is_archived')) {
            $query->where('is_archived', true);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%$search%")
                  ->orWhere('description', 'like', "%$search%")
                  ->orWhere('url', 'like', "%$search%");
            });
        }

        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        // For @mention pickers, allow lightweight limited list without paginator
        if ($request->filled('limit')) {
            $limit = min(max((int) $request->input('limit', 10), 1), 100);
            $items = $query->limit($limit)->get();
            return response()->json(['data' => $items]);
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginated = $query->paginate($perPage);
        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'url' => 'required|url|max:2000',
            'image_url' => 'nullable|url|max:2000',
            'favicon_url' => 'nullable|url|max:2000',
            'platform' => 'nullable|in:instagram,tiktok,youtube,twitter,linkedin,pinterest,other',
            'platform_metadata' => 'nullable|array',
            'type' => 'nullable|in:inspiration,reference,competitor,trend',
            'folder_id' => 'nullable|exists:folders,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'is_favorite' => 'sometimes|boolean',
        ]);

        $bookmark = Bookmark::create([
            'organization_id' => $organization->id,
            'folder_id' => $data['folder_id'] ?? null,
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'url' => $data['url'],
            'image_url' => $data['image_url'] ?? null,
            'favicon_url' => $data['favicon_url'] ?? null,
            'platform' => $data['platform'] ?? 'other',
            'platform_metadata' => $data['platform_metadata'] ?? null,
            'type' => $data['type'] ?? 'inspiration',
            'is_favorite' => $data['is_favorite'] ?? false,
        ]);

        if (!empty($data['tag_ids'])) {
            // ensure tags belong to the same organization
            $tagIds = Tag::whereIn('id', $data['tag_ids'])->where('organization_id', $organization->id)->pluck('id');
            $bookmark->tags()->sync($tagIds);
        }

        // Auto-link: create ingestion_source for the new bookmark (idempotent)
        try {
            \App\Models\IngestionSource::firstOrCreate(
                [ 'source_type' => 'bookmark', 'source_id' => $bookmark->id ],
                [
                    'organization_id' => $organization->id,
                    'user_id' => $request->user()->id,
                    'origin' => 'browser',
                    'platform' => $bookmark->platform,
                    'raw_url' => $bookmark->url,
                    'title' => $bookmark->title,
                    'dedup_hash' => \App\Models\IngestionSource::dedupHashFromUrl($bookmark->url),
                    'status' => 'pending',
                ]
            );
        } catch (\Throwable $e) {
            // Non-fatal: log and continue returning bookmark
            \Log::warning('bookmark.ingestion_source_link_failed', ['bookmark_id' => $bookmark->id, 'error' => $e->getMessage()]);
        }

        return response()->json($bookmark->load(['tags', 'folder']), 201);
    }

    public function show(Request $request, Bookmark $bookmark)
    {
        $this->authorize('view', $bookmark);
        return response()->json($bookmark->load(['tags', 'folder', 'creator']));
    }

    public function update(Request $request, Bookmark $bookmark)
    {
        $this->authorize('update', $bookmark);
        $data = $request->validate([
            'title' => 'sometimes|string|max:500',
            'description' => 'sometimes|nullable|string',
            'url' => 'sometimes|url|max:2000',
            'image_url' => 'sometimes|nullable|url|max:2000',
            'favicon_url' => 'sometimes|nullable|url|max:2000',
            'platform' => 'sometimes|in:instagram,tiktok,youtube,twitter,linkedin,pinterest,other',
            'platform_metadata' => 'sometimes|array',
            'type' => 'sometimes|in:inspiration,reference,competitor,trend',
            'folder_id' => 'sometimes|nullable|exists:folders,id',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'is_favorite' => 'sometimes|boolean',
            'is_archived' => 'sometimes|boolean',
        ]);
        $bookmark->update($data);
        if (array_key_exists('tag_ids', $data)) {
            $organization = $bookmark->organization;
            $tagIds = Tag::whereIn('id', $data['tag_ids'] ?? [])->where('organization_id', $organization->id)->pluck('id');
            $bookmark->tags()->sync($tagIds);
        }
        return response()->json($bookmark->load(['tags', 'folder']));
    }

    public function destroy(Request $request, Bookmark $bookmark)
    {
        $this->authorize('delete', $bookmark);
        $bookmark->delete();
        return response()->json(null, 204);
    }

    /**
     * Convert a bookmark into a KnowledgeItem via ingestion pipeline.
     * POST /api/v1/bookmarks/{bookmark}/ingest
     *
     * Optional body: { "force": boolean }
     */
    public function ingest(Request $request, Bookmark $bookmark): JsonResponse
    {
        $this->authorize('view', $bookmark);
        // Minimal org guard: ensure request org matches bookmark org if middleware isn't sufficient
        $org = $request->attributes->get('organization');
        if ($org && $bookmark->organization_id !== $org->id) {
            abort(403);
        }

        dispatch(new \App\Jobs\BookmarkToKnowledgeItemJob($bookmark->id, (string) $request->user()->id, (string) ($org->id ?? $bookmark->organization_id)));

        return response()->json([
            'status' => 'queued',
            'message' => 'Bookmark ingestion queued. A KnowledgeItem will be created upon fetch.',
        ], 202);
    }
}
