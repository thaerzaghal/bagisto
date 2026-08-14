<?php

declare(strict_types=1);

namespace Platform\Plans\Providers;

use Illuminate\Support\ServiceProvider;
use Platform\Plans\Console\Commands\SeedPlans;

/**
 * TASK-ARCH-008. Deliberately minimal - no migrations registered here
 * (see the create_plans_table migration's docblock for why: this
 * package's central-only migrations live in database/migrations root,
 * NOT loadMigrationsFrom(), so Platform\Tenancy\Services\TenantProvisioner
 * ::ensureMigrated()'s tenant-migration path discovery never sweeps them
 * into a tenant database). No event listeners, no middleware, no routes -
 * this task builds a data/service layer only (section 15: no Tenant Admin
 * UI, no menu.php/acl.php changes yet).
 */
class PlansServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedPlans::class,
            ]);
        }
    }
}
