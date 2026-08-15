<?php

declare(strict_types=1);

namespace Platform\Billing\DTOs;

use Platform\Billing\Enums\PaymentStatus;

/**
 * TASK-ARCH-018 (task section 15). The ONLY shape a
 * Platform\Billing\Contracts\PaymentProvider implementation may return -
 * no provider SDK type (e.g. \Stripe\PaymentIntent) is ever allowed to
 * cross the contract boundary. A future bank adapter returns exactly this
 * same, provider-neutral shape.
 */
final class PaymentResult
{
    public function __construct(
        public readonly string $providerReference,
        public readonly PaymentStatus $status,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {}
}
