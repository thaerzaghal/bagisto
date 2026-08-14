<?php

/**
 * TASK-ARCH-012 - Feature & Limit Enforcement Foundation test matrix.
 *
 * Real MySQL, real HTTP requests through the actual, unmodified Bagisto
 * Admin product-creation endpoint (`admin.catalog.products.store`, POST
 * `admin/catalog/products/create`), real tenant provisioning, real login.
 * TenantEntitlements/TenantLimits/Plan/PlanFeature are never mocked - the
 * primary enforcement tests exercise the real
 * Platform\Enforcement\Listeners\EnforceProductCreationLimit listener,
 * registered on Webkul\Product\Models\Product's real Eloquent `creating`
 * event, exactly as it runs in production.
 *
 * COUNTING SEMANTICS UNDER TEST: only root/aggregate products
 * (`parent_id IS NULL`) count toward `products.limit` - see
 * docs/architecture/feature-limits.md "Product counting semantics" and
 * Platform\Enforcement\Listeners\EnforceProductCreationLimit's own
 * docblock for the full reasoning.
 */

use Illuminate\Support\Facades\DB;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const LIMIT_TEST_TENANT_IDS = ['tenant-limit-a', 'tenant-limit-b'];

/**
 * DEDICATED tenant-limit-a/tenant-limit-b fixtures (matches the
 * established per-file-dedicated-tenant convention). Products are wiped
 * from both in beforeEach() (raw DELETE, real FK cascade removes every
 * child row - product_attribute_values, product_flat, etc. - exactly as
 * a real product deletion would) so every test starts from a known,
 * empty product count regardless of what a previous run left behind.
 */
function ensureLimitTestTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (LIMIT_TEST_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

/**
 * A small, dedicated, idempotent test plan per numeric limit - avoids
 * coupling these tests to PlanSeeder's own illustrative business numbers
 * (free=10, basic=100), which could change independently of this file.
 */
function ensureNumericLimitPlan(int $limit): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'limit-test-numeric-'.$limit],
        ['name' => "Limit Test (numeric {$limit})", 'is_active' => true, 'sort_order' => 90]
    );

    $plan->features()->updateOrCreate(
        ['feature_code' => FeatureCode::ProductsLimit->value],
        ['type' => FeatureType::Numeric, 'value' => $limit]
    );

    return $plan;
}

function ensureUnlimitedPlan(): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'limit-test-unlimited'],
        ['name' => 'Limit Test (unlimited)', 'is_active' => true, 'sort_order' => 91]
    );

    $plan->features()->updateOrCreate(
        ['feature_code' => FeatureCode::ProductsLimit->value],
        ['type' => FeatureType::Unlimited, 'value' => null]
    );

    return $plan;
}

/**
 * A plan that deliberately has NO plan_features row for products.limit -
 * for test 10 (missing entitlement fail-safe contract).
 */
function ensureNoLimitConfiguredPlan(): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'limit-test-not-configured'],
        ['name' => 'Limit Test (not configured)', 'is_active' => true, 'sort_order' => 92]
    );

    $plan->features()->where('feature_code', FeatureCode::ProductsLimit->value)->delete();

    return $plan;
}

/**
 * A plan where products.limit is (incorrectly) configured as boolean -
 * for test 11 (wrong entitlement type fails safely).
 */
function ensureWrongTypeLimitPlan(): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'limit-test-wrong-type'],
        ['name' => 'Limit Test (wrong type)', 'is_active' => true, 'sort_order' => 93]
    );

    $plan->features()->updateOrCreate(
        ['feature_code' => FeatureCode::ProductsLimit->value],
        ['type' => FeatureType::Boolean, 'value' => 1]
    );

    return $plan;
}

function attributeFamilyId(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('attribute_families')->value('id'));
}

function createProductViaAdmin(Tests\TestCase $test, string $domain, string $sku, array $extra = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson('http://'.$domain.'/admin/catalog/products/create', array_merge([
        'type' => 'simple',
        'attribute_family_id' => attributeFamilyId(Tenant::find(explode('.', $domain)[0])),
        'sku' => $sku,
    ], $extra));
}

function loginAsTenantAdmin(Tests\TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

function rootProductCount(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('products')->whereNull('parent_id')->count());
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureLimitTestTenants();

    $this->tenantA->run(fn () => DB::table('products')->delete());
    $this->tenantB->run(fn () => DB::table('products')->delete());
});

test('1. a numeric limit below the cap allows creation', function () {
    $plan = ensureNumericLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    $response = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-below-cap');

    $response->assertOk();
    expect(rootProductCount($this->tenantA))->toBe(1);
});

test('2. a numeric limit exactly at the cap blocks creation', function () {
    $plan = ensureNumericLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-first')->assertOk();
    expect(rootProductCount($this->tenantA))->toBe(1);

    $blocked = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-second');

    $blocked->assertStatus(422);
    expect(rootProductCount($this->tenantA))->toBe(1);
});

