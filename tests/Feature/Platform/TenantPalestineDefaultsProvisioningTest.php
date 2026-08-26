<?php

/**
 * TASK-MVP-016 (DECISION_LOG.md C88-C91). Proves `Platform\Tenancy\
 * Services\TenantProvisioner`'s five new Palestine-first provisioning
 * steps (`ensurePalestineCurrencySeeded()`, `ensurePalestineGovernoratesSeeded()`,
 * `ensurePalestineTimezoneSet()`, `ensurePalestineAddressDefaultsSeeded()`,
 * `ensurePalestineCashOnDeliveryEnabled()`): every newly provisioned
 * tenant gets ILS as its only currency, the 16 real Palestinian
 * governorates seeded, `channels.timezone = Asia/Hebron`, postcode
 * requirement off, and Cash on Delivery enabled - all idempotent under
 * retry, all strictly scoped per-tenant, and - critically - a tenant
 * already `Ready` before this feature existed is never touched by it
 * (the same `provision()` no-op-when-Ready guard TASK-MVP-012 already
 * established). Also proves the real storefront checkout accepts a
 * Palestine address (governorate + free-text city + no postcode) and
 * that ILS pricing/Arabic-RTL rendering both remain correct.
 *
 * Real MySQL, real Bagisto migrations/seeders/repositories, real HTTP
 * checkout requests - nothing mocked, following this file's own
 * established siblings' (TenantArabicLocaleProvisioningTest.php,
 * StoreReadinessTest.php) conventions exactly.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Platform\Tenancy\Support\PalestineGovernorates;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

uses(PlatformIntegrationTestCase::class);

function loginAsTpdTenantAdmin(TestCase $test, string $domain, string $email = 'admin@example.com', string $password = 'admin123'): void
{
    $test->post('http://'.$domain.'/admin/login', ['email' => $email, 'password' => $password]);
}

/**
 * Creates a real, fully purchasable product via Bagisto's own real Admin
 * HTTP path - the exact same established, proven technique
 * `StoreReadinessTest.php::createProductViaRealAdminPath()` already uses,
 * duplicated locally (not imported - these are Pest global functions,
 * scoped per file) to avoid a cross-test-file dependency.
 */
function createTpdProduct(TestCase $test, string $domain, string $sku, float $price): int
{
    loginAsTpdTenantAdmin($test, $domain);

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
            'url_key' => 'tpd-product-'.$id,
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

const TPD_TEST_IDS = ['tpd-a', 'tpd-b', 'tpd-legacy'];

function cleanupTpdTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (TPD_TEST_IDS as $id) {
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

function provisionTpdTenant(string $id): Tenant
{
    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    return $tenant->fresh();
}

beforeEach(fn () => cleanupTpdTestTenants());
afterEach(fn () => cleanupTpdTestTenants());

// --- A. Currency -------------------------------------------------------

test('1. a freshly provisioned tenant has exactly one currency, ILS, correctly wired to its channel', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        expect(DB::table('currencies')->count())->toBe(1);

        $currency = DB::table('currencies')->first();
        expect($currency->code)->toBe('ILS');
        expect($currency->symbol)->toBe('₪');
        expect((int) $currency->decimal)->toBe(2);

        $channel = DB::table('channels')->where('id', 1)->first();
        expect($channel->base_currency_id)->toBe($currency->id);

        expect(DB::table('channel_currencies')->where('channel_id', 1)->count())->toBe(1);
        expect(DB::table('channel_currencies')->where('channel_id', 1)->value('currency_id'))->toBe($currency->id);
    });
});

test('2. a real product price renders with the ILS symbol on the real storefront', function () {
    provisionTpdTenant('tpd-a');

    $productId = createTpdProduct($this, 'tpd-a.platform.test', 'tpd-ils-product', 49.99);

    $response = $this->get('http://tpd-a.platform.test/tpd-product-'.$productId);

    $response->assertOk();
    $response->assertSee('₪', false);
});

// --- B. Governorates -----------------------------------------------------

test('3. all 16 Palestinian governorates are seeded with correct codes, Arabic base names, and ar/en translations', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        $countryId = DB::table('countries')->where('code', 'PS')->value('id');

        $states = DB::table('country_states')->where('country_code', 'PS')->get()->keyBy('code');
        expect($states)->toHaveCount(16);

        foreach (PalestineGovernorates::ALL as $code => $names) {
            expect($states->has($code))->toBeTrue("Missing governorate code [{$code}]");

            $state = $states[$code];
            expect($state->country_id)->toBe($countryId);
            // Base column deliberately holds the ARABIC name - see
            // TenantProvisioner::ensurePalestineGovernoratesSeeded()'s own
            // docblock (the real storefront dropdown reads this raw).
            expect($state->default_name)->toBe($names['ar']);

            $translations = DB::table('country_state_translations')
                ->where('country_state_id', $state->id)
                ->get()
                ->keyBy('locale');

            expect($translations->has('ar'))->toBeTrue();
            expect($translations->has('en'))->toBeTrue();
            expect($translations['ar']->default_name)->toBe($names['ar']);
            expect($translations['en']->default_name)->toBe($names['en']);
        }
    });
});

