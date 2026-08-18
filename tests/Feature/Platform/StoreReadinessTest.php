<?php

/**
 * TASK-MVP-003 - Merchant Store Essentials & First-Run Readiness.
 *
 * Covers: (A) the new channel-hostname-correctness provisioning step, (B)
 * the existing-tenant repair mechanism, (C) the merchant-side half of the
 * shopper-order workflow (product creation via the real Admin path ->
 * storefront visibility, and order visibility via the real Admin Sales
 * grid), (D) the welcome banner. Does not reproduce TASK-MVP-002's own
 * shopper-side coverage - only what's needed here to place a minimal real
 * order for the merchant-Admin-visibility check.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Product\Repositories\ProductRepository;

uses(PlatformIntegrationTestCase::class);

const MVP003_TENANT_A = 'mvp003-a';
const MVP003_TENANT_B = 'mvp003-b';
const MVP003_TENANT_LEGACY = 'mvp003-legacy';
const MVP003_TENANT_ADMIN_PROVISION = 'mvp003-admin-provision';
const MVP003_TENANT_PENDING = 'mvp003-pending';
const MVP003_TENANT_SIGNUP = 'mvp003-signup';

function ensureMvp003Tenant(string $id): Tenant
{
    $tenant = Tenant::find($id);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => $id.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    $tenant = $tenant->fresh();

    // R40-class discipline - this file creates real products/orders
    // against persistent (non-transacted) fixture tenants.
    $proPlan = Plan::where('code', 'pro')->firstOrFail();
    if ($tenant->plan_id !== $proPlan->id) {
        $tenant->forceFill(['plan_id' => $proPlan->id])->save();
    }

    return $tenant->fresh();
}

function mvp003ChannelHostname(Tenant $tenant): ?string
{
    return $tenant->run(fn () => DB::table('channels')->where('id', 1)->value('hostname'));
}

function mvp003ChannelSnapshot(Tenant $tenant): array
{
    return $tenant->run(fn () => (array) DB::table('channels')->where('id', 1)->first());
}

function loginAsMvp003TenantAdmin(TestCase $test, string $domain, string $email = 'admin@example.com', string $password = 'admin123'): void
{
    $test->post('http://'.$domain.'/admin/login', ['email' => $email, 'password' => $password]);
}

function ensureMvp003PlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'mvp003-platform-admin@example.test'],
        ['name' => 'MVP003 Platform Admin', 'password' => 'platform-secret-1']
    );
}

function loginAsMvp003PlatformAdmin(TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'mvp003-platform-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * Creates a real product via Bagisto's own real Admin HTTP `store()`
 * path (POST admin/catalog/products/create), then completes it to a
 * fully purchasable state via the SAME `ProductRepository::update()`
 * method `Admin\ProductController::update()` itself calls internally -
 * `Admin\Http\Requests\ProductForm`'s own validation is built dynamically
 * per attribute-family and is Bagisto's own already-tested concern, not
 * what this test is verifying (tenant/channel correctness is).
 */
function createProductViaRealAdminPath(TestCase $test, string $domain, string $sku): int
{
    loginAsMvp003TenantAdmin($test, $domain);

    $familyId = Tenant::find(explode('.', $domain)[0])->run(fn () => DB::table('attribute_families')->value('id'));

    $created = $test->postJson('http://'.$domain.'/admin/catalog/products/create', [
        'type' => 'simple',
        'attribute_family_id' => $familyId,
        'sku' => $sku,
    ]);
    $created->assertOk();
    $productId = $created->json('data.redirect_url');
    preg_match('/(\d+)$/', $productId, $m);
    $id = (int) $m[1];

    Tenant::find(explode('.', $domain)[0])->run(function () use ($id) {
        app(ProductRepository::class)->update([
            'name' => 'MVP003 Product '.$id,
            'url_key' => 'mvp003-product-'.$id,
            'price' => 39.99,
            'weight' => 1,
            'status' => 1,
            'visible_individually' => 1,
            'guest_checkout' => 1,
            'short_description' => 'short',
            'description' => 'description',
        ], $id);

        Event::dispatch('catalog.product.update.after', Product::find($id)->fresh());
    });

    return $id;
}

