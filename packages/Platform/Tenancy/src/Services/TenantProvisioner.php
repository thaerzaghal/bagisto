<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use RuntimeException;
use Throwable;

/**
 * Explicit, observable, retryable tenant provisioning - see
 * docs/architecture/provisioning.md for the full design this implements.
 *
 * Deliberately NOT queued/async yet (TASK-ARCH-002 scope: "may be a service +
 * artisan command for now"). Deliberately NOT backed by a step-level audit
 * table (tenant_provisioning_events) yet - status + last_error on the tenant
 * row is the whole observability surface for now; see the class-level note
 * on ensureSeeded() for the one place that tradeoff is not fully idempotent.
 */
class TenantProvisioner
{
    /**
     * Provision (or resume provisioning) a tenant. Safe to call multiple
     * times: a READY tenant is a no-op, a PENDING/PROVISIONING/FAILED tenant
     * (re)runs the remaining steps, each of which checks its own completion
     * state before acting.
     */
    public function provision(Tenant $tenant): void
    {
        if ($tenant->status === TenantStatus::Ready) {
            return;
        }

        if (! $tenant->status->isProvisionable()) {
            throw new RuntimeException(
                "Tenant [{$tenant->getTenantKey()}] cannot be provisioned from status [{$tenant->status->value}]."
            );
        }

        $tenant->forceFill(['status' => TenantStatus::Provisioning, 'last_error' => null])->save();

        try {
            $this->ensureDatabaseCreated($tenant);
            $this->ensureMigrated($tenant);
            $this->ensureSeeded($tenant);

            $tenant->forceFill(['status' => TenantStatus::Ready])->save();
        } catch (Throwable $e) {
            $tenant->forceFill(['status' => TenantStatus::Failed, 'last_error' => $e->getMessage()])->save();

            throw $e;
        }
    }

    /**
     * Step 1: create the physical tenant database (+ scoped DB user, via
     * PermissionControlledMySQLDatabaseManager - see config/tenancy.php and
     * docs/architecture/provisioning.md "Database provisioning credentials").
     * Idempotent: skips if the database already exists (e.g. resuming after
     * a crash that happened during a later step).
     */
    protected function ensureDatabaseCreated(Tenant $tenant): void
    {
        $manager = $tenant->database()->manager();

        if ($manager->databaseExists($tenant->database()->getName())) {
            return;
        }

        $tenant->database()->makeCredentials();
        $manager->createDatabase($tenant);
    }

    /**
     * Step 2: run every Bagisto package migration (discovered dynamically,
     * not a maintained list - see docs/architecture/provisioning.md "Bagisto
     * tenant migration strategy") plus anything under database/migrations/tenant,
     * against this tenant's database only. Idempotent via Laravel's own
     * migrations table (already-run migrations are skipped automatically).
     */
    protected function ensureMigrated(Tenant $tenant): void
    {
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
            '--path' => app('migrator')->paths(),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Step 3: seed Bagisto's own default data (channel, locale, currency,
     * attribute family, admin role/user, ...) via the same DatabaseSeeder
     * class Bagisto's own Installer uses - NOT via the Installer command
     * itself (see RISK_REGISTER.md R11/ADR-001).
     *
     * Idempotency caveat (documented, not hidden): this guards on the
     * `admins` table already having a row, which is seeded 9th of the 10
     * sub-seeders BagistoDatabaseSeeder runs (Attribute, Category, Core,
     * Customer, CMS, Inventory, SocialLogin, Shop, User, RMA). A crash
     * between User and RMA would look "seeded" on retry and skip RMA's 3
     * rows. This is accepted as "idempotent where practical" for this
     * foundation task; closing the gap completely needs the step-level
     * tenant_provisioning_events audit table, deferred to Phase 3/4.
     */
    protected function ensureSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (Schema::hasTable('admins') && DB::table('admins')->count() > 0) {
                return;
            }

            Artisan::call('db:seed', ['--force' => true]);
        });
    }
}
