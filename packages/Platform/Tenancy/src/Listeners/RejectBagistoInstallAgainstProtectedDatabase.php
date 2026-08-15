<?php

declare(strict_types=1);

namespace Platform\Tenancy\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Platform\Tenancy\Services\CentralDatabaseWipeGuard;
use RuntimeException;

/**
 * INCIDENT-001. Supplementary, best-effort layer only - the tested and
 * guaranteed protection is Platform\Tenancy\Services\CentralDatabaseWipeGuard
 * (see that class's docblock for why: bagisto:install's own internal
 * `$this->call('db:wipe')`/`$this->call('migrate:fresh')` are what actually
 * caused the incident, and CentralDatabaseWipeGuard blocks those regardless
 * of how they are reached).
 *
 * This listener adds a clean, immediate, well-worded rejection of
 * `bagisto:install` itself (Phase 8's "intercept/block accidental use of
 * bagisto:install" ask) for REAL interactive/CLI usage, where Laravel's
 * Illuminate\Console\Events\CommandStarting reliably fires. It does NOT fire
 * during this project's own Pest suite (APP_ENV=testing - Illuminate\
 * Foundation\Console\Kernel::rerouteSymfonyCommandEvents() explicitly skips
 * the Symfony-event-to-CommandStarting bridge whenever
 * app()->runningUnitTests() is true, confirmed empirically), so it is
 * intentionally NOT the mechanism any automated test relies on. Without
 * this listener, running `bagisto:install` against the protected central
 * database still cannot drop anything (CentralDatabaseWipeGuard prohibits
 * its nested db:wipe/migrate:fresh calls), but it would stumble into a
 * confusing downstream seeder error instead of a clear message - this
 * listener exists purely for that better real-world failure message.
 */
class RejectBagistoInstallAgainstProtectedDatabase
{
    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'bagisto:install') {
            return;
        }

        if (CentralDatabaseWipeGuard::currentDefaultDatabaseIsSafeForDestructiveCommands()) {
            return;
        }

        $database = CentralDatabaseWipeGuard::currentDefaultDatabaseName();

        throw new RuntimeException(
            "Refusing to run [bagisto:install] against database [{$database}] (INCIDENT-001). ".
            '`bagisto:install` is not a supported workflow for this SaaS fork once the central database is real '.
            '(see docs/incidents/INCIDENT-001-central-db-wipe.md and docs/architecture/provisioning.md). '.
            'Use `php artisan platform:migrate:central` + `php artisan platform:plans:seed` + '.
            '`php artisan platform:admin:create` for central bootstrap, or target an explicitly disposable '.
            'database (prefixed bagisto_test_/bagisto_ci_/bagisto_probe_) for a genuine throwaway install.'
        );
    }
}
