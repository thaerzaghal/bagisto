<?php

/**
 * TASK-MVP-004B (RISK_REGISTER.md R51) - production Host-error handling
 * regression matrix.
 *
 * A real production bug was found during the pilot deployment: under
 * APP_DEBUG=false, an unrecognized/unknown Host header produced a raw 500
 * instead of the clean 404 bootstrap/app.php already tried to configure.
 * Every OTHER test in this repo's history ran under APP_DEBUG=true (the
 * local/CI default), under which Webkul\Core\Exceptions\Handler::register()
 * early-returns and never registers anything - which is exactly why this
 * bug was never caught until a real APP_DEBUG=false deployment. This file
 * therefore explicitly, deliberately overrides config('app.debug') to false
 * for every test in it - see beforeEach() below - rather than relying on
 * whatever the ambient test environment's APP_DEBUG happens to resolve to
 * (nothing in phpunit.xml/.env.testing pins it either way today).
 *
 * The fix under test lives in Platform\Tenancy\Providers\
 * TenancyServiceProvider::handleUnresolvedTenantDomains() - see that
 * method's own docblock for the full, source-verified root cause and why a
 * stancl/tenancy $onFail hook (not a Laravel exception-handler registration)
 * was the correct fix. Real MySQL, real HTTP requests through the actual
 * registered routes/middleware - nothing mocked.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const HOSTERR_TEST_TENANT_READY_ID = 'tenant-hosterr-ready';
const HOSTERR_TEST_TENANT_PENDING_ID = 'tenant-hosterr-pending';
const HOSTERR_UNKNOWN_HOST = 'does-not-exist.hosterr-probe.localhost';

function ensureHosterrReadyTenant(): Tenant
{
    $tenant = Tenant::find(HOSTERR_TEST_TENANT_READY_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => HOSTERR_TEST_TENANT_READY_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => HOSTERR_TEST_TENANT_READY_ID.'.localhost']);
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

function ensureHosterrPendingTenant(): Tenant
{
    $tenant = Tenant::find(HOSTERR_TEST_TENANT_PENDING_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => HOSTERR_TEST_TENANT_PENDING_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => HOSTERR_TEST_TENANT_PENDING_ID.'.localhost']);
    }

    // Deliberately never provisioned - no physical tenant database exists
    // for this fixture at all (see TenantAccessGateTest.php's identical
    // "fresh tenant" strategy for why this is a stronger proof of "no
    // tenant DB access" than an already-provisioned tenant would be).
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    return $tenant->fresh();
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForHosterr(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $response = $callback();

    return [$response, $connections];
}

beforeEach(function () {
    // The one thing every test in this file exists to force - see file
    // docblock. Applied per-test (not via phpunit <env>) because
    // Webkul\Core\Exceptions\Handler::register() reads config('app.debug')
    // freshly at Handler-construction time (which happens per-request, per
    // exception, not once at process boot) - a runtime config() override
    // set before the request is dispatched is correctly seen by it.
    config(['app.debug' => false]);

    $this->readyTenant = ensureHosterrReadyTenant();
    $this->pendingTenant = ensureHosterrPendingTenant();
});

test('A. the central host still serves a real central route under APP_DEBUG=false', function () {
    $response = $this->get('http://localhost/platform/login');

    $response->assertOk();
});

test('B. an unknown Host header returns a clean 404 under APP_DEBUG=false, never a 500', function () {
    $response = $this->get('http://'.HOSTERR_UNKNOWN_HOST.'/');

    $response->assertStatus(404);
});

test('C. an unknown Host header JSON request returns a clean JSON 404 under APP_DEBUG=false, with no exception/stack-trace leakage', function () {
    $response = $this->getJson('http://'.HOSTERR_UNKNOWN_HOST.'/api/products');

    $response->assertStatus(404);
    $response->assertJsonStructure(['message']);

    $body = $response->getContent();
    expect($body)->not->toContain('Exception');
    expect($body)->not->toContain('Stack trace');
    expect($body)->not->toContain('TenantCouldNotBeIdentified');
    expect($body)->not->toContain(HOSTERR_UNKNOWN_HOST);
});

test('D. no tenant database connection or query occurs for an unknown Host header', function () {
    [$response, $connections] = captureQueriedConnectionsForHosterr(
        fn () => $this->get('http://'.HOSTERR_UNKNOWN_HOST.'/')
    );

    $response->assertStatus(404);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('E. a valid Ready tenant host still works normally under APP_DEBUG=false', function () {
    $response = $this->get('http://'.HOSTERR_TEST_TENANT_READY_ID.'.localhost/');

    $response->assertOk();
});

test('F. a Suspended tenant still returns 423 under APP_DEBUG=false', function () {
    app(TenantLifecycle::class)->suspend($this->readyTenant);

    $response = $this->get('http://'.HOSTERR_TEST_TENANT_READY_ID.'.localhost/');

    $response->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->readyTenant->fresh());
});

test('G. a non-Ready (Pending) tenant still follows TenantAccessGate 503 semantics under APP_DEBUG=false, without any tenant DB query', function () {
    [$response, $connections] = captureQueriedConnectionsForHosterr(
        fn () => $this->get('http://'.HOSTERR_TEST_TENANT_PENDING_ID.'.localhost/')
    );

    $response->assertStatus(503);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('H. a generic, unrelated application exception on a resolved tenant is NOT swallowed or converted into a tenancy 404', function () {
    // Proves the $onFail hook (TenancyServiceProvider::
    // handleUnresolvedTenantDomains()) is narrowly scoped to
    // TenantCouldNotBeIdentifiedException specifically, not a blanket
    // catch-all - an ad hoc 'web'-group route is used so this needs no
    // packages/Webkul/* modification to exercise a genuine, uncaught,
    // unrelated exception mid-request.
    Route::middleware('web')->get('/hosterr-generic-exception-probe', function () {
        throw new RuntimeException('TASK-MVP-004B regression test H - deliberate generic exception');
    });

    $response = $this->getJson('http://'.HOSTERR_TEST_TENANT_READY_ID.'.localhost/hosterr-generic-exception-probe');

    $response->assertStatus(500);
    expect($response->json())->not->toHaveKey('message');

    $body = $response->getContent();
    expect($body)->not->toContain('TASK-MVP-004B regression test H');
    expect($body)->not->toContain('RuntimeException');
});
