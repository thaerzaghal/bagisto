<?php

/**
 * TASK-MVP-002 - Storefront Shopper Order End-to-End Verification.
 *
 * Drives the REAL, unmodified Bagisto storefront flow (browse -> add to
 * cart -> address -> shipping -> payment -> place order) via real HTTP
 * requests against a genuinely provisioned tenant - no fake Platform
 * checkout, no bypassed Bagisto services, nothing mocked. See
 * docs/architecture/storefront-order.md for the full investigation
 * writeup (exact routes/controllers, why no provisioning gap was found,
 * and the Platform-Billing-vs-Shop-Payment distinction).
 *
 * Product fixtures use `Webkul\Faker\Helpers\Product` - the SAME helper
 * Bagisto's own shipped test suite uses
 * (packages/Webkul/Shop/tests/Feature/Checkout/CheckoutTest.php) - a
 * real domain-path product creation (real factories/models, real
 * `catalog.product.update.after` event, real channel/inventory sync),
 * not a raw INSERT.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Faker\Helpers\Product as ProductFaker;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const STOREFRONT_E2E_TENANT_A = 'storefront-e2e-a';
const STOREFRONT_E2E_TENANT_B = 'storefront-e2e-b';

function ensureStorefrontE2ETenant(string $id): Tenant
{
    $tenant = Tenant::find($id);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => $id.'.localhost']);
    }

    if ($tenant->status === TenantStatus::Suspended) {
        app(TenantLifecycle::class)->reactivate($tenant);
        $tenant = $tenant->fresh();
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    $tenant = $tenant->fresh();

    // R40-class discipline (RISK_REGISTER.md): this file re-runs against
    // the same persistent tenant across repeated test-suite executions
    // (PlatformIntegrationTestCase disables DatabaseTransactions), and
    // each run creates several new products - the default 'free' plan's
    // products.limit=10 would eventually block product creation that has
    // nothing to do with what this file actually tests (checkout, not
    // plan enforcement - that's ProductLimitEnforcementTest.php's job).
    // Pinned to the unlimited 'pro' plan idempotently, matching the
    // established ensureAdminPlanPageTestFixtures()/TenantCacheIsolationTest
    // idiom.
    $proPlan = Plan::where('code', 'pro')->firstOrFail();
    if ($tenant->plan_id !== $proPlan->id) {
        $tenant->forceFill(['plan_id' => $proPlan->id])->save();
    }

    return $tenant->fresh();
}

/**
 * Creates a real, fully purchasable simple product - the exact minimum
 * Bagisto requires (see docs/architecture/storefront-order.md "Minimum
 * purchasable-product requirements"): status/visible_individually/
 * guest_checkout true (channel-scoped for status), a channel sync, and
 * an inventory row on inventory_source_id=1 (the default source every
 * fresh tenant already has, seeded by Bagisto's own InventorySourceTableSeeder).
 * MUST run inside $tenant->run() - core()->getCurrentChannel() and every
 * attribute lookup need the tenant's own DB connection active.
 */
function createPurchasableProduct(string $sku, ?float $priceOverride = null, array $extraOverrides = []): \Webkul\Product\Models\Product
{
    $locale = app()->getLocale();

    $attributeValues = array_merge([
        'sku' => ['text_value' => $sku],
        'name' => ['text_value' => 'E2E Test Product '.$sku, 'locale' => $locale],
        'url_key' => ['text_value' => 'e2e-test-product-'.$sku, 'locale' => $locale],
        'price' => ['float_value' => $priceOverride ?? 49.99],
    ], $extraOverrides);

    return (new ProductFaker(['attribute_value' => $attributeValues]))
        ->getSimpleProductFactory()
        ->create();
}

function guestAddressPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Sam',
        'last_name' => 'Shopper',
        'email' => 'sam.shopper@example.test',
        'address' => ['123 Market Street'],
        'city' => 'Ramallah',
        'country' => 'US',
        'state' => 'California',
        'postcode' => '90001',
        'phone' => '+15551234567',
    ], $overrides);
}

beforeEach(function () {
    $this->tenantA = ensureStorefrontE2ETenant(STOREFRONT_E2E_TENANT_A);
    $this->tenantB = ensureStorefrontE2ETenant(STOREFRONT_E2E_TENANT_B);
});

test('1. the tenant storefront is reachable and tenancy resolves correctly', function () {
    $response = $this->get('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/');

    $response->assertOk();

    $this->tenantA->run(function () {
        expect(DB::connection()->getDatabaseName())->not->toBe('bagisto_central');
    });
});

test('2. a real product is visible through the actual storefront product-detail request', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-visibility'));

    $response = $this->get('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/'.$product->url_key);

    $response->assertOk();
    $response->assertSee('E2E Test Product e2e-visibility');
});

