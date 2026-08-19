<?php

/**
 * TASK-MVP-015 - Merchant Onboarding Checklist / First-Real-Merchant
 * Readiness.
 *
 * Proves the small, DERIVED-ONLY additions to `Platform\Admin\Http\
 * Controllers\TenantController::show()`/`tenants/show.blade.php` (owner
 * identity, clickable storefront/Merchant Admin URLs, and the "Onboarding
 * Status" readiness panel) are correct, authoritative, and strictly
 * scoped to the one tenant being viewed - no new persistence anywhere,
 * real HTTP requests and real MySQL tenant provisioning throughout,
 * nothing mocked.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;

uses(PlatformIntegrationTestCase::class);

const TOR_PROVISIONED_IDS = ['tor-a', 'tor-b'];
const TOR_CENTRAL_ONLY_IDS = ['tor-pending', 'tor-failed', 'tor-provisioning'];

function cleanupTorTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach ([...TOR_PROVISIONED_IDS, ...TOR_CENTRAL_ONLY_IDS] as $id) {
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

function ensureTorPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'tor-platform-admin@example.test'],
        ['name' => 'TOR Platform Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForTor(TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'tor-platform-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * Real provisioning (`TenantProvisioner::provision()`, unmocked) - the
 * same pipeline `MerchantOnboarding::register()` itself calls. Owner
 * identity is set directly on the central `Tenant` row, exactly as
 * `MerchantOnboarding::register()` already does before provisioning
 * runs. Provisioning starts the default ('free') plan/subscription
 * automatically via `ensureInitialSubscriptionStarted()` - a fresh
 * tenant is therefore plan/subscription-consistent and Arabic-first by
 * construction, with no extra setup needed here.
 */
function provisionTorReadyTenant(string $id, string $ownerName, string $ownerEmail): Tenant
{
    $tenant = Tenant::create([
        'id' => $id,
        'status' => TenantStatus::Pending,
        'owner_name' => $ownerName,
        'owner_email' => $ownerEmail,
    ]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);

    app(TenantProvisioner::class)->provision($tenant);

    return $tenant->fresh();
}

beforeEach(function () {
    cleanupTorTenants();
    ensureTorPlatformAdmin();
});

afterEach(fn () => cleanupTorTenants());

test('1. an unauthenticated caller cannot view a tenant\'s show page', function () {
    $tenant = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');

    $response = $this->get('http://localhost/platform/tenants/'.$tenant->getTenantKey());

    $response->assertRedirect(route('platform.login'));
});

test('2. the show page displays the correct tenant\'s own owner identity and domain, never another tenant\'s', function () {
    $tenantA = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');
    $tenantB = provisionTorReadyTenant('tor-b', 'Basil Owner', 'tor-b-owner@example.test');

    loginPlatformAdminForTor($this);

    $pageA = $this->get('http://localhost/platform/tenants/'.$tenantA->getTenantKey());
    $pageA->assertOk();
    $pageA->assertSee('Amina Owner');
    $pageA->assertSee('tor-a-owner@example.test');
    $pageA->assertSee('tor-a.localhost');
    $pageA->assertDontSee('Basil Owner');
    $pageA->assertDontSee('tor-b-owner@example.test');
    $pageA->assertDontSee('tor-b.localhost');

    $pageB = $this->get('http://localhost/platform/tenants/'.$tenantB->getTenantKey());
    $pageB->assertOk();
    $pageB->assertSee('Basil Owner');
    $pageB->assertSee('tor-b-owner@example.test');
    $pageB->assertDontSee('Amina Owner');
    $pageB->assertDontSee('tor-a-owner@example.test');
});

test('3. the show page renders clickable storefront and Merchant Admin URLs for the tenant\'s own domain only', function () {
    $tenant = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');

    loginPlatformAdminForTor($this);

    $response = $this->get('http://localhost/platform/tenants/'.$tenant->getTenantKey());

    $response->assertOk();
    $response->assertSee('href="http://tor-a.localhost"', false);
    $response->assertSee('href="http://tor-a.localhost/'.config('app.admin_url').'/login"', false);
});

test('4. the Onboarding Status panel correctly distinguishes Ready from Pending/Failed/Provisioning without ever touching a physical database for a non-Ready tenant', function () {
    loginPlatformAdminForTor($this);

    $ready = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');
    $readyPage = $this->get('http://localhost/platform/tenants/'.$ready->getTenantKey());
    $readyPage->assertOk();
    $readyPage->assertSeeInOrder(['Tenant provisioned', 'Yes']);

    foreach ([
        'tor-pending' => TenantStatus::Pending,
        'tor-failed' => TenantStatus::Failed,
        'tor-provisioning' => TenantStatus::Provisioning,
    ] as $id => $status) {
        $tenant = Tenant::create(['id' => $id, 'status' => $status, 'owner_name' => 'Not Ready Owner', 'owner_email' => $id.'@example.test']);
        $tenant->domains()->create(['domain' => $id.'.localhost']);

        // No physical database exists for any of these - if the
        // controller mistakenly tried to run a tenant-context query for
        // a non-Ready tenant, this request would throw instead of
        // rendering. It must render cleanly regardless.
        $page = $this->get('http://localhost/platform/tenants/'.$id);
        $page->assertOk();
        $page->assertSee("Status: {$status->value}");

        // Arabic locale check must read "N/A" (not Yes/No) - not
        // knowable without a physical database.
        $page->assertSeeInOrder(['Arabic locale configured', 'N/A']);
    }
});

test('5. plan & subscription consistency is correctly derived: true for a freshly provisioned tenant, false once the subscription is canceled', function () {
    $tenant = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');

    loginPlatformAdminForTor($this);

    $before = $this->get('http://localhost/platform/tenants/'.$tenant->getTenantKey());
    $before->assertOk();
    $before->assertSeeInOrder(['Plan & subscription consistent', 'Yes']);

    $subscription = Subscription::currentFor($tenant);
    expect($subscription)->not->toBeNull();
    $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();

    $after = $this->get('http://localhost/platform/tenants/'.$tenant->getTenantKey());
    $after->assertOk();
    $after->assertSeeInOrder(['Plan & subscription consistent', 'No']);
});

test('6. Arabic locale readiness is computed live and strictly scoped to the tenant being viewed, never leaking another tenant\'s locale state', function () {
    $tenantA = provisionTorReadyTenant('tor-a', 'Amina Owner', 'tor-a-owner@example.test');
    $tenantB = provisionTorReadyTenant('tor-b', 'Basil Owner', 'tor-b-owner@example.test');

    // Both are freshly provisioned - Arabic-first by construction
    // (TASK-MVP-012). Deliberately break ONLY tenant B's own default
    // locale back to English, entirely inside tenant B's own database.
    $tenantB->run(function () {
        $englishId = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => $englishId]);
    });

    loginPlatformAdminForTor($this);

    $pageA = $this->get('http://localhost/platform/tenants/'.$tenantA->getTenantKey());
    $pageA->assertOk();
    $pageA->assertSeeInOrder(['Arabic locale configured', 'Yes']);

    $pageB = $this->get('http://localhost/platform/tenants/'.$tenantB->getTenantKey());
    $pageB->assertOk();
    $pageB->assertSeeInOrder(['Arabic locale configured', 'No']);

    // Re-check A again, after viewing B - proves the check for A was
    // never contaminated by having just read B's (now-broken) state.
    $pageAAgain = $this->get('http://localhost/platform/tenants/'.$tenantA->getTenantKey());
    $pageAAgain->assertOk();
    $pageAAgain->assertSeeInOrder(['Arabic locale configured', 'Yes']);
});
