<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-015. Thrown by Platform\Plans\Services\TenantPlanAssignment::
 * assign() when the target plan is deactivated (`is_active = false`).
 * Deliberately NOT an EntitlementException - that marker is for tenant-
 * facing entitlement RESOLUTION failures (Platform\Enforcement's 422
 * renderer), not this platform-operator-side operational error. Caught
 * directly by Platform\Admin\Http\Controllers\TenantController, the same
 * pattern Platform\Tenancy\Exceptions\InvalidTenantTransitionException
 * already established for suspend()/reactivate().
 */
class InactivePlanAssignmentException extends RuntimeException
{
    public function __construct(string $tenantId, string $planCode)
    {
        parent::__construct(
            "Cannot assign plan [{$planCode}] to tenant [{$tenantId}] - the plan is not active."
        );
    }
}
