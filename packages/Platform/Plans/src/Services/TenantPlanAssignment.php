<?php

declare(strict_types=1);

namespace Platform\Plans\Services;

use Platform\Plans\Exceptions\InactivePlanAssignmentException;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-015. The one explicit entry point for changing which plan a
 * tenant is on - mirrors Platform\Tenancy\Services\TenantLifecycle's
 * shape exactly (a plain, request/session-independent service; no
 * controller ever sets `$tenant->plan_id` directly). Reused by both
 * Platform\Admin\Http\Controllers\TenantController::changePlan() (manual,
 * platform-operator-initiated reassignment) and
 * Platform\Tenancy\Services\TenantProvisioner::ensureDefaultPlanAssigned()
 * (automatic default-plan assignment at provisioning time) - a single
 * source of truth for "is this plan assignable" (currently: must be
 * active), not duplicated logic in two places.
 *
 * A PURE central `tenants` row write - `Plan`/`Tenant` both resolve
 * against the central connection regardless of active tenant context (see
 * Plan's own CentralConnection docblock), and this method never calls
 * `$tenant->run()` or touches the tenant's own database. This is what
 * makes a plan change take effect immediately: Platform\Plans\Services\
 * TenantEntitlements/TenantLimits always read `tenants.plan_id` and
 * `plan_features` fresh via a plain Eloquent query on every call - there
 * is no cache layer over this data to invalidate, and no per-tenant-
 * request bootstrap cycle to wait for (unlike Stancl\Tenancy\
 * Bootstrappers\CacheTenancyBootstrapper's tenant-tagged cache, which
 * this domain never uses). See tests/Feature/Platform/
 * PlatformPlanManagementTest.php for the live proof (a downgrade/upgrade
 * is observed by the very next enforcement check, no worker restart, no
 * cache clear).
 */
class TenantPlanAssignment
{
    public function assign(Tenant $tenant, Plan $plan): void
    {
        if (! $plan->is_active) {
            throw new InactivePlanAssignmentException($tenant->getTenantKey(), $plan->code);
        }

        $tenant->forceFill(['plan_id' => $plan->id])->save();
    }
}
