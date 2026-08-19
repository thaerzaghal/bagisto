<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use Platform\Plans\Exceptions\Concerns\RendersAsEntitlementFailure;
use RuntimeException;

/**
 * TASK-ARCH-012. Thrown by Platform\Plans\Services\TenantLimits::
 * assertWithinLimit() when a numeric feature's current usage has already
 * reached its plan-configured cap. Deliberately carries only structured
 * data (feature code, limit, current usage) - the HTTP 422 rendering
 * itself (including the one feature-specific "up to N products" message)
 * lives in the shared `RendersAsEntitlementFailure` trait (TASK-MVP-013,
 * RISK_REGISTER.md R72) rather than a caller-registered renderer, which
 * is what the products-specific wording historically depended on and is
 * still built here, not in `Platform\Enforcement` - see that trait's own
 * docblock for why this replaced the previous mechanism, and why this is
 * not a "feature-agnostic" boundary violation (the mapping is keyed off
 * this package's own `FeatureCode` enum, not any `Webkul\Product`
 * knowledge).
 */
class LimitExceededException extends RuntimeException implements EntitlementException
{
    use RendersAsEntitlementFailure;

    public function __construct(
        public readonly string $feature,
        public readonly int $limit,
        public readonly int $currentUsage,
    ) {
        parent::__construct(
            "Feature [{$feature}] limit reached (limit: {$limit}, current usage: {$currentUsage})."
        );
    }
}
