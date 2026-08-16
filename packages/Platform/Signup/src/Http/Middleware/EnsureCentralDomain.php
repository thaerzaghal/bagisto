<?php

declare(strict_types=1);

namespace Platform\Signup\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * TASK-MVP-001. Deliberately a small, self-contained duplicate of
 * `Platform\Admin\Http\Middleware\EnsureCentralDomain` (byte-for-byte
 * identical logic - a host check against `config('tenancy.central_domains')`,
 * including TASK-MVP-004B/RISK_REGISTER.md R54's fix - see that class's
 * own docblock for the full root cause),
 * not a reuse of that class. The task's own instruction was to keep
 * `Platform\Signup` dependent ONLY on `Platform\Tenancy` unless another
 * dependency proves genuinely required - importing this one from
 * `Platform\Admin` would create a new, backwards-feeling dependency edge
 * (a lower-level tenant-creation concern depending on a higher-level
 * operational-panel package that nothing else depends on today) for
 * seven lines of logic that read a single shared config key and are
 * extremely unlikely to drift. If a third consumer of this exact check
 * ever appears, that is the point to consolidate it into
 * `Platform\Tenancy` (its more natural home); not done here to avoid
 * touching an already-approved package's file layout for this task.
 *
 * Signup routes are ALSO registered under the `platform` middleware
 * group (bootstrap/app.php - the `web` group minus
 * `InitializeTenancyByDomain`), which already makes it structurally
 * impossible for any tenant DB connection to become active for these
 * routes regardless of Host header. This middleware exists only for the
 * secondary requirement: a tenant/unknown domain must not even be able
 * to REACH the signup form at all merely by knowing the `/join` path.
 */
class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->getHost(), config('tenancy.central_domains', []), true)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return $next($request);
    }
}
