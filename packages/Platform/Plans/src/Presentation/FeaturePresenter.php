<?php

declare(strict_types=1);

namespace Platform\Plans\Presentation;

use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\PlanFeature;

/**
 * TASK-ARCH-009. The smallest maintainable presentation layer for a
 * PlanFeature row - a raw `feature_code`/`type`/`value` should never be
 * dumped directly into a view. Deliberately NOT a localization/metadata
 * framework: a single label lookup array (falling back to a readable
 * auto-derived label for any feature code not yet enumerated, since
 * `plan_features.feature_code` is intentionally not limited to
 * `FeatureCode`'s known cases - see that enum's own docblock) and a
 * single value-formatting switch on `FeatureType`.
 */
class FeaturePresenter
{
    protected const array LABELS = [
        'products.limit' => 'Products',
        'staff.limit' => 'Staff',
        'domains.custom' => 'Custom Domain',
        'reports.advanced' => 'Advanced Reports',
    ];

    public static function label(PlanFeature|string $feature): string
    {
        $code = $feature instanceof PlanFeature ? $feature->feature_code : $feature;

        if (isset(self::LABELS[$code])) {
            return self::LABELS[$code];
        }

        // Fallback for a feature code not in the label table above (e.g. a
        // centrally-added feature this class hasn't been updated for yet):
        // "reports.advanced" -> "Reports Advanced" - readable, not raw.
        return ucwords(str_replace(['.', '_'], ' ', $code));
    }

    public static function value(PlanFeature $feature): string
    {
        return match ($feature->type) {
            FeatureType::Boolean => $feature->value ? 'Enabled' : 'Disabled',
            FeatureType::Unlimited => 'Unlimited',
            FeatureType::Numeric => (string) $feature->value,
        };
    }
}
