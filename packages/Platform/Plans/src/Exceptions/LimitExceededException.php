<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-012. Thrown by Platform\Plans\Services\TenantLimits::
 * assertWithinLimit() when a numeric feature's current usage has already
 * reached its plan-configured cap. Deliberately carries only structured
 * data (feature code, limit, current usage) and a generic internal
 * message - it does NOT know it's specifically about "products", since
 * TenantLimits/this exception are feature-agnostic (reusable for any
 * future numeric limit, e.g. staff.limit). A feature-specific, friendly
 * user-facing message (e.g. "Your current plan allows up to 50
 * products.") is built by whichever caller actually knows the feature's
 * real-world meaning - see Platform\Enforcement\Providers\
 * EnforcementServiceProvider's exception renderer for the products.limit
 * case.
 */
class LimitExceededException extends RuntimeException implements EntitlementException
{
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
