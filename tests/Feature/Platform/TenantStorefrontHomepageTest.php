<?php

/**
 * TASK-ARCH-010 - storefront homepage 500 investigation and fix,
 * regression test matrix.
 *
 * Root cause (RISK_REGISTER.md R33): `SESSION_DRIVER=database` (the real
 * `.env` value - phpunit.xml overrides this to `array` for the entire
 * Platform test suite, which is exactly why the bug was invisible until
 * this task deliberately forces the real value back) makes
 * `Illuminate\Session\Middleware\StartSession` - part of the 'web'
 * middleware group every Bagisto Shop/Admin route runs under - read/write
 * the `sessions` table on whatever the CURRENT default connection is.
 * `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` runs earlier in
 * the same pipeline and has already swapped that connection to the
 * tenant's own database - but no tenant database had a `sessions` table
 * at all, because it's a root `database/migrations/` file (stock Laravel
 * scaffolding), and `TenantProvisioner::ensureMigrated()` only ever
 * migrates `packages/Webkul/*`- and `database/migrations/tenant/`-
 * registered files into a tenant database (the same mechanism R17 relies
 * on to keep CENTRAL-only tables OUT of tenant databases, here cutting
 * the other way for a table that DOES need to be tenant-scoped). Fixed
 * by adding `database/migrations/tenant/2026_08_14_150000_create_sessions_table.php`
 * (a copy of the central table's schema) - TenantProvisioner needed zero
 * code change for NEW tenants (the migration path is already dynamically
 * discovered); EXISTING tenants are repaired via the new, idempotent
 * `php artisan platform:tenants:migrate-pending` command.
 *
 * This bug was never specific to the storefront - every 'web'-group
 * tenant route (Admin included) hits the identical failure under the
 * real session driver. This file focuses on the real Bagisto storefront
 * homepage (`shop.home.index`) since that's where it was originally
 * found (via R32's fix restoring the real route), plus one regression
 * check that the Admin "My Plan" page (TASK-ARCH-009) still works too.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const STOREFRONT_TEST_TENANT_IDS = ['tenant-storefront-a', 'tenant-storefront-b'];

/**
 * DEDICATED, freshly-provisioned-if-missing tenant-storefront-a/b
 * fixtures - matches this task's own explicit requirement ("Freshly
 * provisioned Tenant A/B homepage returns 200"), and the established
 * per-file-dedicated-tenant convention.
 */
function ensureStorefrontTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-storefront-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-storefront-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-storefront-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-storefront-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-storefront-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-storefront-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }

    return [$tenantA->fresh(), $tenantB->fresh()];
}

beforeEach(function () {
    // The single most important line in this file: without it, every
    // test here would silently pass regardless of whether R33 is fixed,
    // exactly like the original bug being invisible to the whole engagement
    // until this was forced back to the real .env value. See this file's
    // own top docblock and RISK_REGISTER.md R33.
    config(['session.driver' => 'database']);

    [$this->tenantA, $this->tenantB] = ensureStorefrontTestFixtures();

    $this->tenantA->run(fn () => DB::table('sessions')->truncate());
    $this->tenantB->run(fn () => DB::table('sessions')->truncate());
});

test('a freshly provisioned Tenant A storefront homepage returns 200 under the real session driver', function () {
    $response = $this->get('http://tenant-storefront-a.localhost/');

    $response->assertOk();
});

test('a freshly provisioned Tenant B storefront homepage returns 200 under the real session driver', function () {
    $response = $this->get('http://tenant-storefront-b.localhost/');

    $response->assertOk();
});

test('Tenant A\'s homepage request writes its session into Tenant A\'s own database', function () {
    $this->get('http://tenant-storefront-a.localhost/')->assertOk();

    $count = $this->tenantA->run(fn () => DB::table('sessions')->count());

    expect($count)->toBeGreaterThan(0, 'a real session row must have been written to tenant A\'s own sessions table');
});

test('Tenant B\'s homepage request writes its session into Tenant B\'s own database', function () {
    $this->get('http://tenant-storefront-b.localhost/')->assertOk();

    $count = $this->tenantB->run(fn () => DB::table('sessions')->count());

    expect($count)->toBeGreaterThan(0, 'a real session row must have been written to tenant B\'s own sessions table');
});

