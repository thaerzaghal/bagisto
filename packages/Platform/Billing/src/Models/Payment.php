<?php

declare(strict_types=1);

namespace Platform\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-018. A tenant's billing-attempt record - central-database-only
 * (see `create_payments_table`'s own docblock), matching `Subscription`'s
 * own `CentralConnection` pattern exactly.
 *
 * HISTORICAL SNAPSHOT, NOT A LIVE JOIN (task section 10, load-bearing):
 * `amount_minor`/`currency` are copied onto THIS row at creation time by
 * `Platform\Billing\Services\BillingService` and never re-derived from
 * `plan_price_id` afterward - a PlanPrice's amount changing later (or the
 * row being deleted, hence `plan_price_id`'s `nullOnDelete()`) must never
 * alter what a real historical Payment says it charged/attempted to
 * charge. Nothing in this domain joins `plan_prices` to reconstruct a
 * Payment's money.
 *
 * `provider_metadata` is raw, provider-specific, audit/debugging-only
 * JSON (task section 5, explicit) - no business logic anywhere in this
 * codebase reads it; `status`/`amount_minor`/`currency`/`failure_*` above
 * are the sole authoritative domain data.
 */
class Payment extends Model
{
    use CentralConnection;

    protected $table = 'payments';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'plan_price_id',
        'provider',
        'provider_reference',
        'status',
        'amount_minor',
        'currency',
        'failure_code',
        'failure_message',
        'paid_at',
        'failed_at',
        'refunded_at',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'provider_metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }
}
