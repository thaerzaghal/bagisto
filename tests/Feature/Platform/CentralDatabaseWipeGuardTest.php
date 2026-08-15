<?php

/**
 * INCIDENT-001 (2026-08-15) safeguard tests. Real MySQL, real artisan
 * command execution - nothing mocked. Proves Platform\Tenancy\Services\
 * CentralDatabaseWipeGuard actually prevents the exact class of accident
 * that happened (a `db:wipe`/`migrate:fresh`/`bagisto:install` invocation
 * wiping the real, persistent bagisto_central database), while leaving
 * every legitimate destructive workflow (tenant provisioning/migration,
 * platform:migrate:central, wiping an explicitly disposable database)
 * fully intact.
 *
 * TASK-ARCH-017A (fixing a real defect the first GitHub Actions run
 * exposed). Tests 4-6 originally ran db:wipe/migrate:fresh/bagisto:install
 * against THIS PROCESS'S OWN ambient default connection, on the assumption
 * that it is always literally `bagisto_central` - true in local development
 * (phpunit.xml does not override DB_CONNECTION/DB_DATABASE), but FALSE in
 * CI, where the Platform lane's database is correctly, deliberately
 * `bagisto_ci_platform` (a disposable name CentralDatabaseWipeGuard does
 * NOT protect - by design, since CI's own database must remain wipeable).
 * Under the old design, in CI the guard correctly allowed db:wipe to
 * proceed, which genuinely dropped that CI job's own central tables mid-run
 * and cascaded into every later test in the suite (`Table
 * 'bagisto_ci_platform.plans' doesn't exist`).
 *
 * Fix: tests 4-6 now create their OWN throwaway database literally named
 * `bagisto_central`, independent of whatever the ambient default connection
 * happens to be, and drive the artisan command via a real subprocess
 * (`Illuminate\Support\Facades\Process`) with DB_DATABASE pointed at it -
 * this proves the actual runtime mechanism (not just the pure
 * classification function, which tests 1-3 already cover with zero DB
 * interaction) without ever depending on, or risking, the CI job's own
 * active database. Guarded by an existence check: if a database already
 * named `bagisto_central` is found on the connected MySQL server (i.e. this
 * suite is running against a real local development environment, where a
 * genuine, non-disposable bagisto_central lives), the test SKIPS rather
 * than ever creating/touching/dropping anything under that name - never
 * relying on "the guard will surely reject it" as a reason it's safe to
 * experiment with a real central database's name.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\CentralDatabaseWipeGuard;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

/**
 * The app's own 'mysql' connection uses the least-privileged 'sail' user
 * (no CREATE/DROP DATABASE grant, by design - see INCIDENT-001's tenant
 * mapping findings), so creating/dropping/inspecting a throwaway database
 * uses a direct root PDO connection instead, exactly like the INCIDENT-001
 * forensic investigation itself did outside the app.
 */
function withRootMysqlConnection(callable $callback): mixed
{
    $pdo = new PDO(
        'mysql:host='.config('database.connections.mysql.host').';port='.config('database.connections.mysql.port'),
        'root',
        'password'
    );

    return $callback($pdo);
}

function aDatabaseNamedBagistoCentralAlreadyExists(): bool
{
    return withRootMysqlConnection(function (PDO $pdo) {
        $statement = $pdo->query(
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'bagisto_central'"
        );

        return (bool) $statement->fetch();
    });
}

/**
 * Proves a guarded command (db:wipe/migrate:fresh/bagisto:install) is
 * rejected before any DROP against a throwaway database literally named
 * `bagisto_central` - created and destroyed entirely within this function,
 * never touching the calling process's own ambient database. Skips (never
 * creates/touches anything) if a database already named `bagisto_central`
 * exists on the connected MySQL server.
 */
function assertGuardedCommandRejectsRealBagistoCentral(string $command, array $arguments = []): void
{
    if (aDatabaseNamedBagistoCentralAlreadyExists()) {
        test()->markTestSkipped(
            'A database already named bagisto_central exists on this MySQL server (real local '.
            'development data) - skipping the isolated destructive-subprocess proof to avoid ever '.
            'creating/touching/dropping anything under that name, even briefly. Test 1\'s pure '.
            'classification check already proves the same safety property without touching any '.
            'database at all.'
        );

        return;
    }

    withRootMysqlConnection(function (PDO $pdo) {
        $pdo->exec('CREATE DATABASE `bagisto_central`');
        $pdo->exec('CREATE TABLE `bagisto_central`.marker_table (id INT PRIMARY KEY)');
    });

    try {
        $commandLine = 'php artisan '.$command.' '.implode(' ', $arguments);

        $result = Process::env(['DB_DATABASE' => 'bagisto_central'])->run($commandLine);

        $markerTableStillExists = withRootMysqlConnection(function (PDO $pdo) {
            $statement = $pdo->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES ".
                "WHERE TABLE_SCHEMA = 'bagisto_central' AND TABLE_NAME = 'marker_table'"
            );

            return (bool) $statement->fetch();
        });

        expect($markerTableStillExists)->toBeTrue(
            "Expected [{$command}] to leave the throwaway bagisto_central-named database's tables ".
            "untouched. Process output:\n".$result->output().$result->errorOutput()
        );
    } finally {
        withRootMysqlConnection(fn (PDO $pdo) => $pdo->exec('DROP DATABASE IF EXISTS `bagisto_central`'));
    }
}

