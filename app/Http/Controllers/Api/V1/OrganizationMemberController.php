<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizationMemberController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);
        $members = $organization->members()->with('pivot')->get()->map(function ($user) {
            return [
                'id' => $user->pivot->id ?? null,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ],
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->joined_at,
            ];
        });
        return response()->json(['data' => $members]);
    }

    public function invite(Request $request, Organization $organization)
    {
        $this->authorize('inviteMembers', $organization);
        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:owner,admin,member,viewer',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['message' => 'User not found. Only existing users can be invited in this version.'], 422);
        }

        OrganizationMember::updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            ['role' => $data['role'], 'invited_by' => $request->user()->id, 'invited_at' => now(), 'joined_at' => now()]
        );

        return response()->json(['message' => 'Member added'], 201);
    }

    public function update(Request $request, Organization $organization, OrganizationMember $member)
    {
        if ($member->organization_id !== $organization->id) {
            return response()->json(['message' => 'Member not in organization'], 404);
        }
        $this->authorize('updateMemberRoles', $organization);
        $data = $request->validate(['role' => 'required|in:owner,admin,member,viewer']);
        $member->update(['role' => $data['role']]);
        return response()->json($member);
    }

    public function destroy(Request $request, Organization $organization, OrganizationMember $member)
    {
        if ($member->organization_id !== $organization->id) {
            return response()->json(['message' => 'Member not in organization'], 404);
        }
        $this->authorize('removeMembers', $organization);
        $member->delete();
        return response()->json(null, 204);
    }
}
