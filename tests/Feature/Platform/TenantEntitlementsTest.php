<?php

/**
 * TASK-ARCH-008 - Plan & Feature Entitlement Foundation security/correctness
 * test matrix.
 *
 * Addresses the minimal Phase 9 slice pulled ahead of Phase 7 (see
 * IMPLEMENTATION_PLAN.md's "RESEQUENCING NOTE" on Phase 7). Real MySQL,
 * real tenant provisioning, real HTTP where relevant. Nothing mocked.
 *
 * DOMAIN BOUNDARY UNDER TEST: `plans`/`plan_features` are CENTRAL-only
 * tables (Platform\Plans\Models\Plan/PlanFeature both use stancl's
 * CentralConnection trait - config('tenancy.database.central_connection'),
 * read fresh on every query, never frozen). `tenants.plan_id` is the only
 * SaaS-plan-related column that exists anywhere near a tenant, and even
 * that lives in the CENTRAL `tenants` table, not inside any tenant
 * database. This file proves that boundary holds, not just that the
 * feature-resolution logic is arithmetically correct.
 *
 * This task deliberately does NOT include subscriptions, billing,
 * enforcement, usage tracking, or any Tenant Admin UI - see
 * IMPLEMENTATION_PLAN.md's TASK-ARCH-008 section for the full scope
 * boundary. These tests only prove the plan/feature/entitlement domain
 * itself.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Models\Plan;
use Platform\Plans\Models\PlanFeature;
use Platform\Plans\Services\PlanSeeder;
use Platform\Plans\Services\TenantEntitlements;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const PLAN_TEST_TENANT_IDS = ['tenant-plan-a', 'tenant-plan-b'];

/**
 * DEDICATED tenant-plan-a/tenant-plan-b fixtures - not the shared
 * tenant-a/tenant-b fixtures other Platform test files reuse. Matches the
 * established convention (tenant-search-a/b, tenant-queue-a/b,
 * tenant-prov-a/b): any file whose fixtures assign/assert specific plan
 * state needs tenant ids no other file's assertions depend on.
 *
 * Tenant A is left on whatever plan provisioning assigns it (the default,
 * 'free' - proves TenantProvisioner::ensureDefaultPlanAssigned() end to
 * end). Tenant B is explicitly moved to 'pro' so tests 5/7/8/9 have two
 * tenants on genuinely different plans to prove isolation against, not
 * just two tenants that happen to share the default.
 */
function ensurePlanTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-plan-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-plan-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-plan-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-plan-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-plan-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-plan-b.localhost']);
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

    return [$tenantA, $tenantB];
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensurePlanTestFixtures();
});

test('plans exist in the central database only', function () {
    $rows = DB::connection('mysql')->table('plans')->get();

    expect($rows)->not->toBeEmpty();
    expect($rows->pluck('code')->all())->toContain('free', 'basic', 'pro');
});

test('tenant databases do not contain plan tables', function () {
    $hasPlans = $this->tenantA->run(fn () => Schema::hasTable('plans'));
    $hasPlanFeatures = $this->tenantA->run(fn () => Schema::hasTable('plan_features'));

    expect($hasPlans)->toBeFalse();
    expect($hasPlanFeatures)->toBeFalse();

    $hasPlansB = $this->tenantB->run(fn () => Schema::hasTable('plans'));
    expect($hasPlansB)->toBeFalse();
});

test('a tenant can be assigned a plan', function () {
    $free = Plan::where('code', 'free')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    expect($this->tenantA->plan_id)->toBe($free->id, 'tenant A should have received the default plan during provisioning');
    expect($this->tenantB->plan_id)->toBe($pro->id, 'tenant B was explicitly assigned pro by the fixture');
});

test('a tenant can resolve its current plan', function () {
    $plan = TenantEntitlements::for($this->tenantA)->currentPlan();

    expect($plan)->toBeInstanceOf(Plan::class);
    expect($plan->code)->toBe('free');
});

test('boolean entitlement resolves correctly', function () {
    // Tenant A (free): custom_domain is off. Tenant B (pro): custom_domain is on.
    expect(TenantEntitlements::for($this->tenantA)->can(FeatureCode::CustomDomain))->toBeFalse();
    expect(TenantEntitlements::for($this->tenantB)->can(FeatureCode::CustomDomain))->toBeTrue();
});

test('numeric limit resolves correctly', function () {
    // Tenant A is on 'free', seeded with products.limit = 10 (a numeric cap).
    expect(TenantEntitlements::for($this->tenantA)->limit(FeatureCode::ProductsLimit))->toBe(10);
});

test('unlimited value resolves correctly', function () {
    // Tenant B is on 'pro', seeded with products.limit = unlimited.
    expect(TenantEntitlements::for($this->tenantB)->limit(FeatureCode::ProductsLimit))->toBeNull();
});

test('two tenants may have different plans', function () {
    $planA = TenantEntitlements::for($this->tenantA)->currentPlan();
    $planB = TenantEntitlements::for($this->tenantB)->currentPlan();

    expect($planA->code)->toBe('free');
    expect($planB->code)->toBe('pro');
    expect($planA->id)->not->toBe($planB->id);
});

