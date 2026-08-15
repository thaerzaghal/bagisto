<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\InactivePlanException;
use Platform\Billing\Exceptions\InactivePlanPriceException;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-019. The one entry point for initiating a checkout - the
 * ONLY caller of `PaymentProvider::createCheckout()` anywhere in this
 * codebase, and the only place a checkout redirect URL is produced.
 * Reuses `Platform\Billing\Services\BillingService` for Payment creation
 * (task section 2, explicit: "do not create duplicate payment/domain
 * logic") - never re-implements ownership/active-price validation itself.
 *
 * NEVER TRUST THE BROWSER REDIRECT (task section 0/14/23, the central
 * invariant this whole task builds against): the `successUrl` this class
 * builds and hands to the provider is a plain "return here" address - it
 * carries a `payment` identifier for the return page to look up and
 * DISPLAY, never a claim this class or the provider is expected to honor
 * as proof of anything. Only `Platform\Billing\Services\
 * WebhookEventProcessor`, acting on a signature-verified provider event,
 * may ever transition a Payment to Succeeded.
 *
 * DOUBLE-CLICK IDEMPOTENCY (task section 21): if the tenant already has a
 * Pending Payment for the exact same `(tenant, plan_price)` pair, THAT
 * row is reused (a fresh checkout session is created against it) instead
 * of creating a second, redundant Payment - deliberately the simplest
 * rule that prevents duplicate financial records without any
 * payment-intent-orchestration machinery. A Payment already Succeeded/
 * Failed/Canceled/RequiresAction for the same pair does NOT block a new
 * attempt - a fresh Payment row is created for those, matching
 * `SubscriptionLifecycle`'s own "restart in place only from a terminal
 * state" precedent.
 */
class CheckoutService
{
    public function __construct(
        protected BillingService $billingService,
        protected PaymentProvider $provider,
    ) {}

    /**
     * @return array{payment: Payment, redirectUrl: string}
     */
    public function initiate(Tenant $tenant, Subscription $subscription, PlanPrice $planPrice, string $successUrl, string $cancelUrl): array
    {
        if (! $planPrice->is_active) {
            throw new InactivePlanPriceException($planPrice->id);
        }

        if (! $planPrice->plan->is_active) {
            throw new InactivePlanException($planPrice->plan_id);
        }

        $payment = Payment::where('tenant_id', $tenant->getTenantKey())
            ->where('plan_price_id', $planPrice->id)
            ->where('status', PaymentStatus::Pending)
            ->latest('id')
            ->first();

        if (! $payment) {
            $payment = $this->billingService->createPendingPayment($tenant, $subscription, $planPrice);
        }

        $checkout = $this->provider->createCheckout($payment, $successUrl, $cancelUrl);

        $payment->forceFill(['provider_reference' => $checkout->providerReference])->save();

        return ['payment' => $payment, 'redirectUrl' => $checkout->redirectUrl];
    }
}
