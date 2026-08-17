<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * TASK-MVP-004B (RISK_REGISTER.md R57). The single source of truth for
 * "what tenant (if any) does this host resolve to, and is it eligible to
 * have its own database touched at all" - shared by
 * `Platform\Tenancy\Http\Middleware\TenantAccessGate` (the existing,
 * already-proven pre-DB rejection gate) and
 * `Platform\Tenancy\Providers\TenancyServiceProvider`'s early
 * `RouteMatched` tenancy-initialization listener (added to fix R57 - see
 * that provider's own docblock for the full root cause).
 *
 * Extracted specifically so those two call sites can never independently
 * drift into two different Ready/Suspended/etc. eligibility matrices -
 * `isReady()` is the ONLY place either of them checks tenant status
 * against `TenantStatus::Ready`. `TenantAccessGate` still owns its own
 * per-status RESPONSE selection (423 vs 503 vs pass-through) - that part
 * is deliberately NOT here, since the early listener never needs to
 * produce a response at all (it only ever needs a yes/no "safe to touch
 * this tenant's database").
 */
class TenantHostResolver
{
    public function __construct(protected DomainTenantResolver $resolver)
    {
    }

    /**
     * Resolves the tenant for a host, or null when no tenant matches
     * (an unknown or central host) - never throws.
     */
    public function resolve(string $host): ?Tenant
    {
        try {
            return $this->resolver->resolveWithoutCache($host);
        } catch (TenantCouldNotBeIdentifiedException) {
            return null;
        }
    }

    /**
     * Whether a resolved tenant is eligible to have its own database
     * connection initialized/queried at all. `null` (host did not resolve
     * to any tenant) is never eligible.
     */
    public function isReady(?Tenant $tenant): bool
    {
        return $tenant?->status === TenantStatus::Ready;
    }
}
