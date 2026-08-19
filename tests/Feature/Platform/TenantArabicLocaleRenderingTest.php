<?php

/**
 * TASK-MVP-012 (DECISION_LOG.md). Proves the RENDERED HTML behavior a
 * database-only assertion cannot: a newly provisioned Arabic-first
 * tenant's storefront and Admin both actually emit `lang="ar"
 * dir="rtl"` on a real request, with zero manual locale selection -
 * exercising `Webkul\Shop\Http\Middleware\Locale` (already correct,
 * untouched) for the storefront and the new `Platform\Tenancy\Http\
 * Middleware\SetTenantAdminLocale` for tenant Admin. Also proves
 * Platform Admin (the internal Technify operator panel) is completely
 * unaffected - still `lang="en"`, never `dir="rtl"` - and that two
 * differently-configured tenants never bleed their locale into each
 * other across consecutive requests in the same process.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

const TAR_TEST_IDS = ['tar-a', 'tar-b'];

function cleanupTarTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (TAR_TEST_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbUsername = $data['tenancy_db_username'] ?? null;
            $dbName = $data['tenancy_db_name'] ?? null;

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

function ensureTarPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'tar-test-admin@example.test'],
        ['name' => 'TAR Test Admin', 'password' => 'platform-secret-1']
    );
}

beforeEach(function () {
    cleanupTarTestTenants();
    ensureTarPlatformAdmin();
});

afterEach(fn () => cleanupTarTestTenants());

test('6. a first-time, unauthenticated storefront request for a new tenant renders Arabic/RTL with zero manual locale selection', function () {
    $tenant = Tenant::create(['id' => 'tar-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tar-a.localhost']);
    app(TenantProvisioner::class)->provision($tenant);

    $response = $this->get('http://tar-a.localhost/');

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('lang="ar"');
    expect($html)->toContain('dir="rtl"');
});

test('7. tenant Admin login (pre-auth) and dashboard (post-auth) both render Arabic/RTL with zero manual locale selection', function () {
    $tenant = Tenant::create(['id' => 'tar-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tar-a.localhost']);
    app(TenantProvisioner::class)->provision($tenant);

    $loginPage = $this->get('http://tar-a.localhost/admin/login');
    $loginPage->assertOk();
    expect($loginPage->getContent())->toContain('lang="ar"');
    expect($loginPage->getContent())->toContain('dir="rtl"');

    $this->post('http://tar-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    $dashboard = $this->get('http://tar-a.localhost/admin/dashboard');
    $dashboard->assertOk();
    expect($dashboard->getContent())->toContain('lang="ar"');
    expect($dashboard->getContent())->toContain('dir="rtl"');
});

test('8. Platform Admin (the internal operator panel) remains English/LTR - never dir="rtl" - regardless of Arabic-first tenants existing', function () {
    // A real Arabic-first tenant exists at this point, proving Platform
    // Admin's own English/LTR rendering is genuinely independent of it,
    // not merely "no Arabic tenant happened to exist yet."
    $tenant = Tenant::create(['id' => 'tar-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tar-a.localhost']);
    app(TenantProvisioner::class)->provision($tenant);

    $loginPage = $this->get('http://localhost/platform/login');
    $loginPage->assertOk();
    expect($loginPage->getContent())->toContain('lang="en"');
    expect($loginPage->getContent())->not->toContain('dir="rtl"');

    $this->post('http://localhost/platform/login', [
        'email' => 'tar-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));

    $dashboard = $this->get(route('platform.dashboard'));
    $dashboard->assertOk();
    expect($dashboard->getContent())->toContain('lang="en"');
    expect($dashboard->getContent())->not->toContain('dir="rtl"');
});

test('11. two differently-configured tenants never bleed Admin locale into each other across consecutive requests', function () {
    $tenantA = Tenant::create(['id' => 'tar-a', 'status' => TenantStatus::Pending]);
    $tenantA->domains()->create(['domain' => 'tar-a.localhost']);
    app(TenantProvisioner::class)->provision($tenantA);

    $tenantB = Tenant::create(['id' => 'tar-b', 'status' => TenantStatus::Pending]);
    $tenantB->domains()->create(['domain' => 'tar-b.localhost']);
    app(TenantProvisioner::class)->provision($tenantB);

    // Reconfigure tenant B to English-default only, AFTER both are already
    // provisioned - simulating a merchant who explicitly changed their own
    // store's language back, or a legacy-shaped tenant - to prove this is
    // genuine per-request/per-tenant resolution, not incidental agreement.
    $tenantB->run(function () {
        $enId = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => $enId]);
    });

    $this->post('http://tar-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
    $dashboardA1 = $this->get('http://tar-a.localhost/admin/dashboard');
    expect($dashboardA1->getContent())->toContain('lang="ar"');
    expect($dashboardA1->getContent())->toContain('dir="rtl"');

    $this->post('http://tar-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
    $dashboardB = $this->get('http://tar-b.localhost/admin/dashboard');
    expect($dashboardB->getContent())->toContain('lang="en"');
    expect($dashboardB->getContent())->not->toContain('dir="rtl"');

    // Back to tenant A - must still be Arabic, not leaked-over English from B.
    $dashboardA2 = $this->get('http://tar-a.localhost/admin/dashboard');
    expect($dashboardA2->getContent())->toContain('lang="ar"');
    expect($dashboardA2->getContent())->toContain('dir="rtl"');
});
