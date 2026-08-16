<?php

declare(strict_types=1);

namespace Platform\Signup\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webkul\Theme\ViewRenderEventManager;

/**
 * TASK-MVP-001. Registers public merchant self-service signup as
 * `packages/Platform/Signup` - depends ONLY on `Platform\Tenancy`
 * (`Tenant` model, `TenantProvisioner`), matching the approved plan's
 * "keep Signup dependent only on Tenancy unless implementation proves
 * another dependency is genuinely required" instruction (no other
 * dependency proved necessary - see SignupResultResponder/
 * MerchantOnboarding, neither of which imports Platform\Plans/
 * Subscriptions/Billing/Admin at all; TenantProvisioner already
 * encapsulates default-plan lookup and subscription start internally).
 *
 * Deliberately registers NO migration of its own via
 * `loadMigrationsFrom()` - the one schema change this task needs
 * (`owner_name`/`owner_email` on `tenants`) lives directly in
 * `database/migrations/` root, exactly like every other Platform central
 * table, picked up automatically by `platform:migrate:central` (same
 * reasoning as R17/R30/R33/R31).
 *
 * TASK-MVP-003: also owns the tiny "welcome" touch at the very end of
 * the signup->provisioning->first-login journey. `FlagFirstLoginWelcome`
 * is registered as an ordinary 'web'-group middleware member in
 * `bootstrap/app.php` (not attached here - see that middleware's own
 * docblock for why the R25-style per-route attachment this package
 * first tried does not work for `admin.session.create` specifically).
 * `attachWelcomeBannerToAdminDashboard()` below is the officially-
 * supported `view_render_event()` injection point this uses instead of
 * a Bagisto core view edit.
 */
class SignupServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'signup');

        $this->loadRoutesFrom(__DIR__.'/../Routes/signup-routes.php');

        $this->attachWelcomeBannerToAdminDashboard();
    }

    /**
     * Uses Bagisto's own officially-supported `view_render_event()`
     * extension point (`bagisto.admin.dashboard.overall_details.before`,
     * the very first hook in `packages/Webkul/Admin/src/Resources/
     * views/dashboard/index.blade.php`) - the same mechanism
     * `Webkul\Core\Providers\CoreServiceProvider` itself already uses
     * for its own small template injections. Zero `packages/Webkul`
     * view edit. The listener only ever adds a template when the
     * CURRENT request's own `?welcome=1` query parameter is present
     * (set by FlagFirstLoginWelcome, only ever true immediately after a
     * first post-signup login) - an ordinary dashboard visit is
     * completely unaffected, proven by test.
     */
    protected function attachWelcomeBannerToAdminDashboard(): void
    {
        Event::listen(
            'bagisto.admin.dashboard.overall_details.before',
            function (ViewRenderEventManager $viewRenderEventManager) {
                if (request()->boolean('welcome')) {
                    $viewRenderEventManager->addTemplate('signup::admin.welcome-banner');
                }
            }
        );
    }
}
