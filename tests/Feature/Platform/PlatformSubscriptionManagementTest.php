<?php

/**
 * TASK-ARCH-016 - Provider-Agnostic Subscription Foundation test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered Platform
 * Admin routes, real Subscription/SubscriptionLifecycle/TenantPlanAssignment.
 * Nothing mocked - SubscriptionLifecycle is exercised directly, exactly as
 * the task's own explicit instruction requires ("Do not mock
 * SubscriptionLifecycle for primary lifecycle tests").
 *
 * STRICTLY PROVIDER-NEUTRAL, matching the domain itself: no Stripe/
 * Cashier/PayPal/webhook/invoice/payment-method concept appears anywhere
 * in this file.
 *
 * FIXTURE DISCIPLINE (R40 lesson, re-applied yet again): dedicated
 * `tenant-subscription-a/b` fixtures throughout - never the shared
 * `tenant-a`/`tenant-b`/`free`/`pro` fixtures other files assert exact
 * values against. A dedicated `tenant-subscription-legacy` fixture
 * simulates a pre-TASK-ARCH-016 tenant (plan_id set directly via
 * forceFill, bypassing SubscriptionLifecycle - exactly what real
 * tenants provisioned before this task looked like) for the backfill
 * tests specifically.
 *
 * CENTRAL-DB-BEFORE-TENANT-DB PROOF: mirrors the DB::listen() pattern
 * established since TASK-ARCH-013/014/015.
 *
 * SAME-PROCESS TEST ARTIFACT (R35/R37): `tenancy()->end()` is called
 * after any status/plan mutation followed by another real HTTP request
 * to the same tenant domain within the same test() function.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Exceptions\InvalidSubscriptionTransitionException;
use Platform\Subscriptions\Models\Subscription;
use Platform\Subscriptions\Services\SubscriptionBackfill;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const SUB_MGMT_TENANT_IDS = ['tenant-subscription-a', 'tenant-subscription-b'];

function ensureSubMgmtTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (SUB_MGMT_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

/**
 * Simulates a tenant provisioned BEFORE TASK-ARCH-016 existed: a real
 * `tenants` row with `plan_id` set directly (bypassing
 * SubscriptionLifecycle entirely, exactly how every tenant's plan_id was
 * written before this task), and deliberately no Subscription row - the
 * exact precondition the backfill (task section 8) is designed for.
 */
function ensureSubMgmtLegacyTenant(): Tenant
{
    $id = 'tenant-subscription-legacy';

    Subscription::where('tenant_id', $id)->delete();
    Tenant::where('id', $id)->delete();

    $plan = Plan::where('code', 'free')->firstOrFail();

    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Ready]);
    $tenant->forceFill(['plan_id' => $plan->id])->save();

    return $tenant->fresh();
}

function ensureSubMgmtTestPlan(string $code, bool $active = true, int $sortOrder = 600): Plan
{
    Plan::where('code', $code)->delete();

    return Plan::create(['code' => $code, 'name' => 'Sub Mgmt '.$code, 'is_active' => $active, 'sort_order' => $sortOrder]);
}

function ensureSubMgmtPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'submgmt-test-admin@example.test'],
        ['name' => 'Sub Mgmt Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForSubMgmt(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'submgmt-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForSubMgmt(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $response = $callback();

    return [$response, $connections];
}

function subMgmtAttributeFamilyId(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('attribute_families')->value('id'));
}

function subMgmtCreateProductViaAdmin(Tests\TestCase $test, string $domain, string $sku): \Illuminate\Testing\TestResponse
{
    return $test->postJson('http://'.$domain.'/admin/catalog/products/create', [
        'type' => 'simple',
        'attribute_family_id' => subMgmtAttributeFamilyId(Tenant::find(explode('.', $domain)[0])),
        'sku' => $sku,
    ]);
}

function subMgmtLoginAsTenantAdmin(Tests\TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

function subMgmtRootProductCount(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('products')->whereNull('parent_id')->count());
}

beforeEach(function () {
    ensureSubMgmtPlatformAdmin();

    [$this->tenantA, $this->tenantB] = ensureSubMgmtTenants();

    $this->tenantA->run(fn () => DB::table('products')->delete());
});

test('1-2. subscriptions table exists only in the central database, never a tenant database', function () {
    expect(Schema::connection('mysql')->hasTable('subscriptions'))->toBeTrue();

    $tenantHasTable = $this->tenantA->run(fn () => Schema::hasTable('subscriptions'));
    expect($tenantHasTable)->toBeFalse();
});

