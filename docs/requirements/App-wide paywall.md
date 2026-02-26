## Backend engineering spec: App-wide paywall (Laravel + Sanctum + Laravel Billing package)

### Goal

Block all paid functionality server-side behind a single billing rule, for a React SPA that talks JSON-only, using Sanctum for auth.

### Non-goals

* No frontend implementation details beyond required API responses.
* No client-side Stripe logic.
* No feature-level entitlements (this is whole-app access). Extend later.

---

## Assumptions

* API routes use `auth:sanctum`
* SPA uses cookie-based Sanctum (stateful domains + CSRF) OR token auth; spec supports both.
* Billing package provides `billing()` API on `User` and Stripe webhooks endpoint already exists.

---

## Definitions

**Access rule (canonical):** user has access if:

* active subscription OR on trial OR (optional) credits >= 1
  Choose one default. Recommended for “entire app paywall”: **subscription/trial only**.

**Paywall response contract:** API returns `402 Payment Required` with machine-readable `error: "billing_required"` and optional billing state.

---

## Deliverables

1. `User::hasActiveAccess()` (single source of truth)
2. `EnsureBillingAccess` middleware
3. Route groups applying `auth:sanctum` + `billing.access`
4. Billing status endpoint: `GET /api/me/billing`
5. Checkout session creation endpoint: `POST /api/billing/checkout`
6. Billing success/cancel endpoints (optional) for SPA flows
7. Standard error format + tests
8. Webhook verification/processing sanity checks (ensuring lockout happens on payment failure/cancel)

---

## Implementation Plan

### 1) User access API (canonical rule)

**File:** `app/Models/User.php`

Add a single method that encodes “paid user”.

```php
public function hasActiveAccess(): bool
{
    // Recommended for whole-app paywall:
    if ($this->billing()->hasActiveSubscription()) return true;
    if ($this->billing()->onTrial()) return true;

    // Optional (only if you truly want credits to unlock the whole app)
    // if ($this->billing()->hasCredits(1)) return true;

    return false;
}
```

**Notes**

* Do not call Stripe directly here.
* This method must be cheap (DB/local state), not network-bound.

---

### 2) Middleware: hard block on unpaid users

**File:** `app/Http/Middleware/EnsureBillingAccess.php`

Responsibilities:

* Require authenticated user (assumes `auth:sanctum` already ran)
* Block when `!user->hasActiveAccess()`
* Return JSON 402 with stable error code
* Allow a small allowlist of endpoints (billing endpoints themselves, logout, health checks, etc.)

```php
public function handle(Request $request, Closure $next)
{
    $user = $request->user();

    if (! $user || ! $user->hasActiveAccess()) {
        return response()->json([
            'error' => 'billing_required',
            'message' => 'An active subscription is required.',
            'billing' => [
                'has_access' => false,
                'active' => $user?->billing()->hasActiveSubscription() ?? false,
                'on_trial' => $user?->billing()->onTrial() ?? false,
                'credits' => $user?->billing()->getCreditBalance() ?? 0,
            ],
        ], 402);
    }

    return $next($request);
}
```

**Register middleware**

* Laravel 10: `app/Http/Kernel.php` -> `$routeMiddleware['billing.access'] = ...`
* Laravel 11: register in `bootstrap/app.php` route middleware aliases.

**Allowlist / exclusions**
Do not apply this middleware to:

* `/api/login`, `/api/register` (if exists)
* `/api/me/billing`
* `/api/billing/*` endpoints
* `/billing/stripe/webhook` (webhook is not authenticated)
* `/sanctum/csrf-cookie` (if SPA cookie auth)

You enforce that by applying middleware to a protected route group (recommended) rather than global middleware.

---

### 3) Routes: group structure (correct layering)

**File:** `routes/api.php`

Create three groups:

#### A) Public routes (no auth)

* health checks
* stripe webhook route (likely provided by package in `routes/web.php`, but keep consistent)

#### B) Auth-only routes (no paywall)

* `GET /api/me`
* `GET /api/me/billing`
* `POST /api/billing/checkout`
* `POST /api/billing/portal` (optional)

#### C) Paid routes (auth + paywall)

* everything else

