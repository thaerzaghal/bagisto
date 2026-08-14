<?php

declare(strict_types=1);

namespace Platform\Tenancy\Listeners;

use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\DB;
use ReflectionObject;
use RuntimeException;

/**
 * TASK-ARCH-008 cleanup round (RISK_REGISTER.md R30). Every Webkul
 * package's ServiceProvider calls loadMigrationsFrom() (the standard
 * Laravel package convention) - REQUIRED, not a bug: Platform\Tenancy\
 * Services\TenantProvisioner::ensureMigrated() explicitly relies on
 * app('migrator')->paths() including every one of those directories, so
 * tenant provisioning can migrate a fresh tenant database against the
 * FULL Bagisto schema. Removing/un-registering them would break
 * provisioning outright - not attempted here, per explicit instruction.
 *
 * The actual danger is narrower: Laravel's bare `php artisan migrate`
 * (no --path) merges EVERY registered path (necessary for tenant
 * provisioning) with the default database/migrations path and runs all
 * of it against whatever the CURRENT default connection is - which, for
 * an ordinary console invocation with no tenant context active, is the
 * CENTRAL connection (see R30's own discovery: this happened live during
 * this task). `tenants:migrate`/TenantProvisioner never hit this danger
 * (they always run with tenancy active, against the tenant connection),
 * so this guard only needs to fire when the connection is central.
 *
 * This is a defense-in-depth SAFETY NET, not the primary mechanism -
 * Platform\Tenancy\Console\Commands\MigrateCentral
 * (`platform:migrate:central`) is the supported, documented way to run
 * central migrations. This listener exists so a bare `php artisan
 * migrate` (or `migrate:rollback`/`:fresh`/`:reset` - anything that goes
 * through Migrator, all of which fire this same event per migration
 * file) STILL fails loudly instead of silently corrupting the schema
 * boundary, even if someone bypasses the documented command.
 *
 * Mechanism: Illuminate\Database\Events\MigrationStarted fires once per
 * migration FILE, immediately before it runs, carrying the resolved
 * Migration instance. reflection on that instance gives the real
 * filesystem path it was loaded from (Laravel's own Migrator uses this
 * exact technique internally - Migrator::resolvePath() - to detect
 * already-required migrations, so this is not a fragile assumption). If
 * that path is NOT inside database_path('migrations') (i.e. it's a
 * package-registered path, not a central-only migration) AND the
 * migrator's current default connection is the central one, abort before
 * that migration executes.
 *
 * Deliberately does NOT touch/override/rebind any Laravel or Bagisto
 * class - purely an additive Event::listen() on a public, documented
 * Laravel event, evaluated fresh on every migration file, every time.
 */
class PreventCentralMigrationOfTenantSchema
{
    public function handle(MigrationStarted $event): void
    {
        $file = (new ReflectionObject($event->migration))->getFileName();

        if ($file === false) {
            return;
        }

        $centralMigrationsRoot = realpath(database_path('migrations'));
        $isCentralOwnedMigration = $centralMigrationsRoot && str_starts_with(
            realpath($file) ?: $file,
            $centralMigrationsRoot.DIRECTORY_SEPARATOR
        );

        if ($isCentralOwnedMigration) {
            return;
        }

        $currentConnection = DB::connection()->getName();
        $centralConnection = config('tenancy.database.central_connection');

        if ($currentConnection !== $centralConnection) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run migration [{$file}] against the central connection [{$currentConnection}]. ".
            'This migration lives outside database/migrations (a package-registered, tenant-schema path) - '.
            'running it centrally would install Bagisto commerce tables into the platform database (RISK_REGISTER.md R30). '.
            "Use `php artisan platform:migrate:central` for central-only migrations, or provision/migrate tenants via `php artisan tenant:provision`/`tenants:migrate`."
        );
    }
}