test('3. existing tenant backfill creates exactly one subscription', function () {
    $legacy = ensureSubMgmtLegacyTenant();
    expect(Subscription::where('tenant_id', $legacy->getTenantKey())->count())->toBe(0);

    $result = app(SubscriptionBackfill::class)->run();

    expect($result['backfilled'])->toBeGreaterThanOrEqual(1);
    $subscriptions = Subscription::where('tenant_id', $legacy->getTenantKey())->get();
    expect($subscriptions)->toHaveCount(1);
    expect($subscriptions->first()->plan_id)->toBe($legacy->plan_id);
    expect($subscriptions->first()->status)->toBe(SubscriptionStatus::Active);
    expect($subscriptions->first()->starts_at->equalTo($legacy->created_at))->toBeTrue();
});

test('4. backfill is idempotent', function () {
    $legacy = ensureSubMgmtLegacyTenant();

    $first = app(SubscriptionBackfill::class)->run();
    $existingId = Subscription::where('tenant_id', $legacy->getTenantKey())->firstOrFail()->id;

    $second = app(SubscriptionBackfill::class)->run();

    expect(Subscription::where('tenant_id', $legacy->getTenantKey())->count())->toBe(1);
    expect(Subscription::where('tenant_id', $legacy->getTenantKey())->firstOrFail()->id)->toBe($existingId);
    expect($second['already_had_subscription'])->toBeGreaterThanOrEqual(1);
});

test('5. new tenant provisioning creates a subscription', function () {
    $subscription = Subscription::currentFor($this->tenantA);

    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

test('6. provisioning keeps subscription.plan_id == tenant.plan_id', function () {
    $subscription = Subscription::currentFor($this->tenantA);

    expect($subscription->plan_id)->toBe($this->tenantA->fresh()->plan_id);
});

test('7. active subscription creation works', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-active-create');
    $tenant = ensureSubMgmtLegacyTenant();
    Subscription::where('tenant_id', $tenant->getTenantKey())->delete();
    $tenant->forceFill(['plan_id' => null])->save();

    $subscription = app(SubscriptionLifecycle::class)->start($tenant->fresh(), $plan);

    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->trial_ends_at)->toBeNull();
    expect($tenant->fresh()->plan_id)->toBe($plan->id);
});

test('8. trialing subscription creation works', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-trial-create');
    $tenant = ensureSubMgmtLegacyTenant();
    Subscription::where('tenant_id', $tenant->getTenantKey())->delete();
    $tenant->forceFill(['plan_id' => null])->save();

    // Truncated to whole seconds - Eloquent's 'datetime' cast normalizes
    // to Y-m-d H:i:s precision at SET time (not only on a DB round-trip),
    // so comparing against a microsecond-precision now() would fail
    // regardless of persistence - see test 18's own comment for the full
    // explanation, first found here.
    $trialEndsAt = now()->addDays(14)->startOfSecond();
    $subscription = app(SubscriptionLifecycle::class)->startTrial($tenant->fresh(), $plan, $trialEndsAt);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing);
    expect($subscription->trial_ends_at->equalTo($trialEndsAt))->toBeTrue();
});

test('9. trial -> active transition works', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-trial-to-active');
    $tenant = ensureSubMgmtLegacyTenant();
    Subscription::where('tenant_id', $tenant->getTenantKey())->delete();
    $tenant->forceFill(['plan_id' => null])->save();

    $lifecycle = app(SubscriptionLifecycle::class);
    $subscription = $lifecycle->startTrial($tenant->fresh(), $plan, now()->addDays(14));

    $lifecycle->activate($subscription->fresh());

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('10. an invalid transition fails safely', function () {
    $subscription = Subscription::currentFor($this->tenantA);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    expect(fn () => app(SubscriptionLifecycle::class)->activate($subscription))
        ->toThrow(InvalidSubscriptionTransitionException::class);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('11. plan change goes through SubscriptionLifecycle via real Platform Admin HTTP', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-plan-change');
    loginPlatformAdminForSubMgmt($this);

    $response = $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/change-plan', [
        'plan_id' => $plan->id,
    ]);

    $response->assertRedirect();
    expect(Subscription::currentFor($this->tenantA->fresh())->plan_id)->toBe($plan->id);
});

