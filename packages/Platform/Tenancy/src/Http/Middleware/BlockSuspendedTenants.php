<?php

declare(strict_types=1);

namespace Platform\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Tenancy\Enums\TenantStatus;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-ARCH-013. Rejects a request for a Suspended tenant BEFORE tenancy
 * is ever initialized - i.e. before `Stancl\Tenancy\Middleware\
 * InitializeTenancyByDomain` runs at all, so the tenant database
 * connection is never swapped to for a suspended tenant's request. This
 * is the actual request flow:
 *
 *   Host
 *     -> DomainTenantResolver::resolveWithoutCache() [THIS middleware -
 *        a CENTRAL-only `tenants`/`domains` query, no tenant DB touched]
 *     -> tenant.status == Suspended?
 *          YES -> 423 response, request never proceeds further
 *          NO  -> $next($request) -> InitializeTenancyByDomain runs
 *                 NORMALLY (re-resolves the SAME way it always has -
 *                 see below for why re-resolving here is deliberate,
 *                 not a mistake) -> tenancy initialized -> Bagisto
 *
 * WHY A MIDDLEWARE, NOT A LISTENER ON `Stancl\Tenancy\Events\
 * InitializingTenancy`: that event fires for EVERY call to `Tenancy::
 * initialize()`, including internal, TRUSTED `$tenant->run(...)` calls
 * Platform Admin itself makes for an already-Suspended tenant - e.g.
 * `platform.tenants.migrate-pending`, whose own docblock explicitly
 * promises "safe to run against any tenant, any number of times,
 * regardless of status" (TASK-ARCH-010/R33). A listener on that event
 * cannot distinguish "a real inbound HTTP request reached Bagisto" from
 * "Platform Admin's own trusted backend code is inspecting/maintaining
 * this tenant" - both call the identical `Tenancy::initialize()` method.
 * A middleware, scoped to the 'web' HTTP middleware group only (see
 * bootstrap/app.php), naturally excludes every internal `$tenant->run()`
 * call site (none of them pass through any HTTP middleware pipeline at
 * all), so trusted Platform-initiated tenant access is never blocked by
 * suspension - only real inbound requests reaching Bagisto's own
 * Shop/Admin/API routes are.
 *
 * WHY RE-RESOLVING VIA THE SAME `DomainTenantResolver`, NOT A NEW ONE:
 * per the task's own guidance, this reuses stancl's own resolver class
 * unmodified (`resolveWithoutCache()` - a plain, cheap, indexed `WHERE
 * domain = ?` central query, resolution caching is off by default in
 * this app's config either way) rather than inventing a second,
 * competing tenant/domain lookup. `InitializeTenancyByDomain` re-resolves
 * a moment later regardless (this middleware has no way to hand its
 * already-resolved Tenant instance into stancl's own middleware without
 * either forking/wrapping that class - a much larger, unproven-necessary
 * change - or fully replacing it), so the identical central-only query
 * simply runs twice per non-suspended request. Confirmed via the R37-
 * documented mechanics of `Stancl\Tenancy\Tenancy::initialize()`, this
 * causes no double-initialization or double-bootstrap - only the second
 * (real) resolution ever reaches `Tenancy::initialize()`.
 *
 * An unresolvable host (unknown domain) is deliberately left completely
 * alone - this middleware passes it straight through to `$next($request)`,
 * so `InitializeTenancyByDomain`'s own, already-established
 * unknown-domain handling (a plain 404, bootstrap/app.php) is preserved
 * byte-for-byte. This middleware only ever changes behavior for a host
 * that resolves to a tenant currently in `TenantStatus::Suspended` -
 * every other status (including Pending/Provisioning/Failed, which are
 * out of this task's scope) is passed through unchanged too.
 */
class BlockSuspendedTenants
{
    public function __construct(protected DomainTenantResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $tenant = $this->resolver->resolveWithoutCache($request->getHost());
        } catch (TenantCouldNotBeIdentifiedException) {
            return $next($request);
        }

        if ($tenant->status !== TenantStatus::Suspended) {
            return $next($request);
        }

        return $this->suspendedResponse($request);
    }

    /**
     * 423 Locked, not 403/404/503: this is not an authentication/
     * authorization failure (403 - Bagisto's own `Webkul\Core\Exceptions\
     * Handler` already owns that meaning for real permission checks
     * inside a live store, and reusing it here would blur "this one
     * action is forbidden" with "the entire tenant is inaccessible"),
     * not "does not exist" (404 - the tenant is real, just locked), and
     * not "temporarily down for infrastructure reasons" (503 - this is
     * an explicit, administrative, reversible platform action, not an
     * outage). 423 precisely communicates "this resource exists and is
     * administratively locked, not permanently forbidden" - the same
     * semantic distinction several real-world multi-tenant SaaS
     * platforms already use for a suspended account/store, and is
     * unambiguous to any HTTP-aware client without inventing a bespoke
     * status code. See docs/architecture/security.md for the full
     * reasoning record.
     */
    protected function suspendedResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 423);
        }

        return response()->view('tenancy::suspended', [], 423);
    }
}
