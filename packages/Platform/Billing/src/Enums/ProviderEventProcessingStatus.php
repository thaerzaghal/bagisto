<?php

declare(strict_types=1);

namespace Platform\Billing\Enums;

/**
 * TASK-ARCH-019 (task section 9). The processing state of one
 * `BillingProviderEvent` row - deliberately minimal, matching
 * `PaymentStatus`/`SubscriptionStatus`'s own precedent for representing
 * only states this platform has real semantics for.
 *
 *   Received  - the event row exists but processing has not committed
 *               yet (set inside the same transaction as the row's own
 *               creation - see WebhookEventProcessor's own docblock for
 *               why this state is never actually observable after a
 *               successful request, only after a genuine mid-transaction
 *               crash, and is what a retry re-enters processing from).
 *   Processed - the event was successfully acted on (or correctly
 *               determined to need no action, e.g. the Payment was
 *               already in the target state) and its Payment/Subscription
 *               side effects (if any) are durably committed.
 *   Ignored   - a recognized-but-not-actionable event type (task section
 *               12: "acknowledge safely... but do not treat it as
 *               payment success") - no Payment/Subscription mutation was
 *               ever attempted.
 *   Rejected  - the event correlated to no known Payment, or its
 *               amount/currency did not match the correlated Payment's
 *               own authoritative snapshot (task section 17) - recorded
 *               for audit, never acted on.
 */
enum ProviderEventProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
}
