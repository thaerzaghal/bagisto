<?php

declare(strict_types=1);

namespace Platform\Billing\Adapters;

use Platform\Billing\Contracts\WebhookVerifier;
use Platform\Billing\DTOs\WebhookEvent;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\InvalidWebhookSignatureException;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * TASK-ARCH-019 (task section 11/26). Uses Stripe's own official
 * signature-verification mechanism (`\Stripe\Webhook::constructEvent()`,
 * which internally HMAC-SHA256-verifies the `Stripe-Signature` header
 * against `STRIPE_WEBHOOK_SECRET`) - no hand-rolled HMAC parsing.
 *
 * EVENT SCOPE (task section 12, "handle only the minimum Stripe events
 * required for this checkout flow" - deliberately not every Stripe event
 * type): only `checkout.session.completed` (the authoritative "this
 * Checkout Session finished" signal for the "payment" mode session
 * `StripePaymentProvider::createCheckout()` creates) and
 * `checkout.session.expired` (the session's own expiration passed without
 * completion - Stripe's documented signal for "the customer never
 * finished paying," mapped to Canceled) are mapped to a real
 * `PaymentStatus`. Every other event type - including
 * `payment_intent.payment_failed`, which CAN fire mid-session while
 * Stripe's own hosted page is still prompting the customer to retry a
 * declined card - is deliberately left unmapped (`status: null`) so
 * `Platform\Billing\Services\WebhookEventProcessor` acknowledges it
 * safely without ever prematurely failing a Payment the customer might
 * still complete seconds later on Stripe's own retry UI.
 */
class StripeWebhookVerifier implements WebhookVerifier
{
    public function verify(string $payload, string $signatureHeader): WebhookEvent
    {
        $secret = (string) config('platform-billing.stripe.webhook_secret');

        if ($secret === '') {
            throw new MissingProviderCredentialsException('stripe', 'STRIPE_WEBHOOK_SECRET');
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhookSignatureException($e->getMessage(), previous: $e);
        }

        return $this->mapEvent($event);
    }

    protected function mapEvent(Event $event): WebhookEvent
    {
        $object = $event->data->object;

        return match ($event->type) {
            'checkout.session.completed' => new WebhookEvent(
                providerEventId: $event->id,
                eventType: $event->type,
                providerReference: $object->id,
                status: $object->payment_status === 'paid' ? PaymentStatus::Succeeded : null,
                amountMinor: $object->amount_total,
                currency: $object->currency !== null ? strtoupper($object->currency) : null,
            ),
            'checkout.session.expired' => new WebhookEvent(
                providerEventId: $event->id,
                eventType: $event->type,
                providerReference: $object->id,
                status: PaymentStatus::Canceled,
                amountMinor: $object->amount_total,
                currency: $object->currency !== null ? strtoupper($object->currency) : null,
            ),
            default => new WebhookEvent(
                providerEventId: $event->id,
                eventType: $event->type,
            ),
        };
    }
}
