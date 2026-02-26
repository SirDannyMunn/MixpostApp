<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

class CheckSubscriptionLimit
{
    public function handle(Request $request, Closure $next, string $resource)
    {
        $organization = $request->attributes->get('organization');
        if (!$organization) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $subscriptionService = app(SubscriptionService::class);
        if (!$subscriptionService->canCreate($organization, $resource)) {
            return response()->json([
                'message' => 'Subscription limit reached',
                'resource' => $resource,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}

