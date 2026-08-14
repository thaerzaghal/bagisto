<?php

/**
 * TASK-ARCH-011 - Platform Admin Foundation test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered
 * `platform.*` routes (Platform\Admin\Providers\PlatformAdminServiceProvider),
 * real login (Auth::guard('platform')->attempt(), not actingAs()), real
 * tenant provisioning for the isolation proofs. Nothing mocked.
 *
 * SESSION DRIVER: forced to 'database' (the real .env value) in
 * beforeEach - phpunit.xml overrides SESSION_DRIVER to 'array' for the
 * whole Platform test suite, which would make every session-based
 * assertion here pass regardless of whether central/tenant session
 * isolation actually works, exactly like the lesson RISK_REGISTER.md R29
 * and R33 both already record. See also R34 (the follow-up roadmap item
 * this exact pattern is meant to cover).
 *
 * CENTRAL DB SAFETY: item 12 of the task brief ("tenant list cannot
 * accidentally query a tenant DB") is proven structurally, not just
 * asserted - Platform Admin routes run under the 'platform' middleware
 * group (bootstrap/app.php), which never includes
 * Stancl\Tenancy\Middleware\InitializeTenancyByDomain, so no tenant
 * database connection can become active for these routes at all. The
 * "tenant list uses central data" test below confirms this directly by
 * asserting tenancy() never became initialized during the request.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Models\Plan;
use Platform\Plans\Services\PlanSeeder;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const PLATFORM_ADMIN_TEST_EMAIL = 'platform-owner@example.test';
const PLATFORM_ADMIN_TEST_PASSWORD = 'platform-secret-1';
const PLATFORM_TEST_TENANT_ID = 'tenant-platform-check';

/**
 * DEDICATED tenant-platform-check fixture (matches the established
 * per-file-dedicated-tenant convention: tenant-admin-a/b, tenant-plan-a/b,
 * tenant-storefront-a/b, ...) - used only for the "tenant domain cannot
 * reach Platform Admin" and "tenant admin session isolated from platform
 * session" proofs, both of which need a real, ready tenant with a real
 * admin login, not a comparison against a second tenant.
 */
function ensurePlatformAdminTestTenant(): Tenant
{
    $provisioner = app(TenantProvisioner::class);

    $tenant = Tenant::find(PLATFORM_TEST_TENANT_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => PLATFORM_TEST_TENANT_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => PLATFORM_TEST_TENANT_ID.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        $provisioner->provision($tenant);
    }

    return $tenant->fresh();
}

/**
 * Find-or-create - a fixed, TEST-ONLY credential local to this file, not
 * a shipped default (the task brief's "no default insecure credentials
 * in source control" is about `platform:admin:create` never seeding one
 * for real deployments, not about test fixtures needing a login).
 */
function ensurePlatformAdminTestUser(): PlatformUser
{
    return PlatformUser::firstOrCreate(
        ['email' => PLATFORM_ADMIN_TEST_EMAIL],
        ['name' => 'Platform Owner', 'password' => PLATFORM_ADMIN_TEST_PASSWORD]
    );
}

beforeEach(function () {
    config(['session.driver' => 'database']);

    $this->platformAdmin = ensurePlatformAdminTestUser();
    $this->tenant = ensurePlatformAdminTestTenant();

    DB::connection('mysql')->table('sessions')->truncate();
    $this->tenant->run(fn () => DB::table('sessions')->truncate());
});

function loginPlatformAdmin(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => PLATFORM_ADMIN_TEST_EMAIL,
        'password' => PLATFORM_ADMIN_TEST_PASSWORD,
    ])->assertRedirect(route('platform.dashboard'));
}

test('1. platform admin model lives in the central database', function () {
    expect((new PlatformUser)->getConnectionName())->toBe(config('tenancy.database.central_connection'));
    expect($this->platformAdmin->getConnectionName())->toBe('mysql');
});

