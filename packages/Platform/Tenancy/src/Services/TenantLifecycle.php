<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Exceptions\InvalidTenantTransitionException;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-013. The single, explicit owner of the Ready<->Suspended
 * transition - deliberately NOT scattered as `$tenant->status = ...;
 * $tenant->save();` calls inside controllers, matching the pattern
 * `TenantProvisioner` already established for the provisioning state
 * machine. Only these two transitions exist here:
 *
 *   Ready      -> Suspended   (suspend())
 *   Suspended  -> Ready       (reactivate())
 *
 * Deliberately does NOT touch Pending/Provisioning/Failed (those remain
 * TenantProvisioner's own territory) and does NOT implement Deleting/
 * Deleted - TASK-ARCH-013 explicitly excludes tenant deletion, which has
 * its own unresolved backup/export design questions (see
 * docs/architecture/provisioning.md).
 *
 * Reusable outside the HTTP layer by design (constructor-free, no request/
 * session dependency) - `Platform\Admin\Http\Controllers\TenantController`
 * is its only caller today, but a future billing-driven automation
 * (subscription delinquent -> suspend(), payment recovered ->
 * reactivate()) can call this exact same service with zero changes here.
 * See DECISION_LOG.md for the specific decision record.
 *
 * Suspension/reactivation is PURE STATUS METADATA - a plain central
 * `tenants.status` write. Neither method here ever touches a tenant's own
 * database (no `$tenant->run()`, no `TenantProvisioner` call) - the
 * tenant's commerce data, and the tenant database itself, are completely
 * untouched by either transition. This is what makes reactivation safe to
 * describe as "immediate, no reprovisioning" (task requirement #7): there
 * is nothing to reprovision, because nothing about the tenant's own
 * database was ever modified when it was suspended in the first place.
 */
class TenantLifecycle
{
    public function suspend(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Ready) {
            throw new InvalidTenantTransitionException(
                "Cannot suspend tenant [{$tenant->getTenantKey()}] from status [{$tenant->status->value}] - only a Ready tenant can be suspended."
            );
        }

        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    }

    public function reactivate(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Suspended) {
            throw new InvalidTenantTransitionException(
                "Cannot reactivate tenant [{$tenant->getTenantKey()}] from status [{$tenant->status->value}] - only a Suspended tenant can be reactivated."
            );
        }

        $tenant->forceFill(['status' => TenantStatus::Ready])->save();
    }
}
