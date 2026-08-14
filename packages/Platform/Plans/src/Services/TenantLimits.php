<?php

declare(strict_types=1);

namespace Platform\Plans\Services;

use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Exceptions\LimitExceededException;
use Platform\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * TASK-ARCH-012. The small, reusable enforcement foundation - a thin
 * layer over TenantEntitlements (TASK-ARCH-008), which already resolves
 * `tenant -> plan -> plan_features` correctly against the central
 * connection regardless of active tenant context (see that class's own
 * docblock). TenantLimits adds nothing to that resolution; it only adds
 * the "is a given USAGE count still within the resolved limit" question,
 * on top of an already-proven-correct read.
 *
 * Deliberately feature-agnostic and product-agnostic - knows nothing
 * about "products" specifically. The first (and, per this task's scope,
 * only) real consumer is products.limit, wired up in the separate
 * Platform\Enforcement package (see that package's docblocks) - not
 * here, so this class stays reusable for any future numeric limit
 * without carrying product-specific assumptions.
 *
 * Mirrors TenantEntitlements' own current()/for() split exactly, for the
 * same reason: current() is the common case (enforcement always runs
 * inside an active tenant request), for() lets platform-side code (e.g.
 * a future admin action) check an explicit tenant's limits without an
 * active tenancy.
 */
class TenantLimits
{
    protected function __construct(protected readonly Tenant $tenant)
    {
    }

    public static function current(): self
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException(
                'TenantLimits::current() requires an initialized tenant context. Use TenantLimits::for($tenant) to check limits for a specific tenant outside an active tenancy.'
            );
        }

        /** @var Tenant $tenant */
        $tenant = tenant();

        return new self($tenant);
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * @return int|null Remaining capacity, or null if the feature is
     *                    unlimited. Never negative (clamped to 0) - a
     *                    usage count already past the limit reads as "0
     *                    remaining", not a negative number.
     */
    public function remaining(FeatureCode|string $feature, int $currentUsage): ?int
    {
        $limit = TenantEntitlements::for($this->tenant)->limit($feature);

        return $limit === null ? null : max(0, $limit - $currentUsage);
    }

    /**
     * Throws LimitExceededException if $currentUsage has already reached
     * the resolved numeric limit - the caller is asking "may I create ONE
     * MORE", so `currentUsage >= limit` (not `>`) is the correct
     * boundary: limit=50, currentUsage=49 -> allowed (would become 50);
     * limit=50, currentUsage=50 -> blocked (would become 51).
     *
     * Unlimited (limit === null) always passes. Every other
     * TenantEntitlements failure mode (no plan assigned, feature not
     * configured for this plan, feature configured as the wrong type)
     * propagates UNCAUGHT from ->limit() - per this task's explicit
     * instruction, a missing/misconfigured entitlement must fail
     * according to TenantEntitlements' existing contract, never be
     * silently treated as "unlimited".
     */
    public function assertWithinLimit(FeatureCode|string $feature, int $currentUsage): void
    {
        $limit = TenantEntitlements::for($this->tenant)->limit($feature);

        if ($limit === null) {
            return;
        }

        if ($currentUsage >= $limit) {
            $code = $feature instanceof FeatureCode ? $feature->value : $feature;

            throw new LimitExceededException($code, $limit, $currentUsage);
        }
    }
}