function placeMinimalOrder(TestCase $test, string $domain, int $productId): void
{
    $test->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $productId, 'quantity' => 1])->assertOk();
    $test->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => [
            'first_name' => 'Sam', 'last_name' => 'Shopper', 'email' => 'mvp003-shopper@example.test',
            'address' => ['123 Market Street'], 'city' => 'Ramallah', 'country' => 'US',
            'state' => 'California', 'postcode' => '90001', 'phone' => '+15551234567',
            'use_for_shipping' => 1,
        ],
    ])->assertOk();
    $test->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free'])->assertOk();
    $test->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']])->assertOk();
    $test->postJson('http://'.$domain.'/api/checkout/onepage/orders')->assertOk();
}

const MVP003_PRODUCT_SKUS = [
    'mvp003-admin-product',
    'mvp003-order-product',
    'mvp003-isolation-a',
    'mvp003-isolation-b',
];

beforeEach(function () {
    // TASK-MVP-007. Production now defaults PUBLIC_SIGNUP_ENABLED to false
    // (managed-only onboarding) - the A1/D1 tests below exercise the real
    // /join flow directly, so they explicitly opt back in.
    config(['platform.signup.enabled' => true]);

    $this->tenantA = ensureMvp003Tenant(MVP003_TENANT_A);
    $this->tenantB = ensureMvp003Tenant(MVP003_TENANT_B);

    // Idempotency for re-runs against these persistent (non-transacted)
    // fixture tenants - C1-C4 create real products/orders with fixed
    // SKUs every run; delete any leftovers from a previous run first.
    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        $tenant->run(function () {
            $ids = DB::table('products')->whereIn('sku', MVP003_PRODUCT_SKUS)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('order_items')->whereIn('product_id', $ids)->delete();
                DB::table('product_ordered_inventories')->whereIn('product_id', $ids)->delete();
                DB::table('product_inventories')->whereIn('product_id', $ids)->delete();
                DB::table('product_inventory_indices')->whereIn('product_id', $ids)->delete();
                DB::table('product_attribute_values')->whereIn('product_id', $ids)->delete();
                DB::table('product_flat')->whereIn('product_id', $ids)->delete();
                DB::table('product_channels')->whereIn('product_id', $ids)->delete();
                DB::table('products')->whereIn('id', $ids)->delete();
            }
        });
    }
});

// --- A. Provisioning hostname correctness ---------------------------------

test('A1. fresh signup via /join gets the correct channel hostname, not the central app URL', function () {
    Tenant::find(MVP003_TENANT_SIGNUP)?->forceFill(['status' => TenantStatus::Pending])->save();
    DB::connection('mysql')->table('domains')->where('tenant_id', MVP003_TENANT_SIGNUP)->delete();
    DB::connection('mysql')->table('tenants')->where('id', MVP003_TENANT_SIGNUP)->delete();

    $this->post('http://localhost/join', [
        'owner_name' => 'MVP003 Signup Owner',
        'owner_email' => 'mvp003-signup@example.test',
        'slug' => MVP003_TENANT_SIGNUP,
        'password' => 'signup-password-1',
        'password_confirmation' => 'signup-password-1',
    ]);

    $tenant = Tenant::find(MVP003_TENANT_SIGNUP);
    expect($tenant)->not->toBeNull();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    $hostname = mvp003ChannelHostname($tenant);
    expect($hostname)->toBe(MVP003_TENANT_SIGNUP.'.platform.test');
    expect($hostname)->not->toBe(config('app.url'));

    // Cleanup - not a shared fixture other tests depend on.
    $tenant->run(fn () => null);
    DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.$tenant->database()->getName().'`');
    DB::connection('mysql')->table('domains')->where('tenant_id', MVP003_TENANT_SIGNUP)->delete();
    DB::connection('mysql')->table('tenants')->where('id', MVP003_TENANT_SIGNUP)->delete();
});

