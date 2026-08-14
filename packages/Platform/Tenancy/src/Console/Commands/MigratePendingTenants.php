<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

/**
 * TASK-ARCH-010 (R33). The repair step for tenants provisioned before a
 * new tenant-scoped migration (database/migrations/tenant/*) was added -
 * `sessions` being the first real example. Deliberately NOT a seeder
 * re-run and does not touch any tenant's actual data: `TenantProvisioner::
 * remigrate()` is just Laravel's own idempotent migration runner, scoped
 * per tenant, safe to call on every tenant (READY or not) any number of
 * times - it can only ever apply migration files that haven't run yet for
 * that specific tenant database, never re-apply or duplicate anything
 * already recorded in that database's own `migrations` table.
 *
 * `--tenant=` may be repeated to target specific tenants; with no
 * `--tenant` option, every tenant in the central `tenants` table is
 * processed (a deliberate default, unlike destructive commands - running
 * pending, additive-only migrations against every tenant is the
 * intended, safe common case, not something requiring an explicit
 * "all" opt-in).
 */
class MigratePendingTenants extends Command
{
    protected $signature = 'platform:tenants:migrate-pending {--tenant=* : Specific tenant id(s); all tenants if omitted}';

    protected $description = 'Apply any tenant-scoped migration not yet run for existing tenants (idempotent, safe to rerun, safe for every tenant regardless of status).';

    public function handle(TenantProvisioner $provisioner): int
    {
        $ids = $this->option('tenant');

        $tenants = empty($ids)
            ? Tenant::all()
            : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->info("Migrating tenant [{$tenant->getTenantKey()}]...");
            $provisioner->remigrate($tenant);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
