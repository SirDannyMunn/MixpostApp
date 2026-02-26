<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;
use Vendor\LaravelBilling\Support\PlanResolver;

class BillingCheckoutController extends BaseController
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        $plans = (array) config('billing.plans', []);
        $allowedCodes = collect($plans)->pluck('code')->filter()->values()->all();

        $validated = $request->validate([
            'plan_code' => ['required', 'string', Rule::in($allowedCodes)],
        ]);

        $planCode = $validated['plan_code'];
        $currentPlan = PlanResolver::currentPlanCode($user);
        if ($currentPlan && strtoupper((string) $currentPlan) === strtoupper((string) $planCode)) {
            return response()->json([
                'message' => 'You are already on this plan.',
            ], 422);
        }

        $priceId = config("billing.stripe.price_map.{$planCode}");
        if (! $priceId) {
            return response()->json([
                'message' => 'Plan is not available for checkout.',
            ], 422);
        }

        if (method_exists($user, 'createOrGetStripeCustomer')) {
            $user->createOrGetStripeCustomer();
        } elseif (method_exists($user, 'createAsStripeCustomer') && ! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
        }

        $frontendBase = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');

        try {
            $url = $user->billing()->checkout([
                'mode' => 'subscription',
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1,
                ]],
                'success_url' => $frontendBase.'/billing/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $frontendBase.'/billing/cancel',
                'customer' => method_exists($user, 'stripeId') ? $user->stripeId() : null,
                'client_reference_id' => (string) $user->getAuthIdentifier(),
                'metadata' => [
                    'plan_code' => $planCode,
                    'user_id' => (string) $user->getAuthIdentifier(),
                    'organization_id' => (string) ($request->header('X-Organization-Id') ?? ''),
                    'env' => app()->environment(),
                ],
            ]);

            return response()->json(['url' => $url]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create checkout session',
                'error' => config('app.debug') ? $e->getMessage() : 'Checkout error',
            ], 422);
        }
    }
}

