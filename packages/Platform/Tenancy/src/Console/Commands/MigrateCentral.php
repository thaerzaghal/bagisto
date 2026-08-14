<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * TASK-ARCH-008 cleanup round (RISK_REGISTER.md R30). The one supported,
 * documented way to run central/platform migrations - always scoped to
 * `--path=database/migrations`, never the package-registered paths every
 * Webkul provider's loadMigrationsFrom() adds (those are for TENANT
 * provisioning only, via `tenants:migrate`/`tenant:provision`).
 *
 * Bare `php artisan migrate` remains technically callable (this project
 * cannot and does not override Laravel's own command) but is UNSUPPORTED
 * for this project - see this command's own guidance below and
 * Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema, which
 * fails it loudly as a defense-in-depth safety net if anyone runs it
 * anyway. Deployment procedure: run `php artisan platform:migrate:central`
 * once per environment (idempotent - Laravel's own migrations table
 * skips already-run migrations) before `php artisan platform:plans:seed`
 * and before provisioning any tenant.
 */
class MigrateCentral extends Command
{
    protected $signature = 'platform:migrate:central {--pretend : Dump the SQL queries that would be run}';

    protected $description = 'Run ONLY central/platform migrations (database/migrations root) - never Bagisto\'s tenant-schema migrations. The supported replacement for bare `php artisan migrate` in this project.';

    public function handle(): int
    {
        $this->call('migrate', [
            '--path' => 'database/migrations',
            '--force' => true,
            '--pretend' => (bool) $this->option('pretend'),
        ]);

        return self::SUCCESS;
    }
}
