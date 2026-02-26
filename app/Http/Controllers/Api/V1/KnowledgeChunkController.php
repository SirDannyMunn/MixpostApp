<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeChunkEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeChunkController extends Controller
{
    // GET /api/v1/knowledge/chunks
    public function index(Request $request): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.view');

        $org = $request->attributes->get('organization');
        $q = KnowledgeChunk::query()
            ->where('organization_id', $org->id);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where('chunk_text', 'like', '%' . substr($search, 0, 128) . '%');
        }

        $kind = trim((string) $request->query('kind', ''));
        if ($kind !== '') {
            $q->where('chunk_kind', $kind);
        }

        $status = trim((string) $request->query('status', 'active'));
        if ($status === 'active') {
            $q->where('is_active', true);
        } elseif ($status === 'inactive') {
            $q->where('is_active', false);
        }

        $policy = trim((string) $request->query('policy', ''));
        if ($policy !== '') {
            $q->where('usage_policy', $policy);
        }

        $sourceType = trim((string) $request->query('source_type', ''));
        if ($sourceType !== '') {
            $q->where('source_type', $sourceType);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));
        $page = (int) $request->query('page', 1);
        $page = max(1, $page);

        $paginator = $q->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()->map(function (KnowledgeChunk $chunk) {
            $text = trim((string) $chunk->chunk_text);
            $preview = mb_strlen($text) > 240 ? (mb_substr($text, 0, 240) . '...') : $text;
            return [
                'id' => (string) $chunk->id,
                'knowledge_item_id' => (string) $chunk->knowledge_item_id,
                'chunk_text_preview' => $preview,
                'chunk_kind' => (string) ($chunk->chunk_kind ?? 'fact'),
                'usage_policy' => (string) ($chunk->usage_policy ?? 'normal'),
                'is_active' => (bool) $chunk->is_active,
                'chunk_role' => (string) ($chunk->chunk_role ?? ''),
                'confidence' => isset($chunk->confidence) ? (float) $chunk->confidence : null,
                'time_horizon' => (string) ($chunk->time_horizon ?? ''),
                'source_type' => (string) ($chunk->source_type ?? ''),
                'source_ref' => $chunk->source_ref,
                'source_title' => (string) ($chunk->source_title ?? ''),
                'created_at' => $chunk->created_at,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // GET /api/v1/knowledge/chunks/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.view');

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        $events = KnowledgeChunkEvent::query()
            ->where('organization_id', $org->id)
            ->where('chunk_id', $chunk->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['event_type', 'before', 'after', 'reason', 'user_id', 'created_at']);

        return response()->json([
            'id' => (string) $chunk->id,
            'knowledge_item_id' => (string) $chunk->knowledge_item_id,
            'chunk_text' => (string) $chunk->chunk_text,
            'chunk_kind' => (string) ($chunk->chunk_kind ?? 'fact'),
            'usage_policy' => (string) ($chunk->usage_policy ?? 'normal'),
            'is_active' => (bool) $chunk->is_active,
            'chunk_role' => (string) ($chunk->chunk_role ?? ''),
            'authority' => (string) ($chunk->authority ?? ''),
            'confidence' => isset($chunk->confidence) ? (float) $chunk->confidence : null,
            'time_horizon' => (string) ($chunk->time_horizon ?? ''),
            'domain' => (string) ($chunk->domain ?? ''),
            'actor' => (string) ($chunk->actor ?? ''),
            'source_type' => (string) ($chunk->source_type ?? ''),
            'source_ref' => $chunk->source_ref,
            'source_title' => (string) ($chunk->source_title ?? ''),
            'created_at' => $chunk->created_at,
            'audit_trail' => $events->map(fn($e) => [
                'event_type' => (string) ($e->event_type ?? ''),
                'before' => $e->before,
                'after' => $e->after,
                'reason' => (string) ($e->reason ?? ''),
                'user_id' => (string) ($e->user_id ?? ''),
                'created_at' => $e->created_at,
            ])->values(),
        ]);
    }

    // POST /api/v1/knowledge/chunks/{id}/deactivate
    public function deactivate(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.deactivate');
        $data = $request->validate([
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        if ($chunk->is_active) {
            $before = ['is_active' => true];
            $chunk->is_active = false;
            $chunk->save();
            $this->logEvent($request, $chunk, 'deactivated', $before, ['is_active' => false], $data['reason'] ?? null);
        }

        return response()->json(['status' => 'ok']);
    }

    // POST /api/v1/knowledge/chunks/{id}/activate
    public function activate(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.deactivate');
        $data = $request->validate([
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        if (!$chunk->is_active) {
            $before = ['is_active' => false];
            $chunk->is_active = true;
            $chunk->save();
            $this->logEvent($request, $chunk, 'activated', $before, ['is_active' => true], $data['reason'] ?? null);
        }

        return response()->json(['status' => 'ok']);
    }

    // POST /api/v1/knowledge/chunks/{id}/reclassify
    public function reclassify(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.reclassify');
        $data = $request->validate([
            'chunk_kind' => 'required|string|in:fact,angle,example,quote',
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        $before = ['chunk_kind' => (string) ($chunk->chunk_kind ?? 'fact')];
        $chunk->chunk_kind = $data['chunk_kind'];
        $chunk->save();
        $this->logEvent($request, $chunk, 'reclassified', $before, ['chunk_kind' => $data['chunk_kind']], $data['reason'] ?? null);

        return response()->json(['status' => 'ok']);
    }

    // POST /api/v1/knowledge/chunks/{id}/set-policy
    public function setPolicy(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.set_policy');
        $data = $request->validate([
            'usage_policy' => 'required|string|in:normal,inspiration_only,never_generate',
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        $before = ['usage_policy' => (string) ($chunk->usage_policy ?? 'normal')];
        $chunk->usage_policy = $data['usage_policy'];
        $chunk->save();
        $this->logEvent($request, $chunk, 'policy_changed', $before, ['usage_policy' => $data['usage_policy']], $data['reason'] ?? null);

        return response()->json(['status' => 'ok']);
    }

    // DELETE /api/v1/knowledge/chunks/{id}
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertPermission($request, 'knowledge.delete_hard');

        if (!$request->boolean('confirm', false)) {
            return response()->json(['message' => 'confirm=true is required'], 422);
        }

        $org = $request->attributes->get('organization');
        $chunk = KnowledgeChunk::query()
            ->where('organization_id', $org->id)
            ->findOrFail($id);

        $before = [
            'chunk_kind' => (string) ($chunk->chunk_kind ?? 'fact'),
            'usage_policy' => (string) ($chunk->usage_policy ?? 'normal'),
            'is_active' => (bool) $chunk->is_active,
        ];
        $chunk->delete();

        $this->logEvent($request, $chunk, 'deleted_hard', $before, null, null);

        return response()->json(null, 204);
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

    private function logEvent(
        Request $request,
        KnowledgeChunk $chunk,
        string $eventType,
        ?array $before,
        ?array $after,
        ?string $reason
    ): void {
        $org = $request->attributes->get('organization');
        $user = $request->user();

        KnowledgeChunkEvent::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'chunk_id' => $chunk->id,
            'event_type' => $eventType,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
