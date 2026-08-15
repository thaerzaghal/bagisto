<?php

declare(strict_types=1);

namespace Platform\Billing\Contracts;

use Platform\Billing\DTOs\WebhookEvent;

/**
 * TASK-ARCH-019 (task section 26). A DELIBERATELY SEPARATE contract from
 * `PaymentProvider` - signature verification is not a concept every
 * future provider shares (a bank adapter might use a signed callback, a
 * polling mechanism, or server-to-server verification with a completely
 * different event format - task section 26's own examples), so it has no
 * business living on the shared `PaymentProvider` interface every
 * provider implements. Keeping it separate means a future provider that
 * has NO inbound webhook concept at all simply never implements this
 * interface, without `PaymentProvider` itself needing an unused/no-op
 * method.
 *
 * `Platform\Billing\Http\Controllers\StripeWebhookController` resolves
 * this contract the same way `Platform\Billing\Services\
 * BillingProviderResolver` resolves `PaymentProvider` - config-driven,
 * one provider at a time, never a hardcoded Stripe reference outside the
 * adapter itself.
 */
interface WebhookVerifier
{
    /**
     * Verify the inbound request's signature and parse it into a
     * provider-neutral event. MUST throw
     * `Platform\Billing\Exceptions\InvalidWebhookSignatureException` for
     * any signature failure - never returns a WebhookEvent for an
     * unverified payload, and never partially trusts an unverified body.
     */
    public function verify(string $payload, string $signatureHeader): WebhookEvent;
}
