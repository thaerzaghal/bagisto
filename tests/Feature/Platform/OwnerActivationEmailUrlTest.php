<?php

/**
 * TASK-MVP-007 production regression (found live via the real
 * `mvp007-check` managed-onboarding verification): the owner activation
 * email's reset link pointed at the CENTRAL domain
 * (`https://app.technify.dev/admin/reset-password/...`) instead of the
 * merchant's own tenant domain - a 404 for the merchant. Root cause:
 * `$tenant->run()` only swaps DB/cache/filesystem via tenancy
 * bootstrappers - it never touches `Illuminate\Routing\UrlGenerator`'s
 * root, so `route('admin.reset_password.create', $token)` (called deep
 * inside Bagisto's own, unmodified `ResetPasswordNotification::toMail()`
 * view) resolved against the CENTRAL Platform Admin request that
 * triggered the whole managed-creation flow. Fixed in
 * `Platform\Signup\Services\OwnerActivationMailer::send()` by scoping
 * `URL::forceRootUrl()` tightly around the broker call - see that
 * class's own docblock for the full root-cause record.
 *
 * `Notification::fake()` is deliberately NEVER used in this file -
 * confirmed empirically that it never calls `toMail()` at all, which
 * would hide this exact bug entirely. Instead, the real notification is
 * left to dispatch for real (this project's test env already uses the
 * real, safe `MAIL_MAILER=array` driver - see `phpunit.xml`), and the
 * actual rendered HTML the real `route()` call produced is inspected
 * directly from `Illuminate\Mail\Transport\ArrayTransport` (the same
 * transport class Laravel's own `array` mailer always uses) - this is
 * the "narrowest real notification/mail generation path necessary" this
 * task's own governing instruction called for.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Models\Plan;
use Platform\Signup\Services\OwnerActivationMailer;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;

uses(PlatformIntegrationTestCase::class);

const OAU_TEST_IDS = [
    'oau-a',
    'oau-b',
    'oau-resend',
    'oau-forgot',
    'oau-exception',
];

function cleanupOauTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (OAU_TEST_IDS as $id) {
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

function ensureOauPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'oau-test-admin@example.test'],
        ['name' => 'OAU Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForOau(TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'oau-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

function oauPlan(string $code = 'free'): Plan
{
    return Plan::where('code', $code)->where('is_active', true)->firstOrFail();
}

function oauPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'store_name' => 'Store '.$slug,
        'slug' => $slug,
        'owner_first_name' => 'Amina',
        'owner_last_name' => 'Merchant',
        'owner_email' => $slug.'@example.test',
        'plan_id' => oauPlan()->id,
    ], $overrides);
}

/**
 * The exact same real transport class Laravel's own `array` mailer
 * driver uses (`Illuminate\Mail\Transport\ArrayTransport`) - never
 * faked, so the HTML body inspected here is genuinely what the real
 * `route()` call inside Bagisto's own Blade view produced.
 */
function oauFlushMail(): void
{
    Mail::mailer(config('mail.default'))->getSymfonyTransport()->flush();
}

function oauLatestMailHtml(): string
{
    $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();

    return (string) $messages->last()->getOriginalMessage()->getHtmlBody();
}

function oauProvisionTenant(string $id, string $ownerEmail): Tenant
{
    $tenant = Tenant::create([
        'id' => $id,
        'status' => TenantStatus::Pending,
        'owner_name' => 'Forgot Password Owner',
        'owner_email' => $ownerEmail,
    ]);
    $tenant->domains()->create(['domain' => $id.'.platform.test']);

    app(TenantProvisioner::class)->provision($tenant, [
        'name' => 'Forgot Password Owner',
        'email' => $ownerEmail,
        'password' => 'oau-fixture-password-1',
    ]);

    return $tenant->fresh();
}

beforeEach(function () {
    cleanupOauTestTenants();
    Cache::flush();
    ensureOauPlatformAdmin();
    config(['platform.plans.default_code' => 'free']);
    oauFlushMail();
});

afterEach(fn () => cleanupOauTestTenants());

test('1. a managed-onboarding activation email links to the tenant domain, never the central domain', function () {
    loginPlatformAdminForOau($this);

    $this->post('http://localhost/platform/tenants', oauPayload('oau-a'))->assertRedirect();

    $html = oauLatestMailHtml();

    expect($html)->toContain('http://oau-a.platform.test/admin/reset-password/');
    expect($html)->not->toContain('http://localhost/admin/reset-password/');
});