test('Tenant A cannot accidentally resolve Tenant B\'s plan assignment', function () {
    // Direct row-level proof, not just behavioral: read tenants.plan_id for
    // both from the real central connection and confirm they point at
    // genuinely different plan rows - not merely "the API returned
    // different labels", the underlying assignment data itself differs.
    $rowA = DB::connection('mysql')->table('tenants')->where('id', 'tenant-plan-a')->first();
    $rowB = DB::connection('mysql')->table('tenants')->where('id', 'tenant-plan-b')->first();

    expect($rowA->plan_id)->not->toBe($rowB->plan_id);

    // And the resolution service itself never conflates the two, even when
    // asked back-to-back for the same feature code.
    $limitA = TenantEntitlements::for($this->tenantA)->limit(FeatureCode::ProductsLimit);
    $limitB = TenantEntitlements::for($this->tenantB)->limit(FeatureCode::ProductsLimit);

    expect($limitA)->toBe(10);
    expect($limitB)->toBeNull();
});

test('entitlements can be queried during an active tenant context while still reading central plan metadata safely', function () {
    $this->tenantA->run(function () {
        // Central-context-safety proof: TenantEntitlements::current() must
        // resolve the CENTRAL plan correctly from inside an initialized
        // tenant context, via Plan/PlanFeature's CentralConnection trait -
        // never via tenancy()->end()/tenancy()->central(), which this test
        // proves by checking tenancy is still fully initialized, and the
        // tenant's own database connection still resolves correctly,
        // immediately AFTER the entitlement read - not disturbed at all.
        $plan = TenantEntitlements::current()->currentPlan();
        expect($plan->code)->toBe('free');

        expect(tenancy()->initialized)->toBeTrue('reading central plan data must not end the active tenancy');
        expect(DB::connection()->getDatabaseName())->toBe('tenanttenant-plan-a', 'the tenant DB connection must still be the correct tenant\'s after a central read');

        // And a genuinely tenant-scoped query still works correctly right
        // after, proving the connection swap was never touched.
        expect(Schema::hasTable('admins'))->toBeTrue();
    });

    expect(tenancy()->initialized)->toBeFalse('tenancy must still end normally after the run() block, unaffected by the entitlement read inside it');
});

test('default-plan assignment works during tenant provisioning', function () {
    // A brand new tenant, provisioned fresh in this test (not reused from
    // beforeEach), proving TenantProvisioner::ensureDefaultPlanAssigned()
    // actually runs as part of provision() and lands the configured
    // default plan - not just that an already-provisioned fixture happens
    // to have one.
    $id = 'tenant-plan-default-check';
    Tenant::where('id', $id)->delete();

    $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => $id.'.localhost']);

    expect($tenant->plan_id)->toBeNull('a freshly created tenant must not have a plan yet - it is assigned during provisioning');

    app(TenantProvisioner::class)->provision($tenant);

    $freePlan = Plan::where('code', config('platform.plans.default_code'))->firstOrFail();
    $tenant = $tenant->fresh();

    expect($tenant->plan_id)->toBe($freePlan->id);

    // Cleanup: this tenant is disposable, provisioned only for this test.
    $data = json_decode($tenant->data ?? '{}', true) ?: [];
    if (! empty($data['tenancy_db_name'])) {
        DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    $tenant->domains()->delete();
    $tenant->delete();
});

test('plan seed/bootstrap is idempotent', function () {
    $countBefore = Plan::count();
    $featureCountBefore = PlanFeature::count();

    app(PlanSeeder::class)->seed();
    app(PlanSeeder::class)->seed();

    expect(Plan::count())->toBe($countBefore, 'seeding twice more must not create duplicate plan rows');
    expect(PlanFeature::count())->toBe($featureCountBefore, 'seeding twice more must not create duplicate plan_features rows');
});

test('legacy JSON-only tenant metadata (R31) is correctly backfilled into real columns by the real migration', function () {
    // Simulates exactly what every tenant row in this project looked like
    // before TASK-ARCH-008's getCustomColumns() fix: real columns stale/
    // default, the true current values living only inside `data`. Built
    // via a raw INSERT (bypassing Eloquent/VirtualColumn entirely) since
    // the fixed Tenant model can no longer produce this shape itself.
    $id = 'tenant-legacy-backfill-check';
    DB::connection('mysql')->table('tenants')->where('id', $id)->delete();

    $proPlan = Plan::where('code', 'pro')->firstOrFail();

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'status' => 'pending', // stale real column
        'last_error' => null,  // stale real column
        'plan_id' => null,     // stale real column
        'data' => json_encode([
            'status' => 'ready',
            'last_error' => 'a legacy failure message',
            'plan_id' => $proPlan->id,
            'tenancy_db_name' => 'irrelevant-to-this-test',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Runs the real, permanent migration - not a reimplementation of its
    // logic - the same way `php artisan platform:migrate:central` would.
    (require database_path('migrations/2026_08_14_140000_backfill_tenant_real_columns_from_legacy_data.php'))->up();

    $row = DB::connection('mysql')->table('tenants')->where('id', $id)->first();

    expect($row->status)->toBe('ready');
    expect($row->last_error)->toBe('a legacy failure message');
    expect($row->plan_id)->toBe($proPlan->id);

    // The migration writes real columns via a targeted raw UPDATE and
    // deliberately never touches `data` at all (see that migration's own
    // docblock for why: Eloquent's save()/dirty-tracking is not used for
    // the write, so there is nothing to strip) - `data` must therefore be
    // completely unchanged, including the now-redundant duplicate keys.
    $data = json_decode($row->data, true);
    expect($data)->toHaveKey('tenancy_db_name');
    expect($data['tenancy_db_name'])->toBe('irrelevant-to-this-test', 'genuinely virtual-only internal keys must be untouched');

    DB::connection('mysql')->table('tenants')->where('id', $id)->delete();
});
