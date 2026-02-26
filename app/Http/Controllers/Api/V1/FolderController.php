<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FolderController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $query = Folder::where('organization_id', $organization->id);

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        $folders = $query->orderBy('position')->get();
        return response()->json(['data' => $folders]);
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            // Preferred: system_name. Back-compat: accept `name` as alias.
            'system_name' => 'sometimes|required_without:name|string|max:255',
            'name' => 'sometimes|required_without:system_name|string|max:255',
            'display_name' => 'nullable|string|max:120',
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('folders', 'id')->where(fn($q) => $q->where('organization_id', $organization->id)),
            ],
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
        ]);

        $systemName = trim((string) ($data['system_name'] ?? ($data['name'] ?? '')));
        if ($systemName === '') {
            $systemName = 'Folder ' . Str::lower(Str::random(6));
        }

        $displayName = isset($data['display_name']) ? trim((string) $data['display_name']) : '';
        $displayName = $displayName !== '' ? $displayName : null;

        $folder = new Folder();
        $folder->organization_id = $organization->id;
        $folder->parent_id = $data['parent_id'] ?? null;
        $folder->system_name = $systemName;
        $folder->display_name = $displayName;
        $folder->system_named_at = now();
        $folder->color = $data['color'] ?? null;
        $folder->icon = $data['icon'] ?? null;
        $folder->created_by = $request->user()->id;
        $folder->save();

        return response()->json($folder, 201);
    }

    public function show(Request $request, Folder $folder)
    {
        $organization = $request->attributes->get('organization');
        if ((string) $folder->organization_id !== (string) $organization->id) {
            abort(404);
        }
        return response()->json($folder);
    }

    public function update(Request $request, Folder $folder)
    {
        $organization = $request->attributes->get('organization');
        if ((string) $folder->organization_id !== (string) $organization->id) {
            abort(404);
        }

        // Rename semantics: update ONLY display_name.
        $data = $request->validate([
            'display_name' => 'nullable|string|max:120',
            // Attempts to set system_name (or legacy name) via API must be rejected.
            'system_name' => 'prohibited',
            'name' => 'prohibited',
        ]);

        $newDisplay = array_key_exists('display_name', $data) ? trim((string) $data['display_name']) : null;
        $newDisplay = ($newDisplay !== null && $newDisplay !== '') ? $newDisplay : null;

        if ($folder->display_name !== $newDisplay) {
            $folder->display_name = $newDisplay;
            $folder->display_renamed_at = now();
            $folder->save();
        }

        return response()->json($folder);
    }

    public function destroy(Request $request, Folder $folder)
    {
        $organization = $request->attributes->get('organization');
        if ((string) $folder->organization_id !== (string) $organization->id) {
            abort(404);
        }
        $folder->delete();
        return response()->json(null, 204);
    }

    public function reorder(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $orders = $request->validate([
            'orders' => 'required|array',
            'orders.*.id' => [
                'required',
                'uuid',
                Rule::exists('folders', 'id')->where(fn($q) => $q->where('organization_id', $organization->id)),
            ],
            'orders.*.position' => 'required|integer',
        ])['orders'];

        foreach ($orders as $item) {
            Folder::where('organization_id', $organization->id)
                ->where('id', $item['id'])
                ->update(['position' => $item['position']]);
        }
        return response()->json(['message' => 'Reordered']);
    }
}

