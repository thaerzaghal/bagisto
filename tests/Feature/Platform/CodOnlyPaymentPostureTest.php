<?php

/**
 * TASK-MVP-023 (RISK_REGISTER.md R78). PRODUCT DECISION: the only
 * supported storefront payment method for the current MVP is Cash On
 * Delivery. Money Transfer stays inactive (TASK-MVP-016, unchanged).
 * Every bundled external gateway (Stripe, Razorpay, PayU, PhonePe,
 * PayPal Smart Button, PayPal Standard, PayGlocal) is intentionally out
 * of current MVP scope, not merely unconfigured - confirmed by direct
 * source reading that each one ships `active => true` with a non-empty
 * PLACEHOLDER credential in its own package config, so absent this
 * task's fix, every one of them is structurally selectable by a real
 * shopper (see `Platform\Tenancy\Support\UnsupportedPaymentGateways`'s
 * own docblock for the full root-cause record).
 *
 * Every checkout-facing assertion below exercises the REAL, authoritative
 * enforcement path - `Webkul\Shop\Http\Controllers\API\
 * OnepageController::isPaymentMethodAvailable()`, which itself calls the
 * real `Webkul\Payment\Facades\Payment::getSupportedPaymentMethods()` -
 * never a raw config/core_config read alone, matching this project's own
 * established discipline (R73/C95/R74/R75) of proving a defect/fix
 * through the real read/enforcement path, not merely inspecting stored
 * state.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Platform\Tenancy\Support\UnsupportedPaymentGateways;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;
use Webkul\Core\Repositories\CoreConfigRepository;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

uses(PlatformIntegrationTestCase::class);

const COPP_TENANT = 'copp-tenant-a';
const COPP_LEGACY_TENANT = 'copp-tenant-legacy';

/**
 * @return array<int, string> the 9 real sales.payment_methods.* codes this
 *                            task governs, COD/Money Transfer first
 */
function coppAllMethodCodes(): array
{
    return array_merge(['cashondelivery', 'moneytransfer'], UnsupportedPaymentGateways::CODES);
}

function ensureCoppTenant(string $id): Tenant
{
    $tenant = Tenant::find($id);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => $id.'.platform.test']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
        $tenant = $tenant->fresh();
    }

    return $tenant->fresh();
}

/**
 * Raw fixture manipulation - reproducing exactly what a REAL pre-TASK-MVP-023
 * tenant's core_config would look like, the same established technique
 * TenantPalestineDefaultsProvisioningTest's own "tpd-legacy" fixture uses
 * (direct DB::table('core_config') writes/deletes to simulate history this
 * test file cannot otherwise produce). Never used to touch a real production
 * tenant - test fixtures only.
 */
function coppSetRawActive(string $tenantId, string $method, ?string $value): void
{
    Tenant::find($tenantId)->run(function () use ($method, $value) {
        $channelCode = DB::table('channels')->where('id', 1)->value('code');
        $code = "sales.payment_methods.{$method}.active";

        DB::table('core_config')->where('code', $code)->where('channel_code', $channelCode)->delete();

        if ($value !== null) {
            DB::table('core_config')->insert([
                'code' => $code,
                'channel_code' => $channelCode,
                'locale_code' => null,
                'value' => $value,
            ]);
        }
    });
}

function loginAsCoppTenantAdmin(TestCase $test, string $domain, string $email = 'admin@example.com', string $password = 'admin123'): void
{
    $test->post('http://'.$domain.'/admin/login', ['email' => $email, 'password' => $password]);
}

/**
 * Duplicated locally rather than imported - Pest global functions are
 * file-scoped, matching TenantPalestineDefaultsProvisioningTest's own
 * `createTpdProduct()` (itself a documented duplication of
 * StoreReadinessTest's original technique).
 */
function createCoppProduct(TestCase $test, string $domain, string $sku, float $price): int
{
    loginAsCoppTenantAdmin($test, $domain);

    $tenantId = explode('.', $domain)[0];
    $familyId = Tenant::find($tenantId)->run(fn () => DB::table('attribute_families')->value('id'));

    $created = $test->postJson('http://'.$domain.'/admin/catalog/products/create', [
        'type' => 'simple',
        'attribute_family_id' => $familyId,
        'sku' => $sku,
    ]);
    $created->assertOk();
    preg_match('/(\d+)$/', (string) $created->json('data.redirect_url'), $m);
    $id = (int) $m[1];

    Tenant::find($tenantId)->run(function () use ($id, $price) {
        app(ProductRepository::class)->update([
            'name' => 'منتج تجريبي '.$id,
            'url_key' => 'copp-product-'.$id,
            'price' => $price,
            'weight' => 1,
            'status' => 1,
            'visible_individually' => 1,
            'guest_checkout' => 1,
            'short_description' => 'short',
            'description' => 'description',
        ], $id);

        DB::table('product_inventories')->updateOrInsert(
            ['product_id' => $id, 'inventory_source_id' => 1],
            ['qty' => 50]
        );

        Event::dispatch('catalog.product.update.after', Product::find($id)->fresh());
    });

    return $id;
}