test('2. the first platform admin can be created securely via the artisan command', function () {
    PlatformUser::where('email', 'cli-created@example.test')->delete();

    $this->artisan('platform:admin:create', [
        '--name' => 'CLI Admin',
        '--email' => 'cli-created@example.test',
        '--password' => 'a-real-password-1',
    ])->assertSuccessful();

    $user = PlatformUser::where('email', 'cli-created@example.test')->first();
    expect($user)->not->toBeNull();
    expect($user->password)->not->toBe('a-real-password-1');
    expect(\Illuminate\Support\Facades\Hash::check('a-real-password-1', $user->password))->toBeTrue();

    // Duplicate email is rejected, not silently overwritten/duplicated.
    $this->artisan('platform:admin:create', [
        '--name' => 'Second CLI Admin',
        '--email' => 'cli-created@example.test',
        '--password' => 'another-password-1',
    ])->assertFailed();

    expect(PlatformUser::where('email', 'cli-created@example.test')->count())->toBe(1);

    PlatformUser::where('email', 'cli-created@example.test')->delete();
});

test('3. platform login succeeds with correct credentials', function () {
    $login = $this->post('http://localhost/platform/login', [
        'email' => PLATFORM_ADMIN_TEST_EMAIL,
        'password' => PLATFORM_ADMIN_TEST_PASSWORD,
    ]);

    $login->assertRedirect(route('platform.dashboard'));

    $dashboard = $this->get('http://localhost/platform');
    $dashboard->assertOk();
    $dashboard->assertSee('Platform Dashboard');
});

test('4. invalid credentials fail to log in', function () {
    $login = $this->post('http://localhost/platform/login', [
        'email' => PLATFORM_ADMIN_TEST_EMAIL,
        'password' => 'definitely-the-wrong-password',
    ]);

    $login->assertRedirect();
    $login->assertSessionHasErrors('email');

    // Not authenticated - the dashboard still redirects to login.
    $dashboard = $this->get('http://localhost/platform');
    $dashboard->assertRedirect(route('platform.login'));
});

test('5. an unauthenticated request to a protected platform route redirects to platform login', function () {
    $response = $this->get('http://localhost/platform/tenants');

    $response->assertRedirect(route('platform.login'));
});

test('6. the central domain can access platform routes', function () {
    $response = $this->get('http://localhost/platform/login');

    $response->assertOk();
    $response->assertSee('Platform Admin Login');
});

test('7. tenant domains cannot access platform routes', function () {
    $loginPage = $this->get('http://'.PLATFORM_TEST_TENANT_ID.'.localhost/platform/login');
    $loginPage->assertNotFound();

    loginPlatformAdmin($this);
    $dashboard = $this->get('http://'.PLATFORM_TEST_TENANT_ID.'.localhost/platform');
    $dashboard->assertNotFound();

    // An unrecognized domain is exactly as safe as a real tenant domain.
    $unknown = $this->get('http://unknown-domain-for-platform-check.localhost/platform/login');
    $unknown->assertNotFound();
});

test('8. the platform dashboard renders live central data, not invented metrics', function () {
    $expectedTenantCount = Tenant::count();
    $expectedReadyCount = Tenant::where('status', TenantStatus::Ready)->count();
    $expectedFailedCount = Tenant::where('status', TenantStatus::Failed)->count();
    $expectedActivePlanCount = Plan::where('is_active', true)->count();

    loginPlatformAdmin($this);

    $response = $this->get('http://localhost/platform');
    $response->assertOk();
    $response->assertSee((string) $expectedTenantCount);
    $response->assertSee((string) $expectedReadyCount);
    $response->assertSee((string) $expectedFailedCount);
    $response->assertSee((string) $expectedActivePlanCount);
});

test('9. the tenant list is populated from central data', function () {
    loginPlatformAdmin($this);

    $response = $this->get('http://localhost/platform/tenants');
    $response->assertOk();
    $response->assertSee(PLATFORM_TEST_TENANT_ID);
    $response->assertSee(PLATFORM_TEST_TENANT_ID.'.localhost');
    $response->assertSee($this->tenant->status->value);
});

test('10. rendering the tenant list never initializes any tenant database connection', function () {
    loginPlatformAdmin($this);

    expect(tenancy()->initialized)->toBeFalse();

    $response = $this->get('http://localhost/platform/tenants');
    $response->assertOk();

    // If InitializeTenancyByDomain (or any tenant-DB switch) had run during
    // this request, tenancy() would now be initialized - it never runs at
    // all for the 'platform' middleware group, so this proves the tenant
    // list was rendered without ever touching a tenant commerce database.
    expect(tenancy()->initialized)->toBeFalse();
});

test('11. the tenant detail page shows correct central metadata', function () {
    loginPlatformAdmin($this);

    $response = $this->get('http://localhost/platform/tenants/'.PLATFORM_TEST_TENANT_ID);
    $response->assertOk();
    $response->assertSee(PLATFORM_TEST_TENANT_ID);
    $response->assertSee($this->tenant->status->value);
    $response->assertSee(PLATFORM_TEST_TENANT_ID.'.localhost');
});

