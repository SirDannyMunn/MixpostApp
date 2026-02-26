<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureBillingAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->hasActiveAccess()) {
            $credits = 0;
            try {
                $credits = $user?->billing()->getCreditBalance() ?? 0;
            } catch (\Throwable $e) {
                $credits = 0;
            }
            return response()->json([
                'error' => 'billing_required',
                'message' => 'An active subscription is required.',
                'billing' => [
                    'has_access' => false,
                    'active' => $user?->subscribed('default') ?? false,
                    'on_trial' => $user?->onTrial('default') ?? false,
                    'credits' => $credits,
                ],
            ], 402);
        }

        return $next($request);
    }
}
