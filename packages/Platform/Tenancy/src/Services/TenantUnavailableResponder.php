<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Illuminate\Http\Request;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-MVP-003B (RISK_REGISTER.md R58). The SINGLE source of truth for "what
 * HTTP response does a non-Ready tenant get" - extracted out of
 * `Platform\Tenancy\Http\Middleware\TenantAccessGate` (which owned this
 * logic alone until now) specifically so `TenantNotReadyHttpException`
 * (thrown from `Platform\Tenancy\Providers\TenancyServiceProvider`'s early
 * `RouteMatched` listener - see that class's own docblock for why an early,
 * pre-middleware rejection is needed at all) can produce the EXACT same
 * response, byte for byte, without a second, independently-maintained
 * Suspended/other-status decision living in two places. `TenantAccessGate`
 * itself now just delegates here too - it no longer builds any response
 * body itself, only decides Ready (pass through) vs not (delegate).
 *
 * Deliberately still requires the CALLER to have already decided "this
 * tenant is not Ready" - `respondTo()` does not itself check for Ready and
 * pass through, since both current call sites already need to make that
 * distinction themselves (one to decide whether to initialize tenancy at
 * all, one to decide whether to call `$next($request)`).
 */
class TenantUnavailableResponder
{
    /**
     * FAIL CLOSED: exactly one explicit "administratively locked" arm
     * (`Suspended` -> 423) and a `default` arm covering everything else
     * (Pending/Provisioning/Failed/Deleting/Deleted, and any future/
     * unrecognized `TenantStatus` case nobody has updated this file for
     * yet) -> 503. An unrecognized status therefore always falls into
     * "reject, generically", never "allow" or "leak detail" - satisfying
     * TASK-ARCH-014 requirement 14 by construction.
     */
    public function respondTo(Tenant $tenant, Request $request): Response
    {
        return match ($tenant->status) {
            TenantStatus::Suspended => $this->suspended($request),
            default => $this->unavailable($request),
        };
    }

    /**
     * 423 Locked: unchanged since TASK-ARCH-013 - not an authentication/
     * authorization failure (403), not "does not exist" (404 - the tenant
     * is real, just locked), and not "temporarily down for infrastructure
     * reasons" (503 - this is an explicit, administrative, reversible
     * platform action, not an outage). See docs/architecture/security.md
     * for the full reasoning record.
     */
    public function suspended(Request $request): Response
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 423);
        }

        return response()->view('tenancy::suspended', [], 423);
    }

    /**
     * 503 Service Unavailable, not 423: covers Pending, Provisioning,
     * Failed, Deleting, Deleted (and, by `respondTo()`'s own fail-closed
     * `default` arm, any unrecognized status too). None of these are an
     * administrative lock on an otherwise-working store - they are "not
     * yet available" (Pending/Provisioning), "not currently in a working
     * state" (Failed), or "on the way out" (Deleting/Deleted). 503 is the
     * standard HTTP semantic for exactly this: a resource that is real but
     * not currently servable for infrastructure/lifecycle reasons, distinct
     * from 423's "administratively locked" meaning (DECISION_LOG.md C28,
     * RISK_REGISTER.md R41). The response body is deliberately generic - it
     * must never mention provisioning state, migration/seed progress,
     * exception messages, or the tenant's database name (TASK-ARCH-014
     * requirement 14).
     */
    public function unavailable(Request $request): Response
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'This store is currently unavailable.',
            ], 503);
        }

        return response()->view('tenancy::unavailable', [], 503);
    }
}