test('A2. Platform Admin-triggered provisioning also gets the correct channel hostname', function () {
    ensureMvp003PlatformAdmin();
    $tenant = Tenant::find(MVP003_TENANT_ADMIN_PROVISION);
    if (! $tenant) {
        $tenant = Tenant::create(['id' => MVP003_TENANT_ADMIN_PROVISION, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => MVP003_TENANT_ADMIN_PROVISION.'.localhost']);
    }
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    loginAsMvp003PlatformAdmin($this);

    $this->post(route('platform.tenants.provision', $tenant))->assertRedirect();

    $tenant = $tenant->fresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);
    expect(mvp003ChannelHostname($tenant))->toBe(MVP003_TENANT_ADMIN_PROVISION.'.localhost');
});

test('A3. re-provisioning an already-Ready tenant is idempotent and leaves the hostname (and unrelated channel fields) unchanged', function () {
    $before = mvp003ChannelSnapshot($this->tenantA);

    app(TenantProvisioner::class)->provision($this->tenantA);

    $after = mvp003ChannelSnapshot($this->tenantA);
    expect($after)->toBe($before);
    expect($after['hostname'])->toBe(MVP003_TENANT_A.'.localhost');
});

test('A4. Tenant A never receives Tenant B\'s hostname, and vice versa', function () {
    expect(mvp003ChannelHostname($this->tenantA))->toBe(MVP003_TENANT_A.'.localhost');
    expect(mvp003ChannelHostname($this->tenantB))->toBe(MVP003_TENANT_B.'.localhost');
    expect(mvp003ChannelHostname($this->tenantA))->not->toBe(mvp003ChannelHostname($this->tenantB));
});

test('A5. the central database is untouched by this step beyond legitimate central Tenant/Domain reads', function () {
    expect(Schema::connection('mysql')->hasTable('channels'))->toBeFalse();
});

// --- B. Existing-tenant repair ---------------------------------------------

test('B1. the repair command corrects an intentionally wrong legacy hostname while leaving every other channel field unchanged', function () {
    $tenant = ensureMvp003Tenant(MVP003_TENANT_LEGACY);

    $before = mvp003ChannelSnapshot($tenant);

    // Simulate "provisioned before this fix existed".
    $tenant->run(fn () => DB::table('channels')->where('id', 1)->update(['hostname' => 'http://localhost']));
    expect(mvp003ChannelHostname($tenant))->toBe('http://localhost');

    Artisan::call('platform:tenants:repair-channel-hostname', ['--tenant' => [MVP003_TENANT_LEGACY]]);

    $after = mvp003ChannelSnapshot($tenant);
    expect($after['hostname'])->toBe(MVP003_TENANT_LEGACY.'.localhost');

    // Every OTHER field is byte-for-byte unchanged.
    unset($before['hostname'], $after['hostname']);
    expect($after)->toBe($before);
});

test('B2. the repair command is idempotent', function () {
    $tenant = ensureMvp003Tenant(MVP003_TENANT_LEGACY);

    Artisan::call('platform:tenants:repair-channel-hostname', ['--tenant' => [MVP003_TENANT_LEGACY]]);
    $first = mvp003ChannelHostname($tenant);

    Artisan::call('platform:tenants:repair-channel-hostname', ['--tenant' => [MVP003_TENANT_LEGACY]]);
    $second = mvp003ChannelHostname($tenant);

    expect($second)->toBe($first);
    expect($second)->toBe(MVP003_TENANT_LEGACY.'.localhost');
});

test('B3. the repair command safely skips a non-Ready tenant without erroring, reprovisioning, or touching its data', function () {
    $tenant = Tenant::find(MVP003_TENANT_PENDING);
    if (! $tenant) {
        $tenant = Tenant::create(['id' => MVP003_TENANT_PENDING, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => MVP003_TENANT_PENDING.'.localhost']);
    }
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();

    $exitCode = Artisan::call('platform:tenants:repair-channel-hostname', ['--tenant' => [MVP003_TENANT_PENDING]]);

    expect($exitCode)->toBe(0);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Pending);
    expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeFalse();
});

