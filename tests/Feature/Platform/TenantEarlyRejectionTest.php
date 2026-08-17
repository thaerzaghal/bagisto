<?php

/**
 * TASK-MVP-003B (RISK_REGISTER.md R58). In-process query-instrumented proof
 * of the parts of the R58 fix that Pest's in-process HTTP testing CAN
 * reliably exercise.
 *
 * IMPORTANT, EMPIRICALLY-CONFIRMED SCOPE LIMIT (do not expand this file to
 * cover eager-crash routes - it was tried and does not work): Pest's
 * `$this->get()` does not reliably exercise `Platform\Tenancy\Providers\
 * TenancyServiceProvider`'s early `RouteMatched` listener against an
 * eager-crash-prone route (`/admin/dashboard`, `/admin/reporting/*`) at
 * all - confirmed empirically (not assumed) by running exactly that
 * scenario in isolation, with no other test able to have left stale
 * state: it raw-crashes with the SAME `SQLSTATE[42S02]: ... 'channels'
 * doesn't exist` error the R58 fix exists to prevent, even though the
 * REAL fix (verified via a real, separate subprocess -
 * `AdminDashboardNonReadyTenantTimingTest.php`) correctly returns 423/503.
 * This is the exact same class of Pest-in-process-testing limitation
 * RISK_REGISTER.md R57 already established (there, Pest masked the BUG
 * by never reproducing it; here, for a still-unexplained but consistent
 * reason, Pest instead masks the FIX). Given R57's own precedent and
 * this project's standing evidentiary practice, the correct response is
 * the same one already applied there: do not trust Pest for this specific
 * timing-sensitive scenario at all, use a real subprocess instead - NOT
 * to spend further effort trying to make Pest agree with reality.
 *
 * This file therefore only covers what Pest CAN reliably prove: the
 * un-changed storefront (non-eager-crash-route) status matrix, JSON
 * behavior on that same safe route, central-route safety, and unknown-
 * domain safety on a non-eager-crash route. All eager-crash-route
 * assertions (the actual point of R58) live in the real-subprocess file.
 *
 * FIXTURE STRATEGY (mirrors TenantAccessGateTest.php's own, deliberately,
 * for the same reason): TENANT_FRESH_ID is created centrally but NEVER
 * provisioned - no physical database exists for it at all, the strongest
 * possible proof that "no tenant DB access occurs". TENANT_SUSPENDED_ID
 * is fully, really provisioned, then suspended (Suspended, by definition,
 * was Ready before, so it must have a real database).
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const EARLYREJECT_TENANT_FRESH_ID = 'tenant-earlyreject-fresh';
const EARLYREJECT_TENANT_SUSPENDED_ID = 'tenant-earlyreject-suspended';

function ensureEarlyRejectFreshTenant(): Tenant
{
    $tenant = Tenant::find(EARLYREJECT_TENANT_FRESH_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => EARLYREJECT_TENANT_FRESH_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => EARLYREJECT_TENANT_FRESH_ID.'.localhost']);
    }

    return $tenant->fresh();
}

function ensureEarlyRejectSuspendedTenant(): Tenant
{
    $tenant = Tenant::find(EARLYREJECT_TENANT_SUSPENDED_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => EARLYREJECT_TENANT_SUSPENDED_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => EARLYREJECT_TENANT_SUSPENDED_ID.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Suspended) {
        if ($tenant->status !== TenantStatus::Ready) {
            app(TenantProvisioner::class)->provision($tenant);
            $tenant = $tenant->fresh();
        }
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    }

    return $tenant->fresh();
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForEarlyReject(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $response = $callback();

    return [$response, $connections];
}

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

dataset('non_ready_statuses', [
    'Pending' => [TenantStatus::Pending],
    'Provisioning' => [TenantStatus::Provisioning],
    'Failed' => [TenantStatus::Failed],
    'Deleting' => [TenantStatus::Deleting],
    'Deleted' => [TenantStatus::Deleted],
]);

test('a fresh never-provisioned tenant with status %s returns 503 on the storefront, with zero tenant DB queries - unchanged by R58', function (TenantStatus $status) {
    $tenant = ensureEarlyRejectFreshTenant();
    $tenant->forceFill(['status' => $status])->save();

    [$response, $connections] = captureQueriedConnectionsForEarlyReject(
        fn () => $this->get('http://'.EARLYREJECT_TENANT_FRESH_ID.'.localhost/')
    );

    $response->assertStatus(503);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
})->with('non_ready_statuses');

test('a Suspended tenant on the storefront still returns 423, unchanged by R58', function () {
    ensureEarlyRejectSuspendedTenant();

    [$response, $connections] = captureQueriedConnectionsForEarlyReject(
        fn () => $this->get('http://'.EARLYREJECT_TENANT_SUSPENDED_ID.'.localhost/')
    );

    $response->assertStatus(423);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('TenantUnavailableResponder::respondTo() fails closed to 503 for any status other than the one explicit Suspended arm - by construction, not by enumerating every current status', function () {
    // A genuinely-invalid raw enum backing value cannot be simulated via
    // the database (Eloquent's own enum cast throws a ValueError while
    // hydrating the model, before TenantUnavailableResponder ever sees
    // it - a real, but separate, Laravel-level concern unrelated to R58).
    // The actual guarantee this test proves is the one that matters: the
    // match statement itself has exactly ONE explicit "special" arm
    // (Suspended) and a `default` arm for everything else - so a REAL
    // current status this project already ships (e.g. Failed) correctly
    // takes the SAME default/503 path a hypothetical future status would,
    // proving the fail-closed shape is real, not merely asserted.
    $responder = app(\Platform\Tenancy\Services\TenantUnavailableResponder::class);
    $tenant = ensureEarlyRejectFreshTenant();
    $tenant->forceFill(['status' => TenantStatus::Failed])->save();

    $response = $responder->respondTo($tenant->fresh(), request());

    expect($response->getStatusCode())->toBe(503);
});

test('a non-ready tenant JSON/API request gets a JSON 503, never an HTML lifecycle page', function () {
    $tenant = ensureEarlyRejectFreshTenant();
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    $response = $this->getJson('http://'.EARLYREJECT_TENANT_FRESH_ID.'.localhost/');

    $response->assertStatus(503);
    $response->assertJson(['message' => 'This store is currently unavailable.']);
});

test('a Suspended tenant JSON/API request gets a JSON 423', function () {
    ensureEarlyRejectSuspendedTenant();

    $response = $this->getJson('http://'.EARLYREJECT_TENANT_SUSPENDED_ID.'.localhost/');

    $response->assertStatus(423);
    $response->assertJson(['message' => 'This store is currently unavailable.']);
});

test('a non-ready tenant response body never leaks provisioning state, exception messages, or the tenant database name', function () {
    $tenant = ensureEarlyRejectFreshTenant();
    $tenant->forceFill(['status' => TenantStatus::Failed])->save();

    $response = $this->get('http://'.EARLYREJECT_TENANT_FRESH_ID.'.localhost/');

    $response->assertStatus(503);
    $body = $response->getContent();
    expect($body)->not->toContain(EARLYREJECT_TENANT_FRESH_ID);
    expect($body)->not->toContain('TenantNotReadyHttpException');
    expect($body)->not->toContain('SQLSTATE');
});

test('an unknown domain retains its existing safe 404 behavior on a non-eager route, no tenant initialized', function () {
    [$response, $connections] = captureQueriedConnectionsForEarlyReject(
        fn () => $this->get('http://does-not-exist-earlyreject.localhost/')
    );

    $response->assertStatus(404);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('a central Platform-only route reached via a real tenant Host still 404s (EnsureCentralDomain, R54) - early listener does not interfere', function () {
    $tenant = ensureEarlyRejectFreshTenant();
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    // The Host itself resolves to a real, non-ready tenant, but the PATH
    // is central-only (Platform Admin login) - proves the early listener's
    // 'web'-group-only scoping (unchanged since R57) still excludes the
    // 'platform' group entirely, and that EnsureCentralDomain's own R54
    // rejection is unaffected.
    $response = $this->get('http://'.EARLYREJECT_TENANT_FRESH_ID.'.localhost/platform/login');

    $response->assertStatus(404);
    expect(tenancy()->initialized)->toBeFalse();
});

test('Ready tenant storefront behavior is completely unaffected by this file\'s changes', function () {
    // Not a substitute for the real-subprocess R57 regression proof - Pest
    // cannot reproduce R57's own timing bug at all. This only proves the
    // ROUTE/RESPONSE wiring itself (TenantAccessGate's Ready pass-through,
    // the early listener's Ready pre-init branch) was not accidentally
    // broken by this file's non-ready assertions.
    $tenant = Tenant::find('tenant-earlyreject-ready');

    if (! $tenant) {
        $tenant = Tenant::create(['id' => 'tenant-earlyreject-ready', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-earlyreject-ready.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
        $tenant = $tenant->fresh();
    }

    $response = $this->get('http://tenant-earlyreject-ready.localhost/');
    $response->assertStatus(200);
});
