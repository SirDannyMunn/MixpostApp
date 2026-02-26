<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Organization;
use App\Models\OrganizationMember;

class DevAutoAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local')) {
            // In local, authenticate as seeded admin and default org context to the seeded admin org

            // Target seeded records
            $adminEmail = 'admin@example.com';
            $adminOrgSlug = 'admin-org';

            // If not already authenticated, log in seeded admin user
            if (! Auth::check()) {
                $user = User::where('email', $adminEmail)->first();
                if (! $user) {
                    return response()->json([
                        'message' => 'DevAutoAuth: seeded admin user not found',
                        'hint' => 'Ensure database seeders created user admin@example.com',
                    ], 500);
                }
                Auth::login($user);
            }

            // Ensure we have the seeded admin org available
            $user = Auth::user();
            if ($user) {
                $org = Organization::where('slug', $adminOrgSlug)->first();
                if (! $org) {
                    return response()->json([
                        'message' => 'DevAutoAuth: seeded organization not found',
                        'hint' => 'Ensure database seeders created organization with slug admin-org',
                    ], 500);
                }

                // Make sure user is a member of the admin org (avoid 403 in organization middleware)
                OrganizationMember::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => 'owner',
                        'joined_at' => now(),
                    ]
                );

                // If org context missing or invalid, force to admin org
                $requested = $request->header('X-Organization-Id') ?: $request->query('organization_id');
                $useAdminOrg = false;
                if (! $requested) {
                    $useAdminOrg = true;
                } else {
                    $requestedOrg = Organization::query()
                        ->where('id', $requested)
                        ->orWhere('slug', $requested)
                        ->first();
                    if (! $requestedOrg || ! $user->isMemberOf($requestedOrg)) {
                        $useAdminOrg = true;
                    }
                }

                if ($useAdminOrg) {
                    // Override header for downstream middleware/controllers
                    $request->headers->set('X-Organization-Id', (string) $org->id);
                    // Also normalize query for any consumers reading from query
                    $request->merge(['organization_id' => $org->id]);
                }
            }
        }

        return $next($request);
    }
}
