
Below is a **backend engineering spec** covering the **additional billing endpoints** the frontend now depends on.
This is designed to drop cleanly into your existing Laravel + Sanctum + billing-package setup.

No frontend assumptions. No Stripe leakage. No ambiguity.

---

# Backend Engineering Spec: Billing API Extensions

## Purpose

Expose **read-only billing state**, **plan metadata**, and **controlled billing actions** to support:

* SPA paywall enforcement
* Subscription management UI
* Checkout initiation
* Cancellation flows

All billing authority remains server-side.

---

## Scope (What this spec adds)

This spec defines **new API endpoints only**.
It does **not** change:

* Billing middleware
* Stripe webhook handling
* Existing subscription/usage internals

---

## Global Constraints

* All endpoints return JSON
* All endpoints are authenticated via `auth:sanctum`
* No Stripe IDs, secrets, or raw Cashier models are exposed
* All plan/pricing data comes from backend config or DB
* Backend remains the single source of truth

---

## 1. `GET /api/me/billing`

### Purpose

Return the **current billing/access state** for the authenticated user.

Used by:

* App bootstrap
* Route gating
* Header/UI indicators
* Post-checkout refresh

---

### Authorization

* `auth:sanctum`
* No billing middleware (must be callable even if unpaid)

---

### Response Contract

```json
{
  "has_access": true,
  "subscription": {
    "active": true,
    "on_trial": false,
    "plan": "PRO",
    "renews_at": "2025-01-15"
  },
  "credits": {
    "balance": 120
  }
}
```

---

### Backend Logic

* `has_access` → `$user->hasActiveAccess()`
* `active` → `$user->billing()->hasActiveSubscription()`
* `on_trial` → `$user->billing()->onTrial()`
* `plan` → canonical plan code (string or null)
* `renews_at` → next billing date if available (nullable)
* `credits.balance` → `$user->billing()->getCreditBalance()`

---

### Implementation Notes

* Do **not** infer plan from Stripe
* Prefer local subscription state
* `renews_at` may be null for trials or free plans

---

## 2. `GET /api/billing/plans`

### Purpose

Expose **all available plans** and the **current plan** in a frontend-safe format.

Used by:

* Subscription page
* Plan comparison UI

---

### Authorization

* `auth:sanctum`
* No billing middleware

---

### Data Source

One of:

* `config/billing.php` → `plans` array
* OR `plans` DB table populated via `billing:sync-plans`

Backend must choose **one canonical source** and document it.

---

### Response Contract

```json
{
  "current_plan": "PRO",
  "plans": [
    {
      "code": "FREE",
      "name": "Free",
      "price": 0,
      "interval": "month",
      "popular": false,
      "features": [
        "Up to 100 bookmarks",
        "Basic slideshow templates",
        "5 social accounts",
        "7 days of analytics",
        "Community support"
      ]
    },
    {
      "code": "PRO",
      "name": "Pro",
      "price": 29,
      "interval": "month",
      "popular": true,
      "features": [
        "Unlimited bookmarks",
        "Advanced slideshow templates",
        "Unlimited social accounts",
        "90 days of analytics",
        "AI-powered content generation",
        "Priority support",
        "Advanced scheduling",
        "Team collaboration (up to 5 members)"
      ]
    }
  ]
}
```

---

### Backend Responsibilities

* Normalize plans into frontend-safe shape
* Remove all Stripe IDs
* Provide `popular` hint (boolean)
* Maintain stable plan `code` identifiers
* Return plans in display order

---

### Implementation Notes

* Features should be plain strings
* No computed entitlements
* No feature gating logic here

---

## 3. `POST /api/billing/checkout`

### Purpose

Initiate a **Stripe Checkout Session** server-side.

Used by:

* “Upgrade” / “Select Plan” actions

---

### Authorization

* `auth:sanctum`
* No billing middleware

---

### Request

```json
{
  "plan_code": "PRO"
}
```

---

### Validation

* `plan_code` is required
* Must exist in backend plan registry
* Must not be the user’s current active plan

---

### Response

```json
{
  "url": "https://checkout.stripe.com/..."
}
```

---

### Backend Logic

1. Validate `plan_code`
2. Resolve plan → Stripe price ID via config
3. Ensure Stripe customer exists
4. Create checkout session:

   * `mode = subscription`
   * `price = resolved price ID`
   * `success_url` and `cancel_url` from config
   * attach metadata:

     * `user_id`
     * `environment`
     * optional `tenant_id`
5. Return redirect URL

---

### Security Rules

* Never accept Stripe price IDs from client
* Never expose checkout session IDs
* Rate-limit endpoint

---

## 4. `POST /api/billing/cancel`

### Purpose

Cancel the user’s active subscription.

Used by:

* “Cancel subscription” UI

---

### Authorization

* `auth:sanctum`
* No billing middleware (user must be able to cancel)

---

### Behavior

Default behavior:

* Cancel **at period end**

Optional (future):

* Support immediate cancel via request flag

---

### Response

```json
{
  "status": "canceled",
  "effective_at": "period_end"
}
```

---

### Backend Logic

* Verify user has active subscription
* Call:

  ```php
  $user->billing()->cancel(); // period end
  ```
* Emit cancellation event (already handled by package)
* Do not revoke access immediately unless required by business rules

---

## 5. `GET /api/billing/payment-method` (Optional but recommended)

### Purpose

Display masked payment method details.

Used by:

* Subscription page “Billing Information” section

---

### Authorization

* `auth:sanctum`
* No billing middleware

---

### Response

```json
{
  "brand": "visa",
  "last4": "4242",
  "expires": "12/2025"
}
```

---

### Backend Logic

* Fetch default payment method via Cashier
* Return masked data only
* If none exists, return `null` fields

---

### Security Rules

* Never expose full card numbers
* Never allow modification via this endpoint

---

## 6. Route Registration Summary

All routes must be registered under API routes.

Conceptual grouping:

* Auth-only (no billing):

  * `GET /api/me/billing`
  * `GET /api/billing/plans`
  * `POST /api/billing/checkout`
  * `POST /api/billing/cancel`
  * `GET /api/billing/payment-method`

* Paid routes (auth + billing middleware):

  * All core app functionality

---

## 7. Error Handling Standards

### Billing-required error (already enforced elsewhere)

```json
{
  "error": "billing_required",
  "message": "An active subscription is required."
}
```

HTTP status: `402`

---

### Validation error

Use standard Laravel validation responses (`422`).

---

## 8. Testing Requirements

### Feature Tests

1. Unpaid user can:

   * fetch plans
   * fetch billing status
   * start checkout
   * cancel (if applicable)

2. Paid user:

   * sees correct current plan
   * cannot checkout same plan

3. Invalid plan code:

   * returns 422

4. Cancel subscription:

   * updates local billing state
   * access remains until period end

---

## 9. Definition of Done

Backend implementation is complete when:

* All specified endpoints exist
* All responses match contracts exactly
* No Stripe internals leak to client
* Unpaid users are never blocked from managing billing
* Paid access is enforced exclusively via middleware
* Frontend can fully render subscription UI from API data

---

If you want the **tenant / org-level version** of this spec next (single subscription → multiple users), say the word.
