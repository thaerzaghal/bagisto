<?php

declare(strict_types=1);

namespace Platform\Plans\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Platform\Plans\Console\Commands\SeedPlans;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;

/**
 * TASK-ARCH-009 (Tenant Admin Integration) added the admin-facing pieces
 * this docblock previously said were deliberately absent: menu.php/
 * acl.php config merges, a view namespace, and one admin route. Still no
 * new migrations (unchanged from TASK-ARCH-008 - see the create_plans_table
 * migration's docblock), no event listeners, no new middleware of our
 * own.
 */
class PlansServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'plans');

        /**
         * Mirrors Webkul\Admin\Providers\AdminServiceProvider::boot()'s
         * own route registration exactly (see that provider and its
         * Routes/web.php): 'web' + PreventRequestsDuringMaintenance for
         * the outer request lifecycle, 'admin' (session auth + ACL,
         * Webkul\User\Http\Middleware\Bouncer) + NoCacheMiddleware for
         * admin-panel behavior, under the same admin URL prefix - so an
         * unauthenticated/unauthorized request to this route behaves
         * identically to every other Bagisto admin route. See
         * Routes/admin-routes.php's own docblock for the full reasoning.
         */
        Route::middleware(['web', PreventRequestsDuringMaintenance::class, 'admin', NoCacheMiddleware::class])
            ->prefix(config('app.admin_url'))
            ->group(__DIR__.'/../Routes/admin-routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedPlans::class,
            ]);
        }
    }
}