test('4. the real storefront checkout state dropdown data includes the seeded Palestinian governorates', function () {
    provisionTpdTenant('tpd-a');

    $response = $this->getJson('http://tpd-a.platform.test/api/core/states');

    $response->assertOk();
    $states = collect($response->json('data.PS'));
    expect($states)->toHaveCount(16);
    expect($states->pluck('code')->sort()->values()->all())->toBe(collect(array_keys(PalestineGovernorates::ALL))->sort()->values()->all());
});

// --- C. Timezone -----------------------------------------------------------

test('5. the tenant channel timezone is set to Asia/Hebron, independent of the global app timezone', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        expect(DB::table('channels')->where('id', 1)->value('timezone'))->toBe('Asia/Hebron');
    });

    expect(config('app.timezone'))->not->toBe('Asia/Hebron');
});

// --- D. Address defaults -----------------------------------------------------

test('6. postcode requirement is off, state requirement stays at Bagisto\'s own default (on)', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        $channelCode = DB::table('channels')->where('id', 1)->value('code');

        $postcode = DB::table('core_config')
            ->where('code', 'customer.address.requirements.postcode')
            ->where('channel_code', $channelCode)
            ->value('value');

        expect($postcode)->toBe('0');

        $stateRow = DB::table('core_config')
            ->where('code', 'customer.address.requirements.state')
            ->where('channel_code', $channelCode)
            ->exists();

        // No row written for `state` - Bagisto's own schema default
        // (required) is left untouched, as documented.
        expect($stateRow)->toBeFalse();
    });
});

test('7. config(app.default_country) resolves to PS when explicitly set, and safely null when unset', function () {
    config(['app.default_country' => 'PS']);
    expect(config('app.default_country'))->toBe('PS');

    config(['app.default_country' => null]);
    expect(config('app.default_country'))->toBeNull();
});

// --- E. Payment defaults -----------------------------------------------------

test('8. Cash on Delivery is active with an Arabic and English title', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        $channelCode = DB::table('channels')->where('id', 1)->value('code');

        $active = DB::table('core_config')
            ->where('code', 'sales.payment_methods.cashondelivery.active')
            ->where('channel_code', $channelCode)
            ->value('value');
        expect($active)->toBe('1');

        $titleAr = DB::table('core_config')
            ->where('code', 'sales.payment_methods.cashondelivery.title')
            ->where('channel_code', $channelCode)
            ->where('locale_code', 'ar')
            ->value('value');
        expect($titleAr)->toBe('الدفع عند الاستلام');

        $titleEn = DB::table('core_config')
            ->where('code', 'sales.payment_methods.cashondelivery.title')
            ->where('channel_code', $channelCode)
            ->where('locale_code', 'en')
            ->value('value');
        expect($titleEn)->toBe('Cash on Delivery');
    });
});

test('9. Money Transfer is explicitly deactivated, correcting Bagisto\'s own active-by-default fallback', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $tenant->run(function () {
        $channelCode = DB::table('channels')->where('id', 1)->value('code');

        // Confirms the real, empirically-found behavior (not the original,
        // wrong assumption this test itself caught during implementation):
        // packages/Webkul/Payment/src/Config/payment-methods.php hardcodes
        // 'active' => true for moneytransfer, so an EXPLICIT '0' row is
        // required - zero core_config rows would leave it live.
        $active = DB::table('core_config')
            ->where('code', 'sales.payment_methods.moneytransfer.active')
            ->where('channel_code', $channelCode)
            ->value('value');

        expect($active)->toBe('0');
        expect((bool) system_config()->getConfigData('sales.payment_methods.moneytransfer.active', $channelCode))->toBeFalse();

        // No title/description/other field was written - the merchant's
        // real bank details are never invented.
        $otherRows = DB::table('core_config')
            ->where('code', 'like', 'sales.payment_methods.moneytransfer.%')
            ->where('code', '!=', 'sales.payment_methods.moneytransfer.active')
            ->where('channel_code', $channelCode)
            ->count();
        expect($otherRows)->toBe(0);
    });
});

test('10. Cash on Delivery is genuinely selectable through a real checkout', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $productId = createTpdProduct($this, 'tpd-a.platform.test', 'tpd-cod-product', 25);

    $cart = $this->postJson('http://tpd-a.platform.test/api/checkout/cart', ['product_id' => $productId, 'quantity' => 1]);
    $cart->assertOk();

    $address = $this->postJson('http://tpd-a.platform.test/api/checkout/onepage/addresses', [
        'billing' => [
            'first_name' => 'Amina', 'last_name' => 'Shopper', 'email' => 'tpd-shopper@example.test',
            'address' => ['123 Al-Manara Street'], 'city' => 'Ramallah', 'country' => 'PS',
            'state' => 'RBH', 'phone' => '+970599123456',
            'use_for_shipping' => 1,
        ],
    ]);
    $address->assertOk();

    $shipping = $this->postJson('http://tpd-a.platform.test/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free']);
    $shipping->assertOk();

    $payment = $this->postJson('http://tpd-a.platform.test/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']]);
    $payment->assertOk();

    $order = $this->postJson('http://tpd-a.platform.test/api/checkout/onepage/orders');
    $order->assertOk();

    $tenant->run(function () {
        $order = DB::table('orders')->latest('id')->first();
        expect($order)->not->toBeNull();
        expect($order->customer_email)->toBe('tpd-shopper@example.test');
    });
});

