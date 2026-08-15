<?php

declare(strict_types=1);

namespace Platform\Billing\Contracts;

use Platform\Billing\DTOs\CheckoutSession;
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
 * `createCheckout()` was added in TASK-ARCH-019, once a real caller
 * (`Platform\Billing\Services\CheckoutService`) existed to justify its
 * exact shape - not spec'd speculatively ahead of time (task section 2's
 * original instruction). Webhook signature verification is deliberately
 * NOT part of THIS contract - see `Platform\Billing\Contracts\
 * WebhookVerifier`'s own docblock for why that is a separate,
 * independent abstraction (task section 26).
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

    /**
     * Create a hosted, provider-owned checkout page for the given Payment
     * (amount/currency already authoritatively set - never accepted here
     * as parameters) and return a redirect URL. The provider itself
     * collects payment details - this platform's own UI never handles
     * card data directly. `$successUrl`/`$cancelUrl` are plain return
     * URLs this platform controls; see `Platform\Billing\Services\
     * CheckoutService`'s own docblock for why the success URL must never
     * be trusted as proof of payment.
     */
    public function createCheckout(Payment $payment, string $successUrl, string $cancelUrl): CheckoutSession;
}
