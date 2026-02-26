<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

class EnsureOrganizationContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Accept header, query param, or fallback to single membership
        $headerValue = $request->header('X-Organization-Id');
        $queryValue = $request->query('organization_id');
        $wanted = $headerValue ?: $queryValue;

        $organization = null;
        if ($wanted) {
            // Resolve by UUID id or slug (supports string primary keys)
            $organization = Organization::query()
                ->where('id', $wanted)
                ->orWhere('slug', $wanted)
                ->first();
            if (!$organization) {
                return response()->json(['message' => 'Organization not found'], 404);
            }
            if (!$user->isMemberOf($organization)) {
                return response()->json(['message' => 'You are not a member of this organization'], 403);
            }
        } else {
            // Fallback: if user belongs to exactly one organization, use it automatically
            $orgs = $user->organizations()->get(['organizations.id', 'organizations.slug', 'organizations.name']);
            if ($orgs->count() === 1) {
                $organization = Organization::find($orgs->first()->id);
            } else {
                return response()->json([
                    'message' => 'Organization context required',
                    'hints' => [
                        'Send X-Organization-Id header with organization id or slug',
                        'Or pass organization_id query parameter',
                    ],
                    'organizations' => $orgs,
                ], 400);
            }
        }

        $request->merge(['organization' => $organization]);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