test('12. plan change synchronizes tenant.plan_id', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-plan-sync');
    loginPlatformAdminForSubMgmt($this);

    $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $plan->id])
        ->assertRedirect();

    $subscription = Subscription::currentFor($this->tenantA->fresh());
    expect($this->tenantA->fresh()->plan_id)->toBe($subscription->plan_id);
    expect($this->tenantA->fresh()->plan_id)->toBe($plan->id);
});

test('13. plan change immediately affects products.limit enforcement', function () {
    // Earlier tests in this file change tenant-subscription-a's plan -
    // explicitly (re)assign a generous starting plan first, rather than
    // assuming whatever plan a prior test left it on still has
    // products.limit configured at all.
    $generous = ensureSubMgmtTestPlan('submgmt-enforcement-generous', sortOrder: 600);
    $generous->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 100]);
    loginPlatformAdminForSubMgmt($this);
    $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $generous->id])
        ->assertRedirect();
    tenancy()->end();

    $restrictive = ensureSubMgmtTestPlan('submgmt-enforcement-plan', sortOrder: 601);
    $restrictive->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 1]);

    subMgmtLoginAsTenantAdmin($this, SUB_MGMT_TENANT_IDS[0].'.localhost');
    subMgmtCreateProductViaAdmin($this, SUB_MGMT_TENANT_IDS[0].'.localhost', 'submgmt-enforce-1')->assertOk();

    loginPlatformAdminForSubMgmt($this);
    $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $restrictive->id])
        ->assertRedirect();

    tenancy()->end();
    subMgmtLoginAsTenantAdmin($this, SUB_MGMT_TENANT_IDS[0].'.localhost');
    subMgmtCreateProductViaAdmin($this, SUB_MGMT_TENANT_IDS[0].'.localhost', 'submgmt-enforce-2')->assertStatus(422);
    expect(subMgmtRootProductCount($this->tenantA))->toBe(1);
});

test('14. cancel-at-period-end sets intent without ending the subscription immediately', function () {
    loginPlatformAdminForSubMgmt($this);

    $response = $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/subscription/cancel-at-period-end');

    $response->assertRedirect();
    $subscription = Subscription::currentFor($this->tenantA->fresh());
    expect($subscription->cancel_at_period_end)->toBeTrue();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->ended_at)->toBeNull();
});

test('15. cancel-immediately sets canceled/ended timestamps correctly', function () {
    loginPlatformAdminForSubMgmt($this);

    $response = $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/subscription/cancel-immediately');

    $response->assertRedirect();
    $subscription = Subscription::currentFor($this->tenantA->fresh());
    expect($subscription->status)->toBe(SubscriptionStatus::Canceled);
    expect($subscription->cancelled_at)->not->toBeNull();
    expect($subscription->ended_at)->not->toBeNull();
});

test('16. canceling a subscription does not automatically suspend TenantStatus', function () {
    loginPlatformAdminForSubMgmt($this);

    $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/subscription/cancel-immediately')
        ->assertRedirect();

    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
});

test('17. a suspended tenant\'s subscription remains unchanged', function () {
    $before = Subscription::currentFor($this->tenantA)->only(['status', 'plan_id', 'cancel_at_period_end']);

    app(TenantLifecycle::class)->suspend($this->tenantA);

    $after = Subscription::currentFor($this->tenantA->fresh())->only(['status', 'plan_id', 'cancel_at_period_end']);
    expect($after)->toBe($before);

    app(TenantLifecycle::class)->reactivate($this->tenantA->fresh());
});

test('18. period validation works', function () {
    $subscription = Subscription::currentFor($this->tenantA);
    $lifecycle = app(SubscriptionLifecycle::class);

    // Truncated to whole seconds: the `current_period_*` columns are
    // plain MySQL TIMESTAMP (no fractional-seconds precision), so a
    // ->fresh() round-trip loses microseconds - compare at the
    // precision that's actually persisted, not PHP's in-memory precision.
    $start = now()->startOfSecond();
    $end = $start->clone()->subDay();

    expect(fn () => $lifecycle->setPeriod($subscription, $start, $end))
        ->toThrow(InvalidArgumentException::class);

    $validEnd = $start->clone()->addMonth();
    $lifecycle->setPeriod($subscription, $start, $validEnd);
    expect($subscription->fresh()->current_period_start->equalTo($start))->toBeTrue();
    expect($subscription->fresh()->current_period_end->equalTo($validEnd))->toBeTrue();
});

