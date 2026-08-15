<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * TASK-ARCH-016. Registers the Subscription lifecycle domain -
 * `packages/Platform/Subscriptions`, depending on `Platform\Plans`
 * (`TenantPlanAssignment`, `Plan`) and `Platform\Tenancy` (`Tenant`),
 * matching the preferred dependency direction:
 *
 *   Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
 *
 * Neither `Platform\Plans` nor `Platform\Tenancy` depends on this
 * package as a general rule - the one deliberate, documented exception
 * is `Platform\Tenancy\Services\TenantProvisioner`, which calls
 * `SubscriptionLifecycle::start()` during provisioning (see that class's
 * own docblock for the full justification - it extends the same
 * reasoning DECISION_LOG.md C19 already established for its Plan
 * dependency), and a second narrow exception in `Platform\Plans\Http\
 * Controllers\Admin\MyPlanController`, which reads the `Subscription`
 * model directly to display subscription info on the existing My Plan
 * page (see that controller's own docblock).
 *
 * No migrations registered via `loadMigrationsFrom()` - the two
 * migrations this task needs (`create_subscriptions_table`,
 * `backfill_subscriptions_from_tenant_plan_id`) live directly in
 * `database/migrations/` root, exactly like `plans`/`plan_features`/
 * `platform_users` - see those migrations' own docblocks for why.
 */
class SubscriptionsServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        //
    }
}
