<?php

declare(strict_types=1);

namespace Platform\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Services\TenantHostResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-ARCH-014 (generalized from TASK-ARCH-013's `BlockSuspendedTenants`,
 * which only ever checked for `Suspended`). Rejects a request for ANY
 * tenant whose lifecycle status is not request-eligible BEFORE tenancy is
 * ever initialized - i.e. before `Stancl\Tenancy\Middleware\
 * InitializeTenancyByDomain` runs at all, so the tenant database
 * connection is never swapped to for a non-ready tenant's request. This
 * is the actual request flow:
 *
 *   Host
 *     -> TenantHostResolver::resolve() [THIS middleware - a CENTRAL-only
 *        `tenants`/`domains` query via `DomainTenantResolver::
 *        resolveWithoutCache()`, no tenant DB touched]
 *     -> tenant.status:
 *          Ready              -> $next($request) -> InitializeTenancyByDomain
 *                                runs NORMALLY (re-resolves the SAME way it
 *                                always has - see below for why re-resolving
 *                                here is deliberate) -> Bagisto
 *          Suspended          -> 423 Locked (unchanged since TASK-ARCH-013 -
 *                                administratively locked, reversible, see
 *                                DECISION_LOG.md C28)
 *          anything else      -> 503 Service Unavailable (Pending,
 *                                Provisioning, Failed, Deleting, Deleted,
 *                                and any future/unrecognized status - see
 *                                "FAIL CLOSED" below)
 *
 * WHY A MIDDLEWARE, NOT A LISTENER ON `Stancl\Tenancy\Events\
 * InitializingTenancy`: that event fires for EVERY call to `Tenancy::
 * initialize()`, including internal, TRUSTED `$tenant->run(...)` calls
 * Platform Admin itself makes for a non-Ready tenant - e.g.
 * `platform.tenants.provision`/`migrate-pending`, whose own contract
 * explicitly promises to work "against any tenant, any number of times,
 * regardless of status" (TASK-ARCH-010/R33). A listener on that event
 * cannot distinguish "a real inbound HTTP request reached Bagisto" from
 * "Platform Admin's own trusted backend code is inspecting/maintaining
 * this tenant" - both call the identical `Tenancy::initialize()` method.
 * A middleware, scoped to the 'web' HTTP middleware group only (see
 * bootstrap/app.php), naturally excludes every internal `$tenant->run()`
 * call site (none of them pass through any HTTP middleware pipeline at
 * all), so trusted Platform-initiated tenant access is never blocked -
 * only real inbound requests reaching Bagisto's own Shop/Admin/API routes
 * are. This is also why the gate cannot live inside
 * `DatabaseTenancyBootstrapper` or any other core tenancy-init code path.
 *
 * WHY RE-RESOLVING VIA THE SAME `DomainTenantResolver`, NOT A NEW ONE:
 * this reuses stancl's own resolver class unmodified (`resolveWithoutCache()`
 * - a plain, cheap, indexed `WHERE domain = ?` central query; resolution
 * caching is off by default in this app's config regardless -
 * `DomainTenantResolver::$shouldCache` is never overridden anywhere, so
 * there is no stale-cache risk to a lifecycle decision made here - see
 * TASK-ARCH-014 requirement 13) rather than inventing a second, competing
 * tenant/domain lookup. `InitializeTenancyByDomain` re-resolves a moment
 * later regardless for a Ready tenant (this middleware has no way to hand
 * its already-resolved Tenant instance into stancl's own middleware
 * without forking/wrapping that class - a much larger, unproven-necessary
 * change), so the identical central-only query simply runs twice per
 * Ready request. Confirmed via the R37-documented mechanics of
 * `Stancl\Tenancy\Tenancy::initialize()`, this causes no
 * double-initialization or double-bootstrap - only the second (real)
 * resolution ever reaches `Tenancy::initialize()`.
 *
 * WHY THE RESOLVE STEP IS EXTRACTED INTO `TenantHostResolver`, NOT
 * INLINED HERE (RISK_REGISTER.md R57): `Platform\Tenancy\Providers\
 * TenancyServiceProvider` also needs to know, as early as
 * `Illuminate\Routing\Events\RouteMatched` (before Laravel's own
 * `Route::controllerMiddleware()` eagerly, wastefully instantiates the
 * matched controller to inspect its middleware - see that provider's own
 * docblock for the full root cause), whether a host resolves to a Ready
 * tenant, so it can safely pre-initialize tenancy only for that tenant.
 * `TenantHostResolver::isReady()` is the ONLY place either call site
 * checks tenant status against `TenantStatus::Ready`, specifically so
 * this middleware and that listener can never independently drift into
 * two different Ready/Suspended/etc. eligibility matrices. This
 * middleware still owns 100% of the actual RESPONSE selection (423 vs
 * 503 vs pass-through, below) - that part is deliberately NOT in the
 * shared service, since the early listener never produces a response at
 * all (it only ever needs a yes/no "safe to touch this tenant's
 * database"). Early tenancy pre-initialization for a Ready tenant does
 * not change anything about this middleware's own re-resolution or
 * response logic below - it still runs, unconditionally, in the same
 * order, for every request.
 *
 * FAIL CLOSED: the `handle()` `match` below has exactly two explicit
 * "allow through" / "known controlled response" arms - `Ready` and
 * `Suspended` - and a single `default` arm that rejects with 503. Any
 * status this middleware doesn't explicitly recognize (including a
 * hypothetical future `TenantStatus` case nobody has updated this file
 * for yet) therefore falls into "reject", never "allow" - satisfying
 * TASK-ARCH-014 requirement 14 ("Do not treat unknown status as Ready")
 * by construction, not by needing to enumerate every current status.
 *
 * An unresolvable host (unknown domain) is deliberately left completely
 * alone - this middleware passes it straight through to `$next($request)`,
 * so `InitializeTenancyByDomain`'s own, already-established
 * unknown-domain handling (a plain 404, bootstrap/app.php) is preserved
 * byte-for-byte.
 */
