Below is a **full engineering spec** for a **Stripe Bootstrap & Sync system** that extends your Laravel Billing library to:

* Create Products & Prices in Stripe
* Sync Stripe IDs back into local config / `.env`
* Optionally generate Stripe coupons and/or your **offline plan-unlock coupons**
* Walk the developer through an interactive bootstrap flow
* Remain **idempotent**, safe for re-runs, and environment-aware

---

# Engineering Spec: Stripe Billing Bootstrap & Sync

## 1. Objective

Provide a **single-command bootstrap workflow** that initializes Stripe billing for a new app or environment with minimal manual dashboard work.

Primary goals:

* Canonical plan definitions live in code
* Stripe is treated as a **derived system**
* All Stripe IDs are generated, synced, and stored automatically
* Bootstrap tasks are interactive but scriptable (CI-safe)

---

## 2. Scope

### In scope

* Stripe Product creation
* Stripe Price creation (recurring + one-time)
* Local config / `.env` population
* Optional Stripe coupon creation
* Optional offline coupon generation (your custom system)
* Re-runnable, idempotent sync
* Test vs Live environment isolation

### Out of scope

* Stripe promotion-code UI management
* Complex price migrations (handled separately)
* Stripe Connect (future phase)

---

## 3. Canonical Source of Truth

### Plan Definitions (Authoritative)

Plans are defined **once**, locally.

**Location (preferred):**

* `config/billing.php` → `plans`
* or `resources/billing/plans.json` (if you want format flexibility)

Example (canonical):

```php
'plans' => [
    [
        'code' => 'PRO_PLAN',
        'name' => 'Pro',
        'amount' => 4900,
        'currency' => 'usd',
        'interval' => 'month',
        'trial_days' => 14,
        'metadata' => [
            'tier' => 'pro',
        ],
    ],
];
```

Rules:

* `code` is **stable forever**
* Stripe IDs are **derived artifacts**
* Human changes happen here, not in Stripe dashboard

---

## 4. Core Commands

### 4.1 `billing:stripe:bootstrap`

**Purpose**
End-to-end interactive setup for Stripe billing.

**Signature**

```bash
php artisan billing:stripe:bootstrap
```

#### Step-by-step flow

1. **Environment detection**

   * Detect `STRIPE_SECRET` key
   * Determine `test` vs `live`
   * Confirm with user:

     > “You are about to create resources in Stripe TEST mode. Continue?”

2. **Plan validation**

   * Validate required fields (`code`, `amount`, `interval`)
   * Fail fast on invalid config

3. **Stripe product sync**

   * For each plan:

     * Look up Stripe Product by:

       * `metadata.plan_code = <code>`
     * If missing → create
     * If exists → reuse
   * Store `product_id`

4. **Stripe price sync**

   * For each plan:

     * Check if a **matching price** exists:

       * amount
       * currency
       * interval
       * product
     * If missing → create new price
     * Never mutate existing prices (Stripe rule)
   * Store `price_id`

5. **Local persistence**
   Prompt user:

   > “Where should Stripe IDs be stored?”

   Options:

   * `config/billing.php`
   * `.env`
   * Database (`plans` table)
   * Multiple (allowed)

6. **Optional Stripe coupons**
   Prompt:

   > “Generate Stripe coupons for these plans?”

   If yes:

   * Ask:

     * percent vs fixed
     * duration
     * redemption limits
   * Create coupons via Stripe API
   * Persist coupon IDs locally

7. **Optional offline coupon generation**
   Prompt:

   > “Generate offline plan unlock coupons?”

   If yes:

   * Delegate to `billing:coupon:generate`
   * Associate coupons with plan codes

8. **Summary output**

   * Table:

     * Plan code
     * Product ID
     * Price ID
     * Coupons created
   * Warning if anything skipped

---

### 4.2 `billing:stripe:sync`

**Purpose**
Idempotent re-sync (safe for CI, deploys).

```bash
php artisan billing:stripe:sync --no-interaction
```

Behavior:

* No prompts
* No destructive changes
* Only creates missing Products/Prices
* Does **not** regenerate coupons
* Useful for staging/prod parity

---

## 5. Stripe API Implementation

### Product creation

```php
\Product::create([
  'name' => $plan['name'],
  'metadata' => [
      'plan_code' => $plan['code'],
      'source' => 'laravel-billing',
  ],
]);
```

### Price creation

```php
\Price::create([
  'product' => $productId,
  'unit_amount' => $plan['amount'],
  'currency' => $plan['currency'],
  'recurring' => [
      'interval' => $plan['interval'],
  ],
  'metadata' => [
      'plan_code' => $plan['code'],
  ],
]);
```

Rules:

* Prices are append-only
* Never update prices in place
* Metadata always includes `plan_code`

---

## 6. Local Persistence Strategy

### Option A — Config file (default)

Update `config/billing.php`:

```php
'stripe' => [
    'price_map' => [
        'PRO_PLAN' => 'price_123',
    ],
],
```

### Option B — `.env`

Write:

```
STRIPE_PRICE_PRO_PLAN=price_123
```

Then reference via:

```php
'price_id' => env('STRIPE_PRICE_PRO_PLAN'),
```

### Option C — Database

If `plans` table exists:

* Upsert by `code`
* Store `stripe_product_id`, `stripe_price_id`

---

## 7. Idempotency Rules

| Resource      | Strategy                                         |
| ------------- | ------------------------------------------------ |
| Product       | Lookup by `metadata.plan_code`                   |
| Price         | Match on `(product, amount, currency, interval)` |
| Coupons       | Never auto-recreated unless explicitly requested |
| Config writes | Overwrite only generated keys                    |

Re-running the command:

* Never creates duplicates
* Never deletes Stripe resources
* Never mutates live prices

---

## 8. Error Handling

### Hard failures

* Invalid Stripe key
* Missing plan fields
* API permission errors

### Soft warnings

* Price exists but mismatched amount
* Multiple prices found (choose newest, warn)
* Config file not writable

---

## 9. Security Considerations

* Stripe keys never logged
* `.env` writes are masked in output
* Test and live keys strictly separated
* Confirmation required before live writes

---

## 10. Files / Classes to Add

```
app/
├── Console/
│   └── Commands/
│       ├── StripeBootstrapCommand.php
│       └── StripeSyncCommand.php
├── Services/
│   └── Billing/
│       ├── StripeProductService.php
│       ├── StripePriceService.php
│       ├── StripeCouponService.php
│       └── BillingBootstrapOrchestrator.php
├── Support/
│   └── ConfigWriters/
│       ├── EnvWriter.php
│       └── BillingConfigWriter.php
```

---

## 11. Testing Strategy

### Unit

* Plan validation
* Stripe lookup logic
* Config writers (mock filesystem)

### Integration (Stripe test mode)

* Product creation
* Price creation
* Idempotent re-run

### Manual

* Full bootstrap in empty Stripe account
* Re-run bootstrap with existing data

---

## 12. Future Extensions

* Stripe webhook auto-sync
* Promotion code generation
* Stripe Connect compatibility
* Multi-currency price generation
* CI `--dry-run` mode

---

## 13. Design Principle (important)

> **Stripe is not your source of truth.
> Your config is. Stripe is infrastructure.**

This spec enforces that discipline and gives you a real **“one-command billing bootstrap”** workflow that very few Laravel billing systems actually do well.