/**
 * Real cart + address, stopping right before the payment-method step -
 * shared setup for both the "COD accepted" and "unsupported method
 * rejected" proofs.
 */
function coppCartReadyForPayment(TestCase $test, string $domain, int $productId): void
{
    $cart = $test->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $productId, 'quantity' => 1]);
    $cart->assertOk();

    $address = $test->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => [
            'first_name' => 'Amina', 'last_name' => 'Shopper', 'email' => 'copp-shopper@example.test',
            'address' => ['123 Al-Manara Street'], 'city' => 'Ramallah', 'country' => 'PS',
            'state' => 'RBH', 'phone' => '+970599123456',
            'use_for_shipping' => 1,
        ],
    ]);
    $address->assertOk();

    $shipping = $test->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free']);
    $shipping->assertOk();
}

beforeEach(function () {
    $this->tenant = ensureCoppTenant(COPP_TENANT);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

// --- A. New-tenant provisioning: raw config state ---------------------------

test('1. a freshly provisioned tenant has COD active and every other payment method inactive', function () {
    $values = $this->tenant->run(function () {
        $result = [];

        foreach (coppAllMethodCodes() as $method) {
            $result[$method] = DB::table('core_config')
                ->where('code', "sales.payment_methods.{$method}.active")
                ->value('value');
        }

        return $result;
    });

    expect($values['cashondelivery'])->toBe('1');
    expect($values['moneytransfer'])->toBe('0');

    foreach (UnsupportedPaymentGateways::CODES as $method) {
        expect($values[$method])->toBe('0');
    }
});

// --- B. Real checkout availability proof -------------------------------------

test('2. COD is genuinely selectable and completes a real order', function () {
    $productId = createCoppProduct($this, COPP_TENANT.'.platform.test', 'copp-cod-product-'.uniqid(), 25);

    coppCartReadyForPayment($this, COPP_TENANT.'.platform.test', $productId);

    $payment = $this->postJson('http://'.COPP_TENANT.'.platform.test/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']]);
    $payment->assertOk();

    $order = $this->postJson('http://'.COPP_TENANT.'.platform.test/api/checkout/onepage/orders');
    $order->assertOk();

    $this->tenant->run(function () {
        $order = DB::table('orders')->latest('id')->first();
        expect($order)->not->toBeNull();
        expect($order->customer_email)->toBe('copp-shopper@example.test');
    });
});

test('3. every unsupported gateway is genuinely rejected server-side at checkout, not merely absent from a listing', function () {
    $productId = createCoppProduct($this, COPP_TENANT.'.platform.test', 'copp-reject-product-'.uniqid(), 25);

    foreach (array_merge(['moneytransfer'], UnsupportedPaymentGateways::CODES) as $method) {
        // Fresh cart per method - a rejected payment-method attempt should
        // never be able to leave the cart in a state that affects the next
        // attempt.
        $this->postJson('http://'.COPP_TENANT.'.platform.test/api/checkout/cart', ['product_id' => $productId, 'quantity' => 1])->assertOk();

        $payment = $this->postJson('http://'.COPP_TENANT.'.platform.test/api/checkout/onepage/payment-methods', ['payment' => ['method' => $method]]);

        expect($payment->status())->toBe(403, "Expected [{$method}] to be rejected (403) - it was not.");
    }
});

// --- C. Fail-before-fix note ------------------------------------------------
// See this task's own final report for the fail-before-fix regression proof
// (the ensureUnsupportedPaymentGatewaysDeactivated() call in provision() was
// temporarily removed, a fresh tenant re-provisioned, and test 3 above
// re-run to confirm every listed gateway leaks through before the fix and
// is rejected after) - not encoded as a persisted toggle in this file.

// --- D. Remediation command -------------------------------------------------

test('4. the remediation command converges a legacy tenant to the current policy (CASE A/B/C/D and Money Transfer)', function () {
    $legacy = ensureCoppTenant(COPP_LEGACY_TENANT);

    // Simulate a real pre-TASK-MVP-023 tenant's core_config:
    coppSetRawActive(COPP_LEGACY_TENANT, 'stripe', '1');       // CASE B: active=1 -> must become 0
    coppSetRawActive(COPP_LEGACY_TENANT, 'razorpay', null);    // CASE A: no row at all -> must become 0
    coppSetRawActive(COPP_LEGACY_TENANT, 'payu', '0');         // CASE C: already 0 -> unchanged
    coppSetRawActive(COPP_LEGACY_TENANT, 'cashondelivery', '0'); // CASE D: 0 -> must become 1
    coppSetRawActive(COPP_LEGACY_TENANT, 'moneytransfer', '1'); // must end inactive

    Artisan::call('platform:tenants:enforce-cod-only-payments', ['--tenant' => [COPP_LEGACY_TENANT]]);
    $output = Artisan::output();

    expect($output)->toContain('stripe.active: changed [active] -> [inactive]');
    // ^ the command's own printed report; the assertions below against the
    // raw DB values remain the authoritative proof regardless of wording.

    $values = Tenant::find(COPP_LEGACY_TENANT)->run(function () {
        $result = [];

        foreach (coppAllMethodCodes() as $method) {
            $result[$method] = DB::table('core_config')
                ->where('code', "sales.payment_methods.{$method}.active")
                ->value('value');
        }

        return $result;
    });

    expect($values['stripe'])->toBe('0');       // CASE B converged
    expect($values['razorpay'])->toBe('0');     // CASE A converged (row created)
    expect($values['payu'])->toBe('0');         // CASE C unchanged
    expect($values['cashondelivery'])->toBe('1'); // CASE D converged
    expect($values['moneytransfer'])->toBe('0'); // converged
});

