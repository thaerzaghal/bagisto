<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\DTOs\WebhookEvent;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Enums\ProviderEventProcessingStatus;
use Platform\Billing\Models\BillingProviderEvent;
use Platform\Billing\Models\Payment;
use Platform\Subscriptions\Services\SubscriptionLifecycle;

/**
 * TASK-ARCH-019 (task section 9/15/16). The one entry point that turns a
 * verified `WebhookEvent` into local state changes - the ONLY caller of
 * `PaymentLifecycle::markSucceeded()`/`markFailed()`/`markCanceled()` and
 * `SubscriptionLifecycle::changePlan()` anywhere in the checkout/webhook
 * flow. `Platform\Billing\Http\Controllers\StripeWebhookController` never
 * mutates a Payment/Subscription itself - it verifies the signature, then
 * delegates entirely to this class (task section 10/13, explicit: "do
 * not mutate Subscription directly from the HTTP controller").
 *
 * ATOMICITY (task section 16): the idempotency-ledger write, the Payment
 * transition, and the Subscription plan change all happen inside ONE
 * `DB::transaction()` - not three separately-committed steps. This is
 * what makes "processing crashes after receiving but before completion"
 * (task section 15) safe without any bespoke intermediate-state tracking:
 * a crash anywhere inside the closure rolls back EVERYTHING (MySQL/InnoDB
 * atomicity), leaving the `BillingProviderEvent` row either nonexistent
 * or still `Received` from a genuinely incomplete prior attempt - both of
 * which correctly allow a clean retry from scratch. There is no
 * "Payment succeeded but plan not yet changed" state that can ever be
 * durably observed.
 *
 * IDEMPOTENCY (task section 9/15, the load-bearing property): a
 * `BillingProviderEvent` already `Processed`/`Ignored` short-circuits
 * immediately - no Payment/Subscription mutation is attempted a second
 * time for the same `(provider, provider_event_id)` pair. A genuine
 * concurrent-delivery race on the row's own creation is caught via
 * `UniqueConstraintViolationException` (the database-level `unique(
 * provider, provider_event_id)` constraint is the real backstop - see the
 * migration's own docblock) and treated as a safe no-op, not an error.
 *
 * AMBIGUOUS CORRELATION NEVER ACTIVATES A PLAN (task section 17, strict):
 * an event with no recognized `providerReference` match against any
 * `Payment`, or whose provider-reported `amountMinor`/`currency` disagree
 * with the correlated Payment's own authoritative snapshot, is recorded
 * `Rejected` and never mutates anything - "never activate a plan on
 * ambiguous correlation" holds structurally, not by convention.
 */
class WebhookEventProcessor
{
    public function __construct(
        protected PaymentLifecycle $paymentLifecycle,
        protected SubscriptionLifecycle $subscriptionLifecycle,
    ) {}

    public function process(string $provider, WebhookEvent $event): void
    {
        try {
            DB::connection('mysql')->transaction(function () use ($provider, $event) {
                $providerEvent = BillingProviderEvent::where('provider', $provider)
                    ->where('provider_event_id', $event->providerEventId)
                    ->lockForUpdate()
                    ->first();

                if ($providerEvent && in_array($providerEvent->processing_status, [
                    ProviderEventProcessingStatus::Processed,
                    ProviderEventProcessingStatus::Ignored,
                    ProviderEventProcessingStatus::Rejected,
                ], true)) {
                    // Already safely, durably handled - idempotent no-op.
                    return;
                }

                $providerEvent ??= BillingProviderEvent::create([
                    'provider' => $provider,
                    'provider_event_id' => $event->providerEventId,
                    'event_type' => $event->eventType,
                    'processing_status' => ProviderEventProcessingStatus::Received,
                    'received_at' => now(),
                ]);

                if ($event->status === null || $event->providerReference === null) {
                    // An event type this platform does not act on (task
                    // section 12) - acknowledge safely, no mutation.
                    $providerEvent->forceFill([
                        'processing_status' => ProviderEventProcessingStatus::Ignored,
                        'processed_at' => now(),
                    ])->save();

                    return;
                }

                $payment = Payment::where('provider', $provider)
                    ->where('provider_reference', $event->providerReference)
                    ->first();

                if (! $payment
                    || ($event->amountMinor !== null && $event->amountMinor !== $payment->amount_minor)
                    || ($event->currency !== null && $event->currency !== $payment->currency)
                ) {
                    $providerEvent->forceFill([
                        'processing_status' => ProviderEventProcessingStatus::Rejected,
                        'payment_id' => $payment?->id,
                        'processed_at' => now(),
                    ])->save();

                    return;
                }

                $this->applyToPayment($payment, $event);

                $providerEvent->forceFill([
                    'processing_status' => ProviderEventProcessingStatus::Processed,
                    'payment_id' => $payment->id,
                    'processed_at' => now(),
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event raced us on the
            // initial row creation - the other request owns processing it.
        }
    }

    protected function applyToPayment(Payment $payment, WebhookEvent $event): void
    {
        if ($event->status === PaymentStatus::Succeeded) {
            if ($payment->status === PaymentStatus::Succeeded) {
                // Already succeeded (a prior, fully-committed processing
                // of an earlier duplicate event) - nothing left to do.
                return;
            }

            $this->paymentLifecycle->markSucceeded(
                $payment,
                new PaymentResult($event->providerReference, PaymentStatus::Succeeded)
            );

            $subscription = $payment->subscription;
            $planPrice = $payment->planPrice;

            // Task section 18: a Payment is not structurally guaranteed to
            // have both - defensive, not expected to be null on any real
            // checkout-created Payment (CheckoutService always sets both).
            if ($subscription && $planPrice) {
                $this->subscriptionLifecycle->changePlan($subscription, $planPrice->plan);
            }

            return;
        }

        if (in_array($event->status, [PaymentStatus::Failed, PaymentStatus::Canceled], true)) {
            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
                // Already in a terminal state - nothing to do.
                return;
            }

            if ($event->status === PaymentStatus::Failed) {
                $this->paymentLifecycle->markFailed(
                    $payment,
                    new PaymentResult($event->providerReference, PaymentStatus::Failed)
                );
            } else {
                $this->paymentLifecycle->markCanceled($payment);
            }
        }
    }
}
