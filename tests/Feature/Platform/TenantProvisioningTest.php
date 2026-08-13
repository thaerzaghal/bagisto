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

const TEST_TENANT_IDS = ['tenant-a', 'tenant-b', 'tenant-bad`id'];

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
    $tenant = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-a.spike.test']);
    expect($tenant->status)->toBe(TenantStatus::Pending);

    app(TenantProvisioner::class)->provision($tenant);
    $tenant->refresh();

    // 5. Tenant reaches READY state.
    expect($tenant->status)->toBe(TenantStatus::Ready);
    expect($tenant->last_error)->toBeNull();

    // 2. Tenant database can be provisioned (physically exists, isolated name).
    $dbName = $tenant->database()->getName();
    expect($dbName)->toBe('tenanttenant-a');
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

    $tenantA = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
    $tenantA->domains()->create(['domain' => 'tenant-a.spike.test']);
    $provisioner->provision($tenantA);

    $tenantB = Tenant::create(['id' => 'tenant-b', 'status' => TenantStatus::Pending]);
    $tenantB->domains()->create(['domain' => 'tenant-b.spike.test']);
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
    // `CREATE DATABASE \`tenanttenant-bad`id\`` statement (MySQLDatabaseManager
    // interpolates the database name into raw DDL with no escaping) - a real,
    // deterministic MySQL syntax error, not a simulated/mocked one.
    $tenant = Tenant::create(['id' => 'tenant-bad`id', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-bad-id.spike.test']);

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

test('provisioning is idempotent: re-running it on an already-READY tenant, or resuming from PENDING again, never duplicates data', function () {
    $provisioner = app(TenantProvisioner::class);

    $tenant = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tenant-a.spike.test']);
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
    expect($tenant->database()->getName())->toBe('tenanttenant-a');

    $tenant->run(function () {
        expect(DB::table('channels')->count())->toBe(1);
        expect(DB::table('admins')->count())->toBe(1);
        expect(DB::table('attribute_families')->count())->toBe(1);
    });
});
