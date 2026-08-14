<?php

declare(strict_types=1);

namespace Platform\Plans\Services;

use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Exceptions\FeatureNotConfiguredException;
use Platform\Plans\Exceptions\FeatureTypeMismatchException;
use Platform\Plans\Exceptions\NoPlanAssignedException;
use Platform\Plans\Models\Plan;
use Platform\Plans\Models\PlanFeature;
use Platform\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * TASK-ARCH-008. The one clean API other modules use to resolve a
 * tenant's SaaS capabilities - callers never need to know the
 * plans/plan_features schema. Deliberately does NOT implement `allows()`
 * as a distinct method from `can()` (both would mean "is this boolean
 * feature on" - the task's own instructions call the exact API "your
 * design decision"); `can()` alone covers that case without an ambiguous
 * duplicate.
 *
 * CENTRAL CONTEXT SAFETY: every query this class makes goes through
 * Plan/PlanFeature, both of which use stancl's own CentralConnection
 * trait (see those models' docblocks) - so calling any method here while
 * a tenant is fully initialized (mid tenant->run(), mid a real tenant
 * HTTP request) reads central data correctly without disturbing the
 * active tenant context at all: no tenancy()->end(), no
 * tenancy()->central() wrapper, nothing to accidentally leave dangling.
 * Proven live in TenantEntitlementsTest.php ("entitlements can be
 * queried during an active tenant context...").
 */
class TenantEntitlements
{
    protected function __construct(protected readonly Tenant $tenant)
    {
    }

    /**
     * Resolve entitlements for the CURRENTLY initialized tenant (the
     * common case: called from inside a tenant request/job/tenant->run()
     * closure). Throws if no tenancy is initialized - callers outside a
     * tenant context must use for() with an explicit Tenant instead of
     * silently resolving nothing.
     */
    public static function current(): self
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException(
                'TenantEntitlements::current() requires an initialized tenant context. Use TenantEntitlements::for($tenant) to resolve entitlements for a specific tenant outside an active tenancy.'
            );
        }

        /** @var Tenant $tenant */
        $tenant = tenant();

        return new self($tenant);
    }

    /**
     * Resolve entitlements for an explicit tenant, regardless of whether
     * any tenancy is currently initialized - e.g. platform-admin code
     * inspecting a tenant that isn't the active request's tenant.
     */
    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function currentPlan(): Plan
    {
        if ($this->tenant->plan_id === null) {
            throw new NoPlanAssignedException(
                "Tenant [{$this->tenant->getTenantKey()}] has no plan assigned."
            );
        }

        return Plan::findOrFail($this->tenant->plan_id);
    }

    /**
     * @return bool Resolves a 'boolean'-type feature. Throws
     *               FeatureTypeMismatchException if the feature is
     *               configured as 'numeric'/'unlimited' for this plan.
     */
    public function can(FeatureCode|string $feature): bool
    {
        $row = $this->featureRow($feature);

        if ($row->type !== FeatureType::Boolean) {
            throw new FeatureTypeMismatchException(
                "Feature [{$row->feature_code}] is type [{$row->type->value}], not boolean - use limit() instead of can()."
            );
        }

        return (bool) $row->value;
    }

    /**
     * @return int|null Resolves a 'numeric'-type feature's cap, or null
     *                   for an 'unlimited'-type feature (no cap at all -
     *                   null is the explicit "unlimited" representation
     *                   throughout this service, never a sentinel like -1
     *                   that a caller could misuse in arithmetic). Throws
     *                   FeatureTypeMismatchException if the feature is
     *                   configured as 'boolean' for this plan.
     */
    public function limit(FeatureCode|string $feature): ?int
    {
        $row = $this->featureRow($feature);

        if ($row->type === FeatureType::Unlimited) {
            return null;
        }

        if ($row->type !== FeatureType::Numeric) {
            throw new FeatureTypeMismatchException(
                "Feature [{$row->feature_code}] is type [{$row->type->value}], not numeric/unlimited - use can() instead of limit()."
            );
        }

        return $row->value;
    }

    protected function featureRow(FeatureCode|string $feature): PlanFeature
    {
        $code = $feature instanceof FeatureCode ? $feature->value : $feature;

        $plan = $this->currentPlan();

        $row = $plan->features()->where('feature_code', $code)->first();

        if (! $row) {
            throw new FeatureNotConfiguredException(
                "Feature [{$code}] is not configured for plan [{$plan->code}]."
            );
        }

        return $row;
    }
}