test('2. two tenants never get each other\'s activation URL host - A gets A\'s domain, B gets B\'s domain', function () {
    loginPlatformAdminForOau($this);

    $this->post('http://localhost/platform/tenants', oauPayload('oau-a'))->assertRedirect();
    $htmlA = oauLatestMailHtml();

    oauFlushMail();

    $this->post('http://localhost/platform/tenants', oauPayload('oau-b'))->assertRedirect();
    $htmlB = oauLatestMailHtml();

    expect($htmlA)->toContain('http://oau-a.platform.test/admin/reset-password/');
    expect($htmlA)->not->toContain('oau-b.platform.test');
    expect($htmlB)->toContain('http://oau-b.platform.test/admin/reset-password/');
    expect($htmlB)->not->toContain('oau-a.platform.test');
});

test('3. "Resend activation email" produces the same correct tenant-domain URL', function () {
    loginPlatformAdminForOau($this);

    $this->post('http://localhost/platform/tenants', oauPayload('oau-resend'))->assertRedirect();
    oauFlushMail();

    // The real PasswordBroker's own recently-created-token throttle
    // (config('auth.passwords.admins.throttle'), 60s) would otherwise
    // silently skip sending a second token/notification this soon after
    // the first - travel past it, matching how an operator would
    // actually use this action in practice (established technique, see
    // ManagedMerchantCreationTest.php test 13).
    $this->travel(61)->seconds();

    $this->post('http://localhost/platform/tenants/oau-resend/resend-activation')->assertRedirect();

    $html = oauLatestMailHtml();

    expect($html)->toContain('http://oau-resend.platform.test/admin/reset-password/');
    expect($html)->not->toContain('http://localhost/admin/reset-password/');
});

test('4. the normal, real tenant forgot-password flow is unaffected and still produces its own correct tenant-domain URL', function () {
    $ownerEmail = 'oau-forgot-owner@example.test';
    oauProvisionTenant('oau-forgot', $ownerEmail);

    $this->post('http://oau-forgot.platform.test/admin/forget-password', [
        'email' => $ownerEmail,
    ])->assertRedirect();

    $html = oauLatestMailHtml();

    expect($html)->toContain('http://oau-forgot.platform.test/admin/reset-password/');
    expect($html)->not->toContain('http://localhost/admin/reset-password/');
});

test('5. URL-generator root is restored to central after OwnerActivationMailer::send() completes - the controller\'s OWN subsequent redirect is still central', function () {
    loginPlatformAdminForOau($this);

    // TenantController::store() calls $activation->send($tenant) and THEN,
    // still within the exact same request, builds its own redirect via
    // redirect()->route('platform.tenants.show', ...) - if forceRootUrl()
    // were not correctly restored, that redirect's own Location header
    // would leak the tenant root instead of staying central. This is a
    // direct, concrete artifact of that exact request - not an ambient
    // url()/route() call made from outside it.
    $response = $this->post('http://localhost/platform/tenants', oauPayload('oau-a'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toBe('http://localhost/platform/tenants/oau-a');
    expect($location)->not->toContain('oau-a.platform.test');
});

test('6. a forced activation-send failure does not leak its forced tenant root into a later, unrelated request (exception-path restoration)', function () {
    loginPlatformAdminForOau($this);

    $ownerEmail = 'oau-exception-owner@example.test';
    $tenant = oauProvisionTenant('oau-exception', $ownerEmail);

    // A real, unmocked connection-refused SMTP target - the same
    // established technique this project's own TenantMailConfigurationTest/
    // ManagedMerchantCreationTest files already use to force a REAL mail
    // failure path, not a simulated one.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.default' => 'smtp']);

    $sent = app(OwnerActivationMailer::class)->send($tenant);
    expect($sent)->toBeFalse();

    // Restore a working mail config, then perform a completely separate,
    // unrelated managed creation in a LATER request - if the previous
    // failed send's forced tenant root ("oau-exception.platform.test")
    // had leaked past its own try/finally, this new, different tenant's
    // own redirect and activation email would be corrupted by it.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525, 'mail.default' => 'array']);
    oauFlushMail();

    $response = $this->post('http://localhost/platform/tenants', oauPayload('oau-a'));
    $response->assertRedirect('http://localhost/platform/tenants/oau-a');

    $html = oauLatestMailHtml();
    expect($html)->toContain('http://oau-a.platform.test/admin/reset-password/');
    expect($html)->not->toContain('oau-exception.platform.test');
});
