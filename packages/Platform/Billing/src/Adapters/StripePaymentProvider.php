<?php

declare(strict_types=1);

namespace Platform\Billing\Adapters;

use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Platform\Billing\Models\Payment;
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
 * NOT IMPLEMENTED HERE, DELIBERATELY (task section 15/28, out of scope
 * until TASK-ARCH-019): the browser checkout redirect flow and webhook
 * endpoint processing. `createPayment()` creates a real server-side
 * PaymentIntent (proving the SDK call/response-mapping path end-to-end)
 * without building any client-facing confirmation UI.
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
