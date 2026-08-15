<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-019 (task section 11). Thrown by any `Platform\Billing\
 * Contracts\WebhookVerifier` implementation when signature verification
 * fails. `Platform\Billing\Http\Controllers\StripeWebhookController`
 * catches this and returns a clean 4xx with zero Payment/Subscription
 * mutation and no `BillingProviderEvent` success record - see that
 * controller's own docblock.
 */
class InvalidWebhookSignatureException extends RuntimeException
{
    //
}
