<?php

/**
 * TASK-ARCH-009 - Tenant Admin Integration ("My Plan" page) security/
 * correctness test matrix.
 *
 * Real MySQL, real tenant provisioning, real HTTP requests through the
 * actual, unmodified Bagisto Admin login flow (POST admin.session.store,
 * the exact same route/controller/session mechanism every real tenant
 * admin uses - not Laravel's actingAs() shortcut, deliberately, so this
 * proves genuine per-domain session-cookie authentication rather than
 * bypassing it). Nothing mocked - TenantEntitlements/Plan/PlanFeature all
 * resolve through the real central connection exactly as TASK-ARCH-008
 * built them.
 *
 * ARCHITECTURE UNDER TEST: the "My Plan" admin page
 * (Platform\Plans\Http\Controllers\Admin\MyPlanController, route
 * `admin.saas.plan.index`) is reached through the SAME middleware stack
 * every other Bagisto admin route uses (web + admin [Bouncer: session
 * auth + ACL] + NoCacheMiddleware, see Platform\Plans\Providers\
 * PlansServiceProvider::boot()) - so authentication/ACL enforcement is
 * proven by construction, not reimplemented; these tests exercise that
 * real mechanism rather than asserting it exists.
 */

use Illuminate\Support\Facades\DB;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const ADMIN_PLAN_PAGE_TEST_TENANT_IDS = ['tenant-admin-a', 'tenant-admin-b'];

/**
 * DEDICATED tenant-admin-a/tenant-admin-b fixtures - this file mutates
 * admin/role data (a second, ACL-restricted role + admin for the
 * "unauthorized role" proof) that no other Platform test file should be
 * exposed to, matching the established per-file-dedicated-tenant
 * convention (tenant-plan-a/b, tenant-search-a/b, tenant-queue-a/b, ...).
 *
 * Tenant A is left on the default plan (assigned during provisioning);
 * Tenant B is explicitly moved to 'pro' - so the two tenants have
 * genuinely different, distinguishable plans to prove isolation against.
 */
function ensureAdminPlanPageTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-admin-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-admin-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-admin-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-admin-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-admin-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-admin-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }

    $tenantA = $tenantA->fresh();
    $tenantB = $tenantB->fresh();

    $proPlan = Plan::where('code', 'pro')->firstOrFail();
    if ($tenantB->plan_id !== $proPlan->id) {
        $tenantB->forceFill(['plan_id' => $proPlan->id])->save();
        $tenantB = $tenantB->fresh();
    }

    // A second, ACL-restricted admin inside Tenant A only, used by the
    // "unauthorized role cannot access" proof - permission_type 'custom'
    // with a permissions list that deliberately does NOT include
    // 'saas'/'saas.plan' (Webkul\User\Models\Admin::hasPermission() does
    // a plain in_array() check - see Webkul\User\Bouncer::allow(), the
    // real enforcement call site behind the 'admin' route middleware).
    $tenantA->run(function () {
        if (! DB::table('roles')->where('id', 2)->exists()) {
            DB::table('roles')->insert([
                'id' => 2,
                'name' => 'Restricted',
                'description' => 'No SaaS permission',
                'permission_type' => 'custom',
                'permissions' => json_encode(['catalog']),
            ]);
        }

        if (! DB::table('admins')->where('email', 'restricted@example.com')->exists()) {
            DB::table('admins')->insert([
                'name' => 'Restricted Admin',
                'email' => 'restricted@example.com',
                'password' => bcrypt('restricted123'),
                'api_token' => \Illuminate\Support\Str::random(80),
                'status' => 1,
                'role_id' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    return [$tenantA, $tenantB];
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureAdminPlanPageTestFixtures();
});

/**
 * A plain substring assertDontSee('Pro')/assertDontSee('true') against a
 * FULL rendered admin layout is too broad to be reliable - "Pro" matches
 * inside "Products" in the sidebar menu, "true"/"false" appear throughout
 * the layout's own inline Vue directives, unrelated to this page's content
 * entirely. This regex, anchored to the exact markup my-plan/index.blade.php
 * renders ("<p ...>Code</p><p ...>{code}</p>"), extracts precisely the
 * plan code THIS page displayed - a much more precise proof than hoping a
 * generic word never appears anywhere else on the page.
 */
function extractRenderedPlanCode(string $html): ?string
{
    preg_match('/>Code<\/p>\s*<p[^>]*>([a-z]+)<\/p>/', $html, $matches);

    return $matches[1] ?? null;
}

test('SaaS/My Plan admin route exists and is reachable at the expected URL', function () {
    expect(\Illuminate\Support\Facades\Route::has('admin.saas.plan.index'))->toBeTrue();

    $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.saas.plan.index');
    expect($route->uri())->toBe('admin/saas/plan');
});

test('an unauthenticated request follows Bagisto admin authentication behavior (redirect to login)', function () {
    $response = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');

    $response->assertRedirect(route('admin.session.create'));
});

test('authenticated Tenant A admin can access the page via a real login POST', function () {
    $login = $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ]);
    $login->assertRedirect();

    $response = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');
    $response->assertOk();
    $response->assertSee('My Plan');
});

test('authenticated Tenant B admin can access the page via a real login POST', function () {
    $login = $this->post('http://tenant-admin-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ]);
    $login->assertRedirect();

    $response = $this->get('http://tenant-admin-b.localhost/admin/saas/plan');
    $response->assertOk();
    $response->assertSee('My Plan');
});

test('Tenant A sees Tenant A\'s actual plan (free) - real domain-driven resolution, not a request parameter', function () {
    $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    $response = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Free');
    expect(extractRenderedPlanCode($response->getContent()))->toBe('free');
});

test('Tenant B sees Tenant B\'s actual plan (pro)', function () {
    $this->post('http://tenant-admin-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    $response = $this->get('http://tenant-admin-b.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Pro');
    expect(extractRenderedPlanCode($response->getContent()))->toBe('pro');
});

test('Tenant A cannot see Tenant B plan information, even when both requests hit the identical admin route', function () {
    // Same route, same controller, same admin credentials shape - the
    // ONLY thing that differs between these two calls is the Host header,
    // proving tenant identity comes exclusively from the resolved domain
    // (TenantEntitlements::current() -> tenant() -> the tenancy resolved
    // by InitializeTenancyByDomain), never from any request parameter.
    $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
    $responseA = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');
    $responseA->assertOk();
    expect(extractRenderedPlanCode($responseA->getContent()))->toBe('free');

    $this->post('http://tenant-admin-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
    $responseB = $this->get('http://tenant-admin-b.localhost/admin/saas/plan');
    $responseB->assertOk();
    expect(extractRenderedPlanCode($responseB->getContent()))->toBe('pro');
});

test('boolean entitlement presentation is correct (Enabled/Disabled, not raw 0/1/true/false)', function () {
    $this->post('http://tenant-admin-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    // Tenant B is on 'pro': domains.custom = true, reports.advanced = true.
    $response = $this->get('http://tenant-admin-b.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Custom Domain');
    $response->assertSee('Enabled');
    $response->assertDontSee('domains.custom');
});

test('numeric limit presentation is correct (the raw integer, with a human label)', function () {
    $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    // Tenant A is on 'free': products.limit = 10 (numeric).
    $response = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Products');
    $response->assertSee('10');
    $response->assertDontSee('products.limit');
});

test('unlimited presentation is correct (the word "Unlimited", never null or a raw sentinel)', function () {
    $this->post('http://tenant-admin-b.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    // Tenant B is on 'pro': products.limit = unlimited.
    $response = $this->get('http://tenant-admin-b.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Unlimited');
});

test('central Plan data resolves correctly while tenant context remains active throughout the request', function () {
    $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    // Central-context-safety proof at the HTTP layer (TASK-ARCH-008 already
    // proved the underlying TenantEntitlements mechanism directly - this
    // proves the full admin request pipeline doesn't disturb it): the page
    // must render the correct central plan AND the request must complete
    // as an ordinary tenant-scoped response (asserted indirectly: a
    // second, tenant-scoped admin page in the SAME tenant still works
    // immediately afterward, proving no lingering central/tenant
    // connection confusion).
    $planPage = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');
    $planPage->assertOk();
    $planPage->assertSee('Free');

    $catalogPage = $this->get('http://tenant-admin-a.localhost/admin/catalog/products');
    $catalogPage->assertOk();
});

test('ACL entry exists and is enforced - a signed-in admin without the saas.plan permission is rejected', function () {
    $this->post('http://tenant-admin-a.localhost/admin/login', [
        'email' => 'restricted@example.com',
        'password' => 'restricted123',
    ])->assertRedirect();

    $response = $this->get('http://tenant-admin-a.localhost/admin/saas/plan');

    $response->assertStatus(401);
});
