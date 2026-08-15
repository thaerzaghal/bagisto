<?php

declare(strict_types=1);

namespace Platform\Billing\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\Contracts\WebhookVerifier;
use Platform\Billing\Services\BillingProviderResolver;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;

/**
 * TASK-ARCH-018/019. Registers the Billing domain -
 * `packages/Platform/Billing`, depending on `Platform\Subscriptions`
 * (`PaymentLifecycle`'s caller relationship via `WebhookEventProcessor`,
 * and `Payment`'s `subscription()` relation), `Platform\Plans` (`Plan`,
 * via `PlanPrice`'s relation), and `Platform\Tenancy` (`Tenant`),
 * matching the preferred dependency direction:
 *
 *   Platform\Billing -> Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
 *
 * `Platform\Subscriptions` remains completely unaware `Platform\Billing`
 * exists - no class in that package imports anything from this one.
 *
 * `PaymentProvider::class`/`WebhookVerifier::class` are bound here to
 * closures that resolve through `BillingProviderResolver` (config-driven,
 * task section 3) - any code needing the configured provider type-hints
 * the contract and gets the right adapter without knowing which one it is.
 *
 * No migrations registered via `loadMigrationsFrom()` -
 * `create_plan_prices_table`/`create_payments_table`/
 * `create_billing_provider_events_table` all live directly in
 * `database/migrations/` root, exactly like every other Platform central
 * table.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(PaymentProvider::class, fn ($app) => $app->make(BillingProviderResolver::class)->resolve());
        $this->app->bind(WebhookVerifier::class, fn ($app) => $app->make(BillingProviderResolver::class)->resolveWebhookVerifier());

        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'billing');

        /**
         * TASK-ARCH-019 (task section 3). Mirrors `Platform\Plans\
         * Providers\PlansServiceProvider::boot()`'s own tenant-admin route
         * registration exactly (see that provider's own docblock) - 'web'
         * + PreventRequestsDuringMaintenance + 'admin' (Webkul\User's
         * session auth + ACL, Bouncer) + NoCacheMiddleware, under
         * `config('app.admin_url')`. An unauthenticated/unauthorized
         * request to a checkout route behaves identically to any other
         * Bagisto tenant-admin route.
         */
        Route::middleware(['web', PreventRequestsDuringMaintenance::class, 'admin', NoCacheMiddleware::class])
            ->prefix(config('app.admin_url'))
            ->group(__DIR__.'/../Routes/tenant-routes.php');

        /**
         * TASK-ARCH-019 (task section 10). The Stripe webhook endpoint -
         * 'platform' middleware group (web state minus
         * InitializeTenancyByDomain, see bootstrap/app.php's own
         * definition) so no tenant DB connection is ever established for
         * this request, regardless of which host it is reached through.
         * CSRF-exempted separately in bootstrap/app.php
         * ('billing/webhook/*').
         */
        Route::middleware(['platform'])
            ->group(__DIR__.'/../Routes/central-routes.php');
    }
}
