<?php

/**
 * TASK-ARCH-015 - Platform Plan & Entitlement Management test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered Platform
 * Admin routes, real Plan/PlanFeature/Tenant models. Nothing mocked.
 * Strictly entitlement-focused per this task's own scope: no monetary/
 * pricing/trial fields exist anywhere in this file.
 *
 * FIXTURE DISCIPLINE (R40 lesson, re-applied here deliberately): this
 * file NEVER edits/deactivates/renames the real, shared 'free'/'basic'/
 * 'pro' plans that `PlatformIntegrationTestCase::setUp()` reseeds before
 * EVERY test in the whole suite, and that other files (e.g.
 * TenantAdminPlanPageTest) assert literal plan NAMES ("Free"/"Pro")
 * against. Every plan-CRUD/feature-CRUD test here creates its own,
 * uniquely-coded, disposable 'planmgmt-*' plan. The two tests that DO
 * need to touch the real default plan's active flag (26, 27) restore it
 * to its original state before returning, in the same test - never left
 * dangling for a later test/file to inherit.
 *
 * CENTRAL-DB-BEFORE-TENANT-DB PROOF: mirrors the DB::listen() pattern
 * established since TASK-ARCH-013/014 - asserts the literal 'tenant'
 * connection name never appears among queries executed for a pure
 * plan/feature/assignment mutation.
 *
 * SAME-PROCESS TEST ARTIFACT (R35/R37): `tenancy()->end()` is called
 * after a plan-assignment mutation that is followed by ANOTHER real HTTP
 * request to the same tenant domain within the same test() function -
 * the same established precaution ProductLimitEnforcementTest's tests
 * 8/9 already use for the identical reason.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;
use Platform\Plans\Models\PlanFeature;
use Platform\Plans\Services\PlanSeeder;
use Platform\Plans\Services\TenantEntitlements;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const PLAN_MGMT_TENANT_IDS = ['tenant-planmgmt-a', 'tenant-planmgmt-b'];

function ensurePlanMgmtTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (PLAN_MGMT_TENANT_IDS as $id) {
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

function ensurePlanMgmtPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'planmgmt-test-admin@example.test'],
        ['name' => 'Plan Mgmt Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForPlanMgmt(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'planmgmt-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * A small, dedicated, disposable plan - never one of the real seeded
 * plans. Deletes any pre-existing row with the same code first (tests
 * are expected to clean up after themselves, but this makes reruns after
 * an interrupted previous run self-healing, matching this project's
 * established idempotent-fixture convention).
 */
function ensurePlanMgmtTestPlan(string $code, string $name = 'Plan Mgmt Test Plan', bool $active = true, int $sortOrder = 500): Plan
{
    Plan::where('code', $code)->delete();

    return Plan::create(['code' => $code, 'name' => $name, 'is_active' => $active, 'sort_order' => $sortOrder]);
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForPlanMgmt(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $response = $callback();

    return [$response, $connections];
}

function planMgmtAttributeFamilyId(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('attribute_families')->value('id'));
}

function planMgmtCreateProductViaAdmin(Tests\TestCase $test, string $domain, string $sku): \Illuminate\Testing\TestResponse
{
    return $test->postJson('http://'.$domain.'/admin/catalog/products/create', [
        'type' => 'simple',
        'attribute_family_id' => planMgmtAttributeFamilyId(Tenant::find(explode('.', $domain)[0])),
        'sku' => $sku,
    ]);
}

function planMgmtLoginAsTenantAdmin(Tests\TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

function planMgmtRootProductCount(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('products')->whereNull('parent_id')->count());
}

beforeEach(function () {
    ensurePlanMgmtPlatformAdmin();

    [$this->tenantA, $this->tenantB] = ensurePlanMgmtTenants();

    $this->tenantA->run(fn () => DB::table('products')->delete());
});