test('1. CentralDatabaseWipeGuard classifies the central database as never safe', function () {
    expect(CentralDatabaseWipeGuard::databaseIsSafeForDestructiveCommands(
        CentralDatabaseWipeGuard::centralDatabaseName()
    ))->toBeFalse();
});

test('2. CentralDatabaseWipeGuard classifies tenant-prefixed databases as always safe', function () {
    expect(CentralDatabaseWipeGuard::isTenantDatabase('tenanttenant-a'))->toBeTrue();
    expect(CentralDatabaseWipeGuard::databaseIsSafeForDestructiveCommands('tenanttenant-a'))->toBeTrue();
});

test('3. CentralDatabaseWipeGuard classifies approved disposable prefixes as safe, others as unsafe', function () {
    expect(CentralDatabaseWipeGuard::isDisposableDatabase('bagisto_test_probe'))->toBeTrue();
    expect(CentralDatabaseWipeGuard::isDisposableDatabase('bagisto_ci_probe'))->toBeTrue();
    expect(CentralDatabaseWipeGuard::isDisposableDatabase('bagisto_probe_x'))->toBeTrue();
    expect(CentralDatabaseWipeGuard::isDisposableDatabase('some_random_db'))->toBeFalse();
    expect(CentralDatabaseWipeGuard::databaseIsSafeForDestructiveCommands('some_random_db'))->toBeFalse();
});

test('4. db:wipe against a real bagisto_central is rejected before any DROP (isolated, environment-independent)', function () {
    assertGuardedCommandRejectsRealBagistoCentral('db:wipe', ['--force', '--database=mysql']);
});

test('5. migrate:fresh against a real bagisto_central is rejected before any DROP (isolated, environment-independent)', function () {
    assertGuardedCommandRejectsRealBagistoCentral('migrate:fresh', ['--force', '--database=mysql']);
});

test('6. bagisto:install against a real bagisto_central is rejected before any DROP (isolated, environment-independent)', function () {
    assertGuardedCommandRejectsRealBagistoCentral('bagisto:install', ['--no-interaction']);
});

test('7. destructive commands are NOT prohibited against an explicitly disposable database (fresh process)', function () {
    $disposableDatabase = 'bagisto_test_incident001_wipeguard_'.substr(md5((string) microtime(true)), 0, 8);

    withRootMysqlConnection(function (PDO $pdo) use ($disposableDatabase) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$disposableDatabase}`");

        // The app's normal 'sail' user only has grants on bagisto_central
        // (see INCIDENT-001 tenant-mapping findings) - a real deployment
        // would grant it access to a genuinely disposable database it's
        // meant to operate against, exactly like this.
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$disposableDatabase}`.* TO 'sail'@'%'");
    });

    try {
        $result = Process::env([
            'DB_DATABASE' => $disposableDatabase,
        ])->run('php artisan db:wipe --force --database=mysql');

        expect($result->successful())->toBeTrue(
            "db:wipe against an explicitly disposable database should succeed, not be prohibited. Output: \n"
            .$result->output().$result->errorOutput()
        );

        expect($result->output().$result->errorOutput())->not->toContain('prohibited');
    } finally {
        withRootMysqlConnection(function (PDO $pdo) use ($disposableDatabase) {
            // Explicit REVOKE first so this test doesn't accumulate
            // orphaned grants (one per run, since the database name is
            // randomized) the way a pre-existing, unrelated tenant grant
            // was found to have during the INCIDENT-001 investigation.
            $pdo->exec("REVOKE ALL PRIVILEGES ON `{$disposableDatabase}`.* FROM 'sail'@'%'");
            $pdo->exec("DROP DATABASE IF EXISTS `{$disposableDatabase}`");
        });
    }
});

test('8. tenant provisioning and tenant migrations still work under the guard', function () {
    $tenant = Tenant::find('tenant-incident001-guard-check');

    if (! $tenant) {
        $tenant = Tenant::create(['id' => 'tenant-incident001-guard-check', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-incident001-guard-check.localhost']);
    }

    app(TenantProvisioner::class)->provision($tenant);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Ready);

    $tenant->run(function () {
        expect(\Illuminate\Support\Facades\Schema::hasTable('products'))->toBeTrue();
    });
});

test('9. platform:migrate:central still works under the guard', function () {
    $this->artisan('platform:migrate:central')->assertSuccessful();
});

test('10. the active default connection database is never touched by the destructive-rejection tests', function () {
    // TASK-ARCH-017A regression proof: tests 4-6 must never depend on, or
    // mutate, whatever the CURRENT process's own default database happens
    // to be (bagisto_central locally, bagisto_ci_platform in CI) - this is
    // the exact property whose absence caused the original CI failure.
    $activeDatabase = CentralDatabaseWipeGuard::currentDefaultDatabaseName();

    expect(DB::connection('mysql')->getDatabaseName())->toBe($activeDatabase);

    $stillHasCoreTables = withRootMysqlConnection(function (PDO $pdo) use ($activeDatabase) {
        $statement = $pdo->query(
            "SELECT COUNT(*) as c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$activeDatabase}'"
        );

        return (int) $statement->fetch()['c'] > 0;
    });

    expect($stillHasCoreTables)->toBeTrue(
        "The active database [{$activeDatabase}] unexpectedly has zero tables - it should never be ".
        'touched by this file\'s destructive-rejection tests (4-6), which operate on an isolated '.
        'throwaway bagisto_central-named database instead.'
    );
});
