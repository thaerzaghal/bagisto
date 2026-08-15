<?php

declare(strict_types=1);

namespace Platform\Plans\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Platform\Plans\Exceptions\NoPlanAssignedException;
use Platform\Plans\Services\TenantEntitlements;
use Platform\Subscriptions\Models\Subscription;

/**
 * TASK-ARCH-009. "My Plan" - the tenant admin's own view of the real
 * Plan/Entitlement domain built in TASK-ARCH-008, extended by
 * TASK-ARCH-016 to also show real subscription lifecycle state.
 *
 * Extends the plain Laravel `Controller`, not `Webkul\Admin\Http\
 * Controllers\Controller` - this page needs none of that base class's
 * extras (AuthorizesRequests/DispatchesJobs/ValidatesRequests), and
 * depending on it would be an unnecessary coupling to Webkul internals
 * beyond what's actually needed (auth/ACL/layout/component reuse, which
 * come from the shared `admin` route middleware and `<x-admin::layouts>`
 * component - both framework-level extension points, not this class).
 *
 * TASK-ARCH-016 PACKAGE-DEPENDENCY NOTE (task section 25): this is the
 * one narrow, deliberate exception to "Platform\Plans should NOT depend
 * on Platform\Subscriptions" (see `Platform\Subscriptions\Providers\
 * SubscriptionsServiceProvider`'s own docblock and DECISION_LOG.md) -
 * this controller reads `Platform\Subscriptions\Models\Subscription`
 * directly, read-only, purely to display it on an already-existing page.
 * This does NOT create a circular package dependency:
 * `Platform\Subscriptions` already depends on `Platform\Plans` (the
 * preferred direction), and this is the only place the reverse read
 * happens - no `Platform\Subscriptions` class imports anything from
 * `Platform\Plans\Http` or vice versa at the presentation layer. A
 * narrow interface/abstraction (task section 25's option B) was
 * considered and rejected as unnecessary ceremony: this codebase already
 * has an established, uniform convention of controllers reading another
 * package's `CentralConnection` model directly for display purposes
 * (e.g. `Platform\Admin\Http\Controllers\TenantController` already reads
 * `Platform\Plans\Models\Plan` the same way) - introducing an interface
 * here alone would be inconsistent with that precedent, not cleaner.
 */
class MyPlanController extends Controller
{
    public function index(): View
    {
        try {
            $plan = TenantEntitlements::current()->currentPlan();
        } catch (NoPlanAssignedException) {
            // Defensive only - TenantProvisioner::ensureInitialSubscriptionStarted()
            // guarantees every tenant reaching this page already has one;
            // fails soft rather than a raw 500 in case that guarantee is
            // ever violated (e.g. a tenant provisioned by hand).
            return view('plans::admin.my-plan.index', [
                'plan' => null,
                'features' => collect(),
                'subscription' => null,
            ]);
        }

        $features = $plan->features()->orderBy('feature_code')->get();

        // Gracefully handles a tenant with no Subscription row yet (task
        // section 16) - genuinely possible only for a transitional/test
        // tenant; every tenant provisioned after TASK-ARCH-016, or
        // covered by its backfill, has one.
        $subscription = Subscription::currentFor(tenant());

        return view('plans::admin.my-plan.index', [
            'plan' => $plan,
            'features' => $features,
            'subscription' => $subscription,
        ]);
    }
}
