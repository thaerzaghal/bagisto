<?php

declare(strict_types=1);

namespace Platform\Signup\Providers;

use Illuminate\Support\ServiceProvider;

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
    }
}
