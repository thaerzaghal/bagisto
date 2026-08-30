<?php

/**
 * TASK-MVP-018 (RISK_REGISTER.md R73). Per-tenant transactional mail
 * sender DISPLAY NAME.
 *
 * ARCHITECTURE UNDER TEST (confirmed by reading source, not assumed):
 * `Webkul\Core\Core::getSenderEmailDetails()` already reads a TENANT-scoped
 * `core_config` row (`emails.configure.email_settings.sender_name`),
 * falling back to `config('mail.from.name')` ("Technify") only when no row
 * exists - and every Bagisto order/invoice/shipment/refund/cancellation
 * Mailable plus the customer/Admin password-reset Notifications already
 * consume it (`Shop\Mail\Mailable`/`Admin\Mail\Mailable::buildFrom()`, or
 * an explicit `->from(core()->getSenderEmailDetails()...)` call) -
 * unmodified `packages/Webkul` code, untouched by this task. This task
 * only seeds that `core_config` row - `Platform\Tenancy\Services\
 * TenantProvisioner::seedSenderIdentity()` (called from
 * `Platform\Signup\Services\MerchantOnboarding::attempt()` at provisioning
 * time, and from `Platform\Tenancy\Console\Commands\RepairSenderIdentity`
 * for backfill) - no new send-time mechanism, no global config mutation.
 *
 * MODEL A ONLY (explicit product decision): only the DISPLAY NAME is ever
 * seeded. `emails.configure.email_settings.sender_email` is NEVER written
 * by this task - `getSenderEmailDetails()` keeps falling back to
 * `config('mail.from.address')` (the already-proven-deliverable central
 * address). No Reply-To is set anywhere in this task's scope.
 *
 * `Mail::fake()`/`Notification::fake()` are deliberately NEVER used for the
 * From-header assertions in this file (section B) - confirmed by this
 * project's own prior finding (`OwnerActivationEmailUrlTest.php`) that
 * `Notification::fake()` never calls `toMail()` at all, which would hide
 * this exact class of bug. Instead, the real message is left to dispatch
 * for real against this test env's real, safe `MAIL_MAILER=array` driver
 * (`phpunit.xml`) and is inspected directly from
 * `Illuminate\Mail\Transport\ArrayTransport` - the same established
 * technique `OwnerActivationEmailUrlTest.php`/`TenantMailConfigurationTest.
 * php` already use.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\Plans\Models\Plan;
use Platform\Signup\Services\MerchantOnboarding;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Webkul\Customer\Models\Customer;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Models\Product;

uses(PlatformIntegrationTestCase::class);

const TMSI_TENANT_A = 'tmsi-tenant-a';
const TMSI_TENANT_B = 'tmsi-tenant-b';
const TMSI_TENANT_NO_NAME = 'tmsi-tenant-no-name';
const TMSI_TENANT_REPAIR = 'tmsi-tenant-repair';
const TMSI_TENANT_FAIL = 'tmsi-tenant-fail';

const TMSI_STORE_NAME_A = 'Sender Identity Store A';
const TMSI_STORE_NAME_B = 'Sender Identity Store B';
const TMSI_STORE_NAME_REPAIR = 'Sender Identity Repair Store';

/**
 * Persistent, idempotently-reused fixture - same "expensive tenant
 * provisioning, reused across runs" discipline as
 * `TenantMailConfigurationTest.php`'s own `ensureMailTestTenant()`.
 * Provisions directly through `MerchantOnboarding::register()` (not HTTP)
 * so a null `$storeName` can be exercised precisely (the real Platform
 * Admin "Create Merchant" form requires a non-empty `store_name`, but
 * public `/join` and CLI provisioning do not pass one at all - this is the
 * scenario tests 4/15 need to reproduce for real).
 */
function ensureTmsiTenant(string $id, ?string $storeName): Tenant
{
    $tenant = Tenant::find($id);

    if (! $tenant) {
        $plan = Plan::where('code', 'free')->where('is_active', true)->firstOrFail();

        $result = app(MerchantOnboarding::class)->register(
            $id,
            $id.'.tmsi.test',
            'TMSI Owner',
            $id.'-owner@example.test',
            'tmsi-fixture-password-1',
            $plan,
            $storeName
        );

        $tenant = $result['tenant'];
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
        $tenant = $tenant->fresh();
    }

    return $tenant->fresh();
}

function tmsiFlushMail(): void
{
    Mail::mailer(config('mail.default'))->getSymfonyTransport()->flush();
}

/**
 * The exact same real transport class Laravel's own `array` mailer driver
 * uses (`Illuminate\Mail\Transport\ArrayTransport`) - never faked, so the
 * From header inspected here is genuinely what Bagisto's own unmodified
 * `getSenderEmailDetails()`-driven code produced.
 */