test('1. Platform Admin can create a plan', function () {
    loginPlatformAdminForPlanMgmt($this);

    Plan::where('code', 'planmgmt-create')->delete();

    $response = $this->post('http://localhost/platform/plans', [
        'code' => 'planmgmt-create',
        'name' => 'Plan Mgmt Create',
        'description' => 'created via real HTTP',
        'sort_order' => 10,
        'is_active' => '1',
    ]);

    $response->assertRedirect();
    $plan = Plan::where('code', 'planmgmt-create')->firstOrFail();
    expect($plan->name)->toBe('Plan Mgmt Create');
    expect($plan->description)->toBe('created via real HTTP');
    expect($plan->sort_order)->toBe(10);
    expect($plan->is_active)->toBeTrue();
});

test('2. duplicate plan code is rejected', function () {
    ensurePlanMgmtTestPlan('planmgmt-dup');
    loginPlatformAdminForPlanMgmt($this);

    $countBefore = Plan::count();

    $response = $this->post('http://localhost/platform/plans', [
        'code' => 'planmgmt-dup',
        'name' => 'A different name',
        'sort_order' => 11,
    ]);

    $response->assertSessionHasErrors('code');
    expect(Plan::count())->toBe($countBefore);
});

test('3. Platform Admin can edit plan metadata', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-edit', 'Original Name');
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->patch('http://localhost/platform/plans/'.$plan->id, [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'sort_order' => 42,
    ]);

    $response->assertRedirect();
    $plan->refresh();
    expect($plan->name)->toBe('Updated Name');
    expect($plan->description)->toBe('Updated description');
    expect($plan->sort_order)->toBe(42);
});

test('4. plan code is immutable after creation', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-immutable');
    loginPlatformAdminForPlanMgmt($this);

    // The update endpoint does not accept a 'code' field at all - sending
    // one anyway must have zero effect, not a silent partial-success.
    $this->patch('http://localhost/platform/plans/'.$plan->id, [
        'code' => 'planmgmt-changed',
        'name' => 'Still Updatable',
        'sort_order' => 1,
    ])->assertRedirect();

    $plan->refresh();
    expect($plan->code)->toBe('planmgmt-immutable');
    expect(Plan::where('code', 'planmgmt-changed')->exists())->toBeFalse();
});

test('5. a plan can be deactivated', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-deactivate');
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->post('http://localhost/platform/plans/'.$plan->id.'/deactivate');

    $response->assertRedirect();
    expect($plan->fresh()->is_active)->toBeFalse();
});

test('6. an existing tenant assigned to a deactivated plan remains assigned', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-deactivate-keep');
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    loginPlatformAdminForPlanMgmt($this);
    $this->post('http://localhost/platform/plans/'.$plan->id.'/deactivate')->assertRedirect();

    expect($this->tenantA->fresh()->plan_id)->toBe($plan->id);
});

test('7. a deactivated plan continues supplying existing tenant entitlements', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-deactivate-entitlements');
    $plan->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 7]);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $plan->update(['is_active' => false]);

    expect(TenantEntitlements::for($this->tenantA->fresh())->limit(FeatureCode::ProductsLimit))->toBe(7);
});

test('8. an inactive plan cannot be newly assigned', function () {
    $inactive = ensurePlanMgmtTestPlan('planmgmt-inactive-assign', active: false);
    $originalPlanId = $this->tenantA->plan_id;

    loginPlatformAdminForPlanMgmt($this);

    $response = $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', [
        'plan_id' => $inactive->id,
    ]);

    $response->assertSessionHasErrors('plan');
    expect($this->tenantA->fresh()->plan_id)->toBe($originalPlanId);
});

test('9. a feature can be added to a plan', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-add');
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Numeric->value,
        'value' => 3,
    ]);

    $response->assertRedirect();
    $feature = $plan->features()->where('feature_code', FeatureCode::StaffLimit->value)->firstOrFail();
    expect($feature->type)->toBe(FeatureType::Numeric);
    expect($feature->value)->toBe(3);
});

test('10. a feature can be edited', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-edit');
    $feature = $plan->features()->create(['feature_code' => FeatureCode::StaffLimit->value, 'type' => FeatureType::Numeric, 'value' => 3]);
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->patch('http://localhost/platform/plans/'.$plan->id.'/features/'.$feature->id, [
        'type' => FeatureType::Numeric->value,
        'value' => 9,
    ]);

    $response->assertRedirect();
    expect($feature->fresh()->value)->toBe(9);
});

