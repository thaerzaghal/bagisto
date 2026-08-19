<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use Platform\Plans\Exceptions\Concerns\RendersAsEntitlementFailure;
use RuntimeException;

/**
 * TASK-ARCH-008. Thrown by TenantEntitlements::currentPlan() when a
 * tenant's `plan_id` is null - fail loud rather than silently resolving
 * "no plan" as "no entitlements at all" (which would be indistinguishable
 * from a genuinely misconfigured plan). Should only be reachable for a
 * tenant that hasn't finished provisioning (TenantProvisioner assigns the
 * default plan as a provisioning step) or was created bypassing
 * TenantProvisioner entirely.
 *
 * `Platform\Plans\Http\Controllers\Admin\MyPlanController::index()`
 * still catches this locally and never lets it reach HTTP rendering at
 * all - `RendersAsEntitlementFailure`'s `render()` (TASK-MVP-013) is only
 * ever invoked for a caller that lets this propagate uncaught, unchanged
 * from before that trait existed.
 */
class NoPlanAssignedException extends RuntimeException implements EntitlementException
{
    use RendersAsEntitlementFailure;
}
