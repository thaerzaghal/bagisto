<?php

declare(strict_types=1);

namespace Platform\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Plans\Enums\FeatureType;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-008. One row per (plan, feature_code) - see the
 * create_plan_features_table migration's docblock for the generic
 * key-value design and why a single nullable `value` column represents
 * all three FeatureType semantics. Central-only, same CentralConnection
 * mechanism as Plan (see that model's docblock).
 *
 * `feature_code` is a plain string column, NOT cast to
 * Platform\Plans\Enums\FeatureCode - see that enum's own docblock for why
 * the schema deliberately stays looser than the enum (centrally
 * configured entitlements, not a fixed code list baked into a migration).
 * `type` IS cast to FeatureType: unlike feature codes, the three type
 * semantics are closed by design (Platform\Plans\Services\
 * TenantEntitlements has fixed, type-specific behavior for each), so
 * typo-protection here is worth the tighter coupling.
 */
class PlanFeature extends Model
{
    use CentralConnection;

    protected $table = 'plan_features';

    protected $fillable = [
        'plan_id',
        'feature_code',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'type' => FeatureType::class,
            'value' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
