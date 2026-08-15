<?php

declare(strict_types=1);

namespace Platform\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Billing\Enums\ProviderEventProcessingStatus;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-019. The idempotency ledger row for one inbound provider
 * event - see `create_billing_provider_events_table`'s own docblock for
 * the full invariant. Central-database-only, matching `Payment`/
 * `PlanPrice`'s own `CentralConnection` pattern.
 */
class BillingProviderEvent extends Model
{
    use CentralConnection;

    protected $table = 'billing_provider_events';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payment_id',
        'processing_status',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processing_status' => ProviderEventProcessingStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