test('3. add-to-cart via the real API creates a correct cart in the tenant database', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-cart'));

    $response = $this->postJson('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/api/checkout/cart', [
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $response->assertOk();

    $this->tenantA->run(function () use ($product) {
        $cartItem = DB::table('cart_items')->where('product_id', $product->id)->first();

        expect($cartItem)->not->toBeNull();
        expect((int) $cartItem->quantity)->toBe(2);
        expect($cartItem->sku)->toBe($product->sku);
    });
});

test('4. the full shopper journey places a real Bagisto order: cart -> address -> shipping -> payment -> order', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-happy-path'));

    $domain = STOREFRONT_E2E_TENANT_A.'.localhost';

    // A. Add to cart.
    $this->postJson('http://'.$domain.'/api/checkout/cart', [
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertOk();

    // B. Address (guest checkout - allow_guest_checkout defaults to '1',
    // seeded by Bagisto's own ConfigTableSeeder; guest_checkout defaults
    // true on the product attribute - no login required).
    $addressResponse = $this->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => guestAddressPayload(['use_for_shipping' => 1]),
    ]);
    $addressResponse->assertOk();
    $addressResponse->assertJsonPath('data.shippingMethods.free.rates.0.method', 'free_free');

    // C. Shipping - "free" (Webkul\Shipping\Carriers\Free), active by
    // default (packages/Webkul/Shipping/src/Config/carriers.php,
    // no core_config override exists for a fresh tenant).
    $this->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', [
        'shipping_method' => 'free_free',
    ])->assertOk();

    // D. Payment - Cash On Delivery (Webkul\Payment\Payment\CashOnDelivery),
    // active by default (packages/Webkul/Payment/src/Config/payment-methods.php).
    // This is SHOPPER-pays-MERCHANT payment for a Bagisto Sales order -
    // entirely separate from Platform\Billing (merchant-pays-platform SaaS
    // subscription, TASK-ARCH-018/019). No Stripe call, no Platform\Billing
    // Payment row, no Subscription mutation happens anywhere in this flow.
    $this->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', [
        'payment' => ['method' => 'cashondelivery'],
    ])->assertOk();

    // E. Place the order through Bagisto's real order-placement path
    // (API\OnepageController::storeOrder() -> Sales\Repositories\OrderRepository::create()).
    $orderResponse = $this->postJson('http://'.$domain.'/api/checkout/onepage/orders');
    $orderResponse->assertOk();
    $orderResponse->assertJsonPath('data.redirect', true);

    // Persistence verification - directly in Tenant A's own database.
    $this->tenantA->run(function () use ($product) {
        $order = DB::table('orders')->latest('id')->first();

        expect($order)->not->toBeNull();
        expect((bool) $order->is_guest)->toBeTrue();
        expect($order->customer_email)->toBe('sam.shopper@example.test');
        expect($order->shipping_method)->toBe('free_free');
        expect((int) $order->total_qty_ordered)->toBe(1);
        expect((float) $order->grand_total)->toBe((float) $order->sub_total); // free shipping, no tax configured

        $orderItem = DB::table('order_items')->where('order_id', $order->id)->first();
        expect($orderItem)->not->toBeNull();
        expect($orderItem->product_id)->toBe($product->id);
        expect((int) $orderItem->qty_ordered)->toBe(1);
        expect((float) $orderItem->price)->toBe(49.99);

        $payment = DB::table('order_payment')->where('order_id', $order->id)->first();
        expect($payment)->not->toBeNull();
        expect($payment->method)->toBe('cashondelivery');

        $addresses = DB::table('addresses')->where('order_id', $order->id)->get();
        expect($addresses)->toHaveCount(2); // billing + shipping (use_for_shipping=1 clones billing)
        expect($addresses->pluck('address_type')->sort()->values()->all())->toBe(['order_billing', 'order_shipping']);
        expect($addresses->first()->city)->toBe('Ramallah');

        $this->orderId = $order->id;
    });
});

test('5. the placed order does not exist centrally or in an unrelated tenant', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-isolation-order'));
    $domain = STOREFRONT_E2E_TENANT_A.'.localhost';

    $this->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => guestAddressPayload(['use_for_shipping' => 1]),
    ])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free'])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/orders')->assertOk();

    // Central database has no Bagisto commerce tables at all (R17/R30's
    // own long-established invariant) - not even a table named `orders`.
    expect(Schema::connection('mysql')->hasTable('orders'))->toBeFalse();

    // Tenant B's own database (real, separate connection) has zero orders
    // referencing Tenant A's product SKU.
    $this->tenantB->run(function () {
        expect(DB::table('order_items')->where('sku', 'e2e-isolation-order')->exists())->toBeFalse();
    });
});

