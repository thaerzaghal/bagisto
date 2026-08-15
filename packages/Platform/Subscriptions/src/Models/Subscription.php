<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-016. A tenant's lifecycle/billing-state record - central-
 * database-only (see the `create_subscriptions_table` migration's own
 * docblock for the full domain-boundary reasoning, matching `Plan`/
 * `PlanFeature`'s already-established pattern). Uses the same
 * `Stancl\Tenancy\Database\Concerns\CentralConnection` mechanism -
 * `getConnectionName()` reads `config('tenancy.database.central_connection')`
 * fresh on every call, so every query here always hits the central
 * connection regardless of active tenant context, with no
 * `tenancy()->end()`/`tenancy()->central()` needed anywhere - the same
 * guarantee `Plan`/`PlanFeature`/`PlatformUser` already rely on.
 *
 * `status` IS cast to `SubscriptionStatus` (unlike `PlanFeature::
 * feature_code`, which deliberately stays an un-cast string) - the four
 * status values are a closed set this application fully controls (see
 * that enum's own docblock), matching `PlanFeature::type`'s own
 * precedent for exactly this same distinction.
 *
 * PROVIDER-NEUTRAL BY DESIGN: no column here has any payment-provider
 * meaning - see the migration's own docblock.
 */
class Subscription extends Model
{
    use CentralConnection;

    protected $table = 'subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'starts_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'cancelled_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Resolves the tenant's single current subscription row, or null if
     * it has none yet - the "one current subscription per tenant"
     * invariant (database-enforced via a unique constraint on
     * `tenant_id`, see the migration) means this is always a plain,
     * unambiguous lookup, never a "latest of several" query.
     */
    public static function currentFor(Tenant $tenant): ?self
    {
        return static::where('tenant_id', $tenant->getTenantKey())->first();
    }
}
