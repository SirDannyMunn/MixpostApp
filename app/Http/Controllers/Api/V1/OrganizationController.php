<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->user()->organizations()
            ->select('organizations.*', 'organization_members.role')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
            'logo_url' => 'nullable|url',
        ]);

        $org = Organization::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name'] . '-' . Str::random(6)),
        ]);

        $org->members()->attach($request->user()->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return response()->json($org, 201);
    }

    public function show(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);
        $organization->loadCount('members');
        return response()->json($organization);
    }

    public function update(Request $request, Organization $organization)
    {
        $this->authorize('update', $organization);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'logo_url' => 'sometimes|nullable|url',
            'subscription_tier' => 'sometimes|in:free,pro,enterprise',
            'subscription_status' => 'sometimes|in:active,cancelled,expired,trial',
        ]);
        $organization->update($data);
        return response()->json($organization);
    }

    public function destroy(Request $request, Organization $organization)
    {
        $this->authorize('delete', $organization);
        $organization->delete();
        return response()->json(null, 204);
    }
}

