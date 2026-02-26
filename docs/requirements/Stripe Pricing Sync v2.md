Below is a **clean, implementation-ready engineering spec** you can hand directly to a backend developer.

It is scoped narrowly to **adding env-ready output support to the existing `billing:sync-plans` command**, without changing current behavior or breaking production usage.

No fluff. No ambiguity.

---

# Engineering Spec: Stripe Plan Sync – Env Output Support

## Objective

Extend the existing `billing:sync-plans` Artisan command to **optionally output Stripe Product and Price IDs** in a format suitable for manual insertion into `.env`, **without automatically mutating environment files**.

This improves developer ergonomics while preserving safety and CI/CD compatibility.

---

## Background (Current Behavior)

The existing command:

```bash
php artisan billing:sync-plans
php artisan billing:sync-plans --stripe
```

* Reads plans from `config(billing.plans)`
* Optionally creates Stripe Products and Prices
* Optionally persists to DB
* Outputs **only a summary table**
* Does **not**:

  * Print Stripe IDs
  * Write `.env`
  * Expose created IDs in any structured way

This spec adds **explicit, opt-in output only**.

---

## Non-Goals (Explicit)

* ❌ Do not write to `.env`
* ❌ Do not modify existing default output
* ❌ Do not change plan resolution logic
* ❌ Do not auto-guess env variable names without config support
* ❌ Do not require DB persistence

---

## High-Level Design

Add a new optional flag to the command:

```
--print-env
```

When present:

* The command prints **newly created or resolved Stripe IDs**
* Output is formatted as `.env`-ready key/value pairs
* Output is printed **after** the summary table
* Output is **deterministic and copy-pasteable**

Default behavior remains unchanged.

---

## CLI Interface

### New Flag

```bash
php artisan billing:sync-plans --stripe --print-env
```

### Optional Combinations

| Flags                      | Behavior                                               |
| -------------------------- | ------------------------------------------------------ |
| (no flags)                 | Current behavior                                       |
| `--stripe`                 | Create/sync Stripe objects                             |
| `--print-env`              | Print env output (requires Stripe data to exist)       |
| `--stripe --print-env`     | Create missing Stripe objects **and** print env output |
| `--no-persist --print-env` | Stripe-only, env output, no DB writes                  |

---

## Output Specification

### Example Output

```text
Stripe plan sync complete.

Add the following to your .env file:

NB_STARTER_PRODUCT_ID=prod_abc123
NB_STARTER_PRICE_ID=price_def456

NB_PRO_PRODUCT_ID=prod_ghi789
NB_PRO_PRICE_ID=price_jkl012
```

### Formatting Rules

* Blank line between plans
* Uppercase, underscore-separated keys
* Deterministic order (same as `config(billing.plans)` order)
* Only output **plans with Stripe data**
* Only output **IDs that exist or were created**

---

## Env Key Naming Rules

Env keys must be derived **explicitly**, not guessed.

### Rule

Each plan definition must expose a stable env prefix.

#### Example `config/billing.php`

```php
'plans' => [
    [
        'code' => 'NB_STARTER',
        'env_prefix' => 'NB_STARTER',
        'stripe' => [
            'product_id' => env('NB_STARTER_PRODUCT_ID'),
            'price_id' => env('NB_STARTER_PRICE_ID'),
        ],
    ],
]
```

### Generated Keys

```text
{env_prefix}_PRODUCT_ID
{env_prefix}_PRICE_ID
```

If `env_prefix` is missing:

* Skip env output for that plan
* Do not error
* Log a warning in verbose mode only

---

## Command Flow (Detailed)

### 1. Parse flags

* `--stripe`
* `--print-env`
* `--no-persist`

### 2. Load plans

* From `config(billing.plans)`
* Preserve order

### 3. Sync logic (existing)

* Resolve local plan
* Optionally create/update Stripe Product
* Optionally create/update Stripe Price
* Optionally persist to DB

### 4. Collect Stripe IDs

For each plan, capture:

```php
[
  'code' => 'NB_STARTER',
  'env_prefix' => 'NB_STARTER',
  'stripe' => [
    'product_id' => 'prod_xxx',
    'price_id' => 'price_yyy',
  ],
]
```

Store only if:

* Stripe IDs exist (created or resolved)

### 5. Print summary table (unchanged)

### 6. Print env output (new, conditional)

Only if:

* `--print-env` is present
* At least one plan has Stripe IDs

---

## Edge Cases

### No Stripe IDs created or resolved

* Print summary only
* Print nothing extra
* No error

### Stripe IDs exist but `env_prefix` missing

* Skip that plan
* Continue
* Optional warning in verbose mode

### Config cache enabled

* No special handling required
* Output is informational only

---

## Implementation Notes

### File(s) Likely Touched

* `app/Console/Commands/SyncBillingPlans.php`
* Possibly a helper like `PlanSyncResult` or `StripePlanResolver`

### Suggested Internal Structure

```php
$results = $this->syncPlans(...);

if ($this->option('print-env')) {
    $this->printEnvOutput($results);
}
```

### `printEnvOutput()` Responsibilities

* Accept array of resolved plans
* Filter to plans with `env_prefix`
* Output formatted env lines
* No side effects

---

## Testing Requirements

### Unit / Feature Tests

1. **Default behavior unchanged**

   * No env output without `--print-env`

2. **Env output appears when requested**

   * Correct formatting
   * Correct keys
   * Correct values

3. **No Stripe objects → no env output**

   * Silent success

4. **Mixed plans**

   * Only plans with Stripe data output

5. **Dry run**

   * `--no-persist --print-env` prints IDs but does not write DB

---

## Acceptance Criteria

This feature is complete when:

* `billing:sync-plans` behavior is unchanged by default
* `--print-env` produces deterministic, copy-pasteable output
* No environment files are modified
* No Stripe behavior changes
* Output can be safely used in local, staging, or prod environments

---

## Rationale (Why this design)

* `.env` mutation is unsafe → manual copy is intentional
* Stripe IDs are environment-specific → output must be explicit
* DB-backed plans remain the recommended path
* This feature improves DX without creating hidden side effects

---

## Optional Future Enhancements (Not in Scope)

* `--format=json` output
* `--print-env=plan_code`
* Separate command `billing:export-env`
* CI-safe output mode

---
