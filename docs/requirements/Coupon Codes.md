## Goal

Add **offline coupon codes** that grant access to a configured plan **without Stripe**. Admins can generate long, unguessable codes in bulk. Users redeem a code via a throttled API endpoint; redemption attaches a **plan entitlement** to the user/tenant and emits billing domain events.

This is **separate** from Stripe “coupons/promotion codes” (discounts). This feature is “plan unlock codes”.

---

## Non-goals

* Discounts against Stripe checkout (already supported via coupons/promotion codes).
* Partial proration, invoicing, or Stripe subscription creation.
* “Gift card” cash balance (this is plan access, not money).

---

## High-level design

### Concepts

* **CouponCode**: an unguessable token that maps to a **plan_code** (one of your config-defined plans).
* **Redemption**: single use (default), or multi-use with limits; optionally limited to tenant/user/email domain, etc.
* **Entitlement**: a local record that means “this user/tenant has plan X until Y”. Your billing layer reads this before deciding the user must pay via Stripe.

### Flow

1. Admin generates codes: `php artisan billing:coupon:generate --plan=PRO_PLAN --count=100 ...`
2. Code stored hashed in DB (never store raw token), with metadata (plan_code, expiry, max_redemptions, etc.).
3. User submits code: `POST /billing/coupons/redeem`
4. Server:

   * rate-limits
   * validates code via constant-time hash lookup
   * checks constraints (expiry, usage limits, org scoping)
   * creates redemption row (idempotent)
   * grants entitlement (plan_code + start/end)
   * emits events (`CouponRedeemed`, `PlanGrantedViaCoupon`)
5. Billing “plan resolver” checks entitlement first. If present and active: user is “on plan” without Stripe.

---

## Data model (migrations)

### `coupon_codes`

* `id` (uuid/bigint)
* `code_hash` (string, unique) — hash of the token (HMAC or Argon/Bcrypt of canonical token)
* `plan_code` (string, indexed)
* `name` (string, nullable) — internal label (“Black Friday Partner Batch 1”)
* `status` (enum: active, disabled) default active
* `starts_at` (timestamp, nullable)
* `expires_at` (timestamp, nullable)
* `max_redemptions` (int, nullable) — null = unlimited
* `redemptions_count` (int) default 0 (optional counter cache)
* `once_per_user` (bool) default true
* `duration_days` (int, nullable) — entitlement duration; null means “forever” or controlled by `entitlements.end_at`
* `grants_trial_days` (int, nullable) — optional: grant trial period semantics instead of full plan duration
* `metadata` (json, nullable)
* `tenant_id` (nullable, indexed) — if multi-tenancy enabled, scope codes
* `created_by` (nullable user id)
* `created_at`, `updated_at`

### `coupon_redemptions`

* `id`
* `coupon_code_id` (fk)
* `user_id` (fk, indexed)
* `tenant_id` (nullable, indexed)
* `redeemed_at` (timestamp)
* `request_ip` (string nullable)
* `user_agent` (string nullable)
* `idempotency_key` (string nullable, indexed)
* `metadata` (json nullable)

Constraints:

* Unique: (`coupon_code_id`, `user_id`) if `once_per_user = true`
* Optional unique: (`coupon_code_id`, `idempotency_key`) to support retry safety

### `plan_entitlements` (or reuse existing plans/users pivot if you have one)

* `id`
* `user_id` (fk, indexed) OR `tenant_id` if your plans apply to org
* `plan_code` (string, indexed)
* `source` (enum: stripe, coupon, admin, migration)
* `starts_at` timestamp
* `ends_at` timestamp nullable (null = no expiry)
* `coupon_code_id` nullable fk
* `created_at`, `updated_at`

If your billing layer already has a “current plan” concept, this table becomes the canonical override.

---

## Code generation strategy (anti-bruteforce)

### Token format

* Generate 32–48 bytes of cryptographic random data.
* Encode using Base32 Crockford or URL-safe Base64 without padding.
* Optional prefix to identify purpose/version: `CPN1_...`

