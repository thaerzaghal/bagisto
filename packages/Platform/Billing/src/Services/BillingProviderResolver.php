<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Platform\Billing\Adapters\StripePaymentProvider;
use Platform\Billing\Adapters\StripeWebhookVerifier;
use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\Contracts\WebhookVerifier;
use Platform\Billing\Exceptions\UnknownBillingProviderException;

/**
 * TASK-ARCH-018/019 (task section 3). The ONE place `config('platform-billing.provider')`
 * is read to decide which `Platform\Billing\Contracts\PaymentProvider`/
 * `WebhookVerifier` implementation to use - deliberately a single `match`
 * expression per contract here, not scattered `if ($provider === 'stripe')`
 * checks anywhere else in this codebase. `Platform\Billing\Providers\
 * BillingServiceProvider` binds both contracts to closures that resolve
 * through this class, so any code needing the configured provider just
 * type-hints the contract and never calls this class directly.
 *
 * A future adapter plugs in by adding exactly one new `match` arm to EACH
 * method it needs (a provider with no inbound webhook concept at all -
 * task section 26 - simply never needs a `resolveWebhookVerifier()` arm)
 * - e.g. `'bank_x' => app(BankXPaymentProvider::class)` - nothing else in
 * this resolver, or anywhere else in the codebase, needs to change.
 */
class BillingProviderResolver
{
    public function resolve(): PaymentProvider
    {
        $provider = (string) config('platform-billing.provider');

        return match ($provider) {
            'stripe' => app(StripePaymentProvider::class),
            default => throw new UnknownBillingProviderException($provider),
        };
    }

    public function resolveWebhookVerifier(): WebhookVerifier
    {
        $provider = (string) config('platform-billing.provider');

        return match ($provider) {
            'stripe' => app(StripeWebhookVerifier::class),
            default => throw new UnknownBillingProviderException($provider),
        };
    }
}
