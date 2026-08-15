<?php

declare(strict_types=1);

namespace Platform\Billing\Enums;

/**
 * TASK-ARCH-018 (task section 6). Deliberately minimal - only the states
 * this platform actually has semantics for, not Stripe's complete
 * PaymentIntent status set.
 *
 * Stripe PaymentIntent status mapping (see
 * Platform\Billing\Adapters\StripePaymentProvider::mapStatus() for the
 * real, tested mapping - documented here for a future adapter author's
 * reference, not duplicated logic):
 *   requires_payment_method, requires_confirmation, processing,
 *   requires_capture -> Pending
 *   requires_action                                 -> RequiresAction
 *   succeeded                                        -> Succeeded
 *   canceled                                          -> Canceled
 * `Refunded` has no PaymentIntent equivalent - Stripe represents a refund
 * as a separate Refund object; this platform sets it explicitly once a
 * refund is recorded against an already-Succeeded payment (the operation
 * itself is TASK-ARCH-019/later scope - the status exists now only so the
 * schema/enum doesn't need a later migration to add it).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
