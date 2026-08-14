<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Platform\Plans\Exceptions\InactivePlanAssignmentException;
use Platform\Plans\Models\Plan;
use Platform\Plans\Services\TenantPlanAssignment;
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
 * `$tenant->status` directly) and, since TASK-ARCH-015, changePlan() via
 * `Platform\Plans\Services\TenantPlanAssignment` (the controller itself
 * never mutates `$tenant->plan_id` directly either). Still no delete -
 * deletion has its own unresolved backup/export design questions and
 * remains out of scope.
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
     * Deliberately does NOT use a plain `'plan_id' => 'exists:plans,id'`
     * validation rule: Laravel's `exists:` rule queries the given TABLE
     * NAME against whatever the ambient "default" database connection
     * currently is - it has no awareness that `plans` is central-only
     * (`Platform\Plans\Models\Plan`'s `CentralConnection` trait is a
     * model-level concern the raw string-based rule never sees). If this
     * action is ever reached while some earlier, unrelated code in the
     * same PHP process left tenancy initialized (a real, if unusual,
     * possibility this project has hit before in test harnesses - see
     * RISK_REGISTER.md R35/R37 - and one Platform Admin's own middleware
     * group cannot itself rule out, since it only guarantees it never
     * INITIATES tenancy, not that it reverts an already-active one), the
     * `exists:` rule would incorrectly query the TENANT connection and
     * fail with a raw `QueryException` for a table that doesn't exist
     * there. `Plan::find()` has no such gap - it always resolves against
     * the central connection regardless of ambient state, the same
     * guarantee every other Plan/PlanFeature read in this codebase already
     * relies on.
     */
    public function changePlan(Request $request, Tenant $tenant, TenantPlanAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        $plan = Plan::find($validated['plan_id']);

        if (! $plan) {
            return back()->withErrors(['plan' => "Plan [{$validated['plan_id']}] does not exist."]);
        }

        try {
            $assignment->assign($tenant, $plan);
        } catch (InactivePlanAssignmentException $e) {
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
