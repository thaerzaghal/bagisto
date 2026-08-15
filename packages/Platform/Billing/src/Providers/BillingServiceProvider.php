<?php

declare(strict_types=1);

namespace Platform\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\Services\BillingProviderResolver;

/**
 * TASK-ARCH-018. Registers the Billing domain -
 * `packages/Platform/Billing`, depending on `Platform\Subscriptions`
 * (via `Platform\Billing\Services\PaymentLifecycle`'s future TASK-ARCH-019
 * caller, and `Payment`'s `subscription()` relation today), `Platform\Plans`
 * (`Plan`, via `PlanPrice`'s relation), and `Platform\Tenancy` (`Tenant`),
 * matching the preferred dependency direction:
 *
 *   Platform\Billing -> Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
 *
 * `Platform\Subscriptions` remains completely unaware `Platform\Billing`
 * exists - no class in that package imports anything from this one.
 *
 * `PaymentProvider::class` is bound here to a closure that resolves
 * through `BillingProviderResolver` (config-driven, task section 3) -
 * any code needing the configured provider type-hints `PaymentProvider`
 * and gets the right adapter without knowing which one it is.
 *
 * No migrations registered via `loadMigrationsFrom()` - `create_plan_prices_table`/
 * `create_payments_table` live directly in `database/migrations/` root,
 * exactly like every other Platform central table.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(PaymentProvider::class, fn ($app) => $app->make(BillingProviderResolver::class)->resolve());
    }

    public function boot()
    {
        //
    }
}