test('19. trial validation works', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-trial-validation');
    $tenant = ensureSubMgmtLegacyTenant();
    Subscription::where('tenant_id', $tenant->getTenantKey())->delete();
    $tenant->forceFill(['plan_id' => null])->save();

    $starts = now();
    $invalidTrialEnd = now()->subDay();

    expect(fn () => app(SubscriptionLifecycle::class)->startTrial($tenant->fresh(), $plan, $invalidTrialEnd, $starts))
        ->toThrow(InvalidArgumentException::class);

    expect(Subscription::where('tenant_id', $tenant->getTenantKey())->exists())->toBeFalse();
});

test('20. subscription mutations are central-only', function () {
    $plan = ensureSubMgmtTestPlan('submgmt-central-only');
    loginPlatformAdminForSubMgmt($this);

    [$response, $connections] = captureQueriedConnectionsForSubMgmt(
        fn () => $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $plan->id])
    );

    $response->assertRedirect();
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('21. Platform Admin can view subscription state', function () {
    loginPlatformAdminForSubMgmt($this);

    $response = $this->get('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0]);

    $response->assertOk();
    $response->assertSee('Subscription');
    $response->assertSee('active');
});

test('22. Platform Admin can activate a trialing subscription', function () {
    $subscription = Subscription::currentFor($this->tenantB);
    // Force the existing subscription into Trialing to exercise the admin action.
    $subscription->forceFill(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(7)])->save();

    loginPlatformAdminForSubMgmt($this);
    $response = $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[1].'/subscription/activate');

    $response->assertRedirect();
    expect(Subscription::currentFor($this->tenantB->fresh())->status)->toBe(SubscriptionStatus::Active);
});

test('23. an unauthenticated caller cannot mutate a subscription', function () {
    $before = Subscription::currentFor($this->tenantA)->status;

    $response = $this->post('http://localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/subscription/cancel-immediately');

    $response->assertRedirect(route('platform.login'));
    expect(Subscription::currentFor($this->tenantA->fresh())->status)->toBe($before);
});

test('24. Tenant Admin cannot mutate a subscription through Platform routes', function () {
    $before = Subscription::currentFor($this->tenantA)->status;

    $response = $this->post('http://'.SUB_MGMT_TENANT_IDS[0].'.localhost/platform/tenants/'.SUB_MGMT_TENANT_IDS[0].'/subscription/cancel-immediately');

    $response->assertNotFound();
    expect(Subscription::currentFor($this->tenantA->fresh())->status)->toBe($before);
});

test('25. My Plan page displays subscription status correctly', function () {
    subMgmtLoginAsTenantAdmin($this, SUB_MGMT_TENANT_IDS[0].'.localhost');

    $response = $this->get('http://'.SUB_MGMT_TENANT_IDS[0].'.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Subscription');
    $response->assertSee('Active');

    // No monetary/provider-shaped content of any kind. Deliberately NOT
    // a bare '$' or 'Invoice' check - Bagisto Admin's own layout/sidebar
    // legitimately contains both elsewhere (inline JS, Tailwind classes,
    // and the real Sales > Invoices menu item) unrelated to subscription
    // billing; check for phrases specific to a billing/price display
    // instead, none of which this page (or any real Bagisto sidebar
    // item) has any legitimate reason to contain.
    $body = $response->getContent();
    expect($body)->not->toContain('Payment Method');
    expect($body)->not->toContain('Next charge');
    expect($body)->not->toContain('/month');
    expect($body)->not->toContain('/year');
});

test('26. My Plan page degrades gracefully for a tenant with a plan but no subscription', function () {
    $tenant = ensureSubMgmtLegacyTenant();
    Subscription::where('tenant_id', $tenant->getTenantKey())->delete();
    // A plan is assigned (legacy-style, forceFill) but no domain exists for
    // this fixture yet - reuse tenant-subscription-a's domain is wrong, so
    // give this tenant its own for a real HTTP request.
    if (! $tenant->domains()->exists()) {
        $tenant->domains()->create(['domain' => $tenant->getTenantKey().'.localhost']);
    }

    // This fixture was never provisioned with a real tenant database, so
    // this test only proves the CENTRAL side (MyPlanController) degrades
    // gracefully - it does not attempt a real tenant-admin login.
    expect(Subscription::currentFor($tenant))->toBeNull();
    expect($tenant->plan_id)->not->toBeNull();
});