test('Tenant A\'s session id never appears in Tenant B\'s sessions table', function () {
    $this->get('http://tenant-storefront-a.localhost/')->assertOk();
    $this->get('http://tenant-storefront-b.localhost/')->assertOk();

    $idsA = $this->tenantA->run(fn () => DB::table('sessions')->pluck('id')->all());
    $idsB = $this->tenantB->run(fn () => DB::table('sessions')->pluck('id')->all());

    expect($idsA)->not->toBeEmpty();
    expect(array_intersect($idsA, $idsB))->toBeEmpty('no session id written for tenant A may also appear in tenant B\'s own sessions table');
});

test('Tenant B\'s session id never appears in Tenant A\'s sessions table', function () {
    $this->get('http://tenant-storefront-b.localhost/')->assertOk();
    $this->get('http://tenant-storefront-a.localhost/')->assertOk();

    $idsB = $this->tenantB->run(fn () => DB::table('sessions')->pluck('id')->all());
    $idsA = $this->tenantA->run(fn () => DB::table('sessions')->pluck('id')->all());

    expect($idsB)->not->toBeEmpty();
    expect(array_intersect($idsB, $idsA))->toBeEmpty('no session id written for tenant B may also appear in tenant A\'s own sessions table');
});

test('an unknown domain still returns a safe 404, not a sessions-table crash', function () {
    $response = $this->get('http://unknown-storefront-domain.localhost/');

    $response->assertNotFound();
});

test('the existing tenant admin My Plan route (TASK-ARCH-009) still works under the real session driver', function () {
    $login = $this->post('http://tenant-storefront-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ]);
    $login->assertRedirect();

    $response = $this->get('http://tenant-storefront-a.localhost/admin/saas/plan');
    $response->assertOk();
    $response->assertSee('My Plan');
});

test('central sessions table is unaffected and remains reachable outside any tenant context', function () {
    // Not a repeat of central-context tests elsewhere - a narrow,
    // R33-specific check that the fix (a NEW tenant-scoped migration)
    // did not disturb the pre-existing, untouched central sessions table.
    expect(Schema::connection('mysql')->hasTable('sessions'))->toBeTrue();
});

test('a tenant provisioned before this fix existed is repaired by the idempotent platform:tenants:migrate-pending command', function () {
    // Deliberately uses its OWN dedicated, disposable tenant rather than
    // the shared tenantA/B fixtures this file's other tests depend on -
    // this test intentionally corrupts a tenant's schema state, and a
    // shared fixture left corrupted by a failed assertion here would
    // silently break every other test in this file on the NEXT run
    // against this project's real, persistent (not rolled back) tenant
    // databases. Learned live while writing this exact test.
    $id = 'tenant-storefront-repair-check';
    DB::connection('mysql')->table('domains')->where('tenant_id', $id)->delete();
    DB::connection('mysql')->table('tenants')->where('id', $id)->delete();

    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);
    app(TenantProvisioner::class)->provision($tenant);

    // Simulates exactly the real-world repair scenario: an already-READY
    // tenant provisioned before this migration file existed would have
    // BOTH no `sessions` table AND no record of that migration in its own
    // `migrations` table (Laravel's per-tenant migration tracking) - so
    // both are removed here, not just the table, to accurately reproduce
    // "this migration has never run for this tenant" rather than the
    // inconsistent "ran once, then the table vanished" state a bare
    // Schema::dropIfExists() alone would leave.
    $tenant->run(function () {
        Schema::dropIfExists('sessions');
        DB::table('migrations')->where('migration', '2026_08_14_150000_create_sessions_table')->delete();
    });

    expect($tenant->run(fn () => Schema::hasTable('sessions')))->toBeFalse();

    $broken = $this->get('http://'.$id.'.localhost/');
    expect($broken->getStatusCode())->toBe(500);

    // Confirms the real repair command fixes it, and that it is itself
    // idempotent (running it twice does not error or duplicate anything,
    // since Laravel's own migration tracking is what makes this safe).
    \Illuminate\Support\Facades\Artisan::call('platform:tenants:migrate-pending', ['--tenant' => [$id]]);
    \Illuminate\Support\Facades\Artisan::call('platform:tenants:migrate-pending', ['--tenant' => [$id]]);

    expect($tenant->run(fn () => Schema::hasTable('sessions')))->toBeTrue();

    $fixed = $this->get('http://'.$id.'.localhost/');
    $fixed->assertOk();

    // Cleanup - this tenant is disposable, provisioned only for this test.
    $data = json_decode($tenant->fresh()->data ?? '{}', true) ?: [];
    if (! empty($data['tenancy_db_name'])) {
        DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    $tenant->domains()->delete();
    $tenant->delete();
});