function tmsiLatestFrom(): array
{
    $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();

    $from = $messages->last()->getOriginalMessage()->getFrom();

    expect($from)->toHaveCount(1);

    return ['name' => $from[0]->getName(), 'address' => $from[0]->getAddress()];
}

/**
 * Idempotent, matching this file's own "persistent, reused fixtures" model
 * for tenants (`ensureTmsiTenant()`) - a plain `Customer::factory()->create()`
 * would violate that same idempotency the SECOND time this test suite runs
 * against an already-fixtured tenant (a real, reproduced failure: a repeated
 * local run hit `customers_email_channel_unique` on this exact email,
 * confirming the fixture from a prior run was still there, not simulated).
 */
function tmsiEnsureCustomer(string $email): Customer
{
    return Customer::where('email', $email)->first()
        ?? Customer::factory()->create(['email' => $email]);
}

function tmsiCreatePurchasableProduct(string $sku): Product
{
    $locale = app()->getLocale();

    return (new ProductFaker(['attribute_value' => [
        'sku' => ['text_value' => $sku],
        'name' => ['text_value' => 'TMSI Product '.$sku, 'locale' => $locale],
        'url_key' => ['text_value' => 'tmsi-product-'.$sku, 'locale' => $locale],
        'price' => ['float_value' => 9.99],
    ]]))->getSimpleProductFactory()->create();
}

function tmsiPlaceGuestOrder(Tenant $tenant, string $domain, string $shopperEmail): void
{
    $product = $tenant->run(fn () => tmsiCreatePurchasableProduct('tmsi-order-'.Str::random(6)));

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

function cleanupTmsiFailTenant(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    $row = $central->table('tenants')->where('id', TMSI_TENANT_FAIL)->first();

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

    $central->table('domains')->where('tenant_id', TMSI_TENANT_FAIL)->delete();
    $central->table('tenants')->where('id', TMSI_TENANT_FAIL)->delete();
}

beforeEach(function () {
    $this->tenantA = ensureTmsiTenant(TMSI_TENANT_A, TMSI_STORE_NAME_A);
    $this->tenantB = ensureTmsiTenant(TMSI_TENANT_B, TMSI_STORE_NAME_B);
    $this->tenantNoName = ensureTmsiTenant(TMSI_TENANT_NO_NAME, null);
    $this->tenantRepair = ensureTmsiTenant(TMSI_TENANT_REPAIR, TMSI_STORE_NAME_REPAIR);

    tmsiFlushMail();
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    // Idempotent no-op for every test except 14, which deliberately
    // creates and corrupts this one throwaway tenant.
    cleanupTmsiFailTenant();
});

// ── A. Provisioning/default data ──────────────────────────────────────

test('1. managed onboarding with a store name seeds emails.configure.email_settings.sender_name', function () {
    $value = $this->tenantA->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->value('value'));

    expect($value)->toBe(TMSI_STORE_NAME_A);
});

test('2. sender_email is NOT seeded by onboarding', function () {
    $exists = $this->tenantA->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_email')
        ->exists());

    expect($exists)->toBeFalse();
});

test('3. an already-configured sender_name is never overwritten by a repeated seed call', function () {
    $first = $this->tenantA->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->value('value'));
    expect($first)->toBe(TMSI_STORE_NAME_A);

    $outcome = app(TenantProvisioner::class)->seedSenderIdentity($this->tenantA, 'Some Different Name');
    expect($outcome)->toBe('already_configured');

    $stillFirst = $this->tenantA->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->value('value'));
    expect($stillFirst)->toBe(TMSI_STORE_NAME_A);
});

test('4. a tenant provisioned without a trustworthy store name gets no sender_name row and keeps the Technify fallback', function () {
    $exists = $this->tenantNoName->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->exists());
    expect($exists)->toBeFalse();

    $customer = $this->tenantNoName->run(fn () => tmsiEnsureCustomer('tmsi-no-name-customer@example.test'));

    test()->post('http://'.TMSI_TENANT_NO_NAME.'.tmsi.test/customer/forgot-password', [
        'email' => $customer->email,
    ])->assertRedirect();

    $from = tmsiLatestFrom();
    expect($from['name'])->toBe(config('mail.from.name'));
});

// ── B. Tenant isolation / actual mail headers ─────────────────────────

test('5. tenant A\'s real order-confirmation message shows tenant A\'s own sender name and the platform address', function () {
    tmsiPlaceGuestOrder($this->tenantA, TMSI_TENANT_A.'.tmsi.test', 'tmsi-shopper-a@example.test');

    $from = tmsiLatestFrom();

    expect($from['name'])->toBe(TMSI_STORE_NAME_A);
    expect($from['address'])->toBe(config('mail.from.address'));
});