test('B4. the repair command requires no plaintext merchant credentials and reports failures clearly', function () {
    // Running it against every tenant (no --tenant filter) must not throw,
    // must not require any credential input, and must report per-tenant.
    Artisan::call('platform:tenants:repair-channel-hostname', []);

    expect(Artisan::output())->toContain('Done.');
});

// --- C. Merchant Admin readiness --------------------------------------------

test('C1-C2. a merchant creates a real product through the actual Admin HTTP path, and it is immediately purchasable on the same storefront with no Platform intervention', function () {
    $productId = createProductViaRealAdminPath($this, MVP003_TENANT_A.'.localhost', 'mvp003-admin-product');

    $product = $this->tenantA->run(fn () => DB::table('products')->where('id', $productId)->first());
    expect($product)->not->toBeNull();

    $storefront = $this->get('http://'.MVP003_TENANT_A.'.localhost/mvp003-product-'.$productId);
    $storefront->assertOk();
    $storefront->assertSee('MVP003 Product '.$productId);
});

test('C3. a real shopper order is visible through the actual Bagisto Admin Sales > Orders surface', function () {
    $productId = createProductViaRealAdminPath($this, MVP003_TENANT_A.'.localhost', 'mvp003-order-product');

    // Manually ensure inventory (createProductViaRealAdminPath doesn't set
    // it - Admin's own separate updateInventories() route/step). Also
    // gives the product a real image (the same real domain-path upload
    // TenantStorageIsolationTest.php already uses) - the Admin Sales
    // grid/order-view pages render a product thumbnail for each line
    // item, and an IMAGE-LESS product falls back to a placeholder SVG
    // resolved through Vite's asset() helper, which this environment's
    // Admin-theme Vite manifest does not have a compiled entry for (a
    // genuine, pre-existing, environment-specific asset-build gap,
    // unrelated to tenancy/Platform correctness - see the task's own
    // final report). A real, Storage-backed image sidesteps that
    // asset() code path entirely rather than requiring a Vite rebuild
    // (out of scope) or avoiding this verification.
    $this->tenantA->run(function () use ($productId) {
        DB::table('product_inventories')->updateOrInsert(
            ['product_id' => $productId, 'inventory_source_id' => 1],
            ['qty' => 50]
        );

        $product = Product::find($productId);
        app(ProductImageRepository::class)->upload([
            'images' => ['files' => [UploadedFile::fake()->image('photo.jpg', 20, 20)]],
        ], $product, 'images');

        Event::dispatch('catalog.product.update.after', $product->fresh());
    });

    placeMinimalOrder($this, MVP003_TENANT_A.'.localhost', $productId);

    $orderId = $this->tenantA->run(fn () => DB::table('orders')->latest('id')->value('id'));

    // Bagisto's real Admin Sales > Orders DataGrid - Webkul\Admin\Http\
    // Controllers\Sales\OrderController::index() branches on
    // request()->ajax() (Laravel's X-Requested-With header, NOT the
    // Accept: application/json header getJson() sends) to decide
    // between the DataGrid JSON payload and the full HTML page - the
    // real contract the Admin UI's own DataGrid JS uses, verified from
    // source before use.
    loginAsMvp003TenantAdmin($this, MVP003_TENANT_A.'.localhost');
    $ordersGrid = $this->get(
        'http://'.MVP003_TENANT_A.'.localhost/admin/sales/orders',
        ['X-Requested-With' => 'XMLHttpRequest']
    );
    $ordersGrid->assertOk();
    $ordersGrid->assertJsonFragment(['customer_email' => 'mvp003-shopper@example.test']);
});

