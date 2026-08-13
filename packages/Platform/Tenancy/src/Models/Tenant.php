<?php

declare(strict_types=1);

namespace Platform\Tenancy\Models;

use Platform\Tenancy\Enums\TenantStatus;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A SaaS tenant (merchant/shop) - NOT a Bagisto customer or admin. Lives
 * exclusively in the central database. Each tenant owns exactly one Bagisto
 * commerce database (see docs/architecture/database-per-tenant.md).
 *
 * Fields:
 * - id           (string, primary key) tenant identifier, also used to derive
 *                the physical tenant database name via DatabaseConfig.
 * - status       (string, cast to TenantStatus) provisioning lifecycle state.
 *                See docs/architecture/provisioning.md for the full state
 *                machine; TASK-ARCH-002 exercises Pending/Provisioning/Ready/
 *                Failed only.
 * - last_error   (nullable string) the exception message from the most
 *                recent failed provisioning attempt, for observability. Not
 *                a full audit trail (see RISK_REGISTER.md / DECISION_LOG.md
 *                on the deferred tenant_provisioning_events table) - just
 *                enough to answer "why did this tenant fail?" without
 *                grepping logs.
 * - data         (json, inherited from stancl's base Tenant) stores every
 *                other attribute not backed by a real column, including
 *                stancl's own internal db_name/db_username/db_password keys
 *                (see database()->makeCredentials()) - no schema change
 *                needed for those, per stancl's HasDataColumn mechanism.
 * - created_at / updated_at (inherited)
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
