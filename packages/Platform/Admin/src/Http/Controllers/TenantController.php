<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Platform\Billing\Models\Payment;
use Platform\Plans\Exceptions\InactivePlanAssignmentException;
use Platform\Plans\Models\Plan;
use Platform\Signup\Services\MerchantOnboarding;
use Platform\Signup\Services\OwnerActivationMailer;
use Platform\Signup\Support\SignupValidationRules;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Exceptions\InvalidSubscriptionTransitionException;
use Platform\Subscriptions\Models\Subscription;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Enums\TenantStatus;
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
 *
 * TASK-MVP-007: `create()`/`store()` are the managed-onboarding entry
 * point - the initial commercial/pilot phase's ONLY way to bring a new
 * merchant onto the platform, since public `/join` defaults to disabled
 * (`config('platform.signup.enabled')`, see docs/architecture/
 * onboarding.md). `store()` reuses `Platform\Signup\Services\
 * MerchantOnboarding::register()` verbatim - the exact same Tenant+Domain
 * creation transaction, `TenantProvisioner` call, and success/failure
 * contract `/join` itself uses - never a second, parallel provisioning
 * implementation. `resendActivation()` exists specifically so a failed
 * owner-activation email can be retried WITHOUT re-provisioning (see
 * `Platform\Signup\Services\OwnerActivationMailer`'s own docblock for the
 * full credential-handling design).
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

    public function create(): View
    {
        return view('platform::tenants.create', [
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * TASK-MVP-007. Reuses `MerchantOnboarding::register()` verbatim - see
     * that class's own docblock for exactly what the new `$plan`/
     * `$storeName` arguments do and why they're safe additions. The
     * temporary password exists only in the two local variables below,
     * for the duration of this one method call - never assigned to
     * anything that outlives it (no property, no session, no cache, no
     * log call references `$password` at all).
     */
    public function store(Request $request, MerchantOnboarding $onboarding, OwnerActivationMailer $activation): RedirectResponse
    {
        $validated = $this->validatedMerchant($request);

        $plan = Plan::where('is_active', true)->find($validated['plan_id']);

        if (! $plan) {
            return back()->withInput()->withErrors(['plan_id' => 'Selected plan is not available.']);
        }

        $domain = $validated['slug'].'.'.config('platform.base_domain');
        $ownerName = trim($validated['owner_first_name'].' '.$validated['owner_last_name']);
        $password = Str::password(40);

        $result = $onboarding->register($validated['slug'], $domain, $ownerName, $validated['owner_email'], $password, $plan, $validated['store_name']);
        $password = null; // discarded - never referenced again below.

        $tenant = $result['tenant'];

        if (! $result['succeeded']) {
            return redirect()->route('platform.tenants.show', $tenant->getTenantKey())
                ->withErrors(['tenant' => "Store creation failed: {$tenant->last_error}"]);
        }

        $emailSent = $activation->send($tenant);

        return redirect()->route('platform.tenants.show', $tenant->getTenantKey())->with(
            $emailSent ? 'status' : 'warning',
            $emailSent
                ? "Store [{$tenant->getTenantKey()}] created and Ready. Owner activation email sent to {$tenant->owner_email}."
                : "Store [{$tenant->getTenantKey()}] created and Ready, but the owner activation email could not be sent. Use \"Resend activation email\" below once the issue is resolved."
        );
    }

    /**
     * TASK-MVP-007. Independent of provisioning entirely - only ever
     * calls `OwnerActivationMailer`, never `TenantProvisioner`. Restricted
     * to a `Ready` tenant: resending for anything else would either be a
     * no-op (no owner Admin row exists yet to send a reset link for) or
     * actively confusing UX.
     */
    public function resendActivation(Tenant $tenant, OwnerActivationMailer $activation): RedirectResponse
    {
        if ($tenant->status !== TenantStatus::Ready) {
            return back()->withErrors([
                'tenant' => "Tenant [{$tenant->getTenantKey()}] is not Ready yet - cannot send the owner activation email.",
            ]);
        }

        $sent = $activation->send($tenant);

        return back()->with(
            $sent ? 'status' : 'warning',
            $sent
                ? "Owner activation email resent to {$tenant->owner_email}."
                : 'Could not send the owner activation email right now - please try again shortly.'
        );
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
            // TASK-ARCH-019 (task section 24): minimal operational
            // visibility only - date/provider/amount/currency/status/
            // provider reference. Deliberately no `provider_metadata`
            // (raw provider payload) rendered anywhere - see
            // tenants/show.blade.php.
            'recentPayments' => Payment::where('tenant_id', $tenant->getTenantKey())
                ->latest('id')
                ->limit(10)
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

    /**
     * TASK-MVP-007. `slug`/`owner_email` reuse `Platform\Signup\Support\
     * SignupValidationRules` verbatim - the IDENTICAL policy `/join` uses
     * (see that class's own docblock for why this must never be a second,
     * independently-maintained copy).
     *
     * @return array{store_name: string, slug: string, owner_first_name: string, owner_last_name: string, owner_email: string, plan_id: int}
     */
    protected function validatedMerchant(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'store_name' => ['required', 'string', 'max:255'],
            'slug' => SignupValidationRules::slug(),
            'owner_first_name' => ['required', 'string', 'max:255'],
            'owner_last_name' => ['required', 'string', 'max:255'],
            'owner_email' => SignupValidationRules::ownerEmail(),
            'plan_id' => ['required', 'integer'],
        ]);

        return $validator->validate();
    }
}
