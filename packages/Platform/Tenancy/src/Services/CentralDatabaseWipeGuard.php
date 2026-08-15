<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;

/**
 * INCIDENT-001 (2026-08-15). A local `php artisan bagisto:install` run
 * intended to target a disposable `bagisto_ci_probe` database (via a
 * `-e DB_DATABASE=...` docker override) instead wiped the real, persistent
 * `bagisto_central` database. Root cause: `bagisto:install`'s own
 * EnvironmentManager re-reads `.env` from disk and rebinds the database
 * connection config from those file values, silently discarding the
 * process-level override before its internal `db:wipe`/`migrate:fresh`
 * calls ran (see docs/incidents/INCIDENT-001-central-db-wipe.md). No amount
 * of "pass the right --database flag" discipline prevents a repeat, because
 * the danger is structural: db:wipe/migrate:fresh/bagisto:install all
 * default to whatever the CURRENT process considers the default connection,
 * and that has always been the shared central database in this project.
 *
 * MECHANISM. Laravel ships exactly this kind of protection as
 * `Illuminate\Support\Facades\DB::prohibitDestructiveCommands()`, which
 * flips a static flag (`Illuminate\Console\Prohibitable::$prohibitedFromRunning`)
 * on WipeCommand/FreshCommand/RefreshCommand/ResetCommand/RollbackCommand;
 * each command's own handle() checks it via isProhibited() before doing
 * anything. This class reimplements that idea narrowly - keyed off the
 * actual configured database NAME (not APP_ENV - a --database=bagisto_central
 * override run from a "testing" or "local" environment must be caught just
 * as much as one run from "production") - because the stock helper is
 * all-or-nothing per environment, and this project's real risk is specific
 * to one specific database name being wiped, not "destructive commands in
 * general are unsafe here" (tenant databases are legitimately wiped/rebuilt
 * constantly, by tests and by tenant provisioning).
 *
 * WHY NOT AN EVENT LISTENER (like PreventCentralMigrationOfTenantSchema).
 * `Illuminate\Console\Events\CommandStarting` was the first design tried
 * here, matching that class's own precedent. It does not work as a
 * *guaranteed* safety net for this specific danger, for two independent
 * reasons, both confirmed empirically before choosing this approach:
 *   1. `Illuminate\Foundation\Console\Kernel` only reroutes Symfony's
 *      ConsoleEvents::COMMAND into Laravel's CommandStarting when
 *      `! $this->app->runningUnitTests()` - i.e. NEVER while APP_ENV=testing,
 *      which is exactly the environment this project's own Pest suite runs
 *      under (phpunit.xml). A guard built on CommandStarting could not be
 *      proven by a real automated test in this repo, and worse, would be
 *      silently inert in that same environment in practice.
 *   2. `bagisto:install`'s own internal `$this->call('db:wipe')` /
 *      `$this->call('migrate:fresh')` calls (Illuminate\Console\Concerns\
 *      CallsCommands::runCommand()) invoke the resolved command's run()
 *      DIRECTLY, bypassing `Illuminate\Console\Application::run()` entirely
 *      - the layer where CommandStarting is dispatched. Only the OUTERMOST
 *      command of a process gets a CommandStarting event; nested `$this->
 *      call()` invocations never do. This is exactly how the incident's
 *      real DROP TABLE happened - inside bagisto:install's nested db:wipe
 *      call - so a listener on the outer command alone would have to
 *      pre-empt bagisto:install before it even starts, which happens to
 *      work here (see RejectBagistoInstallAgainstProtectedDatabase) but is
 *      not a general answer for db:wipe/migrate:fresh reachable via ANY
 *      other nested caller.
 *
 * Static Prohibitable flags avoid both problems: they are read directly
 * inside each guarded command's own handle() (Illuminate\Console\
 * Prohibitable::isProhibited()), so it does not matter whether that command
 * was reached via real CLI invocation, Artisan::call(), or a nested
 * `$this->call()` from inside bagisto:install - and they are unaffected by
 * APP_ENV or Symfony console event rerouting, so a Pest test genuinely
 * proves the same code path production traffic would hit.
 *
 * SCOPE. `migrate:rollback` is deliberately NOT included: unlike the
 * commands above, it operates on a bounded, explicitly-requested number of
 * migrations (typically --step=1), and TASK-ARCH-016's own Historical
 * Migration Stability Review legitimately used
 * `migrate:rollback --path=database/migrations --step=1 --force` against
 * the central connection as a real, narrow repair/verification workflow.
 * Blocking it here would remove a tool this project has already needed.
 */
