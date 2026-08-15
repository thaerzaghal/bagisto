<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use Platform\Billing\Enums\PaymentStatus;
use RuntimeException;

/**
 * TASK-ARCH-018. Mirrors Platform\Subscriptions\Exceptions\
 * InvalidSubscriptionTransitionException exactly - thrown by
 * Platform\Billing\Services\PaymentLifecycle for any transition outside
 * its own explicit matrix (see that class's docblock).
 */
class InvalidPaymentTransitionException extends RuntimeException
{
    public function __construct(public readonly int $paymentId, public readonly PaymentStatus $from, public readonly string $attemptedTransition)
    {
        parent::__construct(
            "Payment [{$paymentId}] cannot transition from [{$from->value}] via [{$attemptedTransition}]."
        );
    }
}
