<?php

declare(strict_types=1);

namespace Platform\Billing\Enums;

/**
 * TASK-ARCH-018 (task section 9). The MVP billing cycles per
 * docs/architecture/billing.md/subscriptions.md - not tied to any
 * provider's own interval identifiers (Stripe's Price objects have their
 * own `interval`/`interval_count` shape; this enum is deliberately
 * separate so a future provider with a different interval model doesn't
 * force a schema change here).
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
