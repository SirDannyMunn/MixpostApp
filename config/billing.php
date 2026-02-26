<?php

$billingPlansGroup = env('BILLING_PLANS_GROUP', 'reagan');

$billingPlanGroups = [
    'reagan' => [
        [
            'code' => env('BILLING_REAGAN_STARTER_CODE', 'STARTER'),
            'name' => env('BILLING_REAGAN_STARTER_NAME', 'Starter'),
            'price' => (float) env('BILLING_REAGAN_STARTER_PRICE', 99),
            'interval' => env('BILLING_REAGAN_STARTER_INTERVAL', 'month'),
            'annual_monthly_price' => (float) env('BILLING_REAGAN_STARTER_ANNUAL_MONTHLY_PRICE', 79),
            'annual_price' => (float) env('BILLING_REAGAN_STARTER_ANNUAL_PRICE', 948),
            'seats_included' => (int) env('BILLING_REAGAN_STARTER_SEATS_INCLUDED', 1),
            'additional_seat_price' => (float) env('BILLING_REAGAN_STARTER_ADDITIONAL_SEAT_PRICE', 59),
            'monthly_credits' => (int) env('BILLING_REAGAN_STARTER_MONTHLY_CREDITS', 1500),
            'daily_connection_limit_per_seat' => (int) env('BILLING_REAGAN_STARTER_DAILY_CONNECTION_LIMIT', 25),
            'daily_message_limit_per_seat' => (int) env('BILLING_REAGAN_STARTER_DAILY_MESSAGE_LIMIT', 50),
            'signal_agents_active' => (int) env('BILLING_REAGAN_STARTER_SIGNAL_AGENTS_ACTIVE', 3),
            'ai_personalization' => env('BILLING_REAGAN_STARTER_AI_PERSONALIZATION', 'Standard'),
            'team_features' => env('BILLING_REAGAN_STARTER_TEAM_FEATURES', 'Single user'),
            'support' => env('BILLING_REAGAN_STARTER_SUPPORT', 'Email + chat'),
            'credit_rollover' => env('BILLING_REAGAN_STARTER_CREDIT_ROLLOVER', 'None'),
            'trial_days' => (int) env('BILLING_REAGAN_STARTER_TRIAL_DAYS', 14),
            'popular' => filter_var(env('BILLING_REAGAN_STARTER_POPULAR', false), FILTER_VALIDATE_BOOL),
            'features' => [
                '1 LinkedIn seat included',
                '1,500 monthly credits',
                '3 active signal agents',
                'Standard AI personalization',
                '25 connections/day per seat',
                '50 messages/day per seat',
            ],
            'stripe' => [
                'product_id' => env('BILLING_REAGAN_STARTER_PRODUCT_ID'),
                'price_id' => env('BILLING_REAGAN_STARTER_PRICE_ID'),
            ],
        ],
        [
            'code' => env('BILLING_REAGAN_GROWTH_CODE', 'GROWTH'),
            'name' => env('BILLING_REAGAN_GROWTH_NAME', 'Growth'),
            'price' => (float) env('BILLING_REAGAN_GROWTH_PRICE', 249),
            'interval' => env('BILLING_REAGAN_GROWTH_INTERVAL', 'month'),
            'annual_monthly_price' => (float) env('BILLING_REAGAN_GROWTH_ANNUAL_MONTHLY_PRICE', 199),
            'annual_price' => (float) env('BILLING_REAGAN_GROWTH_ANNUAL_PRICE', 2388),
            'seats_included' => (int) env('BILLING_REAGAN_GROWTH_SEATS_INCLUDED', 3),
            'additional_seat_price' => (float) env('BILLING_REAGAN_GROWTH_ADDITIONAL_SEAT_PRICE', 49),
            'monthly_credits' => (int) env('BILLING_REAGAN_GROWTH_MONTHLY_CREDITS', 5000),
            'daily_connection_limit_per_seat' => (int) env('BILLING_REAGAN_GROWTH_DAILY_CONNECTION_LIMIT', 25),
            'daily_message_limit_per_seat' => (int) env('BILLING_REAGAN_GROWTH_DAILY_MESSAGE_LIMIT', 50),
            'signal_agents_active' => (int) env('BILLING_REAGAN_GROWTH_SIGNAL_AGENTS_ACTIVE', 10),
            'ai_personalization' => env('BILLING_REAGAN_GROWTH_AI_PERSONALIZATION', 'Standard + Deep Research'),
            'team_features' => env('BILLING_REAGAN_GROWTH_TEAM_FEATURES', 'Shared inbox, team analytics'),
            'support' => env('BILLING_REAGAN_GROWTH_SUPPORT', 'Priority chat + onboarding call'),
            'credit_rollover' => env('BILLING_REAGAN_GROWTH_CREDIT_ROLLOVER', 'Up to 2,000'),
            'trial_days' => (int) env('BILLING_REAGAN_GROWTH_TRIAL_DAYS', 14),
            'popular' => filter_var(env('BILLING_REAGAN_GROWTH_POPULAR', true), FILTER_VALIDATE_BOOL),
            'features' => [
                '3 LinkedIn seats included',
                '5,000 monthly credits',
                '10 active signal agents',
                'Standard + deep-research personalization',
                'Shared inbox + team analytics',
                'Credit rollover up to 2,000',
            ],
            'stripe' => [
                'product_id' => env('BILLING_REAGAN_GROWTH_PRODUCT_ID'),
                'price_id' => env('BILLING_REAGAN_GROWTH_PRICE_ID'),
            ],
        ],
        [
            'code' => env('BILLING_REAGAN_SCALE_CODE', 'SCALE'),
            'name' => env('BILLING_REAGAN_SCALE_NAME', 'Scale'),
            'price' => (float) env('BILLING_REAGAN_SCALE_PRICE', 599),
            'interval' => env('BILLING_REAGAN_SCALE_INTERVAL', 'month'),
            'annual_monthly_price' => (float) env('BILLING_REAGAN_SCALE_ANNUAL_MONTHLY_PRICE', 479),
            'annual_price' => (float) env('BILLING_REAGAN_SCALE_ANNUAL_PRICE', 5748),
            'seats_included' => (int) env('BILLING_REAGAN_SCALE_SEATS_INCLUDED', 10),
            'additional_seat_price' => (float) env('BILLING_REAGAN_SCALE_ADDITIONAL_SEAT_PRICE', 39),
            'monthly_credits' => (int) env('BILLING_REAGAN_SCALE_MONTHLY_CREDITS', 15000),
            'daily_connection_limit_per_seat' => (int) env('BILLING_REAGAN_SCALE_DAILY_CONNECTION_LIMIT', 25),
            'daily_message_limit_per_seat' => (int) env('BILLING_REAGAN_SCALE_DAILY_MESSAGE_LIMIT', 50),
            'signal_agents_active' => env('BILLING_REAGAN_SCALE_SIGNAL_AGENTS_ACTIVE', 'unlimited'),
            'ai_personalization' => env('BILLING_REAGAN_SCALE_AI_PERSONALIZATION', 'Full AI SDR + custom tone'),
            'team_features' => env('BILLING_REAGAN_SCALE_TEAM_FEATURES', 'White-label, client workspaces, permissions'),
            'support' => env('BILLING_REAGAN_SCALE_SUPPORT', 'Dedicated CSM'),
            'credit_rollover' => env('BILLING_REAGAN_SCALE_CREDIT_ROLLOVER', 'Up to 5,000'),
            'trial_days' => (int) env('BILLING_REAGAN_SCALE_TRIAL_DAYS', 14),
            'popular' => filter_var(env('BILLING_REAGAN_SCALE_POPULAR', false), FILTER_VALIDATE_BOOL),
            'features' => [
                '10 LinkedIn seats included',
                '15,000 monthly credits',
                'Unlimited active signal agents',
                'Full AI SDR + custom tone controls',
                'White-label + client workspaces',
                'Credit rollover up to 5,000',
            ],
            'stripe' => [
                'product_id' => env('BILLING_REAGAN_SCALE_PRODUCT_ID'),
                'price_id' => env('BILLING_REAGAN_SCALE_PRICE_ID'),
            ],
        ],
    ],
    'superwriter' => [
        [
            'code' => env('BILLING_SUPERWRITER_BASIC_CODE', 'BASIC'),
            'name' => env('BILLING_SUPERWRITER_BASIC_NAME', 'Basic'),
            'price' => (float) env('BILLING_SUPERWRITER_BASIC_PRICE', 49),
            'interval' => env('BILLING_SUPERWRITER_BASIC_INTERVAL', 'month'),
            'monthly_credits' => (int) env('BILLING_SUPERWRITER_BASIC_MONTHLY_CREDITS', 1500),
            'stripe' => [
                'product_id' => env('BILLING_SUPERWRITER_BASIC_PRODUCT_ID', env('STRIPE_BASIC_PRODUCT_ID')),
                'price_id' => env('BILLING_SUPERWRITER_BASIC_PRICE_ID', env('STRIPE_BASIC_PRICE_ID')),
            ],
        ],
        [
            'code' => env('BILLING_SUPERWRITER_PRO_CODE', 'PRO'),
            'name' => env('BILLING_SUPERWRITER_PRO_NAME', 'Pro'),
            'price' => (float) env('BILLING_SUPERWRITER_PRO_PRICE', 99),
            'interval' => env('BILLING_SUPERWRITER_PRO_INTERVAL', 'month'),
            'monthly_credits' => (int) env('BILLING_SUPERWRITER_PRO_MONTHLY_CREDITS', 5000),
            'stripe' => [
                'product_id' => env('BILLING_SUPERWRITER_PRO_PRODUCT_ID', env('STRIPE_PRO_PRODUCT_ID')),
                'price_id' => env('BILLING_SUPERWRITER_PRO_PRICE_ID', env('STRIPE_PRO_PRICE_ID')),
            ],
        ],
        [
            'code' => env('BILLING_SUPERWRITER_ENTERPRISE_CODE', 'ENTERPRISE'),
            'name' => env('BILLING_SUPERWRITER_ENTERPRISE_NAME', 'Enterprise'),
            'price' => (float) env('BILLING_SUPERWRITER_ENTERPRISE_PRICE', 399),
            'interval' => env('BILLING_SUPERWRITER_ENTERPRISE_INTERVAL', 'month'),
            'monthly_credits' => (int) env('BILLING_SUPERWRITER_ENTERPRISE_MONTHLY_CREDITS', 15000),
            'stripe' => [
                'product_id' => env('BILLING_SUPERWRITER_ENTERPRISE_PRODUCT_ID', env('STRIPE_ENTERPRISE_PRODUCT_ID')),
                'price_id' => env('BILLING_SUPERWRITER_ENTERPRISE_PRICE_ID', env('STRIPE_ENTERPRISE_PRICE_ID')),
            ],
        ],
    ],
];

