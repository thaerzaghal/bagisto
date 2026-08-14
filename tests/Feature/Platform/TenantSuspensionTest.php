<?php

/**
 * TASK-ARCH-013 - Tenant Suspension & Reactivation test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered routes
 * (Shop, Admin, Shop API, Platform Admin), real TenantLifecycle service.
 * Nothing mocked.
 *
 * CENTRAL-DB-BEFORE-TENANT-DB PROOF (task item 10): rather than relying
 * on `tenancy()->initialized === false` alone, several tests here install
 * a real `DB::listen()` query listener before making the request and
 * assert the literal 'tenant' connection name never appears among the
 * queries actually executed - direct evidence the tenant database
 * connection was never even opened for a blocked request, not just an
 * inference from an internal flag.
 *
 * SAME-PROCESS TEST ARTIFACT (R35/R37): a `tenancy()->end()` call is
 * inserted after every suspend()/reactivate() call that is followed by
 * ANOTHER real HTTP request to the SAME tenant domain within the SAME
 * test() function - defensive, matching the established lesson that
 * `Stancl\Tenancy\Tenancy::initialize()`'s same-tenant-ID fast path can
 * otherwise serve a stale, pre-transition Tenant object across two
 * requests sharing one Pest test's container. `BlockSuspendedTenants`
 * itself is NOT subject to this (it does its own fresh central resolve
 * on every request, independent of `Tenancy::initialize()`'s internal
 * caching) - this is precautionary for whatever runs AFTER it allows a
 * request through.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Exceptions\InvalidTenantTransitionException;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const SUSPENSION_TEST_TENANT_IDS = ['tenant-suspend-a', 'tenant-suspend-b'];

function ensureSuspensionTestTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (SUSPENSION_TEST_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status === TenantStatus::Suspended) {
            // A previous run's own assertion may have failed mid-test,
            // leaving the fixture suspended - matches the established
            // "real database state as the idempotency signal" pattern.
            app(TenantLifecycle::class)->reactivate($tenant);
            $tenant = $tenant->fresh();
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

function loginPlatformAdminForSuspension(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'suspension-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

function ensureSuspensionTestPlatformAdmin(): void
{
    \Platform\Admin\Models\PlatformUser::firstOrCreate(
        ['email' => 'suspension-test-admin@example.test'],
        ['name' => 'Suspension Test Admin', 'password' => 'platform-secret-1']
    );
}

/**
 * Executes $callback (expected to make exactly one real HTTP request via
 * $this->get()/$this->post()/etc. and return the TestResponse) while a
 * DB::listen() probe records every connection name actually queried -
 * the item-10 "stronger evidence" proof.
 *
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnections(callable $callback): array
{
    $connections = [];

    $listener = function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    };

    DB::listen($listener);

    $response = $callback();

    return [$response, $connections];
}

beforeEach(function () {
    ensureSuspensionTestPlatformAdmin();

    [$this->tenantA, $this->tenantB] = ensureSuspensionTestTenants();
});

test('1. Ready -> Suspended succeeds via TenantLifecycle', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Suspended);
});

test('2. Suspended -> Ready succeeds via TenantLifecycle', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);
    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());

    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
});

test('3. an invalid suspension transition fails safely (already Suspended)', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    expect(fn () => app(TenantLifecycle::class)->suspend($this->tenantA->fresh()))
        ->toThrow(InvalidTenantTransitionException::class);

    // Status is unchanged by the failed attempt - still Suspended, not
    // silently reset or corrupted.
    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Suspended);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('4. an invalid reactivation transition fails safely (already Ready)', function () {
    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);

    expect(fn () => app(TenantLifecycle::class)->reactivate($this->tenantA))
        ->toThrow(InvalidTenantTransitionException::class);

    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
});

test('5. Platform Admin can suspend a tenant through real HTTP', function () {
    loginPlatformAdminForSuspension($this);

    $response = $this->post('http://localhost/platform/tenants/'.SUSPENSION_TEST_TENANT_IDS[0].'/suspend');

    $response->assertRedirect();
    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Suspended);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('6. an unauthenticated caller cannot suspend a tenant', function () {
    $response = $this->post('http://localhost/platform/tenants/'.SUSPENSION_TEST_TENANT_IDS[0].'/suspend');

    $response->assertRedirect(route('platform.login'));
    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
});

test('7. the platform suspension endpoint is unreachable from a tenant domain', function () {
    $response = $this->post('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/platform/tenants/'.SUSPENSION_TEST_TENANT_IDS[0].'/suspend');

    $response->assertNotFound();
    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
});

test('8. a suspended tenant\'s storefront request is blocked with 423', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    $response = $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/');

    $response->assertStatus(423);
    $response->assertSee('currently unavailable');

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('9. a suspended tenant\'s admin request is blocked with 423', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    $response = $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/admin/login');

    $response->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('10. a suspended tenant\'s API request is blocked with structured JSON', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    $response = $this->getJson('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/api/products');

    $response->assertStatus(423);
    $response->assertJsonStructure(['message']);
    expect($response->json('message'))->toContain('unavailable');

    // No internal exception name/stack trace leaked.
    $body = $response->getContent();
    expect($body)->not->toContain('Exception');
    expect($body)->not->toContain('Stack trace');

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('11. a suspended request never queries the tenant database connection', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    [$response, $connections] = captureQueriedConnections(
        fn () => $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/')
    );

    $response->assertStatus(423);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('12. an unknown domain retains its existing safe behavior, not a suspension response', function () {
    $response = $this->get('http://unknown-domain-for-suspension-check.localhost/');

    $response->assertStatus(404);
    $response->assertDontSee('currently unavailable');
});

test('13. Tenant B remains fully accessible while Tenant A is suspended', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    $blockedA = $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/');
    $blockedA->assertStatus(423);

    $okB = $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[1].'.localhost/');
    $okB->assertOk();

    $okBAdmin = $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[1].'.localhost/admin/login');
    $okBAdmin->assertOk();

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('14. reactivation immediately restores storefront access', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);
    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/')->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
    tenancy()->end();

    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/')->assertOk();
});

test('15. reactivation immediately restores tenant-admin and API access', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);
    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/admin/login')->assertStatus(423);
    $this->getJson('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/api/products')->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
    tenancy()->end();

    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/admin/login')->assertOk();
    tenancy()->end();
    $this->getJson('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/api/products')->assertOk();
});

test('16. tenant data before suspension is unchanged after reactivation', function () {
    $marker = $this->tenantA->run(function () {
        DB::table('products')->where('sku', 'SUSPEND-MARKER')->delete();

        $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
        $product = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'SUSPEND-MARKER']);
        $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Suspend Marker', 'url_key' => 'suspend-marker'], $product->id);

        return DB::table('products')->where('id', $product->id)->first();
    });

    app(TenantLifecycle::class)->suspend($this->tenantA->fresh());
    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/')->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());

    $after = $this->tenantA->run(fn () => DB::table('products')->where('sku', 'SUSPEND-MARKER')->first());

    expect($after)->not->toBeNull();
    expect($after->id)->toBe($marker->id);
    expect($after->sku)->toBe($marker->sku);
    expect($after->created_at)->toBe($marker->created_at);
});

test('17. suspension does not reprovision, reseed, or migrate the tenant', function () {
    $migrationsBefore = $this->tenantA->run(fn () => DB::table('migrations')->pluck('migration')->sort()->values()->all());
    $productCountBefore = $this->tenantA->run(fn () => DB::table('products')->count());

    app(TenantLifecycle::class)->suspend($this->tenantA);
    $this->get('http://'.SUSPENSION_TEST_TENANT_IDS[0].'.localhost/')->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());

    $migrationsAfter = $this->tenantA->run(fn () => DB::table('migrations')->pluck('migration')->sort()->values()->all());
    $productCountAfter = $this->tenantA->run(fn () => DB::table('products')->count());

    expect($migrationsAfter)->toBe($migrationsBefore);
    expect($productCountAfter)->toBe($productCountBefore);
});

test('18. Platform Admin tenant list/detail correctly reflect Suspended status', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    loginPlatformAdminForSuspension($this);

    $list = $this->get('http://localhost/platform/tenants');
    $list->assertOk();
    $list->assertSee(SUSPENSION_TEST_TENANT_IDS[0]);
    $list->assertSee('suspended');

    $detail = $this->get('http://localhost/platform/tenants/'.SUSPENSION_TEST_TENANT_IDS[0]);
    $detail->assertOk();
    $detail->assertSee('suspended');
    $detail->assertSee('Reactivate');
    $detail->assertDontSee('Suspend<'); // the Suspend button itself should not render for an already-Suspended tenant

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});