class CentralDatabaseWipeGuard
{
    /**
     * Explicit, documented naming convention for genuinely disposable
     * databases (throwaway CI/probe/manual-experiment databases that are
     * NOT the real central registry and NOT a tenant database). Anything
     * outside this project's tenant-database prefix (config('tenancy.
     * database.prefix')) must match one of these to be treated as safe to
     * wipe - an unrecognized database name is NOT assumed safe by default.
     */
    public const DISPOSABLE_PREFIXES = [
        'bagisto_test_',
        'bagisto_ci_',
        'bagisto_probe_',
    ];

    /**
     * The real, persistent local/production central database this project
     * has always used (see .env/.env.example DB_DATABASE). Deliberately a
     * FIXED literal, not derived from config('database.connections.*.database')
     * - config('tenancy.database.central_connection') and config('database.
     * default') both resolve to the SAME connection name ('mysql') in this
     * project, so deriving the "protected" name from that connection's
     * CURRENT database config would make the central check tautological:
     * whatever database a caller currently has configured would always
     * equal "centralDatabaseName()", since both read the identical
     * overridable key. Confirmed by empirical reproduction: overriding
     * DB_DATABASE to a disposable-prefixed name to test the escape hatch
     * caused the "central" comparison to silently follow the override too,
     * making the guard prohibit the disposable database it was supposed to
     * allow. A fixed literal has no such blind spot, and correctly leaves
     * CI's own separate, intentionally-disposable `bagisto` database
     * (.github/workflows/pest_tests.yml) unaffected, since it is never
     * named `bagisto_central`.
     */
    public const PROTECTED_CENTRAL_DATABASE_NAME = 'bagisto_central';

    /**
     * Evaluate the CURRENT process's default database connection once (at
     * boot, before any command runs) and prohibit/allow the guarded
     * commands accordingly for the remainder of this process's lifetime.
     * Always sets an explicit true/false (rather than only ever prohibiting)
     * so this method is safe to call more than once - e.g. from a test that
     * wants to re-evaluate after intentionally swapping config.
     */
    public static function apply(): void
    {
        $shouldProhibit = ! static::currentDefaultDatabaseIsSafeForDestructiveCommands();

        FreshCommand::prohibit($shouldProhibit);
        RefreshCommand::prohibit($shouldProhibit);
        ResetCommand::prohibit($shouldProhibit);
        WipeCommand::prohibit($shouldProhibit);
    }

    public static function currentDefaultDatabaseIsSafeForDestructiveCommands(): bool
    {
        return static::databaseIsSafeForDestructiveCommands(static::currentDefaultDatabaseName());
    }

    /**
     * The explicit three-bucket policy (RISK_REGISTER.md, INCIDENT-001
     * safeguards): central is NEVER safe, a tenant database is ALWAYS safe
     * (provisioning/tests legitimately wipe and rebuild these constantly),
     * anything else must match an approved disposable prefix to be safe.
     * Deliberately keyed on the database NAME, never on APP_ENV.
     */
    public static function databaseIsSafeForDestructiveCommands(string $databaseName): bool
    {
        if ($databaseName === '') {
            // Nothing meaningfully configured - not this guard's concern;
            // let Laravel's own connection errors surface normally.
            return true;
        }

        if ($databaseName === static::centralDatabaseName()) {
            return false;
        }

        if (static::isTenantDatabase($databaseName)) {
            return true;
        }

        return static::isDisposableDatabase($databaseName);
    }

    public static function currentDefaultDatabaseName(): string
    {
        $defaultConnection = (string) config('database.default');

        return (string) config("database.connections.{$defaultConnection}.database");
    }

    public static function centralDatabaseName(): string
    {
        return static::PROTECTED_CENTRAL_DATABASE_NAME;
    }

    public static function isTenantDatabase(string $databaseName): bool
    {
        $prefix = (string) config('tenancy.database.prefix', 'tenant');

        return $prefix !== '' && str_starts_with($databaseName, $prefix);
    }

    public static function isDisposableDatabase(string $databaseName): bool
    {
        foreach (self::DISPOSABLE_PREFIXES as $prefix) {
            if (str_starts_with($databaseName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
