<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\InactivePlanPriceException;
use Platform\Billing\Exceptions\PaymentOwnershipException;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-018 (task section 11). The one entry point for creating a new
 * local `Payment` row - the entry point TASK-ARCH-019's later checkout
 * flow calls BEFORE ever talking to a `Platform\Billing\Contracts\
 * PaymentProvider` implementation, mirroring `Platform\Subscriptions\
 * Services\SubscriptionLifecycle`/`Platform\Tenancy\Services\
 * TenantLifecycle`'s "one explicit service, no controller mutates the
 * model directly" shape.
 *
 * AUTHORITATIVE PRICING ONLY (task section 11/23, strict): `amount_minor`/
 * `currency` are ALWAYS copied from the given `PlanPrice`, never accepted
 * as separate parameters - there is structurally no code path here for a
 * caller to pass its own amount/currency, so "a client cannot override
 * the authoritative amount/currency" holds by construction, not by
 * discipline at each call site.
 *
 * OWNERSHIP (task section 12): the given `Subscription` must belong to
 * the given `Tenant` - checked here once, so every future caller (Platform
 * Admin, TASK-ARCH-019's tenant-facing checkout) gets this guarantee for
 * free rather than re-implementing it.
 *
 * PLAN CONSISTENCY - deliberately NOT checked here: `$planPrice->plan_id`
 * is expected to normally DIFFER from `$subscription->plan_id` (the
 * common case is "tenant on Free wants to buy a Pro PlanPrice" - the
 * subscription only moves to the new plan after a confirmed payment, via
 * `Platform\Subscriptions\Services\SubscriptionLifecycle::changePlan()`,
 * which this task does not call). Which `PlanPrice` correctly represents
 * "what the tenant actually selected" is the CALLER's responsibility
 * (e.g. TASK-ARCH-019's checkout controller reading the same plan the
 * tenant clicked "upgrade" on) - this service validates only what it can
 * see: that the price is active and the subscription is genuinely the
 * given tenant's own.
 */
class BillingService
{
    public function createPendingPayment(Tenant $tenant, Subscription $subscription, PlanPrice $planPrice): Payment
    {
        if ($subscription->tenant_id !== $tenant->getTenantKey()) {
            throw new PaymentOwnershipException($tenant->getTenantKey(), $subscription->id);
        }

        if (! $planPrice->is_active) {
            throw new InactivePlanPriceException($planPrice->id);
        }

        return Payment::create([
            'tenant_id' => $tenant->getTenantKey(),
            'subscription_id' => $subscription->id,
            'plan_price_id' => $planPrice->id,
            'provider' => (string) config('platform-billing.provider'),
            'status' => PaymentStatus::Pending,
            'amount_minor' => $planPrice->amount_minor,
            'currency' => $planPrice->currency,
        ]);
    }
}
