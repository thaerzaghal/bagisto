<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-019 (task section 5). Thrown by
 * `Platform\Billing\Services\CheckoutService` when the `Plan` a
 * `PlanPrice` belongs to is itself inactive - a `PlanPrice` can be active
 * while its parent `Plan` has since been deactivated (the two flags are
 * independent), and checkout must refuse both, not just the price.
 */
class InactivePlanException extends RuntimeException
{
    public function __construct(public readonly int $planId)
    {
        parent::__construct("Plan [{$planId}] is inactive and cannot be checked out against.");
    }
}
