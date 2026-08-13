<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;

/**
 * php artisan platform:mark-installed
 *
 * Addresses RISK_REGISTER.md R14: Webkul\Installer\Http\Middleware\CanInstall
 * is registered as a GLOBAL middleware (bootstrap/app.php) and redirects
 * EVERY request - central or tenant - to /install unless storage/installed
 * exists (or its DB-based fallback check passes, which it never will here
 * since the central database deliberately never has Bagisto's `admins`
 * table - see docs/architecture/database-per-tenant.md).
 *
 * This does NOT weaken or remove that protection. CanInstall exists to stop
 * a single-tenant Bagisto install from being used before its own database is
 * set up - a legitimate concern for stock Bagisto. It does not apply to this
 * platform: tenant readiness is governed by Tenant::status (see
 * TenantProvisioner), not by Bagisto's own per-instance installed flag, and
 * the central app is never a Bagisto site in the first place. Since
 * CanInstall checks storage_path('installed') FIRST, before touching any
 * database, satisfying that one check is sufficient and requires zero
 * Bagisto core modification - not a bypass, just the deployment-time
 * equivalent of what Bagisto's own Installer wizard would do at the end of
 * its run, done once for the whole platform instead of never (because we
 * don't run that wizard - see ADR-001 / RISK_REGISTER.md R11).
 *
 * Run once per deployment (idempotent - a second run is a harmless no-op).
 */
class MarkPlatformInstalled extends Command
{
    protected $signature = 'platform:mark-installed';

    protected $description = 'Create the storage/installed marker so Bagisto\'s global CanInstall middleware stops redirecting platform/tenant requests to /install.';

    public function handle(): int
    {
        $path = storage_path('installed');

        if (file_exists($path)) {
            $this->info('Already marked installed - nothing to do.');

            return self::SUCCESS;
        }

        touch($path);

        $this->info('Created storage/installed. CanInstall middleware will no longer redirect requests to /install.');

        return self::SUCCESS;
    }
}
