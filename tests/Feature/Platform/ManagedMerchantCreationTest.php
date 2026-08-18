<?php

/**
 * TASK-MVP-007 - Managed Merchant Onboarding / Disable Public Signup.
 *
 * Proves Platform Admin's new "Create Merchant" flow
 * (`Platform\Admin\Http\Controllers\TenantController::create()`/`store()`/
 * `resendActivation()`) is the initial commercial/pilot phase's real
 * onboarding path: it reuses `Platform\Signup\Services\MerchantOnboarding`
 * verbatim (the SAME service `/join` itself uses - see
 * `PlatformSignupTest.php` for the sibling public-signup proof of the
 * identical underlying pipeline), never asks for or persists a merchant
 * password, and triggers the real Admin password-reset broker for account
 * activation. Real HTTP requests, real MySQL tenant provisioning - nothing
 * mocked. `TenantProvisioner`/`MerchantOnboarding` are deliberately never
 * mocked here; the whole point is proving real provisioning genuinely
 * happens through the shared pipeline. `Notification::fake()` is used only
 * where the point of the test is dispatch-correctness (who/what was
 * notified) - at least one test (17) runs the REAL, unfaked broker path
 * end-to-end.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;
use Webkul\Admin\Mail\Admin\ResetPasswordNotification;
use Webkul\User\Models\Admin;

uses(PlatformIntegrationTestCase::class);

const MMC_TEST_IDS = [
    'mmc-a',
    'mmc-b',
    'mmc-dup',
    'mmc-fail',
];

function cleanupMmcTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (MMC_TEST_IDS as $id) {
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

function ensureMmcPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'mmc-test-admin@example.test'],
        ['name' => 'MMC Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForMmc(TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'mmc-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

function mmcPlan(string $code = 'free'): Plan
{
    return Plan::where('code', $code)->where('is_active', true)->firstOrFail();
}

function mmcPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'store_name' => 'Store '.$slug,
        'slug' => $slug,
        'owner_first_name' => 'Amina',
        'owner_last_name' => 'Merchant',
        'owner_email' => $slug.'@example.test',
        'plan_id' => mmcPlan()->id,
    ], $overrides);
}

beforeEach(function () {
    cleanupMmcTestTenants();
    Cache::flush();
    ensureMmcPlatformAdmin();
    config(['platform.plans.default_code' => 'free']);
});

afterEach(fn () => cleanupMmcTestTenants());

test('1. the Create Merchant form is reachable to an authenticated Platform Admin and lists active plans only', function () {
    $inactive = Plan::create(['code' => 'mmc-inactive-list', 'name' => 'MMC Inactive Plan', 'is_active' => false, 'sort_order' => 999]);

    loginPlatformAdminForMmc($this);

    $response = $this->get('http://localhost/platform/tenants/create');

    $response->assertOk();
    $response->assertSee('Create Merchant');
    $response->assertSee('Store name');
    $response->assertDontSee('MMC Inactive Plan');

    $inactive->delete();
});

test('2. an unauthenticated caller cannot reach the create form or submit it', function () {
    $get = $this->get('http://localhost/platform/tenants/create');
    $get->assertRedirect(route('platform.login'));

    $post = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a'));
    $post->assertRedirect(route('platform.login'));
    expect(Tenant::find('mmc-a'))->toBeNull();
});

test('3. a real merchant creation produces a Ready tenant with a real, separately-provisioned tenant database', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a'));

    $tenant = Tenant::find('mmc-a');
    expect($tenant)->not->toBeNull();
    expect($tenant->status)->toBe(TenantStatus::Ready);
    expect($tenant->owner_name)->toBe('Amina Merchant');
    expect($tenant->owner_email)->toBe('mmc-a@example.test');
    expect(Domain::where('domain', 'mmc-a.platform.test')->exists())->toBeTrue();

    expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeTrue();
    $tenant->run(function () {
        expect(DB::connection()->getDatabaseName())->not->toBe('bagisto_central');
        expect(Schema::hasTable('products'))->toBeTrue();
        expect(Schema::hasTable('admins'))->toBeTrue();
    });

    $response->assertRedirect(route('platform.tenants.show', 'mmc-a'));
});

test('4. the provided store name is applied to the tenant\'s default channel', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['store_name' => 'Amina\'s Real Store']));

    $tenant = Tenant::find('mmc-a');
    $tenant->run(function () {
        $name = DB::table('channel_translations')->where('channel_id', 1)->value('name');
        expect($name)->toBe('Amina\'s Real Store');
    });
});

test('5. the owner identity replaces the generic seeded admin, with a real bcrypt hash password that cannot be logged into without a reset', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a'));

    $tenant = Tenant::find('mmc-a');
    $tenant->run(function () {
        $admin = DB::table('admins')->where('id', 1)->first();
        expect($admin->name)->toBe('Amina Merchant');
        expect($admin->email)->toBe('mmc-a@example.test');
        expect($admin->email)->not->toBe('admin@example.com');
        expect($admin->password)->toStartWith('$2y$');
    });

    // No known password: the operator never saw it, so no guessable
    // credential (default, blank, or the generic Bagisto default) can
    // authenticate as this merchant - only the real reset flow can.
    foreach (['admin123', '', 'password', 'mmc-a@example.test'] as $guess) {
        $this->post('http://mmc-a.platform.test/admin/login', [
            'email' => 'mmc-a@example.test',
            'password' => $guess,
        ]);
        $protected = $this->get('http://mmc-a.platform.test/admin/catalog/products');
        expect($protected->getStatusCode())->not->toBe(200);
        tenancy()->end();
    }
});

test('6. the selected active plan is assigned and an active Subscription is started', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);
    $plan = mmcPlan('free');

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['plan_id' => $plan->id]));

    $tenant = Tenant::find('mmc-a');
    expect($tenant->plan_id)->toBe($plan->id);

    $subscription = Subscription::currentFor($tenant);
    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->plan_id)->toBe($plan->id);
});

test('7. an inactive plan is rejected before any provisioning side effect', function () {
    $inactive = Plan::create(['code' => 'mmc-inactive', 'name' => 'MMC Inactive', 'is_active' => false, 'sort_order' => 998]);
    loginPlatformAdminForMmc($this);

    $before = DB::connection('mysql')->table('tenants')->count();

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['plan_id' => $inactive->id]));

    $response->assertSessionHasErrors('plan_id');
    expect(Tenant::find('mmc-a'))->toBeNull();
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);

    $inactive->delete();
});

test('8. a duplicate slug is rejected before any provisioning side effect - the identical policy /join uses', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-dup'))->assertRedirect();
    expect(Tenant::find('mmc-dup'))->not->toBeNull();

    $before = DB::connection('mysql')->table('tenants')->count();

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-dup', ['owner_email' => 'different-owner@example.test']));

    $response->assertSessionHasErrors('slug');
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);
});

test('9. a duplicate owner email is rejected before any provisioning side effect', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['owner_email' => 'shared-mmc@example.test']))->assertRedirect();

    $before = DB::connection('mysql')->table('tenants')->count();

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-b', ['owner_email' => 'shared-mmc@example.test']));

    $response->assertSessionHasErrors('owner_email');
    expect(Tenant::find('mmc-b'))->toBeNull();
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);
});

test('10. an owner activation notification is dispatched to the correct real Admin', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['owner_email' => 'amina-notify@example.test']));

    $tenant = Tenant::find('mmc-a');
    $admin = $tenant->run(fn () => Admin::first());
    expect($admin->email)->toBe('amina-notify@example.test');

    Notification::assertSentTo($admin, ResetPasswordNotification::class);
});

test('11. a slug collision with an existing Failed tenant is rejected, never silently flipping it to Ready', function () {
    loginPlatformAdminForMmc($this);

    $plan = mmcPlan('free');
    $tenant = Tenant::create([
        'id' => 'mmc-fail',
        'status' => TenantStatus::Failed,
        'owner_name' => 'Amina Merchant',
        'owner_email' => 'mmc-fail@example.test',
        'last_error' => 'simulated prior failure',
    ]);
    $tenant->domains()->create(['domain' => 'mmc-fail.platform.test']);

    // A second create attempt for the same slug is rejected by the shared
    // validation rules (slug already taken) - it must NOT silently flip
    // the existing Failed tenant to Ready.
    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-fail', ['plan_id' => $plan->id]));

    $response->assertSessionHasErrors('slug');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Failed);
});

test('12. an activation-email failure does not roll back or downgrade an already-Ready tenant', function () {
    loginPlatformAdminForMmc($this);

    // A real, unmocked connection-refused SMTP target (port 1 is never a
    // real SMTP listener) - the same established technique
    // TenantMailConfigurationTest.php uses to exercise a REAL mail-failure
    // path, not a simulated one.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.default' => 'smtp']);

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a'));

    $tenant = Tenant::find('mmc-a');
    expect($tenant->status)->toBe(TenantStatus::Ready);
    $response->assertRedirect(route('platform.tenants.show', 'mmc-a'));
    $response->assertSessionHas('warning');
});

test('13. "Resend activation email" works without re-provisioning and targets the correct owner', function () {
    Notification::fake();
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['owner_email' => 'amina-resend@example.test']));
    $tenant = Tenant::find('mmc-a');
    $migrationsBefore = $tenant->run(fn () => DB::table('migrations')->count());

    // The real PasswordBroker's own recently-created-token throttle
    // (config('auth.passwords.admins.throttle'), 60s) would otherwise
    // throttle an immediate resend - travel past it, matching how an
    // operator would actually use this action in practice.
    $this->travel(61)->seconds();

    $response = $this->post('http://localhost/platform/tenants/mmc-a/resend-activation');

    $response->assertRedirect();
    $response->assertSessionHas('status');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Ready);
    expect($tenant->run(fn () => DB::table('migrations')->count()))->toBe($migrationsBefore);

    $admin = $tenant->run(fn () => Admin::first());
    expect($admin->email)->toBe('amina-resend@example.test');
    Notification::assertSentTo($admin, ResetPasswordNotification::class);
});

test('14. "Resend activation email" is refused for a tenant that is not Ready', function () {
    loginPlatformAdminForMmc($this);

    $tenant = Tenant::create(['id' => 'mmc-fail', 'status' => TenantStatus::Pending, 'owner_email' => 'mmc-fail@example.test']);
    $tenant->domains()->create(['domain' => 'mmc-fail.platform.test']);

    $response = $this->post('http://localhost/platform/tenants/mmc-fail/resend-activation');

    $response->assertSessionHasErrors('tenant');
});

test('15. disabling public signup has zero impact on Platform Admin\'s managed creation', function () {
    Notification::fake();
    config(['platform.signup.enabled' => false]);
    loginPlatformAdminForMmc($this);

    $this->get('http://localhost/join')->assertNotFound();

    $response = $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a'));

    $response->assertRedirect(route('platform.tenants.show', 'mmc-a'));
    expect(Tenant::find('mmc-a')->status)->toBe(TenantStatus::Ready);
});

test('16. no plaintext password field is ever accepted by the managed-creation form', function () {
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['password' => 'attempted-plaintext-1']));

    expect(Tenant::find('mmc-a'))->not->toBeNull();

    // A "password" field is simply absent from validatedMerchant()'s own
    // accepted keys, so a client-supplied one can never reach
    // MerchantOnboarding at all - the real (random, discarded) generated
    // password is the only one ever used.
    $this->post('http://mmc-a.platform.test/admin/login', [
        'email' => 'mmc-a@example.test',
        'password' => 'attempted-plaintext-1',
    ]);
    $protected = $this->get('http://mmc-a.platform.test/admin/catalog/products');
    expect($protected->getStatusCode())->not->toBe(200);
});

test('17. the real, unfaked password-reset broker path lets the merchant set their own password and log in', function () {
    loginPlatformAdminForMmc($this);

    $this->post('http://localhost/platform/tenants', mmcPayload('mmc-a', ['owner_email' => 'amina-real-reset@example.test']));

    $tenant = Tenant::find('mmc-a');
    expect($tenant->status)->toBe(TenantStatus::Ready);

    // A REAL token from the REAL broker (Password::broker('admins'),
    // Illuminate's own DatabaseTokenRepository) - not faked, not
    // hand-constructed. OwnerActivationMailer already dispatched the real
    // notification carrying the equivalent link; this obtains an
    // independent, equally-real token the same way a merchant's own
    // in-flight reset request would, to complete the flow without needing
    // to read an email inbox from this test process.
    [$token, $admin] = $tenant->run(function () {
        $admin = Admin::first();
        $token = Password::broker('admins')->createToken($admin);

        return [$token, $admin];
    });

    $reset = $this->post('http://mmc-a.platform.test/admin/reset-password', [
        'token' => $token,
        'email' => 'amina-real-reset@example.test',
        'password' => 'merchant-chosen-pw-1',
        'password_confirmation' => 'merchant-chosen-pw-1',
    ]);

    $reset->assertRedirect();
    tenancy()->end();

    $this->post('http://mmc-a.platform.test/admin/login', [
        'email' => 'amina-real-reset@example.test',
        'password' => 'merchant-chosen-pw-1',
    ]);

    $protected = $this->get('http://mmc-a.platform.test/admin/catalog/products');
    $protected->assertOk();
});
