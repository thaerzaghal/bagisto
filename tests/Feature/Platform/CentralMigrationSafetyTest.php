<?php

/**
 * TASK-ARCH-008 cleanup round - central migration safety (RISK_REGISTER.md
 * R30) test matrix.
 *
 * Proves the explicit separation this cleanup round was asked to make
 * testable:
 *
 *   CENTRAL MIGRATION COMMAND (`platform:migrate:central`) -> central/
 *   platform migrations only (database/migrations root).
 *
 *   TENANT PROVISIONING (`TenantProvisioner`/`tenants:migrate`) -> Bagisto
 *   commerce migrations + tenant-specific migrations only, against a
 *   tenant's own database.
 *
 * A bare `php artisan migrate` is deliberately exercised here (not just
 * documented as unsupported) precisely because RISK_REGISTER.md R30 was a
 * REAL, live near-miss during TASK-ARCH-008's own work - this file proves
 * the guard that now stands between that mistake and the central
 * database, not just that the "supported" path works.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

test('a bare php artisan migrate is blocked before installing any Bagisto commerce table into the central database', function () {
    $tablesBefore = collect(DB::connection('mysql')->select('SHOW TABLES'))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->all();

    expect(fn () => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'central connection');

    $tablesAfter = collect(DB::connection('mysql')->select('SHOW TABLES'))
        ->map(fn ($row) => array_values((array) $row)[0])
        ->all();

    expect($tablesAfter)->toBe($tablesBefore, 'the blocked attempt must not have created, dropped, or altered any table');
    expect(Schema::connection('mysql')->hasTable('admin_password_resets'))->toBeFalse('the first Bagisto migration alphabetically must never have run against the central connection');
    expect(Schema::connection('mysql')->hasTable('products'))->toBeFalse();
});

test('php artisan platform:migrate:central succeeds and installs no Bagisto commerce table', function () {
    $exitCode = Artisan::call('platform:migrate:central');

    expect($exitCode)->toBe(0);
    expect(Schema::connection('mysql')->hasTable('plans'))->toBeTrue('the actual central-owned schema must exist');
    expect(Schema::connection('mysql')->hasTable('products'))->toBeFalse();
    expect(Schema::connection('mysql')->hasTable('admins'))->toBeFalse();
    expect(Schema::connection('mysql')->hasTable('orders'))->toBeFalse();
});

test('tenant provisioning still installs the full Bagisto commerce schema, unaffected by the central migration guard', function () {
    $id = 'tenant-migration-guard-check';
    DB::connection('mysql')->table('domains')->where('tenant_id', $id)->delete();
    DB::connection('mysql')->table('tenants')->where('id', $id)->delete();

    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);

    app(TenantProvisioner::class)->provision($tenant);

    $tenant = $tenant->fresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    $tableCount = $tenant->run(fn () => count(DB::select('SHOW TABLES')));
    expect($tableCount)->toBeGreaterThan(100, 'the tenant database must still receive the full Bagisto commerce schema (products, orders, admins, ...)');

    $hasProducts = $tenant->run(fn () => Schema::hasTable('products'));
    $hasAdmins = $tenant->run(fn () => Schema::hasTable('admins'));
    expect($hasProducts)->toBeTrue();
    expect($hasAdmins)->toBeTrue();

    // Cleanup.
    $data = json_decode($tenant->data ?? '{}', true) ?: [];
    if (! empty($data['tenancy_db_name'])) {
        DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    $tenant->domains()->delete();
    $tenant->delete();
});
