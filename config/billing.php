<?php

declare(strict_types=1);

use App\Enums\UsageDimension;

return [
    /*
    |--------------------------------------------------------------------------
    | Enforcement master switch
    |--------------------------------------------------------------------------
    | When false, metered capabilities are NOT checked or charged (the AI layer
    | behaves exactly as before). Turn on in production once plans are seeded.
    | Default off so existing behavior/tests are unaffected.
    */
    'enforce' => (bool) env('BILLING_ENFORCE', false),

    // Raw env value of the switch above (config-time, config:cache safe):
    // NULL = not set in the environment. Displayed by Sanad Admin as the
    // effective source; the switch itself stays environment-governed.
    'overrides' => [
        'enforce' => env('BILLING_ENFORCE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic trial / default plan on onboarding
    |--------------------------------------------------------------------------
    | When enabled, a brand-new subscriber is auto-assigned the default plan
    | (by slug) on first contact. Safe to disable. A subscriber that already has
    | a subscription is never re-assigned, so no repeated free trials.
    */
    'auto_trial' => (bool) env('BILLING_AUTO_TRIAL', true),
    'default_plan_slug' => env('BILLING_DEFAULT_PLAN', 'free'),

    /*
    |--------------------------------------------------------------------------
    | Plan pricing currency (display + storage default)
    |--------------------------------------------------------------------------
    | The currency new plans default to (ISO 4217, e.g. USD). Prices are DATA
    | managed from the Plans page; this only sets the default a fresh plan/seed
    | starts with. Distinct from cost_currency (service-cost accounting) below.
    */
    'currency' => env('BILLING_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Subscriber-facing messages (Arabic) — separable response layer
    |--------------------------------------------------------------------------
    | Kept out of the enforcement logic so a future WhatsApp upgrade/payment
    | flow can extend them. {upgrade} is replaced with upgrade_url when set.
    */
    'limit_reached_message' => env(
        'BILLING_LIMIT_MESSAGE',
        'لقد وصلت إلى الحدّ المتاح من ردود سَنَد ضمن باقتك الحالية. لترقية باقتك: {upgrade}',
    ),
    'feature_disabled_message' => env(
        'BILLING_DISABLED_MESSAGE',
        'هذه الميزة غير متاحة ضمن باقتك الحالية.',
    ),
    // Placeholder only — real signed checkout link comes in a later phase.
    'upgrade_url' => env('BILLING_UPGRADE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Cost accounting rates (FOUNDATION ONLY — configurable, not hard-coded)
    |--------------------------------------------------------------------------
    | Per-unit service cost per usage dimension, used to record cost on the
    | ledger for revenue − cost = margin. Defaults are 0 so nothing is invented;
    | set real, current rates via env when known (AI/WhatsApp prices change).
    | unit: "event" (per quantity) or "token" (per 1K tokens) — see UsageCostCalculator.
    */
    'cost_currency' => env('BILLING_COST_CURRENCY', 'USD'),
    'cost_rates' => [
        UsageDimension::AiReply->value => ['unit' => 'event', 'rate' => (float) env('COST_AI_REPLY', 0)],
        UsageDimension::AiInputTokens->value => ['unit' => 'token_k', 'rate' => (float) env('COST_AI_INPUT_PER_1K', 0)],
        UsageDimension::AiOutputTokens->value => ['unit' => 'token_k', 'rate' => (float) env('COST_AI_OUTPUT_PER_1K', 0)],
        UsageDimension::WhatsAppInbound->value => ['unit' => 'event', 'rate' => (float) env('COST_WA_INBOUND', 0)],
        UsageDimension::WhatsAppOutbound->value => ['unit' => 'event', 'rate' => (float) env('COST_WA_OUTBOUND', 0)],
    ],
];
