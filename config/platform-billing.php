<?php

declare(strict_types=1);

/**
 * TASK-ARCH-018. A dedicated file (unlike config/platform.php's single
 * "plans.default_code" entry) because billing has real config surface of
 * its own: provider selection plus per-provider credentials. Stripe is
 * this project's first REFERENCE adapter only (task section 0.B) - not a
 * production commitment; see docs/architecture/billing.md.
 *
 * Deliberately does NOT fail application boot if credentials are missing -
 * `Platform\Billing\Adapters\StripePaymentProvider`'s own constructor is
 * where a missing STRIPE_SECRET actually throws (Platform\Billing\
 * Exceptions\MissingProviderCredentialsException), the moment something
 * genuinely tries to resolve/use the provider - never merely because the
 * app booted with a blank .env value nobody is using yet.
 */
return [

    /**
     * Platform\Billing\Services\BillingProviderResolver reads this and
     * resolves to the matching PaymentProvider implementation. An
     * unrecognized value throws Platform\Billing\Exceptions\
     * UnknownBillingProviderException - never silently falls back to a
     * default provider.
     */
    'provider' => env('BILLING_PROVIDER', 'stripe'),

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),

        /**
         * Not read anywhere in TASK-ARCH-018 - no webhook endpoint exists
         * yet (deferred to TASK-ARCH-019). Present here now so
         * .env.example documents the full eventual shape in one place.
         */
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
