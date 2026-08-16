<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * TASK-ARCH-011. The explicit host-boundary check for Platform Admin
 * (item 13 of the task brief: "Do not rely only on URL prefix; enforce
 * host/domain boundary too").
 *
 * Platform Admin routes are registered OUTSIDE the 'web' middleware group
 * (see Platform\Admin\Providers\PlatformAdminServiceProvider::boot() and
 * bootstrap/app.php's 'platform' group definition), so they never run
 * `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` at all - meaning
 * no tenant database connection can EVER become active for these routes,
 * structurally, regardless of what Host header a request arrives with.
 * That already prevents the dangerous outcome (Platform Admin accidentally
 * reading/writing a tenant database).
 *
 * This middleware exists for the OTHER requirement: a tenant domain (or
 * any unrecognized domain) must not even be able to REACH Platform Admin's
 * pages/routes at all (view tenant data, see the login form, etc.) just
 * because it knows the /platform/* URL prefix - Laravel does not scope
 * routes by host unless told to, so without this check any Host header
 * could hit these routes. Checked against the exact same
 * `config('tenancy.central_domains')` list `Stancl\Tenancy\Middleware\
 * PreventAccessFromCentralDomains` already uses for the opposite
 * direction (blocking central domains from tenant routes) - one single
 * source of truth for "what counts as a central/platform domain".
 *
 * A non-central host gets a plain 404, identical in shape to an unknown
 * tenant domain's 404 (Platform\Tenancy\Providers\TenancyServiceProvider::
 * handleUnresolvedTenantDomains(), TASK-MVP-004B/RISK_REGISTER.md R53) -
 * never a redirect or an error page that would confirm Platform Admin
 * exists at that host.
 *
 * TASK-MVP-004B (RISK_REGISTER.md R54). Originally `throw new
 * NotFoundHttpException`, relying on Laravel's exception-handler pipeline
 * to render it. Found live, during the same public-edge verification that
 * uncovered R53's sibling bug (a spoofed `X-Forwarded-Host` reaching a
 * central-only route under real `APP_DEBUG=false`): under that config,
 * `Webkul\Core\Exceptions\Handler::handleHttpException()` renders this via
 * `view("shop::errors.404")`, which does not exist, so it falls back to
 * `shop::errors.index` - a real Bagisto layout view that unconditionally
 * queries the `locales` table, which does not exist on the CENTRAL
 * connection (a request rejected by this middleware never had a tenant
 * database to fall back to either) - producing a raw 500 instead of the
 * clean 404 this middleware's own docblock above already promised. This
 * is the exact same failure family as R53 (an exception reaching Bagisto's
 * catch-all under APP_DEBUG=false, whose own error view needs tenant-scoped
 * tables), but reached via this middleware's own thrown exception rather
 * than a tenancy-resolution one - and it needs no stancl extension point
 * to fix, since this middleware is Platform-owned: returning the response
 * directly, with no exception at all, sidesteps the exception-handler
 * pipeline entirely rather than trying to intercept something thrown into
 * it.
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
