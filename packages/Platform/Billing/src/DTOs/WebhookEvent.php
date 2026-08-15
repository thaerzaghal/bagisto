<?php

declare(strict_types=1);

namespace Platform\Billing\DTOs;

use Platform\Billing\Enums\PaymentStatus;

/**
 * TASK-ARCH-019. The only shape `Platform\Billing\Contracts\WebhookVerifier`
 * may return - no provider SDK type (e.g. `\Stripe\Event`) ever crosses
 * that boundary, mirroring `PaymentResult`'s own rule for `PaymentProvider`.
 *
 * `status`/`providerReference` are nullable together: an event type this
 * platform does not act on (task section 12, "handle only the minimum
 * Stripe events required") maps to `providerReference: null, status: null`
 * - `Platform\Billing\Services\WebhookEventProcessor` treats that as
 * "acknowledge safely, no mutation," never as a failure.
 *
 * `amountMinor`/`currency` are the provider's OWN reported values for this
 * event (Stripe Checkout Session's `amount_total`/`currency`) - compared
 * against the correlated `Payment`'s own stored snapshot before any
 * mutation is allowed (task section 17, amount/currency mismatch
 * detection).
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly ?string $providerReference = null,
        public readonly ?PaymentStatus $status = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}
}
