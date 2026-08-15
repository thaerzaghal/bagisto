<?php

declare(strict_types=1);

namespace Platform\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Billing\Enums\BillingInterval;
use Platform\Plans\Models\Plan;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-018. Central-database-only (see `Platform\Plans\Models\Plan`'s
 * own docblock for why plan/pricing data structurally never belongs in a
 * tenant commerce database) - a SEPARATE table from `plans`, not
 * `price_monthly`/`price_yearly` columns on Plan itself (task section 8).
 *
 * `amount_minor` is always an INTEGER count of the currency's smallest
 * unit (e.g. `1000` = $10.00 for a 2-decimal currency) - never a float,
 * anywhere in this domain. See `Platform\Billing\Models\Payment`'s own
 * docblock for the historical-snapshot reasoning this feeds into.
 */
class PlanPrice extends Model
{
    use CentralConnection;

    protected $table = 'plan_prices';

    protected $fillable = [
        'plan_id',
        'billing_interval',
        'interval_count',
        'amount_minor',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'interval_count' => 'integer',
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
