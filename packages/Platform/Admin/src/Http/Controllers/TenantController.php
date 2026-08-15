<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Platform\Plans\Exceptions\InactivePlanAssignmentException;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Exceptions\InvalidSubscriptionTransitionException;
use Platform\Subscriptions\Models\Subscription;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Exceptions\InvalidTenantTransitionException;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantLifecycle;
use Platform\Tenancy\Services\TenantProvisioner;
use Throwable;

/**
 * TASK-ARCH-011. Every method here reads/writes ONLY the central `tenants`
 * table (Platform\Tenancy\Models\Tenant - central-only by construction,
 * see that model's docblock) and, for the plan name, the central `plans`
 * table. Nothing here ever queries a tenant commerce database to render a
 * listing (the task brief's item 8 requirement) - the tenant's OWN data
 * (products, orders, ...) is simply not part of this page at all.
 *
 * Actions are deliberately minimal per the task brief (item 11): view,
 * two non-destructive, already-idempotent operations - `TenantProvisioner::
 * provision()` (safe retry of a Pending/Provisioning/Failed tenant,
 * TASK-ARCH-002) and `TenantProvisioner::remigrate()` (safe on any tenant,
 * any number of times, TASK-ARCH-010/R33) - and, since TASK-ARCH-013,
 * suspend()/reactivate() via `Platform\Tenancy\Services\TenantLifecycle`
 * (Ready<->Suspended only; the controller itself never mutates
 * `$tenant->status` directly). Still no delete - deletion has its own
 * unresolved backup/export design questions and remains out of scope.
 *
 * TASK-ARCH-016: changePlan() no longer mutates `$tenant->plan_id` (or
 * even calls `TenantPlanAssignment` directly, TASK-ARCH-015's own
 * approach) - it now goes through `Platform\Subscriptions\Services\
 * SubscriptionLifecycle`, which itself calls `TenantPlanAssignment`
 * internally. Task section 10, explicit: "This can no longer remain an
 * independent mutation path once subscriptions exist... Do NOT allow
 * Platform Admin to bypass Subscription once a tenant has one." See
 * changePlan()'s own docblock for the narrow, temporary compatibility
 * path for a tenant with no subscription row yet (should not occur for
 * any tenant provisioned after this task, or after the backfill migration
 * - see docs/architecture/subscriptions.md).
 */
class TenantController
{
    public function index(): View
    {
        $tenants = Tenant::with('domains')->orderByDesc('created_at')->get();

        $plans = Plan::whereIn('id', $tenants->pluck('plan_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return view('platform::tenants.index', [
            'tenants' => $tenants,
            'plans' => $plans,
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $plan = $tenant->plan_id ? Plan::find($tenant->plan_id) : null;

        return view('platform::tenants.show', [
            'tenant' => $tenant->load('domains'),
            'plan' => $plan,
            'subscription' => Subscription::currentFor($tenant),
            // TASK-ARCH-015: only ACTIVE plans are offered for manual
            // (re)assignment - see TenantPlanAssignment's own "assignable"
            // rule. The tenant's CURRENT plan is included even if it has
            // since been deactivated (deactivation must not disturb an
            // existing assignment - see docs/architecture/feature-limits.md
            // "Plan deactivation semantics"), so the dropdown always shows
            // a tenant's real current plan even in that edge case.
            'assignablePlans' => Plan::where('is_active', true)
                ->orWhere('id', $tenant->plan_id)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /**
     * TASK-ARCH-016: routes through `SubscriptionLifecycle` rather than
     * mutating a plan directly (see class docblock). Two cases:
     *
     * - The tenant already has a Trialing/Active subscription (the normal
     *   case for every real tenant after this task) -> `changePlan()`.
     * - The tenant has no subscription, or an already-Canceled/Expired
     *   one (the "narrow, temporary compatibility" case the task
     *   explicitly permits for a transitional tenant-without-subscription
     *   state, task section 10) -> `start()`, which both creates/restarts
     *   the subscription AND assigns the plan in one step - the exact
     *   fallback `SubscriptionLifecycle::start()`'s own docblock already
     *   documents ("RESTART IN PLACE").
     *
     * Still does NOT use a plain `'plan_id' => 'exists:plans,id'`
     * validation rule - see RISK_REGISTER.md R42 (TASK-ARCH-015): that
     * rule queries the given table name against whatever the ambient
     * default DB connection is, with no awareness `plans` is central-
     * only. `Plan::find()` is `CentralConnection`-safe regardless of
     * ambient state.
     */
    public function changePlan(Request $request, Tenant $tenant, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        $plan = Plan::find($validated['plan_id']);

        if (! $plan) {
            return back()->withErrors(['plan' => "Plan [{$validated['plan_id']}] does not exist."]);
        }

        $subscription = Subscription::currentFor($tenant);

        try {
            if ($subscription && in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                $lifecycle->changePlan($subscription, $plan);
            } else {
                $lifecycle->start($tenant, $plan);
            }
        } catch (InactivePlanAssignmentException|InvalidSubscriptionTransitionException $e) {
            return back()->withErrors(['plan' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}] plan changed to [{$plan->name}].");
    }

    public function provision(Tenant $tenant, TenantProvisioner $provisioner): RedirectResponse
    {
        if (! $tenant->status->isProvisionable()) {
            return back()->withErrors([
                'tenant' => "Tenant [{$tenant->getTenantKey()}] cannot be (re)provisioned from status [{$tenant->status->value}].",
            ]);
        }

        try {
            $provisioner->provision($tenant);
        } catch (Throwable $e) {
            return back()->withErrors([
                'tenant' => "Provisioning failed: {$e->getMessage()}",
            ]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}] provisioned successfully.");
    }

    public function migratePending(Tenant $tenant, TenantProvisioner $provisioner): RedirectResponse
    {
        try {
            $provisioner->remigrate($tenant);
        } catch (Throwable $e) {
            return back()->withErrors([
                'tenant' => "Migration failed: {$e->getMessage()}",
            ]);
        }

        return back()->with('status', "Pending migrations applied for tenant [{$tenant->getTenantKey()}].");
    }

    public function suspend(Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->suspend($tenant);
        } catch (InvalidTenantTransitionException $e) {
            return back()->withErrors(['tenant' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}] suspended.");
    }

    public function reactivate(Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->reactivate($tenant);
        } catch (InvalidTenantTransitionException $e) {
            return back()->withErrors(['tenant' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}] reactivated.");
    }
}
