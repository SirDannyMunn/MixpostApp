<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaPack;
use Illuminate\Http\Request;

class MediaPackController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('viewAny', [MediaPack::class, $organization]);

        $query = MediaPack::withCount('images')
            ->where('organization_id', $organization->id);
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%$search%");
        }
        $query->orderBy($request->input('sort', 'created_at'), $request->input('order', 'desc'));
        return response()->json($query->paginate((int)$request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $this->authorize('create', [MediaPack::class, $organization]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $pack = MediaPack::create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
            'image_count' => 0,
        ]);
        return response()->json($pack, 201);
    }

    public function update(Request $request, $id)
    {
        $pack = MediaPack::findOrFail($id);
        $this->authorize('update', $pack);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
        ]);
        $pack->update($data);
        return response()->json($pack);
    }

    public function destroy(Request $request, $id)
    {
        $pack = MediaPack::findOrFail($id);
        $this->authorize('delete', $pack);
        $pack->delete();
        return response()->json(null, 204);
    }
}