Example:

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me/billing', MeBillingController::class);

    Route::prefix('billing')->group(function () {
        Route::post('/checkout', BillingCheckoutController::class);
        // optional:
        // Route::post('/portal', BillingPortalController::class);
    });

    Route::middleware(['billing.access'])->group(function () {
        Route::get('/dashboard', DashboardController::class);
        Route::post('/ai/generate', GenerateController::class);
        // ... all protected functionality
    });
});
```

---

### 4) Billing status endpoint (SPA boot)

**Endpoint:** `GET /api/me/billing`

**Controller:** `app/Http/Controllers/MeBillingController.php`

Response schema (stable contract):

```json
{
  "has_access": true,
  "subscription": { "active": true, "on_trial": false, "plan": "PRO_PLAN" },
  "credits": { "balance": 120 }
}
```

Implementation reads from billing layer only.

---

### 5) Checkout session endpoint (server creates, client redirects)

**Endpoint:** `POST /api/billing/checkout`

**Input**

```json
{ "plan_code": "PRO_PLAN" }
```

**Validation**

* `plan_code` must be in your known allowlist: `config('billing.stripe.price_map')` keys
* Never accept raw price IDs from client

**Output**

```json
{ "url": "https://checkout.stripe.com/..." }
```

**Controller responsibilities**

* `ensureCustomer()` if required by package
* create checkout session with:

  * mode subscription
  * mapped price id
  * success and cancel URLs (frontend URLs)
  * attach metadata: user_id, env, tenant_id (if relevant)

---

### 6) Webhook: ensure it actually revokes access

You rely on package webhook handler to update local subscription state. Your middleware reads local state. Therefore:

**Acceptance criteria**

* When Stripe sends `invoice.payment_failed` and the subscription transitions to non-active status (e.g., unpaid/canceled), `hasActiveSubscription()` becomes false and user is locked out on next API call.

**Engineering tasks**

* Verify the webhook route is reachable publicly and CSRF-exempt
* Confirm Stripe webhook secret configured
* Confirm idempotency works (package says it does)

**Optional hardening**

* Add listener on `SubscriptionCanceled` / `PaymentFailed` to:

  * invalidate sessions (optional, but can be annoying in SPA)
  * notify user
  * log metric

---

### 7) Error format standardization

All paywall rejections must be consistent.

**Standard**

* HTTP status: `402`
* JSON:

  * `error` (machine code)
  * `message` (human safe)
  * `billing` (optional state snapshot)

Ensure you do not leak sensitive billing data.

---

### 8) Sanctum SPA specifics (if cookie-based)

If your React app uses cookie auth:

**Config**

* `SANCTUM_STATEFUL_DOMAINS` includes your SPA domain(s)
* `SESSION_DOMAIN` set correctly
* `CORS` allows credentials
* route `/sanctum/csrf-cookie` must remain accessible (no billing middleware)

**Acceptance criteria**

* Unpaid users can still authenticate and fetch `/me/billing`
* Unpaid users get 402 on paid endpoints

---

## Testing spec

### Feature tests (Pest/PHPUnit)

1. **Unpaid user is blocked**

* Given user authenticated via Sanctum
* And billing state indicates no subscription and not on trial
* When hitting `/api/dashboard`
* Then status 402 and `error=billing_required`

2. **Paid user passes**

* Given user with active subscription (seed local subscription record as cashier expects)
* When hitting protected route
* Then 200

3. **Billing endpoints remain accessible**

* Unpaid user can call `/api/me/billing` and `/api/billing/checkout` (auth required)
* Ensure 200/validation errors but not 402.

4. **Webhook toggles access**

* Simulate webhook event (or manually set local subscription to canceled)
* Ensure `hasActiveAccess()` flips and middleware blocks.

### Unit tests

* `User::hasActiveAccess()` permutations:

  * active subscription
  * trial
  * neither
  * (optional) credits

---

## Security / abuse considerations

* Rate-limit `/api/billing/checkout` to prevent spam session creation.
* Ensure price map codes are immutable server-side.
* Ensure webhook route is signature-verified (package claims it is; test negative signature rejects).
* Never store raw Stripe secrets in responses/logs.

---

## Rollout plan

1. Implement middleware + route grouping
2. Implement `/me/billing` endpoint
3. Implement `/billing/checkout` endpoint
4. Deploy to staging with Stripe test keys + webhook configured
5. Verify flows:

   * sign up → locked out → checkout → webhook → access granted
   * payment failure → webhook → access revoked
6. Deploy production with live keys

---

## Acceptance criteria (definition of done)

* Any request to a protected API route from an authenticated but unpaid user returns `402 billing_required`.
* Paid users receive normal API responses.
* SPA can always determine billing state via `GET /api/me/billing`.
* Checkout is initiated server-side, using plan codes only.
* Webhooks update state so access is granted/revoked without manual intervention.
* Tests cover the above.