test('12. the plan list shows real plan/feature data', function () {
    app(PlanSeeder::class)->seed();
    $plan = Plan::withCount('features')->orderBy('sort_order')->firstOrFail();

    loginPlatformAdmin($this);

    $response = $this->get('http://localhost/platform/plans');
    $response->assertOk();
    $response->assertSee($plan->code);
    $response->assertSee($plan->name);
    $response->assertSee((string) $plan->features_count);
});

test('13. the platform session persists in the central database, not a tenant database', function () {
    loginPlatformAdmin($this);

    $centralSessions = DB::connection('mysql')->table('sessions')->get();

    // Decode each payload and look for the platform guard's own session
    // key (Illuminate\Auth\SessionGuard::getName() => 'login_platform_' .
    // sha1(guard class)) - a direct, mechanism-level proof rather than
    // trusting Auth::check() across separate test HTTP calls.
    $found = false;
    foreach ($centralSessions as $row) {
        $decoded = base64_decode($row->payload);
        if (str_contains($decoded, 'login_platform_')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue();
});

/**
 * 14. Tenant admin session isolated from platform admin session.
 *
 * Deliberately TWO separate tests (not one test doing both logins) - a
 * real, documented finding while writing this: Illuminate\Session\
 * SessionManager::getDatabaseConnection() resolves and CACHES a concrete
 * Connection object into DatabaseSessionHandler the FIRST time the
 * 'database' session driver is resolved within a given container
 * lifetime (Illuminate\Support\Manager's per-driver-name cache) - it does
 * NOT re-resolve by connection name on every read/write. In a real
 * deployment this is a non-issue (one PHP-FPM process per request means
 * a brand-new container, and therefore a brand-new SessionManager, on
 * every single request - exactly like TASK-ARCH-010's own tests, which
 * are also always ONE tenant-domain HTTP call per test() function).
 * Pest, however, reuses the SAME $this->app/container across every
 * $this->post()/$this->get() call WITHIN one test() function - so a test
 * that logs into the platform (central) guard and THEN a tenant admin
 * guard, in that order, in the SAME test, observes the SECOND (tenant)
 * request's session writes still landing on the FIRST-resolved (central)
 * connection object, purely because of this same-process caching - not
 * because of any real cross-context session leak. Recorded in
 * RISK_REGISTER.md (a documentation-only finding, not a production
 * defect - see that entry for the full reasoning) rather than worked
 * around with an internal cache-busting call, since doing so would only
 * be masking a test-harness artifact, not fixing anything real.
 *
 * Splitting into two single-request tests (matching every other file's
 * established one-domain-per-test convention) sidesteps the artifact
 * entirely and is *more*, not less, representative of a real deployment.
 */
test('14a. a platform admin login never writes into a tenant\'s own sessions table', function () {
    loginPlatformAdmin($this);

    $centralPayloads = DB::connection('mysql')->table('sessions')->pluck('payload')
        ->map(fn ($payload) => base64_decode($payload));

    $tenantPayloads = $this->tenant->run(
        fn () => DB::table('sessions')->pluck('payload')->map(fn ($payload) => base64_decode($payload))
    );

    expect($centralPayloads->contains(fn ($p) => str_contains($p, 'login_platform_')))->toBeTrue();
    expect($tenantPayloads->contains(fn ($p) => str_contains($p, 'login_platform_')))->toBeFalse();
    expect($tenantPayloads->isEmpty())->toBeTrue();
});

test('14b. a tenant admin login never writes into the central sessions table', function () {
    $this->post('http://'.PLATFORM_TEST_TENANT_ID.'.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    $tenantPayloads = $this->tenant->run(
        fn () => DB::table('sessions')->pluck('payload')->map(fn ($payload) => base64_decode($payload))
    );

    $centralPayloads = DB::connection('mysql')->table('sessions')->pluck('payload')
        ->map(fn ($payload) => base64_decode($payload));

    expect($tenantPayloads->contains(fn ($p) => str_contains($p, 'login_admin_')))->toBeTrue();
    expect($centralPayloads->contains(fn ($p) => str_contains($p, 'login_admin_')))->toBeFalse();
    expect($centralPayloads->isEmpty())->toBeTrue();
});
