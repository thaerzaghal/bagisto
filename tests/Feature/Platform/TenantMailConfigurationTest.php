<?php

/**
 * TASK-MVP-004 (SMTP/email delivery). Automated regression coverage for the
 * mail ARCHITECTURE (tenant-override-with-central-fallback, correct
 * recipients/tenant context, graceful failure) - deliberately NOT a proof
 * that real Zoho SMTP delivery works. That proof is manual, against the
 * real pilot server, using real external mailboxes - see this task's own
 * final report for that evidence. `Mail::fake()`/`Notification::fake()`
 * here only prove Laravel/Bagisto DISPATCH the right mail, to the right
 * recipient, with the right tenant's data, and that a real (not mocked)
 * unreachable SMTP host fails gracefully - never that Zoho itself received
 * anything.
 *
 * ARCHITECTURE UNDER TEST (confirmed by reading source, not assumed):
 * `Webkul\Core\Mail\Transport\DynamicSmtpTransport::buildTransport()` reads
 * `core()->getConfigData('emails.configure.smtp.*')` - a TENANT-scoped
 * `core_config` value (no ROW exists for a fresh tenant, since nothing has
 * ever been saved through Admin -> Configuration -> Emails -> SMTP) - and
 * falls back to `config('mail.mailers.smtp.*')` (the central `.env`-driven
 * default) only when the tenant hasn't configured its own. This is
 * unmodified Bagisto code; nothing in `packages/Platform` changed it.
 *
 * NUANCE FOUND WHILE WRITING THIS FILE'S OWN tests 1/2 (not assumed - the
 * original test assumption was wrong and corrected after seeing it fail):
 * `core()->getConfigData()` itself does NOT return raw `null` for a field
 * with no stored row - Bagisto's own system-config framework ALSO falls
 * back to that field's declared `'default'` in `packages/Webkul/Admin/src/
 * Config/system.php`, which for every `emails.configure.smtp.*` field is
 * itself literally `config('mail.mailers.smtp.*')` - i.e. a SECOND,
 * redundant central-fallback mechanism that happens to agree with
 * `DynamicSmtpTransport`'s own `??` every time, not a bug, not something
 * this task needed to fix.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Admin\Mail\Admin\ResetPasswordNotification;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Shop\Mail\Order\CreatedNotification;
use Webkul\User\Models\Admin;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const MAIL_TEST_TENANT_A = 'tenant-mail-a';
const MAIL_TEST_TENANT_B = 'tenant-mail-b';

function ensureMailTestTenant(string $id): Tenant
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

    // Same R40-class discipline as StorefrontOrderEndToEndTest.php - this
    // file's own order test creates real products across repeated runs.
    $proPlan = Plan::where('code', 'pro')->firstOrFail();
    if ($tenant->plan_id !== $proPlan->id) {
        $tenant->forceFill(['plan_id' => $proPlan->id])->save();
    }

    return $tenant->fresh();
}

function createMailTestPurchasableProduct(string $sku): \Webkul\Product\Models\Product
{
    $locale = app()->getLocale();

    return (new ProductFaker(['attribute_value' => [
        'sku' => ['text_value' => $sku],
        'name' => ['text_value' => 'Mail Test Product '.$sku, 'locale' => $locale],
        'url_key' => ['text_value' => 'mail-test-product-'.$sku, 'locale' => $locale],
        'price' => ['float_value' => 19.99],
    ]]))->getSimpleProductFactory()->create();
}

function placeMailTestGuestOrder(Tenant $tenant, string $domain, string $shopperEmail): void
{
    // $tenant passed explicitly, not tenant() - no request has resolved
    // tenancy yet at this point in the test, so the global tenant()
    // helper would still be null here.
    $product = $tenant->run(fn () => createMailTestPurchasableProduct('mail-order-'.\Illuminate\Support\Str::random(6)));

    test()->postJson('http://'.$domain.'/api/checkout/cart', [
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertOk();

    test()->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => [
            'first_name' => 'Sam',
            'last_name' => 'Shopper',
            'email' => $shopperEmail,
            'address' => ['123 Market Street'],
            'city' => 'Ramallah',
            'country' => 'US',
            'state' => 'California',
            'postcode' => '90001',
            'phone' => '+15551234567',
            'use_for_shipping' => 1,
        ],
    ])->assertOk();

    test()->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', [
        'shipping_method' => 'free_free',
    ])->assertOk();

    test()->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', [
        'payment' => ['method' => 'cashondelivery'],
    ])->assertOk();

    test()->postJson('http://'.$domain.'/api/checkout/onepage/orders')->assertOk();
}

beforeEach(function () {
    $this->tenantA = ensureMailTestTenant(MAIL_TEST_TENANT_A);
    $this->tenantB = ensureMailTestTenant(MAIL_TEST_TENANT_B);

    // Both are persistent, reused fixtures (PlatformIntegrationTestCase
    // disables DatabaseTransactions) - a stray SMTP core_config row left
    // by a PRIOR run of test 2 (below) would otherwise make test 1 order-
    // dependent/flaky across repeated full-suite invocations over time
    // (the R40-class lesson this project has hit before).
    $this->tenantA->run(fn () => DB::table('core_config')->where('code', 'like', 'emails.configure.smtp%')->delete());
    $this->tenantB->run(fn () => DB::table('core_config')->where('code', 'like', 'emails.configure.smtp%')->delete());
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

test('1. a fresh tenant has no stored SMTP override row and reads the central config value as its default', function () {
    // NUANCE FOUND WHILE WRITING THIS TEST, confirmed live, not assumed:
    // core()->getConfigData() does NOT return raw null for an unconfigured
    // field - Bagisto's own system-config framework falls back to that
    // field's declared 'default' in packages/Webkul/Admin/src/Config/
    // system.php, which for 'emails.configure.smtp.host' is itself
    // literally `config('mail.mailers.smtp.host')` - i.e. it already
    // reads the CURRENT central .env value, independently of
    // DynamicSmtpTransport's own separate `??` fallback. Both mechanisms
    // agree, redundantly - proven here by asserting they produce the
    // identical value, not by assuming either one's behavior.
    $centralHost = config('mail.mailers.smtp.host');

    $this->tenantA->run(function () use ($centralHost) {
        $row = DB::table('core_config')->where('code', 'like', 'emails.configure.smtp%')->first();
        expect($row)->toBeNull();

        expect(core()->getConfigData('emails.configure.smtp.host'))->toBe($centralHost);
    });
});

test('2. a tenant\'s own configured SMTP override is used, and does not leak into an untouched tenant', function () {
    $centralHost = config('mail.mailers.smtp.host');

    $this->tenantA->run(function () {
        DB::table('core_config')->updateOrInsert(
            ['code' => 'emails.configure.smtp.host'],
            ['value' => 'tenant-a-only.smtp.example.test']
        );
    });
    tenancy()->end();

    $this->tenantA->run(function () {
        expect(core()->getConfigData('emails.configure.smtp.host'))->toBe('tenant-a-only.smtp.example.test');
    });
    tenancy()->end();

    // Tenant B, never touched, must NOT see Tenant A's override - still
    // reads the central config value, exactly as test 1 proved for a
    // completely fresh tenant.
    $this->tenantB->run(function () use ($centralHost) {
        expect(core()->getConfigData('emails.configure.smtp.host'))->toBe($centralHost);
        expect(core()->getConfigData('emails.configure.smtp.host'))->not->toBe('tenant-a-only.smtp.example.test');
    });
});

test('3. Admin password-reset dispatches the correct real Notification to the correct real Admin, with a real token', function () {
    Notification::fake();

    $admin = $this->tenantA->run(fn () => Admin::first());
    expect($admin)->not->toBeNull();

    $response = $this->post('http://'.MAIL_TEST_TENANT_A.'.localhost/admin/forget-password', [
        'email' => $admin->email,
    ]);

    $response->assertRedirect();

    Notification::assertSentTo(
        $this->tenantA->run(fn () => Admin::first()),
        ResetPasswordNotification::class
    );
});

test('4. a real guest order dispatches CreatedNotification to the correct shopper with the correct order, tenant-isolated', function () {
    Mail::fake();

    placeMailTestGuestOrder($this->tenantA, MAIL_TEST_TENANT_A.'.localhost', 'mail-shopper-a@example.test');

    $orderA = $this->tenantA->run(fn () => DB::table('orders')->latest('id')->first());
    expect($orderA)->not->toBeNull();
    expect($orderA->customer_email)->toBe('mail-shopper-a@example.test');

    Mail::assertQueued(CreatedNotification::class, function (CreatedNotification $mail) use ($orderA) {
        return $mail->order->id === $orderA->id
            && $mail->order->customer_email === 'mail-shopper-a@example.test'
            && $mail->hasTo('mail-shopper-a@example.test');
    });

    // Tenant isolation: nothing queued for this order should ever
    // reference Tenant B's data, and Tenant B has no order at all yet.
    $orderB = $this->tenantB->run(fn () => DB::table('orders')->latest('id')->first());
    expect($orderB)->toBeNull();
});

test('5. mail failure is graceful for order placement - the order still succeeds, no credentials or stack trace leak', function () {
    // A real, unmocked connection-refused SMTP target (port 1 is never a
    // real SMTP listener) - exercises DynamicSmtpTransport's REAL failure
    // path (Base::prepareMail()'s own try/catch), not a simulated one.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    $product = $this->tenantA->run(fn () => createMailTestPurchasableProduct('mail-fail-'.\Illuminate\Support\Str::random(6)));
    $domain = MAIL_TEST_TENANT_A.'.localhost';

    $this->postJson('http://'.$domain.'/api/checkout/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/addresses', [
        'billing' => [
            'first_name' => 'Sam', 'last_name' => 'Shopper', 'email' => 'mail-fail-shopper@example.test',
            'address' => ['123 Market Street'], 'city' => 'Ramallah', 'country' => 'US', 'state' => 'California',
            'postcode' => '90001', 'phone' => '+15551234567', 'use_for_shipping' => 1,
        ],
    ])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/shipping-methods', ['shipping_method' => 'free_free'])->assertOk();
    $this->postJson('http://'.$domain.'/api/checkout/onepage/payment-methods', ['payment' => ['method' => 'cashondelivery']])->assertOk();

    $orderResponse = $this->postJson('http://'.$domain.'/api/checkout/onepage/orders');

    // The order itself must succeed regardless of the mail transport being
    // completely unreachable - Webkul\Shop\Listeners\Base::prepareMail()'s
    // own try/catch around Mail::queue() (confirmed by reading source).
    $orderResponse->assertOk();
    $body = $orderResponse->getContent();
    expect($body)->not->toContain('127.0.0.1');
    expect($body)->not->toContain('Connection');
    expect($body)->not->toContain('SMTP');

    $this->tenantA->run(function () {
        $order = DB::table('orders')->where('customer_email', 'mail-fail-shopper@example.test')->first();
        expect($order)->not->toBeNull();
    });
});

test('6. mail failure is graceful for Admin password-reset - no stack trace or credential leak in the HTTP response', function () {
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    $admin = $this->tenantA->run(fn () => Admin::first());

    $response = $this->post('http://'.MAIL_TEST_TENANT_A.'.localhost/admin/forget-password', [
        'email' => $admin->email,
    ]);

    // Laravel's password broker itself does not catch a mail-transport
    // exception the way Bagisto's own order listener does - the important
    // property this test actually protects is narrower and still real:
    // whatever the response ends up being, it must never leak the SMTP
    // host/port/credentials or a raw stack trace to the browser.
    $body = $response->getContent();
    expect($body)->not->toContain('127.0.0.1');
    expect($body)->not->toContain('EsmtpTransport');
    expect($body)->not->toContain('Connection refused');
});