test('6. inventory: ordered_inventories is reserved at order placement, physical inventory is only decremented at shipment', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-inventory'));
    $domain = STOREFRONT_E2E_TENANT_A.'.localhost';

    $physicalQtyBefore = $this->tenantA->run(fn () => DB::table('product_inventories')->where('product_id', $product->id)->value('qty'));

    $this->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $product->id, 'quantity' => 3])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => guestAddressPayload(['use_for_shipping' => 1]),
    ])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free'])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/orders')->assertOk();

    $this->tenantA->run(function () use ($product, $physicalQtyBefore) {
        $orderedInventory = DB::table('product_ordered_inventories')->where('product_id', $product->id)->first();
        expect($orderedInventory)->not->toBeNull();
        expect((int) $orderedInventory->qty)->toBe(3);

        // Physical source stock is UNCHANGED at order-placement time -
        // only decremented at shipment (Sales\Repositories\ShipmentItemRepository).
        $physicalQtyAfter = DB::table('product_inventories')->where('product_id', $product->id)->value('qty');
        expect((int) $physicalQtyAfter)->toBe((int) $physicalQtyBefore);

        // The storefront-visible index (physical - reserved) DID drop,
        // which is what actually makes the product look "less available".
        $indexQty = DB::table('product_inventory_indices')->where('product_id', $product->id)->value('qty');
        expect((int) $indexQty)->toBe((int) $physicalQtyBefore - 3);
    });
});

test('7. the shopper session for this order lands in Tenant A\'s own sessions table, never centrally', function () {
    config(['session.driver' => 'database']);

    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-session'));
    $domain = STOREFRONT_E2E_TENANT_A.'.localhost';

    $this->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    $this->tenantA->run(function () {
        expect(DB::table('sessions')->count())->toBeGreaterThan(0);
    });

    expect(DB::connection('mysql')->table('sessions')->count())->toBe(0);

    config(['session.driver' => 'array']);
});

test('8. checkout requests use the tenant database connection, never a cross-tenant one', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-connection-probe'));
    $domain = STOREFRONT_E2E_TENANT_A.'.localhost';

    $connections = [];
    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $this->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    // stancl/tenancy names the dynamically-bound tenant connection 'tenant'
    // (generic, swapped per-request - never a per-tenant-id connection
    // name) - so the only connections a checkout request should ever
    // touch are the central 'mysql' one (tenant/domain resolution,
    // TenantAccessGate) and 'tenant' (the actual cart/product data).
    expect($connections)->toContain('tenant');
    $distinct = collect($connections)->filter()->unique()->sort()->values()->all();
    expect($distinct)->toBe(['mysql', 'tenant']);
});

test('9. Tenant B cannot see Tenant A\'s product through its own storefront', function () {
    $product = $this->tenantA->run(fn () => createPurchasableProduct('e2e-cross-tenant-visibility'));

    $response = $this->get('http://'.STOREFRONT_E2E_TENANT_B.'.localhost/'.$product->url_key);

    $response->assertNotFound();
});

test('10. an inactive product cannot be added to the cart', function () {
    $product = $this->tenantA->run(function () {
        $channelCode = core()->getCurrentChannel()->code;

        return createPurchasableProduct('e2e-inactive', null, [
            'status' => ['boolean_value' => false, 'channel' => $channelCode],
        ]);
    });

    $response = $this->postJson('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/api/checkout/cart', [
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $response->assertStatus(400);

    $this->tenantA->run(function () use ($product) {
        expect(DB::table('cart_items')->where('product_id', $product->id)->exists())->toBeFalse();
    });
});

test('11. requesting more quantity than available inventory is rejected', function () {
    $product = $this->tenantA->run(function () {
        $product = createPurchasableProduct('e2e-insufficient-stock');

        DB::table('product_inventories')->where('product_id', $product->id)->update(['qty' => 2]);
        \Illuminate\Support\Facades\Event::dispatch('catalog.product.update.after', $product->fresh());

        return $product;
    });

    $response = $this->postJson('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/api/checkout/cart', [
        'product_id' => $product->id,
        'quantity' => 5,
    ]);

    $response->assertStatus(400);

    $this->tenantA->run(function () use ($product) {
        expect(DB::table('cart_items')->where('product_id', $product->id)->exists())->toBeFalse();
    });
});

test('12. a suspended tenant remains blocked from checkout routes by the existing TenantAccessGate', function () {
    app(TenantLifecycle::class)->suspend($this->tenantA);

    $response = $this->postJson('http://'.STOREFRONT_E2E_TENANT_A.'.localhost/api/checkout/cart', [
        'product_id' => 1,
        'quantity' => 1,
    ]);

    $response->assertStatus(423);

    app(TenantLifecycle::class)->reactivate($this->tenantA);
});