test('11. a feature can be removed', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-remove');
    $feature = $plan->features()->create(['feature_code' => FeatureCode::StaffLimit->value, 'type' => FeatureType::Numeric, 'value' => 3]);
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->delete('http://localhost/platform/plans/'.$plan->id.'/features/'.$feature->id);

    $response->assertRedirect();
    expect(PlanFeature::find($feature->id))->toBeNull();
});

test('12. duplicate feature_code for one plan is rejected', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-dup');
    $plan->features()->create(['feature_code' => FeatureCode::StaffLimit->value, 'type' => FeatureType::Numeric, 'value' => 3]);
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Numeric->value,
        'value' => 5,
    ]);

    $response->assertSessionHasErrors('feature_code');
    expect($plan->features()->where('feature_code', FeatureCode::StaffLimit->value)->count())->toBe(1);
});

test('13. boolean feature validation works', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-boolean');
    loginPlatformAdminForPlanMgmt($this);

    // Invalid representation rejected.
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::CustomDomain->value,
        'type' => FeatureType::Boolean->value,
        'value' => 2,
    ])->assertSessionHasErrors('value');
    expect($plan->features()->where('feature_code', FeatureCode::CustomDomain->value)->exists())->toBeFalse();

    // Valid representation accepted.
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::CustomDomain->value,
        'type' => FeatureType::Boolean->value,
        'value' => 1,
    ])->assertRedirect();
    expect($plan->features()->where('feature_code', FeatureCode::CustomDomain->value)->first()->value)->toBe(1);
});

test('14. numeric feature validation works', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-numeric');
    loginPlatformAdminForPlanMgmt($this);

    // Negative rejected.
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Numeric->value,
        'value' => -1,
    ])->assertSessionHasErrors('value');

    // Non-integer rejected.
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Numeric->value,
        'value' => 'abc',
    ])->assertSessionHasErrors('value');

    expect($plan->features()->where('feature_code', FeatureCode::StaffLimit->value)->exists())->toBeFalse();

    // Valid non-negative integer accepted (0 is a legitimate cap - "no
    // creation allowed under this plan").
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Numeric->value,
        'value' => 0,
    ])->assertRedirect();
    expect($plan->features()->where('feature_code', FeatureCode::StaffLimit->value)->first()->value)->toBe(0);
});

test('15. unlimited feature validation works', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-feature-unlimited');
    loginPlatformAdminForPlanMgmt($this);

    // No value required at all.
    $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
        'feature_code' => FeatureCode::StaffLimit->value,
        'type' => FeatureType::Unlimited->value,
    ])->assertRedirect();

    $feature = $plan->features()->where('feature_code', FeatureCode::StaffLimit->value)->firstOrFail();
    expect($feature->type)->toBe(FeatureType::Unlimited);
    expect($feature->value)->toBeNull();

    // Sending an inconsistent "type=unlimited, value=10" is structurally
    // impossible to persist - value is force-nulled server-side, proven
    // by editing this same row with a client-supplied value present.
    $this->patch('http://localhost/platform/plans/'.$plan->id.'/features/'.$feature->id, [
        'type' => FeatureType::Unlimited->value,
        'value' => 10,
    ])->assertRedirect();
    expect($feature->fresh()->value)->toBeNull();
});

test('16. a tenant plan can be changed through real Platform Admin HTTP', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-assign');
    loginPlatformAdminForPlanMgmt($this);

    $response = $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', [
        'plan_id' => $plan->id,
    ]);

    $response->assertRedirect();
    expect($this->tenantA->fresh()->plan_id)->toBe($plan->id);
});