test('6. tenant B\'s real order-confirmation message shows tenant B\'s own sender name and the same safe platform address', function () {
    tmsiPlaceGuestOrder($this->tenantB, TMSI_TENANT_B.'.tmsi.test', 'tmsi-shopper-b@example.test');

    $from = tmsiLatestFrom();

    expect($from['name'])->toBe(TMSI_STORE_NAME_B);
    expect($from['address'])->toBe(config('mail.from.address'));
});

test('7. sending tenant A then tenant B in the same process proves no sender-name leakage either direction', function () {
    tmsiPlaceGuestOrder($this->tenantA, TMSI_TENANT_A.'.tmsi.test', 'tmsi-shopper-a2@example.test');
    $fromA = tmsiLatestFrom();

    tmsiFlushMail();

    tmsiPlaceGuestOrder($this->tenantB, TMSI_TENANT_B.'.tmsi.test', 'tmsi-shopper-b2@example.test');
    $fromB = tmsiLatestFrom();

    expect($fromA['name'])->toBe(TMSI_STORE_NAME_A);
    expect($fromA['name'])->not->toBe(TMSI_STORE_NAME_B);
    expect($fromB['name'])->toBe(TMSI_STORE_NAME_B);
    expect($fromB['name'])->not->toBe(TMSI_STORE_NAME_A);
});

test('8. Mailable-based path (CreatedNotification, order confirmation) carries the tenant sender name', function () {
    tmsiPlaceGuestOrder($this->tenantA, TMSI_TENANT_A.'.tmsi.test', 'tmsi-shopper-a3@example.test');

    $from = tmsiLatestFrom();
    expect($from['name'])->toBe(TMSI_STORE_NAME_A);
});

test('9. Notification-based path (CustomerResetPassword) carries the tenant sender name', function () {
    $customer = $this->tenantA->run(fn () => tmsiEnsureCustomer('tmsi-reset-customer@example.test'));

    test()->post('http://'.TMSI_TENANT_A.'.tmsi.test/customer/forgot-password', [
        'email' => $customer->email,
    ])->assertRedirect();

    $from = tmsiLatestFrom();
    expect($from['name'])->toBe(TMSI_STORE_NAME_A);
    expect($from['address'])->toBe(config('mail.from.address'));
});

// ── C. Repair behavior ─────────────────────────────────────────────────

test('10. repair seeds a Ready tenant whose sender_name row is missing, from its real channel name', function () {
    $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->delete());

    $exit = test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])
        ->run();

    expect($exit)->toBe(0);

    $value = $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->value('value'));

    expect($value)->toBe(TMSI_STORE_NAME_REPAIR);
});

test('11. repair skips (never overwrites) a tenant that already has a sender_name configured', function () {
    $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->update(['value' => 'Manually Configured Name']));

    $exit = test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])
        ->run();

    expect($exit)->toBe(0);

    $value = $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->value('value'));

    expect($value)->toBe('Manually Configured Name');
});

test('12. repair never writes sender_email', function () {
    test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])->run();

    $exists = $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_email')
        ->exists());

    expect($exists)->toBeFalse();
});

test('13. repeated repair runs are idempotent - no duplicate rows, same result', function () {
    $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->delete());

    test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])->run();
    test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])->run();
    $exit = test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_REPAIR]])->run();

    expect($exit)->toBe(0);

    $rows = $this->tenantRepair->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->count());

    expect($rows)->toBe(1);
});

test('14. the command reports a genuine per-tenant failure and returns a non-zero exit code', function () {
    cleanupTmsiFailTenant();

    $plan = Plan::where('code', 'free')->where('is_active', true)->firstOrFail();
    $result = app(MerchantOnboarding::class)->register(
        TMSI_TENANT_FAIL,
        TMSI_TENANT_FAIL.'.tmsi.test',
        'TMSI Fail Owner',
        TMSI_TENANT_FAIL.'-owner@example.test',
        'tmsi-fixture-password-1',
        $plan,
        'Doomed Store'
    );
    $tenant = $result['tenant'];

    // A real, unmocked schema failure - not a simulated one: the repair
    // command's own resolution step depends on channel_translations
    // existing, exactly like every other TenantProvisioner step's own
    // Schema::hasTable() guard.
    $tenant->run(fn () => Schema::drop('channel_translations'));

    $exit = test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_FAIL]])
        ->run();

    expect($exit)->toBe(1);
});

// ── D. Existing fallback ────────────────────────────────────────────────

test('15. a tenant with no trustworthy store name is skipped by repair and keeps the Technify fallback', function () {
    $exit = test()->artisan('platform:tenants:repair-sender-identity', ['--tenant' => [TMSI_TENANT_NO_NAME]])
        ->run();

    expect($exit)->toBe(0);

    $exists = $this->tenantNoName->run(fn () => DB::table('core_config')
        ->where('code', 'emails.configure.email_settings.sender_name')
        ->exists());

    expect($exists)->toBeFalse();
});
