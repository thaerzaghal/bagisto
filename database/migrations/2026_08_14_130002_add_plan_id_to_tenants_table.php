<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-008. Implements the `tenants.plan_id` column already sketched
 * (as "proposed") in docs/architecture/database-per-tenant.md's Phase 0
 * central-schema draft. See DECISION_LOG.md for the architecture reasoning
 * behind putting plan_id directly on `tenants` (a fast, denormalized
 * "current effective plan" pointer) rather than a separate assignment
 * table, and how this evolves cleanly once Phase 10 builds real
 * `subscriptions` (source of truth becomes subscriptions.plan_id;
 * tenants.plan_id becomes a synced cache of the active subscription's
 * plan, not something that needs to be dropped/migrated away).
 *
 * Nullable: a tenant can technically exist for a brief window before
 * Platform\Tenancy\Services\TenantProvisioner::ensureDefaultPlanAssigned()
 * runs during provisioning. restrictOnDelete(): a plan referenced by any
 * tenant cannot be deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('last_error')->constrained('plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
