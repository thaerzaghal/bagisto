<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Platform\Subscriptions\Exceptions\InvalidSubscriptionTransitionException;
use Platform\Subscriptions\Models\Subscription;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-016. Manual Platform Admin subscription lifecycle actions -
 * the necessary minimum proof path before any payment provider exists
 * (no webhooks, task section 21), not a full subscription dashboard
 * (task section 15, explicit). Deliberately minimal: "activate trial"
 * (Trialing -> Active), "cancel at period end", "cancel immediately" -
 * matching the exact three actions task section 15 lists beyond "start/
 * change plan" (already covered by `TenantController::changePlan()`,
 * see that method's own docblock for why "start if missing" folds into
 * the same action rather than needing a separate route here).
 *
 * `SubscriptionLifecycle::expire()` is deliberately NOT exposed here -
 * task section 15's own required-action list does not include it, and
 * "manual expiration through the lifecycle service is sufficient for
 * foundation" (task section 12) does not require a UI button for it.
 *
 * Every method here reads/writes ONLY the central `subscriptions` table
 * (via `SubscriptionLifecycle`, `Subscription`'s own `CentralConnection`)
 * - nothing here ever queries a tenant commerce database.
 */
class SubscriptionController
{
    public function activate(Tenant $tenant, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        $subscription = Subscription::currentFor($tenant);

        if (! $subscription) {
            return back()->withErrors(['subscription' => "Tenant [{$tenant->getTenantKey()}] has no subscription to activate."]);
        }

        try {
            $lifecycle->activate($subscription);
        } catch (InvalidSubscriptionTransitionException $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}]'s subscription activated.");
    }

    public function cancelAtPeriodEnd(Tenant $tenant, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        $subscription = Subscription::currentFor($tenant);

        if (! $subscription) {
            return back()->withErrors(['subscription' => "Tenant [{$tenant->getTenantKey()}] has no subscription to cancel."]);
        }

        try {
            $lifecycle->cancelAtPeriodEnd($subscription);
        } catch (InvalidSubscriptionTransitionException $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}]'s subscription will cancel at period end.");
    }

    public function cancelImmediately(Tenant $tenant, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        $subscription = Subscription::currentFor($tenant);

        if (! $subscription) {
            return back()->withErrors(['subscription' => "Tenant [{$tenant->getTenantKey()}] has no subscription to cancel."]);
        }

        try {
            $lifecycle->cancelImmediately($subscription);
        } catch (InvalidSubscriptionTransitionException $e) {
            return back()->withErrors(['subscription' => $e->getMessage()]);
        }

        return back()->with('status', "Tenant [{$tenant->getTenantKey()}]'s subscription canceled immediately.");
    }
}
