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
 * This suite's own test process boots with the real bagisto_central as its
 * default database (phpunit.xml does not override DB_CONNECTION/DB_DATABASE
 * - see CentralDatabaseWipeGuard's docblock for why that is deliberate: the
 * real risk is keyed on database NAME, not APP_ENV), so
 * CentralDatabaseWipeGuard::apply() has already prohibited db:wipe/
 * migrate:fresh/migrate:refresh/migrate:reset for this entire process by
 * the time these tests run - exactly the scenario under test.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\CentralDatabaseWipeGuard;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

function centralTableSnapshot(): array
{
    $rows = DB::connection('mysql')->select('SHOW TABLES');

    $tables = array_map(fn ($row) => array_values((array) $row)[0], $rows);

    sort($tables);

    return [
        'tables' => $tables,
        'plans_count' => DB::connection('mysql')->table('plans')->count(),
        'tenants_count' => DB::connection('mysql')->table('tenants')->count(),
        'subscriptions_count' => DB::connection('mysql')->table('subscriptions')->count(),
    ];
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

test('4. db:wipe against bagisto_central is rejected before any DROP', function () {
    $before = centralTableSnapshot();

    $this->artisan('db:wipe', ['--force' => true])->run();

    $after = centralTableSnapshot();

    expect($after)->toBe($before);
});

test('5. migrate:fresh against bagisto_central is rejected before any DROP', function () {
    $before = centralTableSnapshot();

    $this->artisan('migrate:fresh', ['--force' => true])->run();

    $after = centralTableSnapshot();

    expect($after)->toBe($before);
});

test('6. bagisto:install against bagisto_central is rejected before any DROP', function () {
    $before = centralTableSnapshot();

    try {
        $this->artisan('bagisto:install', ['--no-interaction' => true])->run();
    } catch (\Throwable $e) {
        // Expected: db:wipe/migrate:fresh silently no-op (Prohibitable),
        // so bagisto:install's own subsequent seeding step fails loudly
        // against tables that were never (re)created centrally - see
        // CentralDatabaseWipeGuard's docblock for why a clean top-level
        // rejection of bagisto:install itself is not guaranteed under
        // Pest/testing (RejectBagistoInstallAgainstProtectedDatabase is
        // real-CLI-only). The safety property under test is that nothing
        // was dropped, not the exact shape of the resulting error.
    }

    $after = centralTableSnapshot();

    expect($after)->toBe($before);
});

/**
 * The app's own 'mysql' connection uses the least-privileged 'sail' user
 * (no CREATE/DROP DATABASE grant, by design - see INCIDENT-001's tenant
 * mapping findings), so creating/dropping a throwaway database for this one
 * test uses a direct root PDO connection instead, exactly like the
 * INCIDENT-001 forensic investigation itself did outside the app.
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