test('3. a blocked creation persists no product at all', function () {
    $plan = ensureNumericLimitPlan(0);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-zero-cap')->assertStatus(422);

    $exists = $this->tenantA->run(fn () => DB::table('products')->where('sku', 'limit-test-zero-cap')->exists());
    expect($exists)->toBeFalse();
    expect(rootProductCount($this->tenantA))->toBe(0);
});

test('4. an unlimited plan allows creation regardless of count', function () {
    $plan = ensureUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-unlimited-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-unlimited-2')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-unlimited-3')->assertOk();

    expect(rootProductCount($this->tenantA))->toBe(3);
});

test('5. Tenant A\'s product count does not affect Tenant B', function () {
    $planA = ensureNumericLimitPlan(1);
    $planBUnlimited = ensureUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $planA->id])->save();
    $this->tenantB->forceFill(['plan_id' => $planBUnlimited->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-a-1')->assertOk();
    // Tenant A is now AT its cap of 1.

    loginAsTenantAdmin($this, 'tenant-limit-b.localhost');
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-b-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-b-2')->assertOk();

    expect(rootProductCount($this->tenantA))->toBe(1);
    expect(rootProductCount($this->tenantB))->toBe(2);
});

test('6. Tenant A and Tenant B may have different limits', function () {
    $planA = ensureNumericLimitPlan(1);
    $planB = ensureNumericLimitPlan(3);
    $this->tenantA->forceFill(['plan_id' => $planA->id])->save();
    $this->tenantB->forceFill(['plan_id' => $planB->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-diff-a-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-diff-a-2')->assertStatus(422);

    loginAsTenantAdmin($this, 'tenant-limit-b.localhost');
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-diff-b-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-diff-b-2')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-diff-b-3')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-b.localhost', 'limit-test-diff-b-4')->assertStatus(422);

    expect(rootProductCount($this->tenantA))->toBe(1);
    expect(rootProductCount($this->tenantB))->toBe(3);
});

test('7. central entitlement resolution occurs while tenant DB context remains active', function () {
    $plan = ensureNumericLimitPlan(5);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $result = $this->tenantA->run(function () {
        return [
            'tenancy_initialized' => tenancy()->initialized,
            'remaining' => \Platform\Plans\Services\TenantLimits::current()
                ->remaining(FeatureCode::ProductsLimit, rootProductCount($this->tenantA)),
        ];
    });

    expect($result['tenancy_initialized'])->toBeTrue();
    expect($result['remaining'])->toBe(5);
});

test('8. changing a tenant\'s plan immediately changes enforcement, no stale caching', function () {
    $freePlan = ensureNumericLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $freePlan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-upgrade-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-upgrade-2')->assertOk();

    // At cap - blocked.
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-upgrade-3')->assertStatus(422);

    // Upgrade to unlimited - immediately allowed in PRODUCTION (a real,
    // separate HTTP request always starts with a fresh, uninitialized
    // Stancl\Tenancy\Tenancy singleton, so InitializeTenancyByDomain's
    // per-request re-resolution always sees the latest plan_id). Within
    // this SINGLE test method, however, `Tenancy::initialize()`'s own
    // same-tenant-ID fast path (`if ($this->initialized && $this->tenant
    // ->getTenantKey() === $tenant->getTenantKey()) return;`,
    // vendor/stancl/tenancy/src/Tenancy.php) would otherwise keep serving
    // the STALE, pre-upgrade Tenant object across the next request in
    // this same test process - the same same-container test artifact
    // documented as R35 (TASK-ARCH-011), now found a second time in a
    // different subsystem. `tenancy()->end()` forces the next request's
    // InitializeTenancyByDomain to go through a genuine (non-fast-path)
    // initialize(), exactly as a real second, separate process would.
    $proPlan = ensureUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $proPlan->id])->save();
    tenancy()->end();

    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-upgrade-3')->assertOk();

    expect(rootProductCount($this->tenantA))->toBe(3);
});

test('9. a downgraded over-limit tenant keeps existing products but cannot create another', function () {
    $unlimited = ensureUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $unlimited->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-downgrade-1')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-downgrade-2')->assertOk();
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-downgrade-3')->assertOk();
    expect(rootProductCount($this->tenantA))->toBe(3);

    // Downgrade to a plan whose limit (1) is already exceeded by existing usage (3).
    $restrictive = ensureNumericLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $restrictive->id])->save();
    // See test 8's docblock: forces the next request to re-resolve the
    // tenant instead of reusing the stale, pre-downgrade object still
    // held by Stancl\Tenancy\Tenancy's same-tenant-ID fast path within
    // this one test process (R35-class test artifact, not a production
    // concern - a real request always starts uninitialized).
    tenancy()->end();

    // Existing products remain untouched - no deletion, no data loss.
    expect(rootProductCount($this->tenantA))->toBe(3);
    $stillExists = $this->tenantA->run(fn () => DB::table('products')->where('sku', 'limit-test-downgrade-1')->exists());
    expect($stillExists)->toBeTrue();

    // New creation is blocked until back under the limit or upgraded.
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-downgrade-4')->assertStatus(422);
    expect(rootProductCount($this->tenantA))->toBe(3);
});

