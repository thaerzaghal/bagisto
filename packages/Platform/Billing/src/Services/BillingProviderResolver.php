<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Platform\Billing\Adapters\StripePaymentProvider;
use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\Exceptions\UnknownBillingProviderException;

/**
 * TASK-ARCH-018 (task section 3). The ONE place `config('platform-billing.provider')`
 * is read to decide which `Platform\Billing\Contracts\PaymentProvider`
 * implementation to use - deliberately a single `match` expression here,
 * not scattered `if ($provider === 'stripe')` checks anywhere else in this
 * codebase. `Platform\Billing\Providers\BillingServiceProvider` binds
 * `PaymentProvider::class` to `fn () => app(BillingProviderResolver::class)->resolve()`,
 * so any code needing the configured provider just type-hints
 * `PaymentProvider` and never calls this class directly.
 *
 * A future adapter plugs in by adding exactly one new `match` arm here -
 * e.g. `'bank_x' => app(BankXPaymentProvider::class)` - nothing else in
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
}