Example length:

* 40 bytes random → ~64 chars base32 (very strong).

### Storage

* Store **only hash**:

  * Preferred: `hash_hmac('sha256', token, config('app.key'))` so lookup is deterministic.
  * Alternatively: Argon2/bcrypt makes lookup hard because you can’t query by hash without scanning; don’t do that for large batches.
* Canonicalize input: trim, uppercase if base32, remove hyphens.

### Brute force controls

* Endpoint throttling (see below)
* Optional: require auth (recommended) so only logged-in users can redeem, reducing anonymous brute force.
* Add “failed attempts” logging and temporary IP/user lockout on repeated failures.

---

## Artisan command(s)

### `billing:coupon:generate`

Purpose: bulk generate codes for a plan.

Options:

* `--plan=PRO_PLAN` (required)
* `--count=100` (default 1)
* `--name="Partner X January"`
* `--expires="2026-03-01"`
* `--starts="2026-01-05"`
* `--max-redemptions=1` (default 1)
* `--once-per-user=1` (default 1)
* `--duration-days=365` (optional)
* `--tenant=...` (optional if tenancy enabled)
* `--format=csv|json|table` (default table)
* `--output=storage/app/coupons/generated.csv` (optional; if omitted, prints to console once)

Behavior:

* Validate `plan_code` exists in `config('billing.plans')` (or Plan table if you sync).
* Generate `count` tokens, store hashed tokens + metadata.
* **Only once** output raw tokens (console + optional file). Never store raw.

### Optional: `billing:coupon:disable` / `billing:coupon:enable`

* By id or by name prefix/batch tag.

---

## HTTP API

### Route

`POST /billing/coupons/redeem`

Auth:

* **Require auth** by default (`auth:sanctum` / session), because anonymous redemption + plan granting is a brute force magnet.
* If you must allow anonymous: require additional proof (email magic link or CAPTCHA). Otherwise don’t.

Request JSON:

```json
{
  "code": "CPN1_....",
  "idempotency_key": "uuid-or-client-key"
}
```

Response 200:

```json
{
  "ok": true,
  "plan_code": "PRO_PLAN",
  "starts_at": "2026-01-04T17:00:00Z",
  "ends_at": "2027-01-04T17:00:00Z"
}
```

Errors:

* 400 invalid format
* 401 unauthenticated
* 404 code not found (do not reveal “expired” vs “not found” publicly; return generic)
* 409 already redeemed (or already has entitlement)
* 410 expired/disabled (could still return generic 404 if you want less leakage)
* 429 throttled

---

## Throttling & abuse prevention

### Rate limits (Laravel throttle middleware)

Apply layered limits:

* Per IP: e.g. `5/min`
* Per user: e.g. `10/min`
* Per code hash: e.g. `3/min` (prevents hammering one code)
  Implementation:
* Use `RateLimiter::for('coupon-redeem', fn(Request $r) => Limit::perMinute(5)->by($r->ip()).Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()))`
* Add a second limiter keyed by normalized code (hash of normalized code string, not the raw) to avoid logging raw codes.

### Other measures

* Constant-time compare of hashes if you do any in-PHP comparisons.
* Log failed attempts with IP/user and increment a counter; lock out for 15 minutes after N failures.
* Optionally add WAF rules (Cloudflare) if you’re exposed publicly.

---

## Core application integration (billing behavior)

You need a single place that answers: **“What plan is this user on right now?”**

Add a `PlanResolver` (or extend existing one) priority order:

1. Active local entitlement (source=coupon/admin) with `starts_at <= now` and (`ends_at` null or `ends_at > now`)
2. Stripe subscription state via Cashier (if present)
3. None (free tier)

Then your feature gates and credit refills can rely on `currentPlanCode()`.

### Granting plan effects

When redeemed, do **one** of:

