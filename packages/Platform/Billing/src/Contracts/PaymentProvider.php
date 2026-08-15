<?php

declare(strict_types=1);

namespace Platform\Billing\Contracts;

use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\Models\Payment;

/**
 * TASK-ARCH-018 (task section 2/3/21/26). The one boundary between this
 * platform's provider-neutral billing domain and any specific payment
 * provider's SDK. Deliberately minimal and expressed in OUR terms, not
 * Stripe's (no "PaymentIntent"/"checkout session"/"charge" naming) - a
 * future Palestinian bank adapter implements exactly these same two
 * methods, nothing more:
 *
 *   class BankXPaymentProvider implements PaymentProvider
 *
 * with BILLING_PROVIDER=bank_x added as one new case in
 * Platform\Billing\Services\BillingProviderResolver::resolve(). No change
 * is required to: the `payments`/`plan_prices` schema, Platform\
 * Subscriptions, Platform\Plans, TenantPlanAssignment, TenantEntitlements,
 * product enforcement, or tenant lifecycle - none of those know a
 * PaymentProvider implementation exists at all.
 *
 * Checkout-initiation/webhook-specific methods are deliberately NOT part
 * of this contract yet (task section 2, explicit: "if checkout/webhook-
 * specific methods are not needed until TASK-ARCH-019, do not add
 * speculative methods now"). The two methods below are the minimum that
 * already has a real, non-speculative caller in THIS task
 * (Platform\Billing\Services\BillingService and its tests) and remain
 * directly useful to TASK-ARCH-019's later checkout/webhook flow -
 * `createPayment()` to start a real provider-side payment attempt,
 * `retrievePayment()` to re-verify a payment's true state directly from
 * the provider rather than trusting any client-supplied signal (task
 * section 14/23's "never trust the browser redirect" invariant).
 */
interface PaymentProvider
{
    /**
     * Start a payment attempt with the provider for a Payment record this
     * platform has already created (status Pending, amount/currency
     * already authoritatively derived from PlanPrice by BillingService -
     * never client-supplied). Returns the provider's own resulting state,
     * mapped to our provider-neutral PaymentResult.
     */
    public function createPayment(Payment $payment): PaymentResult;

    /**
     * Re-query the provider's current view of a previously-created
     * payment, by the provider_reference this platform stored after
     * createPayment(). Never trusted blindly elsewhere - callers
     * (TASK-ARCH-019's webhook/reconciliation flow) re-verify against the
     * provider itself rather than accepting an unverified client claim of
     * success.
     */
    public function retrievePayment(string $providerReference): PaymentResult;
}
