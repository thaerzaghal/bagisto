<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Platform\Plans\Models\Plan;
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
        ]);
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