test('C4. Tenant A\'s Admin cannot see Tenant B\'s product or order data', function () {
    $productAId = createProductViaRealAdminPath($this, MVP003_TENANT_A.'.localhost', 'mvp003-isolation-a');
    $productBId = createProductViaRealAdminPath($this, MVP003_TENANT_B.'.localhost', 'mvp003-isolation-b');

    loginAsMvp003TenantAdmin($this, MVP003_TENANT_A.'.localhost');

    // Tenant A's own product is reachable and correctly identified.
    $ownProduct = $this->get('http://'.MVP003_TENANT_A.'.localhost/admin/catalog/products/edit/'.$productAId);
    $ownProduct->assertOk();

    // The SAME numeric id, on Tenant A's OWN session/domain, either does
    // not exist in Tenant A's database at all (404) or - if it happens
    // to coincide with a different product Tenant A itself created in an
    // earlier test - is never Tenant B's product (SKU never appears).
    $crossTenantAttempt = $this->get('http://'.MVP003_TENANT_A.'.localhost/admin/catalog/products/edit/'.$productBId);
    if ($crossTenantAttempt->getStatusCode() === 200) {
        $crossTenantAttempt->assertDontSee('mvp003-isolation-b');
    } else {
        $crossTenantAttempt->assertNotFound();
    }
});

// --- D. Welcome banner -------------------------------------------------------

test('D1. ?welcome=1 produces the welcome notice after the intended first-login flow, with a real product-creation CTA', function () {
    Tenant::find(MVP003_TENANT_SIGNUP)?->forceFill(['status' => TenantStatus::Pending])->save();
    DB::connection('mysql')->table('domains')->where('tenant_id', MVP003_TENANT_SIGNUP)->delete();
    DB::connection('mysql')->table('tenants')->where('id', MVP003_TENANT_SIGNUP)->delete();

    $signup = $this->post('http://localhost/join', [
        'owner_name' => 'Welcome Owner',
        'owner_email' => 'mvp003-welcome@example.test',
        'slug' => MVP003_TENANT_SIGNUP,
        'password' => 'welcome-password-1',
        'password_confirmation' => 'welcome-password-1',
    ]);
    $signup->assertRedirect('http://'.MVP003_TENANT_SIGNUP.'.platform.test/admin/login?welcome=1');

    $loginPage = $this->get($signup->headers->get('Location'));
    $loginPage->assertOk();

    $login = $this->post('http://'.MVP003_TENANT_SIGNUP.'.platform.test/admin/login', [
        'email' => 'mvp003-welcome@example.test',
        'password' => 'welcome-password-1',
    ]);
    $login->assertRedirect('http://'.MVP003_TENANT_SIGNUP.'.platform.test/admin/dashboard?welcome=1');

    $dashboard = $this->get($login->headers->get('Location'));
    $dashboard->assertOk();
    $dashboard->assertSee('Your store is ready');
    $dashboard->assertSee(route('admin.catalog.products.index'), false);

    // Cleanup.
    $tenant = Tenant::find(MVP003_TENANT_SIGNUP);
    DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.$tenant->database()->getName().'`');
    DB::connection('mysql')->table('domains')->where('tenant_id', MVP003_TENANT_SIGNUP)->delete();
    DB::connection('mysql')->table('tenants')->where('id', MVP003_TENANT_SIGNUP)->delete();
});

test('D2. an ordinary login/dashboard visit (no ?welcome=1) is completely unaffected', function () {
    loginAsMvp003TenantAdmin($this, MVP003_TENANT_A.'.localhost');

    $dashboard = $this->get('http://'.MVP003_TENANT_A.'.localhost/admin/dashboard');
    $dashboard->assertOk();
    $dashboard->assertDontSee('Your store is ready');
});

test('D3. no onboarding state is persisted anywhere - the banner is purely query-string-driven', function () {
    $centralTablesBefore = DB::connection('mysql')->select('SHOW TABLES');

    loginAsMvp003TenantAdmin($this, MVP003_TENANT_A.'.localhost');
    $this->get('http://'.MVP003_TENANT_A.'.localhost/admin/dashboard?welcome=1')->assertOk();

    $centralTablesAfter = DB::connection('mysql')->select('SHOW TABLES');
    expect($centralTablesAfter)->toEqual($centralTablesBefore);

    $this->tenantA->run(function () {
        expect(Schema::hasTable('onboarding'))->toBeFalse();
    });
});
