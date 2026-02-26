<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\Bookmark;
use App\Models\BusinessFact;
use App\Models\IngestionSource;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tag;
use App\Queries\LibraryItemQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LibraryItemController extends Controller
{
    public function index(Request $request)
    {
        $org = $request->attributes->get('organization');

        $query = LibraryItemQuery::bookmarksWithIngestion($org->id);

        // Type filtering (supports ingestion-source types; accept legacy alias)
        if ($request->filled('type')) {
            $type = strtolower((string) $request->input('type'));
            if ($type === 'pasted_text') {
                $type = 'text';
            }
            $query->where('ingestion_sources.source_type', $type);
        }

        // Ingestion status filtering
        if ($request->filled('ingestion_status')) {
            $status = strtolower((string) $request->input('ingestion_status'));
            $map = [ 'ingested' => 'completed', 'processing' => 'pending' ];
            if (isset($map[$status])) { $status = $map[$status]; }
            if ($status === 'not_ingested') {
                // Not meaningful for ingestion-source-backed library items.
                $query->whereRaw('1=0');
            } else {
                $query->where('ingestion_sources.status', $status);
            }
        }

        // Folder filtering
        // - Supports legacy bookmark folders via bookmarks.folder_id
        // - Supports ingestion-source folder boundaries via ingestion_source_folders
        // Accepts: folder_id=<uuid>|null, folder_ids[]=<uuid>, folder_ids=<uuid>,<uuid>
        if ($request->filled('folder_id') || $request->filled('folder_ids')) {
            $folderId = trim((string) $request->input('folder_id', ''));
            $folderIdsInput = $request->input('folder_ids', []);
            if (is_string($folderIdsInput)) {
                $folderIdsInput = array_map('trim', explode(',', $folderIdsInput));
            }
            $folderIds = array_values(array_filter(array_map('strval', (array) $folderIdsInput), fn($v) => $v !== ''));
            if ($folderId !== '' && $folderId !== 'null' && empty($folderIds)) {
                $folderIds = [$folderId];
            }
            $folderIds = array_values(array_filter($folderIds, fn($id) => Str::isUuid($id)));

            if ($folderId === 'null') {
                // Unassigned: no ingestion_source_folders row and (for bookmark sources) no bookmarks.folder_id
                if (Schema::hasTable('ingestion_source_folders')) {
                    $query->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('ingestion_source_folders as isf')
                            ->whereColumn('isf.ingestion_source_id', 'ingestion_sources.id');
                    });
                }
                $query->where(function ($q) {
                    $q->where('ingestion_sources.source_type', '!=', 'bookmark')
                      ->orWhereNull('b.folder_id');
                });
            } elseif (!empty($folderIds)) {
                $query->where(function ($q) use ($folderIds) {
                    // Ingestion-source folder boundaries
                    if (Schema::hasTable('ingestion_source_folders')) {
                        $q->whereExists(function ($qq) use ($folderIds) {
                            $qq->select(DB::raw(1))
                                ->from('ingestion_source_folders as isf')
                                ->whereColumn('isf.ingestion_source_id', 'ingestion_sources.id')
                                ->whereIn('isf.folder_id', $folderIds);
                        });
                    }
                    // Legacy bookmark folder
                    $q->orWhere(function ($qq) use ($folderIds) {
                        $qq->where('ingestion_sources.source_type', 'bookmark')
                           ->whereIn('b.folder_id', $folderIds);
                    });
                });
            }
        }
        if ($request->filled('platform')) {
            $query->where('ingestion_sources.source_type', 'bookmark');
            $query->where('b.platform', $request->input('platform'));
        }
        if ($request->filled('tag_id')) {
            $query->where('ingestion_sources.source_type', 'bookmark');
            $tagId = (string) $request->input('tag_id');

            // UI may send a tag UUID or a tag name. Postgres will error if a non-uuid
            // string is compared to a uuid column, so resolve names to ids.
            if (!Str::isUuid($tagId)) {
                $resolved = Tag::query()
                    ->where('organization_id', $org->id)
                    ->where('name', $tagId)
                    ->value('id');

                if (!$resolved) {
                    // No such tag for this org; return empty set.
                    $query->whereRaw('1=0');
                } else {
                    $tagId = (string) $resolved;
                }
            }

            $query->whereExists(function ($q) use ($tagId) {
                $q->selectRaw('1')
                  ->from('bookmark_tags as bt')
                  ->whereRaw('bt.bookmark_id = b.id')
                  ->where('bt.tag_id', $tagId);
            });
        }
        if ($request->boolean('is_favorite')) {
            $query->where('ingestion_sources.source_type', 'bookmark');
            $query->where('b.is_favorite', true);
        }
        if ($request->boolean('is_archived')) {
            $query->where('ingestion_sources.source_type', 'bookmark');
            $query->where('b.is_archived', true);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ingestion_sources.title', 'like', "%$search%")
                  ->orWhere('ingestion_sources.raw_text', 'like', "%$search%")
                  ->orWhere('ingestion_sources.raw_url', 'like', "%$search%")
                  ->orWhere('b.title', 'like', "%$search%")
                  ->orWhere('b.description', 'like', "%$search%")
                  ->orWhere('b.url', 'like', "%$search%");
            });
        }

        // Sorting
        $sort = (string) $request->input('sort', 'created_at');
        $order = strtolower((string) $request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($sort === 'title') {
            $query->orderByRaw(
                "coalesce(nullif(ingestion_sources.title, ''), b.title) " . ($order === 'asc' ? 'asc' : 'desc')
            );
        } elseif ($sort === 'updated_at') {
            $query->orderBy('ingestion_sources.updated_at', $order);
        } elseif ($sort === 'ingestion_status') {
            $query->orderByRaw(
                "case ingestion_sources.status when 'completed' then 0 when 'pending' then 1 when 'failed' then 2 else 3 end " . ($order === 'asc' ? 'asc' : 'desc')
            );
        } else {
            $query->orderBy('ingestion_sources.created_at', $order);
        }

        $perPage = (int) $request->input('per_page', (int) $request->input('limit', 20));
        $paginated = $query->paginate($perPage);
        $items = collect($paginated->items());

        // Attach bookmark (+ tags + folder) only for bookmark-type ingestion sources.
        $bookmarkIds = $items
            ->where('source_type', 'bookmark')
            ->pluck('bookmark_id')
            ->filter()
            ->unique()
            ->values();

        if ($bookmarkIds->isNotEmpty()) {
            $bookmarks = \App\Models\Bookmark::query()
                ->with([
                    'tags:id,name,color',
                    'folder:id,display_name,parent_id',
                ])
                ->whereIn('id', $bookmarkIds)
                ->get()
                ->keyBy('id');

            foreach ($items as $it) {
                $bid = $it->bookmark_id ?? null;
                if ($bid && isset($bookmarks[$bid])) {
                    $it->setRelation('bookmark', $bookmarks[$bid]);
                }
            }
        }

        return LibraryItemResource::collection($paginated);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $org = $request->attributes->get('organization');

        $data = $request->validate([
            'item_ids' => 'required|array|min:1|max:100',
            'item_ids.*' => 'string',
        ]);

        $rawIds = array_values(array_unique(array_filter(array_map('strval', $data['item_ids']))));

        $deleted = [];
        $notFound = [];
        $invalid = [];

        foreach ($rawIds as $rawId) {
            $innerId = $this->parseLibraryId($rawId);
            if (!$innerId) {
                $invalid[] = $rawId;
                continue;
            }

            $src = $this->resolveIngestionSourceByUuid($org->id, $innerId);
            if (!$src) {
                $notFound[] = $rawId;
                continue;
            }

            DB::transaction(function () use ($src, $org) {
                $this->purgeDerived($src);

                if ($src->source_type === 'bookmark' && $src->source_id) {
                    Bookmark::query()
                        ->where('organization_id', $org->id)
                        ->where('id', (string) $src->source_id)
                        ->delete();
                }

                $src->delete();
            });

            $deleted[] = 'lib_' . $src->id;
        }

        return response()->json([
            'deleted_count' => count($deleted),
            'not_found_count' => count($notFound),
            'invalid_count' => count($invalid),
            'deleted' => $deleted,
            'not_found' => $notFound,
            'invalid' => $invalid,
        ]);
    }

    private function parseLibraryId(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, 'lib_')) {
            $value = substr($value, 4);
        }
        return Str::isUuid($value) ? $value : null;
    }

    private function resolveIngestionSourceByUuid(string $organizationId, string $id): ?IngestionSource
    {
        $src = IngestionSource::query()
            ->where('organization_id', $organizationId)
            ->find($id);
        if ($src) {
            return $src;
        }

        return IngestionSource::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', 'bookmark')
            ->where('source_id', $id)
            ->first();
    }

    private function purgeDerived(IngestionSource $src): void
    {
        $kiIds = KnowledgeItem::query()
            ->where('organization_id', $src->organization_id)
            ->where('ingestion_source_id', $src->id)
            ->pluck('id');

        if ($kiIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($kiIds) {
            KnowledgeChunk::query()->whereIn('knowledge_item_id', $kiIds)->delete();
            BusinessFact::query()->whereIn('source_knowledge_item_id', $kiIds)->delete();
            KnowledgeItem::query()->whereIn('id', $kiIds)->delete();
        });
    }
}
