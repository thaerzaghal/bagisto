<?php

/**
 * TASK-ARCH-003 - tenant domain routing / request lifecycle integration tests.
 *
 * Proves the full chain: HTTP Host header -> tenant identification -> tenant
 * DB connection switch -> REAL, unmodified Bagisto routes (Shop API + Admin),
 * not just our own test routes. See bootstrap/app.php for the middleware
 * wiring these tests exercise, and docs/architecture/domain-routing.md for
 * the design. Real MySQL, real Bagisto migrations/seeders/routes/controllers,
 * nothing mocked, nothing stubbed.
 *
 * Fixtures are provisioned ONCE per file run (a `static` flag inside
 * beforeEach(), not beforeEach() itself, and NOT beforeAll() - Pest's
 * beforeAll() maps to PHPUnit's static setUpBeforeClass(), which runs before
 * Laravel's testing app is booted, so app()/Eloquent aren't safely available
 * there). Each tenant provision is ~40-90s of real DDL/DML, and
 * re-provisioning two full tenants for every one of 7 tests was both
 * needlessly slow and (empirically observed once) capable of tipping MySQL
 * into real InnoDB lock-wait contention under Docker Desktop/WSL2 I/O
 * pressure. Individual tests only perform cheap HTTP requests + assertions
 * against the shared fixtures. For the same static-availability reason, this
 * file does not use afterAll() either - cleanup of tenant-a/tenant-b is
 * handled by the NEXT run's own startup cleanup (here or in
 * TenantProvisioningTest.php, which target the same fixture ids) rather than
 * an end-of-file hook.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Product\Repositories\ProductRepository;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const ROUTING_TEST_TENANT_IDS = ['tenant-a', 'tenant-b'];

function cleanupRoutingTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (ROUTING_TEST_TENANT_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbName = $data['tenancy_db_name'] ?? null;
            $dbUsername = $data['tenancy_db_username'] ?? null;

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
 * Creates a product AND makes it storefront-visible. ProductRepository::create()
 * only writes the bare `products` row (see TASK-ARCH-001/002) - the real Shop
 * API listing additionally filters on the `status` and `visible_individually`
 * EAV attribute values (packages/Webkul/Shop/src/Http/Controllers/API/
 * ProductController::getProducts()), which are only set via a subsequent
 * ->update() call, exactly as Bagisto's own admin product form would.
 */
function createVisibleProduct(string $sku, string $name): void
{
    $repository = app(ProductRepository::class);
    $product = $repository->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => $sku]);
    $repository->update([
        'status' => 1,
        'visible_individually' => 1,
        'name' => $name,
        'url_key' => \Illuminate\Support\Str::slug($name),
    ], $product->id);
}

/**
 * Ensures tenant-a/tenant-b are provisioned with their product, exactly
 * once per test run, WITHOUT relying on any static/process flag.
 *
 * SPIKE FINDING: a `static $flag` local to this closure looked like it should
 * persist across beforeEach() invocations within one file run, but does not -
 * Pest/PHPUnit binds a NEW closure instance (Closure::bind($closure, $this))
 * for every test, and each bound copy gets its OWN independent static
 * storage even though they share the same source code. That bug was caught
 * empirically: it caused every test after the first to redundantly re-run
 * full cleanup+provisioning against rows the first test had just created,
 * which is what actually produced the InnoDB lock-wait-timeout failures in
 * the previous run - not a bug in tenancy/provisioning itself.
 *
 * The fix uses real database state (the tenant's own status) as the
 * idempotency signal instead of any in-process flag - immune to closure
 * rebinding, and consistent with how TenantProvisioner itself is already
 * idempotent (see TASK-ARCH-002).
 */
function ensureRoutingFixturesProvisioned(): void
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-a.localhost']);
        }
        $provisioner->provision($tenantA);
        $tenantA->run(fn () => createVisibleProduct('PROD-A', 'Product A'));
    }

    $tenantB = Tenant::find('tenant-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-b.localhost']);
        }
        $provisioner->provision($tenantB);
        $tenantB->run(fn () => createVisibleProduct('PROD-B', 'Product B'));
    }
}

beforeEach(fn () => ensureRoutingFixturesProvisioned());

test('a real Bagisto Shop API route resolves tenant-a.localhost, uses Tenant A\'s database, and returns only Tenant A\'s product', function () {
    // 1, 3, 5, 8: tenant-a.localhost resolves Tenant A, uses Tenant A's
    // database, and Webkul\Shop's real, unmodified ProductController (not a
    // test route) returns only Tenant A's product - proving tenant context
    // was initialized before Bagisto's own application/controller code ran.
    // A non-empty `query` param is used deliberately (see the dedicated
    // "storefront listing cache" test below for why the no-query path is
    // tested separately - it goes through Webkul\Shop\Helpers\CatalogApiCache,
    // which is untenanted, see RISK_REGISTER.md R15/R1).
    $response = $this->getJson('http://tenant-a.localhost/api/products?query=Product');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('sku')->all())->toBe(['PROD-A']);
});

test('a real Bagisto Shop API route resolves tenant-b.localhost, uses Tenant B\'s database, and returns only Tenant B\'s product', function () {
    // 2, 4, 6, 8: same proof for tenant-b.localhost.
    $response = $this->getJson('http://tenant-b.localhost/api/products?query=Product');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('sku')->all())->toBe(['PROD-B']);
});

