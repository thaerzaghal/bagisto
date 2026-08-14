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
 * - plan_id      (nullable, FK -> plans.id, TASK-ARCH-008) the tenant's
 *                current effective SaaS plan. Assigned during provisioning
 *                by Platform\Tenancy\Services\TenantProvisioner::
 *                ensureDefaultPlanAssigned(); resolved via
 *                Platform\Plans\Services\TenantEntitlements, never read
 *                directly by other modules. See DECISION_LOG.md for why
 *                this lives directly on `tenants` rather than a separate
 *                assignment table, and how it evolves once Phase 10
 *                subscriptions exist.
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

    /**
     * TASK-ARCH-008 FINDING: `Stancl\Tenancy\Database\Concerns\
     * HasDataColumn` (an alias for `Stancl\VirtualColumn\VirtualColumn`)
     * silently redirects EVERY attribute except whatever
     * getCustomColumns() names into the `data` JSON blob, on every
     * saving/creating/updating event - its own default is `['id']` only.
     * Nothing in this codebase had ever overridden it, so `status` and
     * `last_error` (real, indexed, migrated columns since TASK-ARCH-002)
     * have been silently write-through-JSON-only this whole engagement:
     * `$tenant->status` always read/wrote correctly (VirtualColumn
     * transparently decodes `data` back into normal attributes on
     * retrieval, so every Eloquent-attribute-level access "worked"), but
     * the REAL `status`/`last_error` COLUMNS have sat stale since each
     * row's creation - invisible until TASK-ARCH-008 ran the first ever
     * raw SQL query against `tenants` in this project's history
     * (`DB::connection('mysql')->table('tenants')->first()`) and found
     * `status: 'pending'` in the real column next to `data: {"status":
     * "ready", ...}`. No production or test code was found to filter
     * tenants by these columns at the SQL level (confirmed by search), so
     * this had zero prior functional impact - but it would have silently
     * broken any future `Tenant::where('status', ...)`, admin/reporting
     * query, or (most concretely) the `plan_id` foreign key this task
     * adds: without this fix, `plan_id` would ALWAYS be NULL in the real
     * column regardless of what Eloquent shows, making its FK constraint
     * inert and any `WHERE plan_id = ?` query silently wrong. Fixed here,
     * not worked around - `status`/`last_error`/`plan_id` are genuine,
     * intentional real columns and belong in getCustomColumns(). The
     * `tenancy_*`-prefixed internal keys (db name/username/password, via
     * HasInternalKeys) are NOT added here - those are deliberately
     * data-blob-only by stancl's own design, unaffected by this fix. See
     * RISK_REGISTER.md R31.
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'status', 'last_error', 'plan_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
