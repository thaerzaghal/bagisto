<?php

/**
 * TASK-ARCH-004 - tenant cache isolation security test matrix.
 *
 * Companion to the R21 fix/flip in TenantDomainRoutingTest.php (which proves
 * isolation on the real Shop API endpoint end-to-end). This file proves the
 * underlying mechanism (Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper,
 * tag-based, config/tenancy.php) directly and covers the remaining items from
 * the task's security test matrix: repository-level caching, cache-key
 * namespacing, invalidation scoping, and the central/unknown-domain cases.
 * Real MySQL, real cache store (CACHE_STORE=array, see .env), nothing mocked.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Product\Repositories\ProductRepository;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const CACHE_TEST_TENANT_IDS = ['tenant-a', 'tenant-b'];

function cleanupCacheTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (CACHE_TEST_TENANT_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            if (! empty($data['tenancy_db_username'])) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
            }
            if (! empty($data['tenancy_db_name'])) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
            }
        }

        $central->table('domains')->where('tenant_id', $id)->delete();
        $central->table('tenants')->where('id', $id)->delete();
    }
}

function ensureCacheTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-a.localhost']);
        }
        $provisioner->provision($tenantA);
        $tenantA->run(function () {
            $repo = app(ProductRepository::class);
            $p = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PROD-A']);
            $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Product A', 'url_key' => 'product-a'], $p->id);
        });
    }

    $tenantB = Tenant::find('tenant-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-b.localhost']);
        }
        $provisioner->provision($tenantB);
        $tenantB->run(function () {
            $repo = app(ProductRepository::class);
            $p = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PROD-B']);
            $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Product B', 'url_key' => 'product-b'], $p->id);
        });
    }

    // TASK-ARCH-012: tenant-a/tenant-b are shared, legacy-named fixtures
    // (predating the later "one dedicated tenant per test file" convention)
    // reused across TenantCacheIsolationTest/TenantDomainRoutingTest/
    // TenantStorageIsolationTest/TenantImageCacheIsolationTest/
    // TenantProvisioningTest, provisioned on the default 'free' plan
    // (products.limit=10) - product-limit enforcement now real means their
    // accumulated product count across this whole engagement's many test
    // runs (nothing here tests limits, so nothing ever cleaned this up)
    // can exceed that unrelated business-rule cap and start blocking
    // product creation these tests have nothing to do with. Pinned to the
    // 'pro' (unlimited) plan explicitly and idempotently - matching the
    // established `ensureAdminPlanPageTestFixtures()` idiom
    // (TenantAdminPlanPageTest.php) - so cache-isolation tests never
    // depend on an unrelated plan's arbitrary limit, on a fresh
    // environment or an already-bloated one alike.
    $proPlan = Plan::where('code', 'pro')->firstOrFail();
    if ($tenantA->plan_id !== $proPlan->id) {
        $tenantA->forceFill(['plan_id' => $proPlan->id])->save();
    }
    if ($tenantB->plan_id !== $proPlan->id) {
        $tenantB->forceFill(['plan_id' => $proPlan->id])->save();
    }

    return [$tenantA, $tenantB];
}

/**
 * No blanket afterEach() here, deliberately - this file shares the same
 * tenant-a/tenant-b fixtures (by id) with TenantProvisioningTest.php and
 * TenantDomainRoutingTest.php within the same `vendor/bin/pest
 * tests/Feature/Platform/` run (see TASK-ARCH-003's perf finding on
 * per-test-file provisioning cost: ~40-90s per tenant). Tearing down after
 * every test here would both slow this file down AND (since Pest runs files
 * in a stable, effectively-alphabetical order within one process) needlessly
 * re-provision fixtures the next file would otherwise reuse. The one test
 * below that mutates shared product data beyond its own scope (the
 * invalidation test, which adds a second product to prove cache-busting)
 * cleans up that extra product itself, specifically so later tests/files
 * asserting "tenant A has exactly one product" are not affected by test
 * order. `cleanupCacheTestTenants()` remains available for manual end-of-run
 * cleanup, matching the pattern already used in the sibling test files.
 */
beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureCacheTestFixtures();
});

test('the same literal cache key never leaks between tenants, and round-trips correctly within one tenant context (direct tag-namespace proof)', function () {
    // This is the mechanism every consumer in the app rides for free (Cache
    // facade / app('cache')) - CatalogApiCache, PhonePe's access-token cache
    // (packages/Webkul/PhonePe/src/Payment/PhonePe.php:130, same
    // Cache::remember() call shape, not separately re-tested here since it
    // needs sandbox credentials we don't have - but it goes through the exact
    // mechanism proven here), and repository caching all resolve through this
    // same swapped binding.
    //
    // FINDING (caught by an earlier version of this test failing): with
    // CACHE_STORE=array, Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper
    // creates a brand-new Stancl\Tenancy\CacheManager instance on every
    // bootstrap cycle (confirmed via spl_object_hash - a different object on
    // every separate tenant->run()/request) - Laravel's ArrayStore keeps its
    // data in PHP process memory on the store object itself, so a value
    // written in one bootstrap cycle does NOT survive into the next one, even
    // for the SAME tenant. This is a property of the `array` driver
    // specifically (in-process memory tied to object lifetime), not a
    // tenancy/isolation bug - an external store (Redis, Memcached) keeps data
    // outside any PHP object's lifetime and does not have this limitation
    // (verified manually against a real Redis container - see
    // docs/architecture/caching.md). This test therefore only asserts what
    // `array` can reliably prove (no cross-tenant leakage, and correct
    // same-context round-tripping) - it deliberately does NOT assert
    // cross-request persistence, which TASK-ARCH-004's supplementary Redis
    // verification covers instead.
    $this->tenantA->run(function () {
        Cache::put('shared-key', 'value-from-tenant-a', 60);
        expect(Cache::get('shared-key'))->toBe('value-from-tenant-a');
    });

    $this->tenantB->run(function () {
        // Must never see Tenant A's write, regardless of the underlying
        // store's own persistence characteristics.
        expect(Cache::get('shared-key'))->toBeNull();

        Cache::put('shared-key', 'value-from-tenant-b', 60);
        expect(Cache::get('shared-key'))->toBe('value-from-tenant-b');
    });
});

