<?php

declare(strict_types=1);

namespace Platform\Billing\Adapters;

use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\DTOs\CheckoutSession;
use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Platform\Billing\Models\Payment;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * TASK-ARCH-018 (task section 15). The first reference `PaymentProvider`
 * implementation - Stripe SANDBOX/TEST MODE only (task section 0.B/23),
 * never a production commitment. Uses `stripe/stripe-php` (the raw SDK,
 * already a dependency) DIRECTLY, not `laravel/cashier` - Cashier owns its
 * own Stripe-shaped `Subscription`/`Customer` model concepts, which would
 * conflict with this codebase's already-built, provider-neutral
 * `Platform\Subscriptions` domain (task section 15, explicit: "Cashier's
 * subscription ownership must not replace our provider-neutral Subscription
 * domain"). This class exists to prove three things (task section 15):
 * the SDK can be initialized behind our contract, configuration is read
 * correctly, and Stripe SDK objects never leak past `mapPaymentIntentToResult()`
 * - every public method returns only `Platform\Billing\DTOs\PaymentResult`.
 *
 * TASK-ARCH-019 added `createCheckout()` (Stripe Checkout Session, hosted
 * payment page - see that method's own docblock for the payment-vs-
 * subscription-mode decision) - the browser-facing half of this adapter.
 * Webhook signature verification/event parsing is a SEPARATE class,
 * `Platform\Billing\Adapters\StripeWebhookVerifier` (task section 26 -
 * not every future provider shares Stripe's signature concept, so it
 * does not belong on this class or the shared `PaymentProvider` contract).
 *
 * CREDENTIAL FAILURE (task section 4): thrown here, in the constructor,
 * the moment something actually tries to resolve a Stripe provider
 * instance - never during application boot for an unrelated request.
 *
 * TESTING (task section 16, "mocked Stripe client boundary"): this
 * adapter uses `\Stripe\StripeClient` directly with no custom injection
 * seam of its own - tests use Stripe's own supported test mechanism,
 * `\Stripe\ApiRequestor::setHttpClient()` (a real, documented,
 * SDK-provided static override point, reset after every test), so the
 * REAL SDK request/response/deserialization path is exercised with a
 * canned HTTP response instead of a real network call. Pure
 * status-mapping logic is additionally tested directly via
 * `\Stripe\PaymentIntent::constructFrom()` fixtures (no HTTP layer
 * involved at all).
 */
class StripePaymentProvider implements PaymentProvider
{
    protected StripeClient $client;

    public function __construct()
    {
        $secret = (string) config('platform-billing.stripe.secret');

        if ($secret === '') {
            throw new MissingProviderCredentialsException('stripe', 'STRIPE_SECRET');
        }

        $this->client = new StripeClient($secret);
    }

    public function createPayment(Payment $payment): PaymentResult
    {
        $intent = $this->client->paymentIntents->create([
            'amount' => $payment->amount_minor,
            'currency' => strtolower($payment->currency),
            'metadata' => [
                'tenant_id' => $payment->tenant_id,
                'payment_id' => (string) $payment->id,
            ],
        ]);

        return $this->mapPaymentIntentToResult($intent);
    }

    public function retrievePayment(string $providerReference): PaymentResult
    {
        $intent = $this->client->paymentIntents->retrieve($providerReference);

        return $this->mapPaymentIntentToResult($intent);
    }

    /**
     * TASK-ARCH-019 (task section 6). Stripe Checkout Session in "payment"
     * mode (NOT "subscription" mode) - a hosted, one-off payment page,
     * deliberately never creating a Stripe-owned Subscription object.
     * `Platform\Subscriptions` remains the sole subscription lifecycle
     * source of truth; Stripe here only ever processes the payment.
     *
     * The line item is built entirely from `$payment`'s own already-
     * authoritative `amount_minor`/`currency` (never re-read from
     * `PlanPrice` here - `$payment` is already the snapshot) - Stripe
     * itself becomes a second, independent confirmation of the same
     * amount this platform already decided, not a source of new pricing
     * data.
     */
    public function createCheckout(Payment $payment, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => $payment->amount_minor,
                    'product_data' => [
                        'name' => 'Platform subscription payment',
                    ],
                ],
            ]],
            'metadata' => [
                'tenant_id' => $payment->tenant_id,
                'payment_id' => (string) $payment->id,
            ],
        ]);

        return $this->mapSessionToCheckoutSession($session);
    }

    protected function mapSessionToCheckoutSession(Session $session): CheckoutSession
    {
        return new CheckoutSession(
            providerReference: $session->id,
            redirectUrl: (string) $session->url,
        );
    }

    protected function mapPaymentIntentToResult(PaymentIntent $intent): PaymentResult
    {
        return new PaymentResult(
            providerReference: $intent->id,
            status: $this->mapStatus($intent->status),
            failureCode: $intent->last_payment_error?->code,
            failureMessage: $intent->last_payment_error?->message,
        );
    }

    /**
     * See `Platform\Billing\Enums\PaymentStatus`'s own docblock for the
     * documented mapping this method implements.
     */
    protected function mapStatus(string $stripeStatus): PaymentStatus
    {
        return match ($stripeStatus) {
            'requires_payment_method', 'requires_confirmation', 'processing', 'requires_capture' => PaymentStatus::Pending,
            'requires_action' => PaymentStatus::RequiresAction,
            'succeeded' => PaymentStatus::Succeeded,
            'canceled' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };
    }
}