test('10. a missing products.limit entitlement follows the fail-safe contract, not silent unlimited', function () {
    $plan = ensureNoLimitConfiguredPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    $response = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-not-configured-1');

    // NOT allowed (would be, if the missing entitlement were silently
    // treated as unlimited) - fails per TenantEntitlements' own contract
    // (FeatureNotConfiguredException), rendered as a clean non-500.
    $response->assertStatus(422);
    expect(rootProductCount($this->tenantA))->toBe(0);
});

test('11. a wrongly-typed entitlement (boolean instead of numeric) fails safely, not a 500', function () {
    $plan = ensureWrongTypeLimitPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    $response = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-wrong-type-1');

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Your current plan does not allow this action.');
    expect(rootProductCount($this->tenantA))->toBe(0);
});

test('12. the user-facing failure is a clean, understandable non-500 response', function () {
    $plan = ensureNumericLimitPlan(0);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    $response = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-clean-failure');

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Your current plan allows up to 0 products.');

    // No leaked internal exception class name/stack trace in the body.
    $body = $response->getContent();
    expect($body)->not->toContain('LimitExceededException');
    expect($body)->not->toContain('Exception');
    expect($body)->not->toContain('Stack trace');
});

test('13. a real Bagisto admin product-creation HTTP path is covered end-to-end', function () {
    $plan = ensureNumericLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    expect(\Illuminate\Support\Facades\Route::has('admin.catalog.products.store'))->toBeTrue();

    $ok = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-real-http-1');
    $ok->assertOk();
    $ok->assertJsonStructure(['data' => ['redirect_url']]);

    $blocked = createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-real-http-2');
    $blocked->assertStatus(422);
});

test('14. a configurable product\'s variants do not count separately toward the limit', function () {
    $plan = ensureNumericLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $familyId = attributeFamilyId($this->tenantA);

    // Deterministic, not dependent on the default seed data actually
    // having a configurable attribute/permutation set up (fragile and
    // not this test's concern - Bagisto's own ConfigurableTest already
    // covers the real variant-generation UI flow end-to-end). This
    // exercises the EXACT SAME production code path
    // (Webkul\Product\Models\Product::creating ->
    // Platform\Enforcement\Listeners\EnforceProductCreationLimit) that
    // Webkul\Product\Type\Configurable::createVariant() itself goes
    // through when it calls `parent::create(['parent_id' => $product->id,
    // ...])` for each variant permutation (see that method) - so this is
    // a real proof of the listener's parent_id-based skip, not a
    // reimplementation of it.
    $root = $this->tenantA->run(function () use ($familyId) {
        return app(\Webkul\Product\Repositories\ProductRepository::class)->create([
            'type' => 'simple',
            'attribute_family_id' => $familyId,
            'sku' => 'limit-test-configurable-root',
        ]);
    });

    expect(rootProductCount($this->tenantA))->toBe(1);

    // Already AT the cap (limit=1, one root product exists) - a second
    // ROOT product would be blocked, but a VARIANT (parent_id set) must
    // still be allowed, exactly as it would be mid-way through
    // Configurable::create()'s variant loop.
    $variant = $this->tenantA->run(function () use ($familyId, $root) {
        return app(\Webkul\Product\Repositories\ProductRepository::class)->create([
            'type' => 'simple',
            'attribute_family_id' => $familyId,
            'sku' => 'limit-test-configurable-root-variant-1',
            'parent_id' => $root->id,
        ]);
    });

    expect($variant->id)->not->toBeNull();
    expect(rootProductCount($this->tenantA))->toBe(1);

    $totalRows = $this->tenantA->run(fn () => DB::table('products')->count());
    expect($totalRows)->toBe(2);

    // A genuine second ROOT product is still correctly blocked.
    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');
    createProductViaAdmin($this, 'tenant-limit-a.localhost', 'limit-test-configurable-second-root')
        ->assertStatus(422);
});

test('15. the existing full Platform suite is unaffected by enforcement (spot check: TASK-ARCH-009 My Plan page still works)', function () {
    $plan = ensureUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginAsTenantAdmin($this, 'tenant-limit-a.localhost');

    $response = $this->get('http://tenant-limit-a.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('My Plan');
});
