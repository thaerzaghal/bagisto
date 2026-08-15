<?php

declare(strict_types=1);

namespace Platform\Billing\DTOs;

/**
 * TASK-ARCH-019 (task section 6/26). Returned by
 * `Platform\Billing\Contracts\PaymentProvider::createCheckout()` - the
 * provider's own hosted payment-collection page (Stripe Checkout in
 * "payment" mode for this adapter, task section 6: chosen specifically to
 * avoid creating any Stripe-owned subscription lifecycle object -
 * `Platform\Subscriptions` remains the sole subscription source of truth).
 * `redirectUrl` is the only thing our tenant-facing controller needs -
 * the browser is sent there directly; our own UI never collects card
 * data.
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $redirectUrl,
    ) {}
}
