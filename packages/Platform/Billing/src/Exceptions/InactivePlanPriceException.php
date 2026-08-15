<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-018 (task section 9). Thrown by
 * Platform\Billing\Services\BillingService when asked to create a Payment
 * against a PlanPrice whose `is_active` is false - mirrors
 * Platform\Plans\Exceptions\InactivePlanAssignmentException's exact
 * reasoning for the same "inactive means unselectable for anything NEW"
 * rule already established for Plan itself.
 */
class InactivePlanPriceException extends RuntimeException
{
    public function __construct(public readonly int $planPriceId)
    {
        parent::__construct("PlanPrice [{$planPriceId}] is inactive and cannot be used to create a new payment.");
    }
}