// --- F. Existing-tenant protection & retry idempotency ---------------------

test('11. a tenant already Ready before this feature existed is never mutated by it', function () {
    $tenant = provisionTpdTenant('tpd-legacy');

    // Roll the tenant's own database back to exactly what a pre-TASK-MVP-016
    // tenant would look like - USD currency, no Palestine governorates, no
    // channel timezone, postcode required, COD inactive - to faithfully
    // simulate a real existing production tenant, not an untested hypothetical.
    $tenant->run(function () {
        DB::table('currencies')->update(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal' => 2]);
        DB::table('country_states')->where('country_code', 'PS')->delete();
        DB::table('channels')->where('id', 1)->update(['timezone' => null]);

        $channelCode = DB::table('channels')->where('id', 1)->value('code');
        DB::table('core_config')->where('channel_code', $channelCode)->delete();

        expect(DB::table('currencies')->value('code'))->toBe('USD');
        expect(DB::table('country_states')->where('country_code', 'PS')->count())->toBe(0);
        expect(DB::table('channels')->where('id', 1)->value('timezone'))->toBeNull();
        expect(DB::table('core_config')->where('channel_code', $channelCode)->count())->toBe(0);
    });

    // Still Ready - provision()'s own top-level guard must make this a
    // complete no-op, never reaching any ensurePalestineXxx() step.
    app(TenantProvisioner::class)->provision($tenant);

    $tenant->run(function () {
        expect(DB::table('currencies')->value('code'))->toBe('USD');
        expect(DB::table('country_states')->where('country_code', 'PS')->count())->toBe(0);
        expect(DB::table('channels')->where('id', 1)->value('timezone'))->toBeNull();

        $channelCode = DB::table('channels')->where('id', 1)->value('code');
        expect(DB::table('core_config')->where('channel_code', $channelCode)->count())->toBe(0);
    });
});

test('12. retrying provisioning on a still-Pending tenant is fully idempotent - no duplicate rows', function () {
    $tenant = Tenant::create(['id' => 'tpd-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tpd-a.platform.test']);

    $provisioner = app(TenantProvisioner::class);
    $provisioner->provision($tenant);

    // Force back to Pending to bypass provision()'s own Ready-early-return,
    // simulating a genuine retried attempt (the only way any of these steps
    // legitimately run twice for the same tenant).
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();
    $provisioner->provision($tenant);

    $tenant->run(function () {
        expect(DB::table('currencies')->count())->toBe(1);
        expect(DB::table('country_states')->where('country_code', 'PS')->count())->toBe(16);

        $channelCode = DB::table('channels')->where('id', 1)->value('code');
        expect(DB::table('core_config')->where('code', 'customer.address.requirements.postcode')->where('channel_code', $channelCode)->count())->toBe(1);
        expect(DB::table('core_config')->where('code', 'sales.payment_methods.cashondelivery.active')->where('channel_code', $channelCode)->count())->toBe(1);
        expect(DB::table('core_config')->where('code', 'sales.payment_methods.cashondelivery.title')->where('channel_code', $channelCode)->count())->toBe(2);
        expect(DB::table('core_config')->where('code', 'sales.payment_methods.moneytransfer.active')->where('channel_code', $channelCode)->count())->toBe(1);
    });
});

// --- G. Cross-tenant isolation ------------------------------------------------

test('13. two tenants never leak Palestine-default configuration into each other', function () {
    $tenantA = provisionTpdTenant('tpd-a');
    $tenantB = provisionTpdTenant('tpd-b');

    // Deliberately break tenant B's own currency AFTER provisioning both,
    // entirely inside tenant B's own database, to prove tenant A's own
    // state was never contaminated by having provisioned alongside B.
    $tenantB->run(function () {
        DB::table('currencies')->update(['code' => 'USD', 'symbol' => '$']);
    });

    $tenantA->run(function () {
        expect(DB::table('currencies')->value('code'))->toBe('ILS');
        expect(DB::table('country_states')->where('country_code', 'PS')->count())->toBe(16);
    });

    $tenantB->run(function () {
        expect(DB::table('currencies')->value('code'))->toBe('USD');
    });
});

// --- H. Arabic/RTL regression spot check --------------------------------------

test('14. the Palestine-first storefront homepage still renders Arabic/RTL correctly', function () {
    $tenant = provisionTpdTenant('tpd-a');

    $response = $this->get('http://tpd-a.platform.test/');

    $response->assertOk();
    $response->assertSee('lang="ar"', false);
    $response->assertSee('dir="rtl"', false);
});
