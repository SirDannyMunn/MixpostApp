<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $query = Tag::where('organization_id', $organization->id);
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%$search%");
        }
        $tags = $query->orderBy('name')->get();
        return response()->json(['data' => $tags]);
    }

    public function store(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
        ]);
        $tag = Tag::create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6b7280',
            'created_by' => $request->user()->id,
        ]);
        return response()->json($tag, 201);
    }

    public function show(Request $request, Tag $tag)
    {
        return response()->json($tag);
    }

    public function update(Request $request, Tag $tag)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'color' => 'sometimes|nullable|string|max:7',
        ]);
        $tag->update($data);
        return response()->json($tag);
    }

    public function destroy(Request $request, Tag $tag)
    {
        $tag->delete();
        return response()->json(null, 204);
    }
}

