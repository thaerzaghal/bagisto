<?php

declare(strict_types=1);

namespace Platform\Plans\Console\Commands;

use Illuminate\Console\Command;
use Platform\Plans\Services\PlanSeeder;

/**
 * TASK-ARCH-008. `php artisan platform:plans:seed` - a required, one-time
 * (idempotent, safe to rerun) deployment step, same category as
 * `platform:mark-installed`: run once per environment before any tenant
 * is provisioned, since Platform\Tenancy\Services\TenantProvisioner::
 * ensureDefaultPlanAssigned() fails provisioning loudly if the configured
 * default plan (config('platform.plans.default_code')) does not exist.
 */
class SeedPlans extends Command
{
    protected $signature = 'platform:plans:seed';

    protected $description = 'Seed the central platform plans/plan_features tables (idempotent, safe to rerun).';

    public function handle(PlanSeeder $seeder): int
    {
        $seeder->seed();

        $this->info('Platform plans seeded.');

        return self::SUCCESS;
    }
}