test('17. tenant plan assignment is central-only', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-assign-central');
    loginPlatformAdminForPlanMgmt($this);

    [$response, $connections] = captureQueriedConnectionsForPlanMgmt(
        fn () => $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $plan->id])
    );

    $response->assertRedirect();
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('18. plan and feature mutations are central-only', function () {
    loginPlatformAdminForPlanMgmt($this);
    Plan::where('code', 'planmgmt-central-mutation')->delete();

    [$response, $connections] = captureQueriedConnectionsForPlanMgmt(
        fn () => $this->post('http://localhost/platform/plans', [
            'code' => 'planmgmt-central-mutation',
            'name' => 'Central Mutation Check',
            'sort_order' => 1,
        ])
    );

    $response->assertRedirect();
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();

    $plan = Plan::where('code', 'planmgmt-central-mutation')->firstOrFail();

    [$featureResponse, $featureConnections] = captureQueriedConnectionsForPlanMgmt(
        fn () => $this->post('http://localhost/platform/plans/'.$plan->id.'/features', [
            'feature_code' => FeatureCode::StaffLimit->value,
            'type' => FeatureType::Numeric->value,
            'value' => 2,
        ])
    );

    $featureResponse->assertRedirect();
    expect($featureConnections)->not->toContain('tenant');
});

test('19. a plan downgrade immediately affects products.limit enforcement', function () {
    // Tenant starts on a generous plan and already has 2 root products.
    $generous = ensurePlanMgmtTestPlan('planmgmt-downgrade-before', sortOrder: 501);
    $generous->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 100]);
    $this->tenantA->forceFill(['plan_id' => $generous->id])->save();

    planMgmtLoginAsTenantAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost');
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-downgrade-1')->assertOk();
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-downgrade-2')->assertOk();
    expect(planMgmtRootProductCount($this->tenantA))->toBe(2);

    // Platform Admin assigns a plan whose limit (2) is already met by
    // existing usage - the exact example from the task brief.
    $restrictive = ensurePlanMgmtTestPlan('planmgmt-downgrade-after', sortOrder: 502);
    $restrictive->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 2]);

    // Belt-and-braces alongside TenantController::changePlan()'s own
    // ambient-connection-independent lookup (see that method's docblock):
    // the R35/R37-established convention of ending a same-process leftover
    // tenancy before the next, unrelated request within one test method.
    tenancy()->end();
    loginPlatformAdminForPlanMgmt($this);
    $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $restrictive->id])
        ->assertRedirect();

    // No worker restart, no cache clear, no manual sync - just the next request.
    tenancy()->end();
    planMgmtLoginAsTenantAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost');
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-downgrade-3')->assertStatus(422);
    expect(planMgmtRootProductCount($this->tenantA))->toBe(2);
});

test('20. a plan upgrade to unlimited immediately restores enforcement access', function () {
    $restrictive = ensurePlanMgmtTestPlan('planmgmt-upgrade-before', sortOrder: 503);
    $restrictive->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 2]);
    $this->tenantA->forceFill(['plan_id' => $restrictive->id])->save();

    planMgmtLoginAsTenantAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost');
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-upgrade-1')->assertOk();
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-upgrade-2')->assertOk();
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-upgrade-3')->assertStatus(422);

    $unlimited = ensurePlanMgmtTestPlan('planmgmt-upgrade-after', sortOrder: 504);
    $unlimited->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Unlimited, 'value' => null]);

    tenancy()->end();
    loginPlatformAdminForPlanMgmt($this);
    $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $unlimited->id])
        ->assertRedirect();

    tenancy()->end();
    planMgmtLoginAsTenantAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost');
    planMgmtCreateProductViaAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost', 'planmgmt-upgrade-3')->assertOk();
    expect(planMgmtRootProductCount($this->tenantA))->toBe(3);
});

test('21. the My Plan page immediately reflects a changed plan', function () {
    $newPlan = ensurePlanMgmtTestPlan('planmgmt-myplan', 'My Plan Reflected Name', sortOrder: 505);
    $newPlan->features()->create(['feature_code' => FeatureCode::ProductsLimit->value, 'type' => FeatureType::Numeric, 'value' => 55]);

    loginPlatformAdminForPlanMgmt($this);
    $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $newPlan->id])
        ->assertRedirect();

    tenancy()->end();
    planMgmtLoginAsTenantAdmin($this, PLAN_MGMT_TENANT_IDS[0].'.localhost');

    $response = $this->get('http://'.PLAN_MGMT_TENANT_IDS[0].'.localhost/admin/saas/plan');
    $response->assertOk();
    $response->assertSee('My Plan Reflected Name');
});

