<?php

/**
 * TASK-MVP-008 (RISK_REGISTER.md R68). Proves `Platform\Tenancy\Exceptions\
 * CentralSafeExceptionHandler` (registered via `Container::extend()` in
 * `TenancyServiceProvider::boot()`) fixes the broader-than-signup class of
 * central-host error-rendering failure, without regressing R51/R53
 * (unknown-domain handling), R57/R58 (non-ready-tenant handling), or normal
 * tenant/Bagisto Admin error rendering.
 *
 * IN-PROCESS vs REAL SUBPROCESS: the 404/405/429 cases in this file are all
 * genuinely reproducible in-process - none of them depend on Laravel's own
 * `VerifyCsrfToken::runningUnitTests()` bypass (that check is CSRF-specific
 * only) or on any timing-sensitive eager-construction ordering. The CSRF/419
 * case is deliberately NOT tested here: `VerifyCsrfToken::handle()`
 * unconditionally bypasses verification whenever `app()->runningUnitTests()`
 * is true (`APP_ENV=testing`, this whole suite's own environment) - there is
 * no way to make an in-process Pest request ever actually throw
 * `TokenMismatchException` at all, regardless of any other setup. See
 * `CentralSafeExceptionHandlerRealHandlerTest.php` for the real-subprocess
 * proof of the 419 case specifically (the same established technique R57/R67
 * already use).
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

const CSEH_TEST_IDS = ['cseh-suspended', 'cseh-ready'];

function cleanupCsehTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (CSEH_TEST_IDS as $id) {
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

/**
 * @return array{0: TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForCseh(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    return [$callback(), $connections];
}

beforeEach(function () {
    cleanupCsehTestTenants();
    config(['app.debug' => false]);
});

afterEach(function () {
    config(['app.debug' => true]);
    cleanupCsehTestTenants();
});

test('1. an unmatched-or-verb-mismatched central POST route returns a clean 4xx, never 500, with zero tenant DB queries', function () {
    // Bagisto's own Shop package registers a broad GET-only catch-all
    // slug route, so this path is actually matched BY PATTERN (just not
    // by verb) - confirmed empirically to produce 405, not 404. Either
    // is a correct, clean outcome; the property under test is "never a
    // raw 500", not which specific 4xx code Bagisto's own routing table
    // happens to produce for this exact path.
    [$response, $connections] = captureQueriedConnectionsForCseh(
        fn () => $this->post('http://localhost/this-genuinely-does-not-exist-central-xyz')
    );

    expect($response->getStatusCode())->toBeLessThan(500);
    expect($response->getContent())->not->toContain('SQLSTATE');
    expect($response->getContent())->not->toContain('locales');
    expect($response->getContent())->not->toContain('Stack trace');
    expect($connections)->not->toContain('tenant');
});

test('2. a central method-not-allowed request returns 405, not 500', function () {
    $response = $this->delete('http://localhost/platform/login');

    $response->assertStatus(405);
    expect($response->getContent())->not->toContain('SQLSTATE');
    expect($response->getContent())->not->toContain('locales');
});

test('3. a central throttled request returns 429, not 500, and preserves the Retry-After header', function () {
    config(['platform.signup.enabled' => true]);

    $last = null;

    for ($i = 0; $i < 4; $i++) {
        $last = $this->post('http://localhost/join', ['slug' => '']);
    }

    $last->assertStatus(429);
    expect($last->headers->has('Retry-After'))->toBeTrue();
    expect($last->getContent())->not->toContain('SQLSTATE');
    expect($last->getContent())->not->toContain('locales');
});

test('4. an unknown/unresolved host - ordinary GET - still gets the established clean 404 (R51/R53 unaffected)', function () {
    [$response, $connections] = captureQueriedConnectionsForCseh(
        fn () => $this->get('http://totally-unknown-fake-host-cseh.test/')
    );

    $response->assertStatus(404);
    $response->assertJson(['message' => 'Not Found']);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('5. an unknown/unresolved host - unmatched-or-verb-mismatched POST - now returns a clean 4xx instead of crashing', function () {
    [$response, $connections] = captureQueriedConnectionsForCseh(
        fn () => $this->post('http://totally-unknown-fake-host-cseh.test/this-path-does-not-exist-either')
    );

    expect($response->getStatusCode())->toBeLessThan(500);
    expect($response->getContent())->not->toContain('SQLSTATE');
    expect($response->getContent())->not->toContain('locales');
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('6. TenantNotReadyHttpException (R57/R58) is never intercepted here - a Suspended tenant still gets its real 423, not this class\'s generic shape', function () {
    $tenant = Tenant::create(['id' => 'cseh-suspended', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'cseh-suspended.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);
    app(TenantLifecycle::class)->suspend($tenant->fresh());

    // A real HTTP request - preInitializeTenancyOnRouteMatch()'s own
    // RouteMatched listener throws TenantNotReadyHttpException for ANY
    // non-ready tenant on a 'web'-group route, not just eager-crash ones -
    // tenancy()->initialized is deliberately still false at this point
    // (R57/R58's own invariant), which is exactly the condition this
    // class's method_exists($e, 'render') guard must correctly exclude.
    $response = $this->get('http://cseh-suspended.platform.test/');

    $response->assertStatus(423);
    expect(tenancy()->initialized)->toBeFalse();
});

test('7. a normal, resolved, initialized tenant request is completely unaffected - delegates to Webkul\'s own Handler unchanged', function () {
    $tenant = Tenant::create(['id' => 'cseh-ready', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'cseh-ready.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    // A real Ready tenant storefront request - tenancy genuinely
    // initialized, so this class must delegate 100% to the inner handler,
    // exactly as before this task.
    $response = $this->get('http://cseh-ready.platform.test/');

    $response->assertOk();
});

test('8. an unauthenticated central Platform Admin request still redirects (a real 3xx), never a generic 500 (real production regression, found and fixed within this same task)', function () {
    // AuthenticationException is neither TokenMismatchException nor
    // HttpExceptionInterface, and tenancy is never initialized for a
    // central 'platform'-group route - the exact combination that, in
    // this class's FIRST deployed version, was incorrectly treated as
    // "unsafe" and rendered a generic 500 instead of delegating to
    // Webkul's own already-safe handleAuthenticationException() callback
    // (a plain redirect, no themed view, no tenant DB dependency at all).
    //
    // NOT asserting the specific redirect TARGET here: found, while
    // building this exact test, that Webkul's own handleAuthenticationException()
    // callback ignores Platform\Admin\Http\Middleware\Authenticate's own
    // redirectTo() override entirely and derives its redirect purely from
    // the URL path prefix (admin/* vs everything else) - so an
    // unauthenticated /platform/* request redirects to the SHOP customer
    // login, not platform.login. This is a real, separate, PRE-EXISTING
    // defect (predates this task entirely - config('app.debug')=true
    // masked it the same way it masked R51/R57/R67/R68 themselves),
    // recorded as its own new finding (RISK_REGISTER.md, see the entry
    // added alongside this task) rather than silently fixed or silently
    // ignored here - out of scope for R68, which is only about "never
    // 500", not about which login page a redirect points to.
    $response = $this->get('http://localhost/platform/tenants');

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(300);
    expect($response->getStatusCode())->toBeLessThan(400);
});