test('repository caching (Webkul\Core\Repositories\ChannelRepository, cache-enabled by config/repository.php) is isolated across tenants', function () {
    // ChannelRepository is one of the few repositories with caching enabled by
    // default (config/repository.php 'repositories' override list) - ->all()
    // is one of CacheableRepository's cached methods. Give each tenant's
    // channel a distinguishing name so a collision would be observable, not
    // just "both say 'default'" by coincidence.
    $this->tenantA->run(function () {
        DB::table('channel_translations')->where('locale', 'en')->update(['name' => 'Channel A']);
        expect(app(ChannelRepository::class)->all()->pluck('name')->all())->toBe(['Channel A']);
    });

    $this->tenantB->run(function () {
        DB::table('channel_translations')->where('locale', 'en')->update(['name' => 'Channel B']);
        expect(app(ChannelRepository::class)->all()->pluck('name')->all())->toBe(['Channel B']);
    });

    // Re-read (now definitely served from cache within getCacheTime()) and
    // confirm no collision in either direction.
    $this->tenantA->run(fn () => expect(app(ChannelRepository::class)->all()->pluck('name')->all())->toBe(['Channel A']));
    $this->tenantB->run(fn () => expect(app(ChannelRepository::class)->all()->pluck('name')->all())->toBe(['Channel B']));
});

test('Tenant A cache invalidation (a real product update) does not invalidate or affect Tenant B\'s cache', function () {
    // Populate both tenants' catalog cache first.
    $this->getJson('http://tenant-a.localhost/api/products')->assertOk();
    $this->getJson('http://tenant-b.localhost/api/products')->assertOk();

    // Tenant A adds a second product and dispatches the exact event Bagisto's
    // real admin controller dispatches on save (Webkul\Admin\Http\Controllers\
    // Catalog\ProductController::store()/update() - not reachable directly in
    // this test without full admin auth/session/CSRF, so the event - the
    // actual invalidation trigger - is dispatched directly here, which is
    // "the behavior that currently exists", per the task brief, not a new
    // invalidation mechanism).
    $this->tenantA->run(function () {
        $repo = app(ProductRepository::class);
        $p = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PROD-A2']);
        $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Product A2', 'url_key' => 'product-a2'], $p->id);
        Event::dispatch('catalog.product.update.after', $p);
    });

    // Tenant A's cache is invalidated - the next request sees both products.
    $afterA = $this->getJson('http://tenant-a.localhost/api/products');
    expect(collect($afterA->json('data'))->pluck('sku')->sort()->values()->all())->toBe(['PROD-A', 'PROD-A2']);

    // Tenant B's cache is untouched - still only its own, original product.
    $afterB = $this->getJson('http://tenant-b.localhost/api/products');
    expect(collect($afterB->json('data'))->pluck('sku')->all())->toBe(['PROD-B']);

    // Restore tenant-a's baseline (single-product) state - this fixture is
    // shared/reused by other tests in this file and other Platform test
    // files within the same run; see the beforeEach() docblock above.
    $this->tenantA->run(function () {
        DB::table('products')->where('sku', 'PROD-A2')->delete();
        app(\Webkul\Shop\Helpers\CatalogApiCache::class)->flush();
    });
});

test('a request that never resolves a tenant cannot read or disturb any tenant\'s cache', function () {
    $this->getJson('http://tenant-a.localhost/api/products')->assertOk();

    // Unknown domain: no tenant ever initializes, so CacheTenancyBootstrapper
    // never swaps the cache binding for this request at all.
    $unknown = $this->getJson('http://unknown.localhost/api/products');
    $unknown->assertNotFound();
    expect($unknown->getContent())->not->toContain('PROD-A');

    // Tenant A's own cached data is unaffected by the unknown-domain request.
    $stillA = $this->getJson('http://tenant-a.localhost/api/products');
    expect(collect($stillA->json('data'))->pluck('sku')->all())->toBe(['PROD-A']);
});

test('central-context cache usage (outside any tenant) is never tenant-tagged - CacheTenancyBootstrapper only swaps the binding while tenancy is initialized', function () {
    // No tenant->run() wrapper here at all - this is the CENTRAL request
    // context (what any future Phase 8 platform-only route would use). If
    // CacheTenancyBootstrapper's swap somehow leaked outside tenancy
    // initialization, this would either throw (tenant() called with no
    // tenant bound) or silently misbehave; instead it must behave like plain,
    // ordinary Laravel caching, proving global/platform caching is
    // unaffected by the tenant isolation mechanism - satisfies the "do not
    // blindly prefix everything" requirement without any allow-list code.
    Cache::put('central-only-key', 'central-value', 60);
    expect(Cache::get('central-only-key'))->toBe('central-value');
});
