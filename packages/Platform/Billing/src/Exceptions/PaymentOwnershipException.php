<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-018 (task section 12/23). Thrown when a Subscription passed
 * to Platform\Billing\Services\BillingService does not belong to the
 * given Tenant - the one structural check that makes "Tenant A cannot
 * create/use a Payment belonging to Tenant B" true by construction rather
 * than by convention at every call site.
 */
class PaymentOwnershipException extends RuntimeException
{
    public function __construct(public readonly string $tenantId, public readonly int $subscriptionId)
    {
        parent::__construct("Subscription [{$subscriptionId}] does not belong to tenant [{$tenantId}].");
    }
}
