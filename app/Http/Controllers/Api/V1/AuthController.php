<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $organization = Organization::create([
                'name' => $user->name . "'s Workspace",
                'slug' => Str::slug($user->name . '-workspace-' . Str::random(6)),
                'subscription_tier' => 'free',
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            ActivityLog::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'action' => 'user.registered',
                'description' => 'User registered and created organization',
            ]);

            return response()->json([
                'user' => $user,
                'token' => $token,
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ],
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('auth-token')->plainTextToken;

        $organizations = $user->organizations()
            ->select('organizations.*', 'organization_members.role')
            ->get();

        return response()->json([
            'user' => $user,
            'token' => $token,
            'organizations' => $organizations,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['organizations']);
        return response()->json($user);
    }
}

