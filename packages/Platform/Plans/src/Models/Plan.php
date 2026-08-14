<?php

declare(strict_types=1);

namespace Platform\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-008. A SaaS plan (FREE/BASIC/PRO, ...) - central-database-only
 * (see docs/architecture/feature-limits.md's "Domain boundary": plan
 * definitions must never be duplicated into tenant commerce databases).
 *
 * Uses `Stancl\Tenancy\Database\Concerns\CentralConnection` - the exact
 * same mechanism `Stancl\Tenancy\Database\Models\Tenant` (the base class
 * Platform\Tenancy\Models\Tenant extends) already relies on:
 * `getConnectionName()` reads `config('tenancy.database.central_connection')`
 * fresh on every call, so every query through this model always hits the
 * central connection regardless of whatever tenant context is (or isn't)
 * currently active - no need to call tenancy()->central() or end tenancy
 * to read plan data safely from inside a tenant request. See
 * Platform\Plans\Services\TenantEntitlements and TenantEntitlementsTest.php
 * ("entitlements can be queried during an active tenant context...") for
 * the live proof.
 *
 * Deliberately NOT storing price_monthly/price_yearly/trial_days here yet
 * (docs/architecture/subscriptions.md sketches those on the Phase-0-era
 * `subscription_plans` table) - this task explicitly excludes billing/
 * subscription concerns; those columns are a simple additive migration
 * whenever Phase 10/11 actually need them, not something to guess at now.
 */
class Plan extends Model
{
    use CentralConnection;

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }
}
