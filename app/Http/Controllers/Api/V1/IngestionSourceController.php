<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractIngestionSourceStructureJob;
use App\Jobs\ProcessIngestionSourceJob;
use App\Jobs\TranscribeVoiceRecordingJob;
use App\Models\Bookmark;
use App\Models\BusinessFact;
use App\Models\IngestionSource;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ai\FolderEmbeddingScheduler;

class IngestionSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('organization');
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $query = IngestionSource::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('created_at');

        // Optional folder scoping: limit results to sources attached to the folder(s).
        // Supports:
        // - ?folder_id=<uuid>
        // - ?folder_ids[]=<uuid>&folder_ids[]=<uuid>
        // - ?folder_ids=<uuid>,<uuid>
        try {
            $folderId = trim((string) $request->input('folder_id', ''));
            $folderIdsInput = $request->input('folder_ids', []);
            if (is_string($folderIdsInput)) {
                $folderIdsInput = array_map('trim', explode(',', $folderIdsInput));
            }
            $folderIds = array_values(array_filter(array_map('strval', (array) $folderIdsInput), fn($v) => $v !== ''));
            if ($folderId !== '' && empty($folderIds)) {
                $folderIds = [$folderId];
            }
            $folderIds = array_values(array_filter($folderIds, fn($id) => Str::isUuid($id)));

            if (!empty($folderIds) && Schema::hasTable('ingestion_source_folders')) {
                $query->whereExists(function ($q) use ($folderIds) {
                    $q->select(DB::raw(1))
                        ->from('ingestion_source_folders as isf')
                        ->whereColumn('isf.ingestion_source_id', 'ingestion_sources.id')
                        ->whereIn('isf.folder_id', $folderIds);
                });
            }
        } catch (\Throwable) {
            // Ignore invalid folder filters; return unfiltered results.
        }

        if ($type = $request->input('type')) {
            $normalized = $type === 'pasted_text' ? 'text' : $type;
            $query->where('source_type', $normalized);
        }

        if ($origin = $request->input('origin')) {
            $query->where('origin', $origin);
        }

        $paginated = $query->paginate($perPage);
        $items = collect($paginated->items());

        // Attach bookmark details for bookmark-type sources
        $bookmarkMap = [];
        $bookmarkIds = $items->where('source_type', 'bookmark')->pluck('source_id')->filter()->unique()->all();
        if (!empty($bookmarkIds)) {
            $bookmarks = Bookmark::query()
                ->whereIn('id', $bookmarkIds)
                ->get(['id','url','platform','image_url','description','title'])
                ->keyBy('id');
            $bookmarkMap = $bookmarks->all();
        }

        $data = $items->map(function (IngestionSource $src) use ($bookmarkMap) {
            $status = $src->status;
            if ($status === 'completed') $status = 'ready';

            $out = [
                'id' => $src->id,
                'type' => $src->source_type, // expose 'text' not 'pasted_text'
                'title' => $src->title,
                'status' => $status,
                'confidence_score' => $src->confidence_score,
                'quality_score' => $src->quality_score,
                'created_at' => $src->created_at,
            ];

            if ($src->source_type === 'bookmark' && isset($bookmarkMap[$src->source_id])) {
                /** @var Bookmark $b */
                $b = $bookmarkMap[$src->source_id];
                $out['bookmark'] = [
                    'id' => $b->id,
                    'url' => $b->url,
                    'platform' => $b->platform,
                    'image_url' => $b->image_url,
                    'description' => $b->description,
                    'title' => $b->title,
                ];
            }

            return $out;
        })->values();

        $paginated->setCollection($data);
        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('IngestionSourceController:store', [ 'query' => $request->query(), 'headers' => $request->headers->all() ]);
        $org = $request->attributes->get('organization');
        $user = $request->user();

        // Fallback: if Laravel didn't parse the JSON body for some reason,
        // try to decode raw content and merge it into the request.
        $input = $request->all();
        if ((empty($input) || (count($input) === 1 && array_key_exists('organization', $input))) && str_contains((string) $request->header('Content-Type'), 'application/json')) {
            $raw = $request->getContent();
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge($decoded);
            }
        }

        $type = $request->input('type');
        if ($type === 'pasted_text') {
            $type = 'text'; // backward compatible alias
        }

        if ($type === 'text') {
            $data = $request->validate([
                'type' => 'required|in:text,pasted_text',
                'title' => 'required|string|max:500',
                'raw_text' => 'sometimes|nullable|string',
                'content' => 'sometimes|nullable|string',
                'metadata' => 'sometimes|array',
                'folder_id' => 'sometimes|nullable|uuid',
                'folder_ids' => 'sometimes|array|max:50',
                'folder_ids.*' => 'sometimes|uuid',
            ]);

            $text = (string) (($data['raw_text'] ?? '') !== '' ? $data['raw_text'] : ($data['content'] ?? ''));
            if (mb_strlen(trim($text)) < 20) {
                return response()->json(['message' => 'raw_text/content too short'], 422);
            }

            $attrs = [
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'source_type' => 'text',
                'source_id' => null,
                'origin' => 'manual',
                'platform' => null,
                'raw_url' => null,
                'raw_text' => $text,
                'mime_type' => null,
                'dedup_hash' => IngestionSource::dedupHashFromText($text),
                'status' => 'pending',
            ];
            if (Schema::hasColumn('ingestion_sources', 'title')) {
                $attrs['title'] = $data['title'];
            }
            if (Schema::hasColumn('ingestion_sources', 'metadata')) {
                $attrs['metadata'] = $data['metadata'] ?? null;
            }
            $src = IngestionSource::create($attrs);
            $logger->capture('created_source', ['id' => $src->id, 'type' => 'text']);

            // Best-effort: attach folder boundaries to the ingestion source
            try {
                $folderId = (string) ($data['folder_id'] ?? '');
                $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
                if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }

                if (!empty($folderIds) && method_exists($src, 'folders') && Schema::hasTable('ingestion_source_folders')) {
                    $attach = [];
                    foreach ($folderIds as $fid) {
                        $attach[$fid] = [ 'created_by' => (string) $user->id, 'created_at' => now() ];
                    }
                    $src->folders()->syncWithoutDetaching($attach);
                    try {
                        app(FolderEmbeddingScheduler::class)->markStaleAndSchedule($folderIds, (string) $org->id);
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } catch (\Throwable $e) {
                $logger->capture('folders.attach_failed', ['error' => $e->getMessage()]);
            }

            $folderId = (string) ($data['folder_id'] ?? '');
            $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
            if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }

            dispatch(new ProcessIngestionSourceJob($src->id, false, !empty($folderIds) ? $folderIds : null, (string) $user->id));
            $logger->flush('queued');
            return response()->json($this->present($src), 201);
        }

        if ($type === 'file') {
            $data = $request->validate([
                'type' => 'required|in:file',
                'title' => 'required|string|max:500',
                'source_id' => 'required|string|max:191',
                'metadata' => 'sometimes|array',
                'folder_id' => 'sometimes|nullable|uuid',
                'folder_ids' => 'sometimes|array|max:50',
                'folder_ids.*' => 'sometimes|uuid',
            ]);

            $base = [
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'origin' => 'upload',
                'platform' => null,
                'raw_url' => null,
                'raw_text' => null,
                'mime_type' => Arr::get($data, 'metadata.mime') ?: null,
                'dedup_hash' => sha1('file:' . $org->id . ':' . $data['source_id']),
                'status' => 'pending',
            ];
            if (Schema::hasColumn('ingestion_sources', 'title')) {
                $base['title'] = $data['title'];
            }
            if (Schema::hasColumn('ingestion_sources', 'metadata')) {
                $base['metadata'] = $data['metadata'] ?? null;
            }
            $src = IngestionSource::firstOrCreate(
                [
                    'source_type' => 'file',
                    'source_id' => $data['source_id'],
                ],
                $base
            );
            $logger->capture('created_source', ['id' => $src->id, 'type' => 'file']);

            // Best-effort: attach folder boundaries to the ingestion source
            try {
                $folderId = (string) ($data['folder_id'] ?? '');
                $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
                if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }
                if (!empty($folderIds) && method_exists($src, 'folders') && Schema::hasTable('ingestion_source_folders')) {
                    $attach = [];
                    foreach ($folderIds as $fid) {
                        $attach[$fid] = [ 'created_by' => (string) $user->id, 'created_at' => now() ];
                    }
                    $src->folders()->syncWithoutDetaching($attach);
                    try {
                        app(FolderEmbeddingScheduler::class)->markStaleAndSchedule($folderIds, (string) $org->id);
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } catch (\Throwable $e) {
                $logger->capture('folders.attach_failed', ['error' => $e->getMessage()]);
            }

            // Extraction to be handled by a separate file ingestion worker
            $logger->flush('created');
            return response()->json($this->present($src), 201);
        }

        if ($type === 'voice_recording') {
            $data = $request->validate([
                'type' => 'required|in:voice_recording',
                'title' => 'required|string|max:500',
                'file' => 'required|file|max:51200',
                'duration' => 'sometimes|nullable|numeric',
                'metadata' => 'sometimes|array',
                'folder_id' => 'sometimes|nullable|uuid',
                'folder_ids' => 'sometimes|array|max:50',
                'folder_ids.*' => 'sometimes|uuid',
            ]);

            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $request->file('file');
            // Use client-provided MIME type since finfo often misdetects audio formats like webm
            $contentType = $file->getClientMimeType() ?: $file->getMimeType() ?: 'audio/webm';
            
            // Validate it's an audio file
            $allowedMimes = ['audio/webm', 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/flac', 'audio/mp3', 'audio/aac', 'video/webm'];
            if (!in_array($contentType, $allowedMimes, true) && !str_starts_with($contentType, 'audio/')) {
                return response()->json(['message' => 'File must be an audio file'], 422);
            }
            
            $audioContent = file_get_contents($file->getRealPath());

            if (!$audioContent || strlen($audioContent) === 0) {
                return response()->json(['message' => 'Audio file is empty'], 422);
            }

            // Store audio temporarily for async transcription
            $audioStoragePath = 'voice-recordings/' . Str::uuid() . '.audio';
            \Illuminate\Support\Facades\Storage::disk('local')->put($audioStoragePath, $audioContent);
            $logger->capture('audio_stored', ['path' => $audioStoragePath, 'size' => strlen($audioContent)]);

            $attrs = [
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'source_type' => 'voice_recording',
                'source_id' => null,
                'origin' => 'upload',
                'platform' => null,
                'raw_url' => null,
                'raw_text' => null, // Will be populated by TranscribeVoiceRecordingJob
                'mime_type' => $contentType,
                'dedup_hash' => sha1('voice:' . $org->id . ':' . $audioStoragePath), // Temporary hash until transcribed
                'status' => 'transcribing',
            ];
            if (Schema::hasColumn('ingestion_sources', 'title')) {
                $attrs['title'] = $data['title'];
            }
            if (Schema::hasColumn('ingestion_sources', 'metadata')) {
                $attrs['metadata'] = array_merge($data['metadata'] ?? [], [
                    'duration' => $data['duration'] ?? null,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $contentType,
                ]);
            }
            $src = IngestionSource::create($attrs);
            $logger->capture('created_source', ['id' => $src->id, 'type' => 'voice_recording']);

            // Best-effort: attach folder boundaries to the ingestion source
            try {
                $folderId = (string) ($data['folder_id'] ?? '');
                $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
                if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }

                if (!empty($folderIds) && method_exists($src, 'folders') && Schema::hasTable('ingestion_source_folders')) {
                    $attach = [];
                    foreach ($folderIds as $fid) {
                        $attach[$fid] = [ 'created_by' => (string) $user->id, 'created_at' => now() ];
                    }
                    $src->folders()->syncWithoutDetaching($attach);
                    try {
                        app(FolderEmbeddingScheduler::class)->markStaleAndSchedule($folderIds, (string) $org->id);
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } catch (\Throwable $e) {
                $logger->capture('folders.attach_failed', ['error' => $e->getMessage()]);
            }

            $folderId = (string) ($data['folder_id'] ?? '');
            $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
            if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }

            // Dispatch async transcription job
            dispatch(new TranscribeVoiceRecordingJob(
                $src->id,
                $audioStoragePath,
                $contentType,
                !empty($folderIds) ? $folderIds : null,
                (string) $user->id
            ));
            $logger->flush('queued_transcription');
            return response()->json($this->present($src), 201);
        }

        $logger->flush('unsupported_type', ['type' => $type]);
        return response()->json(['message' => 'Unsupported type'], 422);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $org = $request->attributes->get('organization');
        $src = $this->resolveIngestionSource($org->id, $id);
        if (!$src) {
            return response()->json(['message' => 'Ingestion source not found'], 404);
        }

        $data = $request->validate([
            'title' => 'sometimes|nullable|string|max:500',
            'metadata' => 'sometimes|nullable|array',
        ]);

        // Only allow metadata edits
        $src->fill([
            'title' => $data['title'] ?? $src->title,
            'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : $src->metadata,
        ]);
        $src->save();

        return response()->json($this->present($src));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $org = $request->attributes->get('organization');
        $src = $this->resolveIngestionSource($org->id, $id);
        if (!$src) {
            return response()->json(['message' => 'Ingestion source not found'], 404);
        }

        $this->purgeDerived($src);
        $src->delete();

        return response()->json(null, 204);
    }

    // POST /api/v1/ingestion-sources/{id}/extract-structure
    public function extractStructure(Request $request, string $id): JsonResponse
    {
        $org = $request->attributes->get('organization');
        $src = $this->resolveIngestionSource($org->id, $id);

        // If request came from Library API (lib_{...}) and there is no ingestion row yet,
        // treat the identifier as a bookmark id and create a minimal ingestion_source so
        // extraction can proceed.
        if (!$src && str_starts_with(trim($id), 'lib_')) {
            $bookmarkId = substr(trim($id), 4);
            if (Str::isUuid($bookmarkId)) {
                $b = Bookmark::query()
                    ->where('organization_id', $org->id)
                    ->find($bookmarkId);
                if ($b) {
                    $src = IngestionSource::firstOrCreate(
                        [
                            'source_type' => 'bookmark',
                            'source_id' => (string) $b->id,
                        ],
                        [
                            'organization_id' => $org->id,
                            'user_id' => (string) $request->user()->id,
                            'origin' => 'browser',
                            'platform' => (string) ($b->platform ?? null),
                            'raw_url' => (string) ($b->url ?? null),
                            'dedup_hash' => IngestionSource::dedupHashFromUrl((string) ($b->url ?? '')),
                            'status' => 'pending',
                        ]
                    );
                }
            }
        }

        if (!$src) {
            return response()->json(['message' => 'Ingestion source not found'], 404);
        }

        // Mark intent to extract (best-effort; columns may not exist in some envs)
        try {
            $src->structure_status = 'pending';
            $src->save();
        } catch (\Throwable) {
            // ignore
        }

        dispatch(new ExtractIngestionSourceStructureJob((string) $src->id, (string) $request->user()->id));

        return response()->json(['status' => 'queued'], 202);
    }

    public function reingest(Request $request, string $id): JsonResponse
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('IngestionSourceController:reingest', ['id' => $id, 'params' => $request->all()]);
        $org = $request->attributes->get('organization');
        $src = $this->resolveIngestionSource($org->id, $id);
        if (!$src) {
            $logger->flush('not_found');
            return response()->json(['message' => 'Ingestion source not found'], 404);
        }


        $data = $request->validate([
            'force' => 'sometimes|boolean',
            'folder_id' => 'sometimes|nullable|uuid',
            'folder_ids' => 'sometimes|array|max:50',
            'folder_ids.*' => 'sometimes|uuid',
        ]);

        $force = (bool) $request->boolean('force');
        $this->purgeDerived($src);
        $src->status = 'pending';
        $src->error = null;
        $src->dedup_reason = null;
        $src->save();

        $folderId = (string) ($data['folder_id'] ?? '');
        $folderIds = array_values(array_filter(array_map('strval', (array) ($data['folder_ids'] ?? [])), fn($v) => $v !== ''));
        if ($folderId !== '' && empty($folderIds)) { $folderIds = [$folderId]; }

        // Best-effort: allow callers to update folder boundaries on reingest.
        if (!empty($folderIds)) {
            try {
                if (method_exists($src, 'folders') && Schema::hasTable('ingestion_source_folders')) {
                    $attach = [];
                    foreach ($folderIds as $fid) {
                        $attach[$fid] = [ 'created_by' => (string) $request->user()->id, 'created_at' => now() ];
                    }
                    $src->folders()->syncWithoutDetaching($attach);
                    try {
                        app(FolderEmbeddingScheduler::class)->markStaleAndSchedule($folderIds, (string) $org->id);
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } catch (\Throwable $e) {
                $logger->capture('folders.attach_failed', ['error' => $e->getMessage()]);
            }
        }

        dispatch(new ProcessIngestionSourceJob($src->id, $force, !empty($folderIds) ? $folderIds : null, (string) $request->user()->id));
        $logger->flush('queued', ['source_id' => $src->id, 'force' => $force]);
        return response()->json(['status' => 'queued'], 202);
    }

    private function purgeDerived(IngestionSource $src): void
    {
        $kiIds = KnowledgeItem::query()
            ->where('organization_id', $src->organization_id)
            ->where('ingestion_source_id', $src->id)
            ->pluck('id');

        if ($kiIds->isEmpty()) return;

        DB::transaction(function () use ($kiIds) {
            KnowledgeChunk::query()->whereIn('knowledge_item_id', $kiIds)->delete();
            BusinessFact::query()->whereIn('source_knowledge_item_id', $kiIds)->delete();
            KnowledgeItem::query()->whereIn('id', $kiIds)->delete();
        });
    }

    private function present(IngestionSource $src): array
    {
        $status = $src->status;
        if ($status === 'completed') $status = 'ready';
        return [
            'id' => $src->id,
            'type' => $src->source_type,
            'title' => $src->title,
            'status' => $status,
            'confidence_score' => $src->confidence_score,
            'quality_score' => $src->quality_score,
            'created_at' => $src->created_at,
        ];
    }

    private function resolveIngestionSource(string $organizationId, string $id): ?IngestionSource
    {
        $raw = trim($id);

        // LibraryItem identifiers can be:
        // - lib_{ingestionSourceId} (preferred)
        // - lib_{bookmarkId} (legacy)
        if (str_starts_with($raw, 'lib_')) {
            $innerId = substr($raw, 4);
            if (!Str::isUuid($innerId)) {
                return null;
            }

            // Preferred: treat lib_{uuid} as an ingestion_sources.id
            $byIngestionId = IngestionSource::query()
                ->where('organization_id', $organizationId)
                ->find($innerId);
            if ($byIngestionId) {
                return $byIngestionId;
            }

            // Legacy: treat lib_{uuid} as a bookmark id and find its ingestion row
            return IngestionSource::query()
                ->where('organization_id', $organizationId)
                ->where('source_type', 'bookmark')
                ->where('source_id', $innerId)
                ->first();
        }

        // Normal ingestion_sources UUID
        if (!Str::isUuid($raw)) {
            return null;
        }

        return IngestionSource::query()
            ->where('organization_id', $organizationId)
            ->find($raw);
    }
}