class TenantAccessGate
{
    public function __construct(protected TenantHostResolver $hostResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->hostResolver->resolve($request->getHost());

        if (! $tenant) {
            return $next($request);
        }

        return match ($tenant->status) {
            TenantStatus::Ready => $next($request),
            TenantStatus::Suspended => $this->suspendedResponse($request),
            default => $this->unavailableResponse($request),
        };
    }

    /**
     * 423 Locked: unchanged since TASK-ARCH-013 - this is not an
     * authentication/authorization failure (403), not "does not exist"
     * (404 - the tenant is real, just locked), and not "temporarily down
     * for infrastructure reasons" (503 - this is an explicit,
     * administrative, reversible platform action, not an outage). See
     * docs/architecture/security.md for the full reasoning record.
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

    /**
     * 503 Service Unavailable, not 423: TASK-ARCH-014 covers Pending,
     * Provisioning, Failed, Deleting, Deleted (and, by the fail-closed
     * `default` arm above, any unrecognized status too). None of these
     * are an administrative lock on an otherwise-working store - they are
     * "not yet available" (Pending/Provisioning), "not currently in a
     * working state" (Failed), or "on the way out" (Deleting/Deleted).
     * 503 is the standard HTTP semantic for exactly this: a resource that
     * is real but not currently servable for infrastructure/lifecycle
     * reasons, distinct from 423's "administratively locked" meaning
     * (DECISION_LOG.md C28, RISK_REGISTER.md R41). The response body is
     * deliberately generic - it must never mention provisioning state,
     * migration/seed progress, exception messages, or the tenant's
     * database name (TASK-ARCH-014 requirement 14).
     */
    protected function unavailableResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 503);
        }

        return response()->view('tenancy::unavailable', [], 503);
    }
}
