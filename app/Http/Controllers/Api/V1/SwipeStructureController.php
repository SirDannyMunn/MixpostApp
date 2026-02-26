<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\SwipeStructure;
use App\Services\SwipeStructures\SwipeStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwipeStructureController extends Controller
{
    public function __construct(protected SwipeStructureService $service) {}

    // GET /api/v1/swipe-structures
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('organization');
        $limitInput = $request->input('limit', null);
        $limit = is_null($limitInput) || $limitInput === ''
            ? null
            : min(max((int) $limitInput, 1), 1000);
        $includeDeleted = (bool) $request->boolean('include_deleted', false);

        $q = SwipeStructure::query()
            ->where('organization_id', $org->id)
            ->where(function ($q) {
                $q->where('is_ephemeral', false)
                    ->orWhereNull('is_ephemeral');
            });

        if (!$includeDeleted) {
            $q->whereNull('deleted_at');
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $q->where('title', 'like', '%' . substr($search, 0, 128) . '%');
        }
        if ($intent = $request->input('intent')) {
            $q->where('intent', (string) $intent);
        }
        if ($origin = $request->input('origin')) {
            $q->where('origin', (string) $origin);
        }

        $q->orderByDesc('confidence')
            ->orderByDesc('created_at');

        if (!is_null($limit)) {
            $q->limit($limit);
        }

        $rows = $q->get(['id', 'title', 'intent', 'confidence', 'origin', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn($s) => [
                'id' => (string) $s->id,
                'title' => (string) ($s->title ?? ''),
                'intent' => (string) ($s->intent ?? ''),
                'confidence' => (int) ((float) ($s->confidence ?? 0)),
                'origin' => (string) ($s->origin ?? ''),
                'created_at' => $s->created_at,
            ])->values(),
        ]);
    }

    // GET /api/v1/swipe-structures/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $org = $request->attributes->get('organization');

        $s = SwipeStructure::query()
            ->where('organization_id', $org->id)
            ->where('is_ephemeral', false)
            ->findOrFail($id);

        return response()->json([
            'id' => (string) $s->id,
            'title' => (string) ($s->title ?? ''),
            'intent' => (string) ($s->intent ?? ''),
            'funnel_stage' => (string) ($s->funnel_stage ?? ''),
            'hook_type' => (string) ($s->hook_type ?? ''),
            'cta_type' => (string) ($s->cta_type ?? ''),
            'confidence' => (int) ((float) ($s->confidence ?? 0)),
            'structure' => is_array($s->structure) ? $s->structure : [],
            'origin' => (string) ($s->origin ?? ''),
            'deleted_at' => $s->deleted_at,
        ]);
    }

    // POST /api/v1/swipe-structures
    public function store(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $org = $request->attributes->get('organization');
        $user = $request->user();

        $data = $request->validate([
            'title' => 'required|string|max:191',
            'intent' => 'sometimes|nullable|string|in:educational,persuasive,story,contrarian,emotional',
            'funnel_stage' => 'sometimes|nullable|string|in:tof,mof,bof',
            'hook_type' => 'sometimes|nullable|string|max:100',
            'cta_type' => 'sometimes|nullable|string|in:none,soft,hard',
            'structure' => 'required|array|min:3|max:10',
            'structure.*.section' => 'required|string|max:80',
            'structure.*.purpose' => 'required|string|min:1|max:500',
        ]);

        $s = $this->service->createManual((string) $org->id, (string) $user->id, $data);

        return response()->json([
            'id' => (string) $s->id,
            'title' => (string) ($s->title ?? ''),
            'intent' => (string) ($s->intent ?? ''),
            'funnel_stage' => (string) ($s->funnel_stage ?? ''),
            'hook_type' => (string) ($s->hook_type ?? ''),
            'cta_type' => (string) ($s->cta_type ?? ''),
            'confidence' => (int) ((float) ($s->confidence ?? 0)),
            'structure' => is_array($s->structure) ? $s->structure : [],
            'origin' => (string) ($s->origin ?? ''),
        ], 201);
    }

    // PUT /api/v1/swipe-structures/{id}
    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertCanManage($request);

        $org = $request->attributes->get('organization');

        $s = SwipeStructure::query()
            ->where('organization_id', $org->id)
            ->where('is_ephemeral', false)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|nullable|string|max:191',
            'intent' => 'sometimes|nullable|string|in:educational,persuasive,story,contrarian,emotional',
            'funnel_stage' => 'sometimes|nullable|string|in:tof,mof,bof',
            'hook_type' => 'sometimes|nullable|string|max:100',
            'cta_type' => 'sometimes|nullable|string|in:none,soft,hard',
            'confidence' => 'sometimes|nullable|integer|min:0|max:100',
            'structure' => 'sometimes|array|min:3|max:10',
            'structure.*.section' => 'required_with:structure|string|max:80',
            'structure.*.purpose' => 'required_with:structure|string|min:1|max:500',
        ]);

        $s = $this->service->update($s, $data);

        return response()->json([
            'id' => (string) $s->id,
            'title' => (string) ($s->title ?? ''),
            'intent' => (string) ($s->intent ?? ''),
            'funnel_stage' => (string) ($s->funnel_stage ?? ''),
            'hook_type' => (string) ($s->hook_type ?? ''),
            'cta_type' => (string) ($s->cta_type ?? ''),
            'confidence' => (int) ((float) ($s->confidence ?? 0)),
            'structure' => is_array($s->structure) ? $s->structure : [],
            'origin' => (string) ($s->origin ?? ''),
        ]);
    }

    // DELETE /api/v1/swipe-structures/{id}
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertCanManage($request);

        $org = $request->attributes->get('organization');

        $s = SwipeStructure::query()
            ->where('organization_id', $org->id)
            ->where('is_ephemeral', false)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        try {
            $this->service->softDelete($s);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    private function assertCanManage(Request $request): void
    {
        $org = $request->attributes->get('organization');
        $user = $request->user();
        $role = OrganizationRole::from($user->roleIn($org));
        if (!$role->hasPermission('manage_swipe_structures')) {
            abort(403, 'Forbidden');
        }
    }
}
