<?php

/**
 * TASK-ARCH-014 - Tenant Readiness Access Gate test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered routes
 * (Shop, Admin, Shop API, Platform Admin), real `Platform\Tenancy\Http\
 * Middleware\TenantAccessGate`. Nothing mocked.
 *
 * FIXTURE STRATEGY - deliberately different from TenantSuspensionTest's:
 * this file uses a dedicated tenant (TENANT_FRESH_ID) that is created
 * centrally (a `tenants` row + `domains` row) but NEVER provisioned - no
 * physical tenant database, DB user, or migration ever runs for it. Every
 * non-Ready status this file proves "blocked" (Pending, Provisioning,
 * Failed, Deleting, Deleted) is set directly on that never-provisioned
 * tenant via forceFill(). This is a STRONGER proof of task item 4/10/12
 * ("no tenant DB access, even where the DB may not exist at all") than
 * using an already-provisioned tenant would be: if the gate had a bug
 * that let a request through to `InitializeTenancyByDomain` for a
 * non-Ready status, the very next real query against that connection
 * would fail loudly (unknown database), not silently succeed - there is
 * no working database behind this fixture to accidentally mask a bypass.
 *
 * A second fixture (TENANT_READY_ID) is fully, really provisioned - it
 * proves the Ready-allowed path actually renders a working store, and
 * (via a temporary forceFill + restore) that a status transition back to
 * Ready immediately restores access on an otherwise-real, working tenant.
 *
 * A third, single-purpose fixture (TENANT_RETRY_ID) proves Platform
 * Admin's own provision/retry action still works for a Failed tenant
 * after this gate exists (task item 16/9) - i.e. the gate does not
 * accidentally block Platform Admin's own trusted `$tenant->run()`-based
 * provisioning calls.
 *
 * SAME-PROCESS TEST ARTIFACT (R35/R37): `tenancy()->end()` is called
 * after any status mutation that is followed by ANOTHER real HTTP
 * request to the SAME tenant domain within the SAME test() function -
 * defensive, matching the established lesson that `Stancl\Tenancy\
 * Tenancy::initialize()`'s same-tenant-ID fast path can otherwise serve a
 * stale, pre-transition Tenant object across two requests sharing one
 * Pest test's container. `TenantAccessGate` itself is NOT subject to this
 * (fresh central resolve on every request, independent of `Tenancy::
 * initialize()`'s internal caching) - purely precautionary for whatever
 * runs AFTER it allows a request through.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const GATE_TEST_TENANT_READY_ID = 'tenant-gate-ready';
const GATE_TEST_TENANT_FRESH_ID = 'tenant-gate-fresh';
const GATE_TEST_TENANT_RETRY_ID = 'tenant-gate-retry';

function ensureGateTestReadyTenant(): Tenant
{
    $tenant = Tenant::find(GATE_TEST_TENANT_READY_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => GATE_TEST_TENANT_READY_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => GATE_TEST_TENANT_READY_ID.'.localhost']);
    }

    if ($tenant->status === TenantStatus::Suspended) {
        app(TenantLifecycle::class)->reactivate($tenant);
        $tenant = $tenant->fresh();
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    return $tenant->fresh();
}

function ensureGateTestFreshTenant(): Tenant
{
    $tenant = Tenant::find(GATE_TEST_TENANT_FRESH_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => GATE_TEST_TENANT_FRESH_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => GATE_TEST_TENANT_FRESH_ID.'.localhost']);
    }

    // Deliberately never provisioned - see file docblock. Reset to Pending
    // at the start of every test that needs it, regardless of whatever
    // status a previous test in this file left it in.
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    return $tenant->fresh();
}

function ensureGateTestRetryTenant(): Tenant
{
    $tenant = Tenant::find(GATE_TEST_TENANT_RETRY_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => GATE_TEST_TENANT_RETRY_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => GATE_TEST_TENANT_RETRY_ID.'.localhost']);
    }

    return $tenant->fresh();
}

function ensureGateTestPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'gate-test-admin@example.test'],
        ['name' => 'Gate Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForGate(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'gate-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForGate(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $response = $callback();

    return [$response, $connections];
}

beforeEach(function () {
    ensureGateTestPlatformAdmin();

    $this->readyTenant = ensureGateTestReadyTenant();
    $this->freshTenant = ensureGateTestFreshTenant();
    $this->retryTenant = ensureGateTestRetryTenant();
});

test('1. a Ready tenant storefront request is allowed', function () {
    $response = $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/');

    $response->assertOk();
});

test('2-6. a non-Ready tenant storefront request is blocked with 503', function (TenantStatus $status) {
    $this->freshTenant->forceFill(['status' => $status])->save();

    $response = $this->get('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/');

    $response->assertStatus(503);
    $response->assertSee('currently unavailable');
})->with([
    'Pending' => [TenantStatus::Pending],
    'Provisioning' => [TenantStatus::Provisioning],
    'Failed' => [TenantStatus::Failed],
    'Deleting' => [TenantStatus::Deleting],
    'Deleted' => [TenantStatus::Deleted],
]);

test('7. Suspended retains the approved 423 behavior, not 503', function () {
    app(TenantLifecycle::class)->suspend($this->readyTenant);

    $response = $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/');

    $response->assertStatus(423);
    $response->assertSee('currently unavailable');

    app(TenantLifecycle::class)->reactivate($this->readyTenant->fresh());
});

test('8. a non-Ready tenant admin route is blocked', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Failed])->save();

    $response = $this->get('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/admin/login');

    $response->assertStatus(503);
});

test('9. a non-Ready tenant API route is blocked with structured JSON, no internal details leaked', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Provisioning])->save();

    $response = $this->getJson('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/api/products');

    $response->assertStatus(503);
    $response->assertJsonStructure(['message']);
    expect($response->json('message'))->toContain('unavailable');

    $body = $response->getContent();
    expect($body)->not->toContain('Exception');
    expect($body)->not->toContain('Stack trace');
    expect($body)->not->toContain('provisioning');
    expect($body)->not->toContain(GATE_TEST_TENANT_FRESH_ID);
});

test('10. an unknown domain still returns 404, not the unavailable response', function () {
    $response = $this->get('http://unknown-domain-for-gate-check.localhost/');

    $response->assertStatus(404);
    $response->assertDontSee('currently unavailable');
});

test('11. a blocked non-Ready request never initializes tenancy', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Pending])->save();

    $response = $this->get('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/');

    $response->assertStatus(503);
    expect(tenancy()->initialized)->toBeFalse();
});

test('12. a blocked non-Ready request never queries the tenant database connection', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Failed])->save();

    [$response, $connections] = captureQueriedConnectionsForGate(
        fn () => $this->get('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/')
    );

    $response->assertStatus(503);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('13. a Ready tenant remains fully accessible while another tenant is Failed', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Failed])->save();

    $blockedA = $this->get('http://'.GATE_TEST_TENANT_FRESH_ID.'.localhost/');
    $blockedA->assertStatus(503);

    $okB = $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/');
    $okB->assertOk();

    $okBAdmin = $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/admin/login');
    $okBAdmin->assertOk();
});

test('14. a status change to Ready restores storefront access on the very next request', function () {
    $this->readyTenant->forceFill(['status' => TenantStatus::Failed])->save();
    tenancy()->end();
    $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/')->assertStatus(503);

    $this->readyTenant->fresh()->forceFill(['status' => TenantStatus::Ready])->save();
    tenancy()->end();

    $this->get('http://'.GATE_TEST_TENANT_READY_ID.'.localhost/')->assertOk();
});

test('15. Platform Admin remains fully reachable while a tenant is non-Ready', function () {
    $this->freshTenant->forceFill(['status' => TenantStatus::Failed])->save();

    loginPlatformAdminForGate($this);

    $list = $this->get('http://localhost/platform/tenants');
    $list->assertOk();
    $list->assertSee(GATE_TEST_TENANT_FRESH_ID);

    $detail = $this->get('http://localhost/platform/tenants/'.GATE_TEST_TENANT_FRESH_ID);
    $detail->assertOk();
    $detail->assertSee('failed');
});

test('16. a Failed tenant can still be retried/provisioned through Platform Admin', function () {
    $this->retryTenant->forceFill(['status' => TenantStatus::Failed])->save();

    loginPlatformAdminForGate($this);

    $response = $this->post('http://localhost/platform/tenants/'.GATE_TEST_TENANT_RETRY_ID.'/provision');

    $response->assertRedirect();
    expect($this->retryTenant->fresh()->status)->toBe(TenantStatus::Ready);

    tenancy()->end();
    $this->get('http://'.GATE_TEST_TENANT_RETRY_ID.'.localhost/')->assertOk();
});
