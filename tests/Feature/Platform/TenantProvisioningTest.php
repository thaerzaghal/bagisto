<?php

/**
 * TASK-ARCH-002 - permanent tenancy foundation integration tests.
 *
 * Supersedes TASK-ARCH-001's tests/Feature/Platform/TenancySpikeTest.php (which
 * relied on temporary /spike/* HTTP routes, now removed). These tests exercise
 * the real, permanent service layer (Platform\Tenancy\Services\TenantProvisioner)
 * directly instead, which is a strictly stronger proof - the spike proved a real
 * HTTP request switches connections correctly; these prove the actual
 * provisioning pipeline that will be used in production is correct, retryable,
 * and failure-safe. Real MySQL, real Bagisto migrations/seeders, nothing mocked.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Product\Repositories\ProductRepository;

// TASK-ARCH-003 correction: uses PlatformIntegrationTestCase (not Tests\TestCase)
// - see that class's docblock. Tests\TestCase's DatabaseTransactions trait DOES
// correctly auto-wrap the central connection per test (an earlier claim here
// that it "doesn't auto-engage" was wrong, based on an incomplete grep). This
// file's own explicit afterEach() cleanup already handled the physical
// (non-transacted) database/user teardown correctly regardless, which is why
// all 5 tests passed despite the incorrect comment - but using the same base
// class as TenantDomainRoutingTest.php keeps behavior consistent and explicit
// rather than accidentally relying on transaction rollback for central rows.
uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

// TASK-ARCH-005 FIX: uses dedicated tenant-prov-* ids, not the shared
// tenant-a/tenant-b ids TenantCacheIsolationTest.php, TenantDomainRoutingTest.php,
// and TenantStorageIsolationTest.php reuse across tests for cheap, idempotent
// fixture sharing (see those files' beforeEach docblocks). This file's own
// tests genuinely need a from-scratch tenant each time (they test the
// provisioning lifecycle itself - "create from Pending", "fail and never
// reach Ready", "retry from Pending again"), so it always runs a full
// cleanup+recreate cycle via beforeEach/afterEach below - correct for what
// THIS file tests, but reusing 'tenant-prov-a'/'tenant-prov-b' caused cross-file
// contamination when the whole tests/Feature/Platform/ suite ran together
// (this file's beforeEach silently deleted the OTHER files' shared fixture
// mid-run, taking their seeded products with it). Distinct ids remove the
// possibility of that interaction entirely, regardless of which file Pest
// happens to execute first.
const TEST_TENANT_IDS = ['tenant-prov-a', 'tenant-prov-b', 'tenant-prov-bad`id'];

function cleanupTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (TEST_TENANT_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbName = $data['tenancy_db_name'] ?? null;
            $dbUsername = $data['tenancy_db_username'] ?? null;

            // Escape identifiers (MySQL: double an embedded backtick) - one of our
            // own fixture tenant ids deliberately contains a backtick to test
            // RISK finding "tenant id is interpolated unescaped into raw DDL by
            // stancl's MySQLDatabaseManager", so cleanup must handle it too.
            if ($dbUsername) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $dbUsername).'`');
            }

            if ($dbName) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $dbName).'`');
            }
        }

        $central->table('domains')->where('tenant_id', $id)->delete();
        $central->table('tenants')->where('id', $id)->delete();
    }
}

beforeEach(fn () => cleanupTestTenants());
afterEach(fn () => cleanupTestTenants());

test('a tenant can be created, provisioned end-to-end, and reaches READY with a working real Bagisto repository', function () {
    // 1. Tenant can be created.
    $tenant = Tenant::create(['id' => 'tenant-prov-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-prov-a.spike.test']);
    expect($tenant->status)->toBe(TenantStatus::Pending);

    app(TenantProvisioner::class)->provision($tenant);
    $tenant->refresh();

    // 5. Tenant reaches READY state.
    expect($tenant->status)->toBe(TenantStatus::Ready);
    expect($tenant->last_error)->toBeNull();

    // 2. Tenant database can be provisioned (physically exists, isolated name).
    $dbName = $tenant->database()->getName();
    expect($dbName)->toBe('tenanttenant-prov-a');
    expect($tenant->database()->manager()->databaseExists($dbName))->toBeTrue();

    // 2b (R18): runtime connection uses a scoped, non-elevated, tenant-specific
    // MySQL user - never the elevated `tenant_provisioning` credentials.
    $runtimeUsername = $tenant->database()->getUsername();
    expect($runtimeUsername)->not->toBeNull();
    expect($runtimeUsername)->not->toBe(config('database.connections.tenant_provisioning.username'));

    $tenant->run(function () use ($dbName) {
        // 3. Tenant migration set is complete - full Bagisto schema, not just a
        // handful of tables (confirms the dynamic migrator->paths() discovery
        // actually picked up all ~40 Webkul packages, not a partial/stale list).
        expect(DB::connection()->getDatabaseName())->toBe($dbName);

        $tableCount = DB::table('information_schema.tables')
            ->where('table_schema', $dbName)
            ->count();
        expect($tableCount)->toBeGreaterThan(100);

        foreach (['products', 'channels', 'admins', 'attribute_families', 'orders'] as $table) {
            expect(Schema::hasTable($table))->toBeTrue("Expected tenant DB to have a `{$table}` table.");
        }

        // 4. Tenant seeding completes (Bagisto's real BagistoDatabaseSeeder).
        expect(DB::table('channels')->count())->toBe(1);
        expect(DB::table('attribute_families')->count())->toBeGreaterThan(0);
        expect(DB::table('admins')->count())->toBe(1);

        // 6. A real, unmodified Bagisto repository works against this tenant DB.
        $product = app(ProductRepository::class)->create([
            'type' => 'simple',
            'attribute_family_id' => 1,
            'sku' => 'FOUNDATION-TEST',
        ]);
        expect($product->sku)->toBe('FOUNDATION-TEST');
        expect(app(ProductRepository::class)->all()->pluck('sku')->all())->toBe(['FOUNDATION-TEST']);
    });
});

test('the central database contains only platform tenancy tables, never Bagisto commerce tables', function () {
    $centralDb = config('database.connections.mysql.database');

    $tables = DB::connection('mysql')
        ->table('information_schema.tables')
        ->where('table_schema', $centralDb)
        ->pluck('TABLE_NAME')
        ->map(fn ($t) => strtolower($t))
        ->all();

    // 7. Platform tables are present...
    expect($tables)->toContain('tenants');
    expect($tables)->toContain('domains');

    // ...and not one single Bagisto commerce table has ever leaked in here -
    // this is the exact failure mode RISK_REGISTER.md R17 warned about.
    foreach (['products', 'channels', 'admins', 'orders', 'categories', 'attribute_families'] as $bagistoTable) {
        expect($tables)->not->toContain($bagistoTable);
    }
});

test('two tenants get fully isolated Bagisto schemas and tenant A cannot see tenant B product data', function () {
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::create(['id' => 'tenant-prov-a', 'status' => TenantStatus::Pending]);
    $tenantA->domains()->create(['domain' => 'tenant-prov-a.spike.test']);
    $provisioner->provision($tenantA);

    $tenantB = Tenant::create(['id' => 'tenant-prov-b', 'status' => TenantStatus::Pending]);
    $tenantB->domains()->create(['domain' => 'tenant-prov-b.spike.test']);
    $provisioner->provision($tenantB);

    expect($tenantA->fresh()->status)->toBe(TenantStatus::Ready);
    expect($tenantB->fresh()->status)->toBe(TenantStatus::Ready);

    // 8. Isolated schemas, isolated MySQL users.
    expect($tenantA->database()->getName())->not->toBe($tenantB->database()->getName());
    expect($tenantA->database()->getUsername())->not->toBe($tenantB->database()->getUsername());

    $tenantA->run(fn () => app(ProductRepository::class)->create([
        'type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PROD-A',
    ]));
    $tenantB->run(fn () => app(ProductRepository::class)->create([
        'type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PROD-B',
    ]));

    // 9. Cross-tenant isolation, checked at the DB level directly (not just
    // behaviorally), per docs/architecture/security.md's testing principle.
    $tenantA->run(function () {
        expect(DB::table('products')->pluck('sku')->all())->toBe(['PROD-A']);
    });
    $tenantB->run(function () {
        expect(DB::table('products')->pluck('sku')->all())->toBe(['PROD-B']);
    });
});

test('a failure during provisioning marks the tenant FAILED with a recorded error, never READY', function () {
    // A backtick in the tenant id produces an invalid, unescaped
    // `CREATE DATABASE \`tenanttenant-prov-bad`id\`` statement (MySQLDatabaseManager
    // interpolates the database name into raw DDL with no escaping) - a real,
    // deterministic MySQL syntax error, not a simulated/mocked one.
    $tenant = Tenant::create(['id' => 'tenant-prov-bad`id', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-prov-bad-id.spike.test']);

    // Pest's toThrow() only special-cases the argument as a type check when
    // class_exists() is true for it - Throwable is an interface, so
    // class_exists('Throwable') is false and it would be treated as a literal
    // message substring instead. \Exception::class works correctly here since
    // the real exception (Illuminate\Database\QueryException) extends it.
    expect(fn () => app(TenantProvisioner::class)->provision($tenant))->toThrow(\Exception::class);

    $tenant->refresh();

    // 10. Never marked READY on failure.
    expect($tenant->status)->toBe(TenantStatus::Failed);
    expect($tenant->last_error)->toBeString();
    expect($tenant->last_error)->not->toBe('');

    // No physical database was left behind by the failed attempt.
    expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeFalse();
});

test('R56: provisioning succeeds through a narrowly-scoped, non-root tenant_provisioning connection - regression for the real production access-denied bug', function () {
    // TASK-MVP-004B (RISK_REGISTER.md R56). Every OTHER test in this file
    // (and every environment this whole engagement ever tested provisioning
    // in) uses DB_PROVISION_USERNAME=root for tenant_provisioning, which has
    // universal MySQL access - structurally unable to ever surface the real
    // bug found live on the pilot server's first-ever real signup:
    // `estore_provisioner` (correctly scoped per docs/architecture/
    // provisioning.md - CREATE/DROP on `tenant%` only, zero grant on the
    // central database) failed immediately with SQLSTATE[HY000] [1044]
    // "Access denied ... to database 'bagisto_central'" the moment ANY
    // query ran on that connection - because config/database.php used to
    // set tenant_provisioning's own `database` to the CENTRAL database
    // name, a value `Stancl\Tenancy\TenantDatabaseManagers\
    // MySQLDatabaseManager::databaseExists()`/`createDatabase()`/
    // `deleteDatabase()` never actually need (all fully-qualified,
    // confirmed by reading that class's own source), but which MySQL's
    // own access-control check still evaluates at connect/session-context
    // time regardless of what the query itself targets.
    //
    // This test creates a REAL MySQL user with the exact production grant
    // shape (mirroring docker/production/mysql-init/
    // 01-create-app-users.sql.example's estore_provisioner grants -
    // CREATE/DROP on `tenant%` + CREATE USER globally, nothing on the
    // central database), points tenant_provisioning at it for the
    // duration of this test only, and proves real end-to-end provisioning
    // succeeds through it - closing the exact masking gap that let this
    // reach a real pilot deployment before being caught.
    $root = DB::connection('tenant_provisioning');
    $testProvisionUser = 'test_prov_scoped_'.substr(md5((string) microtime(true)), 0, 8);
    $testProvisionPassword = 'test-scoped-password-1';

    $root->statement("CREATE USER '{$testProvisionUser}'@'%' IDENTIFIED BY '{$testProvisionPassword}'");
    $root->statement("GRANT CREATE, DROP ON `tenant%`.* TO '{$testProvisionUser}'@'%'");
    $root->statement("GRANT CREATE USER ON *.* TO '{$testProvisionUser}'@'%'");
    $root->statement("GRANT ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES,
        CREATE VIEW, DELETE, DROP, EVENT, EXECUTE, INDEX, INSERT, LOCK TABLES,
        REFERENCES, SELECT, SHOW VIEW, TRIGGER, UPDATE
        ON `tenant%`.* TO '{$testProvisionUser}'@'%' WITH GRANT OPTION");
    $root->statement('FLUSH PRIVILEGES');

    $originalConfig = config('database.connections.tenant_provisioning');

    // Deliberately does NOT override 'database' here - this must exercise
    // whatever config/database.php's own real, current value is (the thing
    // under test), not a value this test hardcodes. Only username/password
    // are swapped to the scoped test user.
    config(['database.connections.tenant_provisioning' => array_merge($originalConfig, [
        'username' => $testProvisionUser,
        'password' => $testProvisionPassword,
    ])]);
    DB::purge('tenant_provisioning');

    try {
        $tenant = Tenant::create(['id' => 'tenant-prov-scoped', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-prov-scoped.spike.test']);

        app(TenantProvisioner::class)->provision($tenant);
        $tenant->refresh();

        expect($tenant->status)->toBe(TenantStatus::Ready);
        expect($tenant->last_error)->toBeNull();
    } finally {
        // Restore the real (root, in this test env) connection BEFORE
        // cleanup - dropping the tenant database/user needs elevated
        // privileges the scoped test user doesn't have for anything
        // outside `tenant%`/its own CREATE USER grant reach.
        config(['database.connections.tenant_provisioning' => $originalConfig]);
        DB::purge('tenant_provisioning');

        $central = DB::connection('mysql');
        $provisioning = DB::connection('tenant_provisioning');
        $row = $central->table('tenants')->where('id', 'tenant-prov-scoped')->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];

            if (! empty($data['tenancy_db_username'])) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
            }

            if (! empty($data['tenancy_db_name'])) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
            }
        }

        $central->table('domains')->where('tenant_id', 'tenant-prov-scoped')->delete();
        $central->table('tenants')->where('id', 'tenant-prov-scoped')->delete();

        $provisioning->statement("DROP USER IF EXISTS '{$testProvisionUser}'@'%'");
    }
});

test('provisioning is idempotent: re-running it on an already-READY tenant, or resuming from PENDING again, never duplicates data', function () {
    $provisioner = app(TenantProvisioner::class);

    $tenant = Tenant::create(['id' => 'tenant-prov-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-prov-a.spike.test']);
    $provisioner->provision($tenant);
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    // 11a. Calling provision() again on an already-READY tenant is a safe no-op
    // (early-return guard) - must not error, must not touch anything.
    $provisioner->provision($tenant);
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    // 11b. Simulating a supervisor/queue retrying a provisioning run (e.g. after
    // a transient failure was recorded elsewhere): resetting status back to
    // Pending and re-provisioning must resume as a full no-op - the database
    // already exists (skip), every migration is already logged (skip), and
    // the seeded-admins guard is already satisfied (skip) - not just "doesn't
    // crash" but verifiably zero duplicate rows anywhere.
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();
    $provisioner->provision($tenant);
    $tenant->refresh();

    expect($tenant->status)->toBe(TenantStatus::Ready);
    expect($tenant->database()->getName())->toBe('tenanttenant-prov-a');

    $tenant->run(function () {
        expect(DB::table('channels')->count())->toBe(1);
        expect(DB::table('admins')->count())->toBe(1);
        expect(DB::table('attribute_families')->count())->toBe(1);
    });
});
