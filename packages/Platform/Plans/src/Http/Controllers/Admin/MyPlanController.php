<?php

declare(strict_types=1);

namespace Platform\Plans\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Platform\Plans\Exceptions\NoPlanAssignedException;
use Platform\Plans\Services\TenantEntitlements;

/**
 * TASK-ARCH-009. "My Plan" - the tenant admin's own view of the real
 * Plan/Entitlement domain built in TASK-ARCH-008. No subscription/
 * billing/usage data exists yet (see this task's own scope boundary in
 * IMPLEMENTATION_PLAN.md), so this page shows only what's genuinely real
 * today: the assigned plan and its configured entitlements.
 *
 * Extends the plain Laravel `Controller`, not `Webkul\Admin\Http\
 * Controllers\Controller` - this page needs none of that base class's
 * extras (AuthorizesRequests/DispatchesJobs/ValidatesRequests), and
 * depending on it would be an unnecessary coupling to Webkul internals
 * beyond what's actually needed (auth/ACL/layout/component reuse, which
 * come from the shared `admin` route middleware and `<x-admin::layouts>`
 * component - both framework-level extension points, not this class).
 */
class MyPlanController extends Controller
{
    public function index(): View
    {
        try {
            $plan = TenantEntitlements::current()->currentPlan();
        } catch (NoPlanAssignedException) {
            // Defensive only - TenantProvisioner::ensureDefaultPlanAssigned()
            // guarantees every tenant reaching this page already has one;
            // fails soft rather than a raw 500 in case that guarantee is
            // ever violated (e.g. a tenant provisioned by hand).
            return view('plans::admin.my-plan.index', [
                'plan' => null,
                'features' => collect(),
            ]);
        }

        $features = $plan->features()->orderBy('feature_code')->get();

        return view('plans::admin.my-plan.index', [
            'plan' => $plan,
            'features' => $features,
        ]);
    }
}