* **Override plan**: store entitlement for `plan_code` with `ends_at = now + duration_days` or null (forever).
* Optionally trigger “monthly credits” grant if your plans include it:

  * Either grant initial credits immediately, or rely on your monthly refill scheduler that checks entitlements.

---

## Events (keep package style)

Add new events:

* `CouponRedeemed` (coupon_code_id, user_id, plan_code, tenant_id, redeemed_at)
* `PlanGrantedViaCoupon` (entitlement_id, plan_code, user_id/tenant_id, starts_at, ends_at)

Listeners:

* audit logging
* grant initial credits
* notify user (optional)
* invalidate cached billing state

---

## Idempotency & concurrency

Redeem endpoint must be safe on retries and races.

Mechanisms:

* Accept `Idempotency-Key` header (or body field) and store in `coupon_redemptions.idempotency_key`.
* Wrap redemption in a DB transaction:

  * Lock coupon row `SELECT ... FOR UPDATE`
  * Check limits
  * Insert redemption (unique constraints prevent duplicates)
  * Increment `redemptions_count`
  * Insert entitlement (or upsert if existing active entitlement)
* If unique constraint hit, return success with existing entitlement (idempotent) or 409 depending on policy.

---

## Validation rules

* Code input length min/max (e.g. 20–120)
* Allowed charset (base32/base64url + `_` prefix)
* Normalize (trim, remove spaces/hyphens, uppercase if base32)
* Ensure `plan_code` exists and is enabled

---

## Configuration

Add to `config/billing.php`:

```php
'coupon_access' => [
  'enabled' => true,
  'require_auth' => true,
  'default_duration_days' => null, // null = forever unless coupon sets duration_days
  'rate_limits' => [
    'per_ip_per_minute' => 5,
    'per_user_per_minute' => 10,
    'per_code_per_minute' => 3,
  ],
],
```

---

## Files / classes to add (suggested)

* `database/migrations/*_create_coupon_codes_table.php`
* `database/migrations/*_create_coupon_redemptions_table.php`
* `database/migrations/*_create_plan_entitlements_table.php`
* `app/Models/CouponCode.php`
* `app/Models/CouponRedemption.php`
* `app/Models/PlanEntitlement.php`
* `app/Console/Commands/GenerateCouponCodes.php`
* `app/Http/Controllers/Billing/CouponRedeemController.php`
* `app/Http/Requests/RedeemCouponRequest.php`
* `app/Services/Billing/CouponRedemptionService.php`
* `app/Support/RateLimiters/CouponRedeemLimiter.php` (or in RouteServiceProvider)
* `Vendor\LaravelBilling\Events\CouponRedeemed.php`
* `Vendor\LaravelBilling\Events\PlanGrantedViaCoupon.php`
* Update existing `Billing` facade/service: `currentPlan()` / `hasActiveEntitlement()` integration.

---

## Testing plan

### Unit

* Token normalization + hashing deterministic
* Plan_code validation against config
* Expiry and starts_at enforcement
* Max redemptions enforcement
* once_per_user enforcement

### Feature

* Redeem success → entitlement exists → returns plan
* Redeem twice with same idempotency key → same response
* Concurrent redemption (simulate) respects max_redemptions
* Throttle returns 429 after limit
* Disabled/expired returns generic failure (as designed)

---

## Security checklist

* Never log raw code (mask all but last 4 chars if you must).
* Never store raw code.
* Require auth for redemption unless you add strong anti-bot controls.
* Use deterministic HMAC hash for indexed lookup.
* Throttle by IP + user + code.
* Add lockout after repeated failures.

---

## Implementation order

1. Migrations + models
2. Generator command
3. Redemption service + controller + request validation
4. Rate limiter + middleware wiring
5. Entitlement-based plan resolver integration
6. Events + listeners
7. Tests + docs

---

implement it as a **driver-independent entitlement layer**. Stripe becomes one entitlement source; coupon redemption becomes another. That keeps the billing abstraction clean and avoids Stripe leaking into “access control”.
