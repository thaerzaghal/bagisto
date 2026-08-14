<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-007 (Phase 15, search isolation): Webkul\Product's own
 * `indexer:index {--type=*} {--mode=*}` (packages/Webkul/Product/src/Console/
 * Commands/Indexer.php) is a global command with no tenant awareness at
 * all - run bare (`php artisan indexer:index`), it executes centrally, no
 * tenant initialized. Since the central database carries none of Bagisto's
 * commerce tables (only platform/tenancy tables - see RISK_REGISTER.md R17/
 * TenantProvisioningTest.php), it fails loudly (table doesn't exist) rather
 * than silently reindexing across tenants - a safe, fail-closed default,
 * confirmed by reading TenantProvisioner::ensureMigrated() (app('migrator')
 * ->paths() structurally never includes the root migrations directory the
 * central DB's own commerce tables would need to exist). But there was
 * still no way to run it FOR a specific tenant at all.
 *
 * This wrapper is deliberately minimal - a single-tenant `tenant->run()`
 * wrapper around the existing command, mirroring ProvisionTenant.php's
 * shape - not a fleet-wide orchestrator (explicitly out of scope per this
 * task's instructions). Iterating it over every tenant, scheduling it, or
 * building a queue-backed fleet reindex pipeline is future work if a real
 * operational need for one arises.
 *
 * php artisan tenant:index {tenant} {--type=*} {--mode=*}
 */
class ReindexTenant extends Command
{
    protected $signature = 'tenant:index {tenant : Tenant identifier} {--type=* : Forwarded to indexer:index (inventory, price, flat, elastic)} {--mode=* : Forwarded to indexer:index (selective or full)}';

    protected $description = 'Run Bagisto\'s indexer:index command against one specific tenant\'s database.';

    public function handle(): int
    {
        $id = (string) $this->argument('tenant');

        $tenant = Tenant::find($id);

        if (! $tenant) {
            $this->error("Tenant [{$id}] does not exist.");

            return self::FAILURE;
        }

        $this->info("Reindexing tenant [{$id}]...");

        $exitCode = self::SUCCESS;

        $tenant->run(function () use (&$exitCode) {
            $exitCode = Artisan::call('indexer:index', [
                '--type' => $this->option('type'),
                '--mode' => $this->option('mode'),
            ]);

            $this->output->write(Artisan::output());
        });

        return $exitCode;
    }
}
