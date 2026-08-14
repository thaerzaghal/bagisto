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
 */
class PlanSeeder
{
    public const string DEFAULT_PLAN_CODE = 'free';

    /**
     * Safe to call any number of times, from any context (updateOrCreate
     * throughout, keyed by the natural unique key in each case) - never
     * duplicates rows, never touches a tenant connection (Plan/PlanFeature
     * both use CentralConnection).
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
            $plan = Plan::updateOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'is_active' => true, 'sort_order' => $definition['sort_order']]
            );

            foreach ($definition['features'] as $featureCode => [$type, $value]) {
                $plan->features()->updateOrCreate(
                    ['feature_code' => $featureCode],
                    ['type' => $type, 'value' => $value]
                );
            }
        }
    }
}