test('a real Bagisto Admin route (login page) is reachable tenant-aware, without any core modification', function () {
    // Closest safely-testable real Admin route at this stage (see final
    // report for why a full authenticated admin action - Vite-compiled
    // assets, session/CSRF flow - is out of scope for this task). Bouncer
    // middleware (packages/Webkul/User) serves this exact route for
    // unauthenticated requests, so reaching it at all proves Bagisto's real
    // Admin routing - registered entirely inside packages/Webkul/Admin,
    // never touched by this task - is tenant-aware via the same global
    // 'web' group middleware used by Shop.
    $response = $this->get('http://tenant-a.localhost/admin/login');

    $response->assertOk();
});

test('an unknown domain is rejected with a controlled 404, never falls back to any tenant, and never exposes tenant data', function () {
    $response = $this->getJson('http://unknown.localhost/api/products?query=Product');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('PROD-A');
    expect($response->getContent())->not->toContain('PROD-B');

    // Central domains behave the same way today: no platform routes exist
    // yet (Phase 8), so central-domain requests to Bagisto's routes 404 too -
    // this IS the platform/tenant boundary for TASK-ARCH-003's scope (see
    // docs/architecture/domain-routing.md): nothing is reachable on the
    // central domain by design, not by accident, until Phase 8 adds real
    // platform-only routes deliberately excluded from tenant resolution.
    $centralResponse = $this->getJson('http://localhost/api/products?query=Product');
    $centralResponse->assertNotFound();
});

test('the Host header alone cannot select an arbitrary tenant database - only registered domain rows resolve', function () {
    // Section 12 security check: a host that looks plausible but was never
    // registered via TenantProvisioner must never resolve to any database,
    // real or guessed.
    $response = $this->getJson('http://tenant-c.localhost/api/products?query=Product');

    $response->assertNotFound();
});

test('tenant DB connection state does not leak between consecutive requests to different tenants in the same process', function () {
    // 9: proves re-initialization (not stale reuse) across sequential
    // requests, which is the practically-relevant version of "connection is
    // restored/ended correctly" for a shared-process test run (and for
    // Octane-style long-lived workers later - see RISK_REGISTER.md R8).
    $this->getJson('http://tenant-a.localhost/api/products?query=Product');
    expect(DB::connection()->getDatabaseName())->toBe('tenanttenant-a');

    $this->getJson('http://tenant-b.localhost/api/products?query=Product');
    expect(DB::connection()->getDatabaseName())->toBe('tenanttenant-b');

    $this->getJson('http://tenant-a.localhost/api/products?query=Product');
    expect(DB::connection()->getDatabaseName())->toBe('tenanttenant-a');
});

test('section 9/TASK-ARCH-004: the cached (no-query) storefront listing endpoint is now tenant-isolated - R15/R21 FIXED, verified by flipping a previously-failing assertion', function () {
    // HISTORY: this test originally asserted the CONFIRMED BUG (TASK-ARCH-003):
    // tenant-b.localhost's response was Tenant A's cached ["PROD-A"], not Tenant
    // B's own ["PROD-B"] - CatalogApiCache (packages/Webkul/Shop/src/Helpers/
    // CatalogApiCache.php) keys cache entries by channel id + locale + currency
    // + params, not by tenant, and both tenants' default channel gets id=1
    // (fresh auto-increment per tenant database), so the identical no-query
    // request computed an identical cache key across tenants.
    //
    // FIX (TASK-ARCH-004): Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper
    // is now enabled (config/tenancy.php) against a taggable cache store
    // (CACHE_STORE=array for tests / redis for production - see .env.example).
    // It transparently tags every Cache:: call with the resolved tenant's key,
    // for EVERY cache consumer in the app (CatalogApiCache, repository caching,
    // PhonePe's token cache, ...) - zero changes were needed in
    // CatalogApiCache.php or anywhere else, since they all resolve the cache
    // through app('cache')/the Cache facade, which this bootstrapper swaps.
    //
    // This assertion is the flip side of the original bug assertion, run
    // against the exact same scenario: re-running this test with the fix
    // reverted (bootstrapper commented out again) reproduces the original
    // failure, which is how the fix was verified rather than assumed.
    $first = $this->getJson('http://tenant-a.localhost/api/products');
    $first->assertOk();
    $firstSkus = collect($first->json('data'))->pluck('sku')->all();
    expect($firstSkus)->toBe(['PROD-A']);

    $second = $this->getJson('http://tenant-b.localhost/api/products');
    $second->assertOk();
    $secondSkus = collect($second->json('data'))->pluck('sku')->all();
    expect($secondSkus)->toBe(['PROD-B']);

    // Repeat both requests - both are now served from cache (second hit within
    // CatalogApiCache::TTL), and isolation still holds on the cached path, not
    // just on the first, cache-populating request.
    $firstAgain = $this->getJson('http://tenant-a.localhost/api/products');
    expect(collect($firstAgain->json('data'))->pluck('sku')->all())->toBe(['PROD-A']);

    $secondAgain = $this->getJson('http://tenant-b.localhost/api/products');
    expect(collect($secondAgain->json('data'))->pluck('sku')->all())->toBe(['PROD-B']);
});
