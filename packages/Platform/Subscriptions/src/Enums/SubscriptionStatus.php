<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Enums;

/**
 * TASK-ARCH-016. Deliberately minimal - only the four statuses this
 * foundation task can actually control manually today, per the task's
 * own explicit instruction not to add statuses "simply because Stripe
 * has them." In particular, `PastDue` is NOT included: detecting "a
 * payment failed" fundamentally requires a payment-provider signal
 * (webhook or reconciliation job, see docs/architecture/billing.md) that
 * does not exist anywhere in this codebase yet - adding the enum case
 * now with nothing that could ever transition into it would be exactly
 * the kind of speculative, unused surface this project's own established
 * discipline avoids (see PlanSeeder's FREE/BASIC/PRO being real, exercised
 * data rather than a bigger illustrative catalog, or TenantStatus's own
 * Deleting/Deleted - those exist because a real design already
 * anticipates the transition, unlike PastDue here). Adding it later is a
 * simple additive enum case - `subscriptions.status` is a plain string
 * column (see the migration), not an enum-typed database column, so no
 * migration is needed to introduce a new case.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
