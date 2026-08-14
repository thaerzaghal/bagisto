<?php

declare(strict_types=1);

namespace Platform\Admin\Providers;

use Illuminate\Support\ServiceProvider;
use Platform\Admin\Console\Commands\CreatePlatformAdmin;

/**
 * TASK-ARCH-011. Registers the CENTRAL Platform Admin area - tenants,
 * plans, provisioning - as a package that depends on Platform\Tenancy and
 * Platform\Plans (reads their models directly) but that neither of those
 * packages knows about or depends on in return. See
 * docs/architecture/platform-admin.md, "Package boundary and dependency
 * direction".
 *
 * Deliberately registers NO migrations of its own via loadMigrationsFrom()
 * - the one migration this task needs (platform_users) lives directly in
 * database/migrations/ root, exactly like tenants/domains/plans, so it is
 * picked up automatically by Laravel's default migration path and by
 * `platform:migrate:central` (see that migration file's own docblock for
 * why - the same reasoning as R17/R30/R33). Registering it a second way
 * here would risk Laravel discovering and attempting to run it twice.
 */
class PlatformAdminServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'platform');

        $this->loadRoutesFrom(__DIR__.'/../Routes/platform-routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreatePlatformAdmin::class,
            ]);
        }
    }
}