$activeBillingPlans = $billingPlanGroups[$billingPlansGroup] ?? $billingPlanGroups['reagan'];

$activeBillingPriceMap = [];
foreach ($activeBillingPlans as $planConfig) {
    $planCode = (string) ($planConfig['code'] ?? '');
    $planPriceId = (string) ($planConfig['stripe']['price_id'] ?? '');
    if ($planCode !== '' && $planPriceId !== '') {
        $activeBillingPriceMap[$planCode] = $planPriceId;
    }
}

return [
    /*
    |--------------------------------------------------------------------------
    | Billing Driver
    |--------------------------------------------------------------------------
    |
    | The billing driver to use for payment processing. Currently supports
    | 'stripe' with more drivers coming in future releases.
    |
    */
    'driver' => env('BILLING_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Currency & Statement Descriptor
    |--------------------------------------------------------------------------
    |
    | Default currency for all transactions and the descriptor that appears
    | on customer credit card statements.
    |
    */
    'currency' => env('BILLING_CURRENCY', 'usd'),
    'statement_descriptor' => env('BILLING_DESCRIPTOR', null),

    /*
    |--------------------------------------------------------------------------
    | Stripe Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Stripe integration including API keys, webhooks, and
    | price/meter mappings for plans and usage-based billing.
    |
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
         | Map your plan codes to Stripe Price IDs
         */
        'price_map' => $activeBillingPriceMap,

        /*
         | Map metric keys to Stripe metered Price IDs
         */
        'meter_map' => [
            // 'API_CALLS' => 'price_metered_api',
            // 'SMS_SENT' => 'price_metered_sms',
            // 'STORAGE_GB' => 'price_metered_storage',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credits System
    |--------------------------------------------------------------------------
    |
    | Configuration for the built-in credits ledger system including
    | auto-refill functionality.
    |
    */
    'credits' => [
        'enabled' => true,
        'enforce' => env('BILLING_CREDITS_ENFORCE', false),

        'pool_order' => ['monthly', 'rollover', 'purchased'],

        'action_costs' => [
            'lead.discovered' => 1,
            'lead.discovered.bulk_import' => 1,
            'enrichment.email' => 3,
            'enrichment.email.not_found' => 0,
            'enrichment.phone' => 8,
            'enrichment.phone.not_found' => 0,
            'enrichment.batch.email' => 3,
            'ai.message.standard' => 1,
            'ai.message.deep_research' => 3,
            'ai.reply.classification' => 2,
            'ai.copilot.query' => 1,
            'export.csv' => 1,
            'export.crm_push' => 1,
        ],

        'tiers' => [
            'starter' => [
                'monthly_credits' => 1500,
                'rollover_cap' => 0,
            ],
            'growth' => [
                'monthly_credits' => 5000,
                'rollover_cap' => 2000,
            ],
            'scale' => [
                'monthly_credits' => 15000,
                'rollover_cap' => 5000,
            ],
        ],

        'top_up_packs' => [
            [
                'name' => 'Starter Pack',
                'credits' => 500,
                'price_cents' => 4000,
                'stripe_price_id' => env('BILLING_TOPUP_STARTER_PRICE_ID'),
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth Pack',
                'credits' => 1000,
                'price_cents' => 7000,
                'stripe_price_id' => env('BILLING_TOPUP_GROWTH_PRICE_ID'),
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Scale Pack',
                'credits' => 2500,
                'price_cents' => 15000,
                'stripe_price_id' => env('BILLING_TOPUP_SCALE_PRICE_ID'),
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Power Pack',
                'credits' => 5000,
                'price_cents' => 27500,
                'stripe_price_id' => env('BILLING_TOPUP_POWER_PRICE_ID'),
                'is_active' => true,
                'sort_order' => 4,
            ],
        ],

        'refill' => [
            'auto_refill' => env('BILLING_AUTO_REFILL', true),
            'threshold' => env('BILLING_REFILL_THRESHOLD', 50),
            'top_up' => env('BILLING_REFILL_AMOUNT', 200),
            'price_code' => env('BILLING_REFILL_PRICE', 'CREDITS_TOPUP'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Listeners
    |--------------------------------------------------------------------------
    |
    | Map billing events to your custom listeners. Package events are
    | dispatched automatically; add your own listeners here.
    |
    */
    'listeners' => [
        \Vendor\LaravelBilling\Events\PaymentFailed::class => [
            // \App\Listeners\NotifySlack::class,
            // \App\Listeners\SendPaymentFailureEmail::class,
        ],
        \Vendor\LaravelBilling\Events\PaymentSucceeded::class => [
            // \App\Listeners\SendPaymentReceipt::class,
        ],
        \Vendor\LaravelBilling\Events\SubscriptionCreated::class => [
            // \App\Listeners\NotifyUserOfSubscription::class,
        ],
        \Vendor\LaravelBilling\Events\CreditsConsumed::class => [
            // \App\Listeners\CheckAutoRefillThreshold::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Enable multi-tenant isolation for billing data. When enabled, all
    | package tables will include a tenant_id column.
    |
    */
    'tenancy' => [
        'enabled' => env('BILLING_TENANCY', false),
        'tenant_key' => 'tenant_id',
    ],

    'plans_group' => $billingPlansGroup,
    'plans_groups' => $billingPlanGroups,

    /*
    |--------------------------------------------------------------------------
    | Plans Definition (Optional)
    |--------------------------------------------------------------------------
    |
    | Define your subscription plans here to enable automatic syncing to your
    | application's plans table and optionally to Stripe Products/Prices.
    |
    | Fields:
    | - code (string, required): unique code used for identification
    | - name (string, required)
    | - price (int|float, required): amount in USD dollars
    | - interval (string, required): 'month' or 'year'
    | - monthly_credits (int, optional): credit allowance per month
    | - stripe (array, optional):
    |     - product_id (string|null)
    |     - price_id (string|null)
    |
    */
    'plans' => $activeBillingPlans,

    /*
    |--------------------------------------------------------------------------
    | Sync API Security
    |--------------------------------------------------------------------------
    |
    | When calling the optional HTTP sync endpoint, requests must provide the
    | X-Billing-Sync-Token header that matches this token.
    |
    */
    'sync' => [
        'token' => env('BILLING_SYNC_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coupons & Promotion Codes (Optional)
    |--------------------------------------------------------------------------
    |
    | Define coupons to be synced to Stripe. Each item may include either a
    | percent_off OR amount_off+currency. These entries are used by the
    | CouponSyncService and the billing:sync-coupons command.
    |
    | Fields:
    | - id (string|null)           Optional explicit coupon id (e.g., WELCOME10)
    | - code (string)              Promotion code shown to users (e.g., WELCOME10)
    | - percent_off (int|null)     Percent discount (e.g., 10)
    | - amount_off (int|null)      Fixed discount in cents
    | - currency (string|null)     Required if amount_off is set (e.g., 'usd')
    | - duration (string)          'once' | 'repeating' | 'forever'
    | - duration_in_months (int)   Required for 'repeating'
    | - max_redemptions (int|null)
    | - redeem_by (int|null)       Unix timestamp
    | - active (bool)              Promotion code active flag
    */
    'coupons' => [
        [
            'id' => 'FREE100',
            'code' => 'FREE100',
            'percent_off' => 100,
            'duration' => 'once',
            'max_redemptions' => 1000,
            'active' => true,
        ],
        [
            'id' => 'FULLFREE',
            'code' => 'FULLFREE',
            'percent_off' => 100,
            'duration' => 'once',
            'active' => true,
        ],
        [
            'id' => 'TRYFREE',
            'code' => 'TRYFREE',
            'percent_off' => 100,
            'duration' => 'once',
            'max_redemptions' => 100,
            'active' => true,
        ],
    ],
];
