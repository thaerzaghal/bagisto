<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-018 (task section 3/26). Thrown by
 * Platform\Billing\Services\BillingProviderResolver when
 * config('platform-billing.provider') does not match any registered
 * adapter - never falls back to a default silently. A future
 * BILLING_PROVIDER=bank_x plugs in by adding one case to that resolver's
 * match expression, nothing else.
 */
class UnknownBillingProviderException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("Unknown billing provider [{$provider}]. Check the BILLING_PROVIDER environment variable.");
    }
}
