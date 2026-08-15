<?php

declare(strict_types=1);

namespace Platform\Billing\Services;

use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\InvalidPaymentTransitionException;
use Platform\Billing\Models\Payment;

/**
 * TASK-ARCH-018. The one explicit entry point for every `Payment` status
 * transition - mirrors `Platform\Subscriptions\Services\SubscriptionLifecycle`'s
 * shape exactly. No controller anywhere calls this in TASK-ARCH-018 - no
 * checkout/webhook HTTP endpoint exists yet (TASK-ARCH-019's job) - which
 * is precisely the point: a Payment's success/failure state can only ever
 * be recorded through this internal, domain-level service, never through
 * any public client-facing mutation path (task section 23: "do not add a
 * public 'mark paid' endpoint").
 *
 * TRANSITION MATRIX (the complete set; every other transition throws
 * `InvalidPaymentTransitionException`):
 *
 *   Pending                     -> RequiresAction   markRequiresAction()
 *   Pending/RequiresAction      -> Succeeded         markSucceeded()
 *   Pending/RequiresAction      -> Failed            markFailed()
 *   Pending/RequiresAction      -> Canceled          markCanceled()
 *   Succeeded                   -> Refunded          markRefunded()
 *
 * FAILED-PAYMENT POLICY - deliberately NOT here (task section 14/19,
 * strict): `markFailed()` changes ONLY this Payment's own status/
 * `failure_code`/`failure_message`/`failed_at`. It does not suspend the
 * tenant, does not touch `Subscription` status/plan, does not cancel or
 * expire anything - failed-payment consequences are an explicitly
 * deferred, separate policy decision (see docs/architecture/billing.md).
 * The same independence applies to `markSucceeded()` in this task: no
 * caller here invokes `SubscriptionLifecycle::changePlan()` - wiring a
 * successful payment to an actual plan change is TASK-ARCH-019's job,
 * once a real checkout/webhook flow exists to call it from.
 */
class PaymentLifecycle
{
    public function markRequiresAction(Payment $payment, PaymentResult $result): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new InvalidPaymentTransitionException($payment->id, $payment->status, 'markRequiresAction');
        }

        $payment->forceFill([
            'status' => PaymentStatus::RequiresAction,
            'provider_reference' => $result->providerReference,
        ])->save();
    }

    public function markSucceeded(Payment $payment, PaymentResult $result): void
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
            throw new InvalidPaymentTransitionException($payment->id, $payment->status, 'markSucceeded');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Succeeded,
            'provider_reference' => $result->providerReference,
            'paid_at' => now(),
        ])->save();
    }

    public function markFailed(Payment $payment, PaymentResult $result): void
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
            throw new InvalidPaymentTransitionException($payment->id, $payment->status, 'markFailed');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'provider_reference' => $result->providerReference,
            'failure_code' => $result->failureCode,
            'failure_message' => $result->failureMessage,
            'failed_at' => now(),
        ])->save();
    }

    public function markCanceled(Payment $payment): void
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RequiresAction], true)) {
            throw new InvalidPaymentTransitionException($payment->id, $payment->status, 'markCanceled');
        }

        $payment->forceFill(['status' => PaymentStatus::Canceled])->save();
    }

    public function markRefunded(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new InvalidPaymentTransitionException($payment->id, $payment->status, 'markRefunded');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'refunded_at' => now(),
        ])->save();
    }
}
