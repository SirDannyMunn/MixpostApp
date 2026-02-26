<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Vendor\LaravelBilling\Domain\Credits\Services\CreditService;

class CreditGate
{
    public function __construct(protected CreditService $creditService)
    {
    }

    public function handle(Request $request, Closure $next, int $requiredCredits = 1)
    {
        if (! config('billing.credits.enabled', true) || ! config('billing.credits.enforce', false)) {
            return $next($request);
        }

        $organization = $request->attributes->get('organization');
        $organizationId = $request->header('X-Organization-Id')
            ?? $request->input('organization_id')
            ?? ($organization?->id ?? null);

        if (! $organizationId) {
            return response()->json([
                'error' => 'organization_required',
                'message' => 'Organization context is required for credit-gated actions.',
            ], 400);
        }

        if ($this->creditService->check($organizationId, $requiredCredits)) {
            return $next($request);
        }

        $balance = $this->creditService->getOrganizationBalancePayload($organizationId);

        return response()->json([
            'error' => 'credit_insufficient',
            'credits_required' => $requiredCredits,
            'credits_available' => (int) ($balance['total_available'] ?? 0),
            'upgrade_url' => '/billing/credits',
        ], 402);
    }
}
