<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Vendor\LaravelBilling\Domain\Credits\Services\CreditService;

class MeBillingController extends BaseController
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        $subscription = $user?->subscription('default');
        $active = $user?->subscribed('default') ?? false;
        $onTrial = $user?->onTrial('default') ?? false;

        // Attempt to reverse-map plan code from configured price map (best effort)
        $planCode = null;
        if ($subscription) {
            try {
                $priceId = optional($subscription->items()->first())->stripe_price ?? null;
                if (! $priceId && method_exists($subscription, 'asStripeSubscription')) {
                    $asStripe = $subscription->asStripeSubscription();
                    $priceId = $asStripe->items->data[0]->price->id ?? null;
                }

                if ($priceId) {
                    $map = (array) config('billing.stripe.price_map', []);
                    foreach ($map as $code => $id) {
                        if ($id === $priceId) {
                            $planCode = $code;
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fail silently; planCode remains null
            }
        }

        $credits = 0;
        try {
            $organization = $request->attributes->get('organization');
            $organizationId = $request->header('X-Organization-Id')
                ?? $request->input('organization_id')
                ?? ($organization?->id ?? null);

            if ($organizationId) {
                $payload = app(CreditService::class)->getOrganizationBalancePayload((string) $organizationId);
                $credits = (int) ($payload['total_available'] ?? 0);
            } else {
                $credits = $user?->billing()->getCreditBalance() ?? 0;
            }
        } catch (\Throwable $e) {
            $credits = 0;
        }

        return response()->json([
            'has_access' => $user?->hasActiveAccess() ?? false,
            'subscription' => [
                'active' => $active,
                'on_trial' => $onTrial,
                'plan' => $planCode,
            ],
            'credits' => [
                'balance' => $credits,
            ],
        ]);
    }
}
