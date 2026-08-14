<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * tenant domain's 404 (Stancl's TenantCouldNotBeIdentifiedException
 * mapping in bootstrap/app.php) - never a redirect or an error page that
 * would confirm Platform Admin exists at that host.
 */
class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->getHost(), config('tenancy.central_domains', []), true)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
