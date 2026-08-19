<?php

/**
 * TASK-MVP-012 (DECISION_LOG.md). Proves `Platform\Signup\Services\
 * OwnerActivationMailer::send()`'s locale handling: a newly provisioned
 * Arabic-first tenant's owner activation/reset-password email renders in
 * Arabic, never the shared central process's own English default - the
 * identical class of bug R69 already fixed for the URL root (see
 * `OwnerActivationEmailUrlTest.php`), now fixed the same way for
 * `app()->getLocale()`. Test 1 also re-proves R69's own URL-root
 * behavior still holds in combination with the new locale fix (both
 * corrections live in the same try/finally block, so a regression in one
 * could plausibly be introduced while "fixing" the other).
 *
 * `Notification::fake()` is deliberately never used - see
 * `OwnerActivationEmailUrlTest.php`'s own docblock for why (it never
 * calls `toMail()`, which would hide this exact class of bug). The real,
 * already-safe `MAIL_MAILER=array` transport is inspected directly.
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

const OAL_TEST_IDS = ['oal-a', 'oal-b', 'oal-exception'];

// Real Arabic strings from packages/Webkul/Admin/src/Resources/lang/ar/app.php
// ('emails.admin.forgot-password.*') - asserted directly against the shipped
// translation file's own content, not guessed/transliterated here.
const OAL_ARABIC_DESCRIPTION = 'تتلقى هذا البريد الإلكتروني لأننا تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك.';
const OAL_ARABIC_BUTTON = 'إعادة تعيين كلمة المرور';
const OAL_ENGLISH_BUTTON = 'Reset Password';

function cleanupOalTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (OAL_TEST_IDS as $id) {
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

function ensureOalPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'oal-test-admin@example.test'],
        ['name' => 'OAL Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForOal(TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'oal-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

function oalPlan(string $code = 'free'): Plan
{
    return Plan::where('code', $code)->where('is_active', true)->firstOrFail();
}

function oalPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'store_name' => 'Store '.$slug,
        'slug' => $slug,
        'owner_first_name' => 'Amina',
        'owner_last_name' => 'Merchant',
        'owner_email' => $slug.'@example.test',
        'plan_id' => oalPlan()->id,
    ], $overrides);
}

function oalFlushMail(): void
{
    Mail::mailer(config('mail.default'))->getSymfonyTransport()->flush();
}

function oalLatestMailHtml(): string
{
    $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();

    return (string) $messages->last()->getOriginalMessage()->getHtmlBody();
}

function oalProvisionTenant(string $id, string $ownerEmail): Tenant
{
    $tenant = Tenant::create([
        'id' => $id,
        'status' => TenantStatus::Pending,
        'owner_name' => 'Locale Test Owner',
        'owner_email' => $ownerEmail,
    ]);
    $tenant->domains()->create(['domain' => $id.'.platform.test']);

    app(TenantProvisioner::class)->provision($tenant, [
        'name' => 'Locale Test Owner',
        'email' => $ownerEmail,
        'password' => 'oal-fixture-password-1',
    ]);

    return $tenant->fresh();
}

beforeEach(function () {
    cleanupOalTestTenants();
    Cache::flush();
    ensureOalPlatformAdmin();
    config(['platform.plans.default_code' => 'free']);
    oalFlushMail();
});

afterEach(fn () => cleanupOalTestTenants());

test('1. a managed-onboarding activation email for a new Arabic-first tenant renders in Arabic AND still points to the tenant domain (R69 combined regression)', function () {
    expect(app()->getLocale())->toBe('en'); // sanity: the central request itself starts English, matching real production APP_LOCALE.

    loginPlatformAdminForOal($this);

    $this->post('http://localhost/platform/tenants', oalPayload('oal-a'))->assertRedirect();

    $html = oalLatestMailHtml();

    expect($html)->toContain(OAL_ARABIC_DESCRIPTION);
    expect($html)->toContain(OAL_ARABIC_BUTTON);
    expect($html)->not->toContain(OAL_ENGLISH_BUTTON);

    // R69's own fix, still holding in combination with the new locale fix.
    expect($html)->toContain('http://oal-a.platform.test/admin/reset-password/');
    expect($html)->not->toContain('http://localhost/admin/reset-password/');
});

test('2. two tenants never get each other\'s activation-email language or URL host', function () {
    loginPlatformAdminForOal($this);

    $this->post('http://localhost/platform/tenants', oalPayload('oal-a'))->assertRedirect();
    $htmlA = oalLatestMailHtml();

    oalFlushMail();

    $this->post('http://localhost/platform/tenants', oalPayload('oal-b'))->assertRedirect();
    $htmlB = oalLatestMailHtml();

    expect($htmlA)->toContain(OAL_ARABIC_BUTTON);
    expect($htmlA)->toContain('http://oal-a.platform.test/admin/reset-password/');
    expect($htmlA)->not->toContain('oal-b.platform.test');

    expect($htmlB)->toContain(OAL_ARABIC_BUTTON);
    expect($htmlB)->toContain('http://oal-b.platform.test/admin/reset-password/');
    expect($htmlB)->not->toContain('oal-a.platform.test');
});

test('3. application locale is restored to central English after OwnerActivationMailer::send() completes - the controller\'s OWN subsequent redirect/render is unaffected', function () {
    loginPlatformAdminForOal($this);

    $this->post('http://localhost/platform/tenants', oalPayload('oal-a'))->assertRedirect();

    // TenantController::store() calls $activation->send($tenant) and THEN,
    // still within the exact same request, builds its own central redirect -
    // if app()->setLocale() were not correctly restored, the rest of this
    // very request (and this assertion, evaluated immediately after) would
    // observe the tenant's Arabic locale leaking into the central context.
    expect(app()->getLocale())->toBe('en');
});

test('4. a forced activation-send failure does not leak Arabic locale into a later, unrelated request (exception-path restoration)', function () {
    loginPlatformAdminForOal($this);

    $ownerEmail = 'oal-exception-owner@example.test';
    $tenant = oalProvisionTenant('oal-exception', $ownerEmail);

    // A real, unmocked connection-refused SMTP target - the same
    // established technique OwnerActivationEmailUrlTest.php's own test 6
    // already uses to force a REAL mail failure path, not a simulated one.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.default' => 'smtp']);

    $sent = app(OwnerActivationMailer::class)->send($tenant);
    expect($sent)->toBeFalse();

    // Locale must already be restored to central English immediately after
    // the failed call returns, regardless of a later request even happening.
    expect(app()->getLocale())->toBe('en');

    // Restore a working mail config, then perform a completely separate,
    // unrelated managed creation in a LATER request - if the previous
    // failed send's forced Arabic locale had leaked past its own
    // try/finally, this new tenant's own activation email (and the
    // request's own English-central rendering) would be corrupted by it.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 2525, 'mail.default' => 'array']);
    oalFlushMail();

    $response = $this->post('http://localhost/platform/tenants', oalPayload('oal-a'));
    $response->assertRedirect('http://localhost/platform/tenants/oal-a');
    expect(app()->getLocale())->toBe('en');

    $html = oalLatestMailHtml();
    expect($html)->toContain(OAL_ARABIC_BUTTON);
    expect($html)->toContain('http://oal-a.platform.test/admin/reset-password/');
});