test('5. CASE E: an already-active COD is left unchanged, and a second run is a genuine no-op', function () {
    $legacy = ensureCoppTenant(COPP_LEGACY_TENANT);

    // Fully converge first (mirrors test 4's own end state, run independently
    // so this test does not depend on execution order).
    coppSetRawActive(COPP_LEGACY_TENANT, 'cashondelivery', '1'); // CASE E starting point
    coppSetRawActive(COPP_LEGACY_TENANT, 'moneytransfer', '0');
    foreach (UnsupportedPaymentGateways::CODES as $method) {
        coppSetRawActive(COPP_LEGACY_TENANT, $method, '0');
    }

    Artisan::call('platform:tenants:enforce-cod-only-payments', ['--tenant' => [COPP_LEGACY_TENANT]]);
    $firstOutput = Artisan::output();
    expect($firstOutput)->toContain('already fully compliant');

    // Second run: must report the same "already fully compliant" outcome -
    // no configuration changes, proving genuine idempotency, not merely a
    // net-zero value change.
    Artisan::call('platform:tenants:enforce-cod-only-payments', ['--tenant' => [COPP_LEGACY_TENANT]]);
    $secondOutput = Artisan::output();
    expect($secondOutput)->toContain('already fully compliant');
    expect($secondOutput)->not->toContain('changed');

    $codValue = Tenant::find(COPP_LEGACY_TENANT)->run(
        fn () => DB::table('core_config')->where('code', 'sales.payment_methods.cashondelivery.active')->value('value')
    );
    expect($codValue)->toBe('1');
});

test('6. --dry-run reports the intended change but writes nothing', function () {
    $legacy = ensureCoppTenant(COPP_LEGACY_TENANT);

    coppSetRawActive(COPP_LEGACY_TENANT, 'paypal_smart_button', '1');

    Artisan::call('platform:tenants:enforce-cod-only-payments', ['--tenant' => [COPP_LEGACY_TENANT], '--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('DRY RUN');
    expect($output)->toContain('paypal_smart_button.active: would change [active] -> [inactive]');

    $rawValue = Tenant::find(COPP_LEGACY_TENANT)->run(
        fn () => DB::table('core_config')->where('code', 'sales.payment_methods.paypal_smart_button.active')->value('value')
    );

    // Untouched by the dry run - still whatever the fixture set it to.
    expect($rawValue)->toBe('1');
});

// --- E. Cache safety (R73/C95 discipline) ------------------------------------

test('7. a cached read before the remediation write is correctly reflected immediately after, no manual cache clear', function () {
    $legacy = ensureCoppTenant(COPP_LEGACY_TENANT);

    coppSetRawActive(COPP_LEGACY_TENANT, 'razorpay', '1');

    $before = Tenant::find(COPP_LEGACY_TENANT)->run(function () {
        // Warm CoreConfigRepository's own cache first, via the EXACT same
        // findOneWhere() call SystemConfig::getCoreConfig() itself issues
        // (confirmed by reading that method directly) - the same
        // cache-population discipline R73/C95/R74's own proofs use.
        $channelCode = DB::table('channels')->where('id', 1)->value('code');
        app(CoreConfigRepository::class)->findOneWhere([
            'code' => 'sales.payment_methods.razorpay.active',
            'channel_code' => $channelCode,
        ]);

        return (bool) core()->getConfigData('sales.payment_methods.razorpay.active');
    });
    expect($before)->toBeTrue();

    Artisan::call('platform:tenants:enforce-cod-only-payments', ['--tenant' => [COPP_LEGACY_TENANT]]);

    $after = Tenant::find(COPP_LEGACY_TENANT)->run(
        fn () => (bool) core()->getConfigData('sales.payment_methods.razorpay.active')
    );

    expect($after)->toBeFalse();
});

// --- F. Untouched existing-tenant guard --------------------------------------

test('8. a tenant already Ready before this task existed is never mutated by re-provisioning', function () {
    $legacy = ensureCoppTenant(COPP_LEGACY_TENANT);

    coppSetRawActive(COPP_LEGACY_TENANT, 'stripe', '1');

    // provision()'s own top-level Ready guard must make this a no-op -
    // ensureUnsupportedPaymentGatewaysDeactivated() must never run for an
    // already-Ready tenant merely because provision() is called again.
    app(TenantProvisioner::class)->provision($legacy);

    $stripeValue = Tenant::find(COPP_LEGACY_TENANT)->run(
        fn () => DB::table('core_config')->where('code', 'sales.payment_methods.stripe.active')->value('value')
    );

    expect($stripeValue)->toBe('1');
});