test('22. tenant DB commerce data remains unchanged by plan reassignment', function () {
    $marker = $this->tenantA->run(function () {
        $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
        $product = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'PLANMGMT-MARKER']);

        return DB::table('products')->where('id', $product->id)->first();
    });
    $migrationsBefore = $this->tenantA->run(fn () => DB::table('migrations')->pluck('migration')->sort()->values()->all());

    $newPlan = ensurePlanMgmtTestPlan('planmgmt-data-unchanged', sortOrder: 506);
    loginPlatformAdminForPlanMgmt($this);
    $this->post('http://localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', ['plan_id' => $newPlan->id])
        ->assertRedirect();

    $after = $this->tenantA->run(fn () => DB::table('products')->where('sku', 'PLANMGMT-MARKER')->first());
    $migrationsAfter = $this->tenantA->run(fn () => DB::table('migrations')->pluck('migration')->sort()->values()->all());

    expect($after->id)->toBe($marker->id);
    expect($after->created_at)->toBe($marker->created_at);
    expect($migrationsAfter)->toBe($migrationsBefore);
});

test('23. an unauthenticated caller cannot mutate plans', function () {
    Plan::where('code', 'planmgmt-unauth')->delete();

    $response = $this->post('http://localhost/platform/plans', [
        'code' => 'planmgmt-unauth',
        'name' => 'Should not be created',
        'sort_order' => 1,
    ]);

    $response->assertRedirect(route('platform.login'));
    expect(Plan::where('code', 'planmgmt-unauth')->exists())->toBeFalse();
});

test('24. Tenant Admin cannot access Platform plan-management operations', function () {
    $plan = ensurePlanMgmtTestPlan('planmgmt-tenant-blocked');

    $response = $this->post('http://'.PLAN_MGMT_TENANT_IDS[0].'.localhost/platform/tenants/'.PLAN_MGMT_TENANT_IDS[0].'/change-plan', [
        'plan_id' => $plan->id,
    ]);

    $response->assertNotFound();
    expect($this->tenantA->fresh()->plan_id)->not->toBe($plan->id);
});

test('25. default plan provisioning still works', function () {
    $id = 'tenant-planmgmt-default-check';
    Tenant::where('id', $id)->delete();

    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);

    app(TenantProvisioner::class)->provision($tenant);

    $freePlan = Plan::where('code', config('platform.plans.default_code'))->firstOrFail();
    expect($tenant->fresh()->plan_id)->toBe($freePlan->id);

    $data = json_decode($tenant->data ?? '{}', true) ?: [];
    if (! empty($data['tenancy_db_name'])) {
        DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    $tenant->domains()->delete();
    $tenant->delete();
});

test('26. an inactive default plan is rejected for new provisioning, without affecting existing tenants', function () {
    $freePlan = Plan::where('code', config('platform.plans.default_code'))->firstOrFail();
    $freePlan->update(['is_active' => false]);

    $id = 'tenant-planmgmt-inactive-default-check';
    Tenant::where('id', $id)->delete();
    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);

    try {
        app(TenantProvisioner::class)->provision($tenant);
    } catch (\Throwable) {
        // Expected - provision() itself catches and records this below.
    }

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Failed);
    expect($tenant->last_error)->toContain('not active');

    // Existing tenants already on the (now-inactive) default plan are
    // completely unaffected.
    expect($this->tenantA->fresh()->plan_id)->not->toBeNull();

    // Restore immediately - never leave the real default plan
    // deactivated for any later test/file (R40-class discipline).
    $freePlan->update(['is_active' => true]);
    $tenant->domains()->delete();
    $tenant->delete();
});

test('27. PlanSeeder does not overwrite operator-managed edits on re-run', function () {
    $freePlan = Plan::where('code', config('platform.plans.default_code'))->firstOrFail();
    $originalName = $freePlan->name;

    $freePlan->update(['name' => 'Operator Renamed Free Plan']);

    app(PlanSeeder::class)->seed();

    expect($freePlan->fresh()->name)->toBe('Operator Renamed Free Plan');

    // Restore immediately - never leave the real default plan's name
    // changed for any later test/file.
    $freePlan->update(['name' => $originalName]);
});
