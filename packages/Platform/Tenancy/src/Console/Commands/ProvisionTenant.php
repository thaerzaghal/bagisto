<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Throwable;

/**
 * php artisan tenant:provision {id} {--domain=}
 *
 * Creates the tenant record (if it doesn't exist - requires --domain) and
 * runs TenantProvisioner. Safe to re-run: an already-READY tenant no-ops,
 * a PENDING/FAILED tenant resumes from wherever it left off (see
 * TenantProvisioner::provision()).
 *
 * Not a queued job yet - TASK-ARCH-002 scope is "a service + artisan
 * command", not a full async signup pipeline. Wiring this behind a queue
 * worker is straightforward later (dispatch a job that just calls this
 * command, or calls the service directly) without changing this command's
 * contract.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision {id : Tenant identifier, also used to derive the tenant database name} {--domain= : Required only when creating a new tenant}';

    protected $description = 'Provision (or resume provisioning of) a tenant database.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $id = (string) $this->argument('id');

        $tenant = Tenant::find($id);

        if (! $tenant) {
            $domain = $this->option('domain');

            if (! $domain) {
                $this->error("Tenant [{$id}] does not exist yet. Pass --domain=... to create it.");

                return self::FAILURE;
            }

            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $domain]);

            $this->info("Created tenant [{$id}] with domain [{$domain}], status: pending.");
        }

        $this->info("Provisioning tenant [{$id}] (current status: {$tenant->status->value})...");

        try {
            $provisioner->provision($tenant);
        } catch (Throwable $e) {
            $this->error("Provisioning failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Tenant [{$id}] is now: {$tenant->fresh()->status->value}.");

        return self::SUCCESS;
    }
}
