<?php

declare(strict_types=1);

namespace Platform\Plans\Services;

use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;

/**
 * TASK-ARCH-008. Central-only, idempotent platform plan bootstrap -
 * deliberately NOT a Laravel database seeder class / not run via
 * `db:seed`, and deliberately kept out of Bagisto's own
 * BagistoDatabaseSeeder call chain (docs/architecture/feature-limits.md
 * "Domain boundary": plan seeding is platform-central data, unrelated to
 * a tenant's own commerce seed data - mixing them would make it unclear
 * which seeder owns which schema, and would risk this running against a
 * tenant connection by accident). Exposed as this plain service so both
 * the `platform:plans:seed` console command AND
 * Tests\Feature\Platform\PlatformIntegrationTestCase (every Platform
 * integration test needs a default plan to exist before it can provision
 * a tenant - see TenantProvisioner::ensureDefaultPlanAssigned()) can call
 * the exact same logic without going through Artisan::call() overhead.
 *
 * The FREE/BASIC/PRO plans and their four example features
 * (products.limit, staff.limit, domains.custom, reports.advanced) below
 * are illustrative example data, not a finalized pricing/limit catalog -
 * matching this task's explicit instruction not to hardcode business
 * limits into application logic. They exist so this task's tests (and
 * Phase 7/9's later real work) have real, non-trivial data to exercise.
 *
 * TASK-ARCH-015 CHANGE - bootstrap-only, never overwrites an operator's
 * edits: before this task, `seed()` used `updateOrCreate()` throughout,
 * meaning re-running `platform:plans:seed` after a platform admin had
 * edited a plan's name/description/sort_order/active flag (or a feature's
 * type/value) via the new Platform Admin CRUD UI would silently REVERT
 * those edits back to this hardcoded illustrative data on every re-run -
 * turning a routine bootstrap command into an unexpected destructive
 * config reset. Switched to `firstOrCreate()` throughout: a plan/feature
 * row is created if its natural key (`code` / `[plan_id, feature_code]`)
 * doesn't exist yet, and left COMPLETELY untouched if it does, no matter
 * what its current values are. This keeps `seed()` genuinely idempotent
 * for its actual purpose - initial bootstrap in a fresh environment, and
 * introducing any brand-new illustrative feature/plan a future code
 * change might add - without ever clobbering real, operator-managed
 * plan/feature data. See DECISION_LOG.md for the full reasoning and
 * `tests/Feature/Platform/PlatformPlanManagementTest.php` ("PlanSeeder
 * does not overwrite operator-managed edits on re-run") for the live
 * proof.
 */
class PlanSeeder
{
    public const string DEFAULT_PLAN_CODE = 'free';

    /**
     * Safe to call any number of times, from any context (firstOrCreate
     * throughout, keyed by the natural unique key in each case) - never
     * duplicates rows, never overwrites an existing row's current values,
     * never touches a tenant connection (Plan/PlanFeature both use
     * CentralConnection).
     */
    public function seed(): void
    {
        $plans = [
            'free' => [
                'name' => 'Free',
                'sort_order' => 0,
                'features' => [
                    FeatureCode::ProductsLimit->value => [FeatureType::Numeric, 10],
                    FeatureCode::StaffLimit->value => [FeatureType::Numeric, 1],
                    FeatureCode::CustomDomain->value => [FeatureType::Boolean, 0],
                    FeatureCode::AdvancedReports->value => [FeatureType::Boolean, 0],
                ],
            ],
            'basic' => [
                'name' => 'Basic',
                'sort_order' => 1,
                'features' => [
                    FeatureCode::ProductsLimit->value => [FeatureType::Numeric, 100],
                    FeatureCode::StaffLimit->value => [FeatureType::Numeric, 5],
                    FeatureCode::CustomDomain->value => [FeatureType::Boolean, 0],
                    FeatureCode::AdvancedReports->value => [FeatureType::Boolean, 0],
                ],
            ],
            'pro' => [
                'name' => 'Pro',
                'sort_order' => 2,
                'features' => [
                    FeatureCode::ProductsLimit->value => [FeatureType::Unlimited, null],
                    FeatureCode::StaffLimit->value => [FeatureType::Unlimited, null],
                    FeatureCode::CustomDomain->value => [FeatureType::Boolean, 1],
                    FeatureCode::AdvancedReports->value => [FeatureType::Boolean, 1],
                ],
            ],
        ];

        foreach ($plans as $code => $definition) {
            $plan = Plan::firstOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'is_active' => true, 'sort_order' => $definition['sort_order']]
            );

            foreach ($definition['features'] as $featureCode => [$type, $value]) {
                $plan->features()->firstOrCreate(
                    ['feature_code' => $featureCode],
                    ['type' => $type, 'value' => $value]
                );
            }
        }
    }
}
