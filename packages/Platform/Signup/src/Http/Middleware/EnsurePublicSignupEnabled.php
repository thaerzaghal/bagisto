<?php

declare(strict_types=1);

namespace Platform\Signup\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-MVP-007. Applied ONLY to the anonymous `join` GET/POST routes -
 * never to `join/retry/{tenant}`, which stays available regardless of
 * this flag (see signup-routes.php's own docblock for why: authorization
 * there is already a cryptographically signed URL, not anonymous public
 * access).
 *
 * A clean 404, not 403/a "signup disabled" message - deliberate: there
 * is no reason to advertise the existence of an inactive registration
 * endpoint to an anonymous prober. A route that always exists but
 * 404s here (rather than conditionally omitting the route registration
 * entirely) is route-cache-agnostic and trivially testable via an
 * ordinary HTTP request - no route-cache invalidation subtlety to reason
 * about if this project ever adopts `route:cache` later.
 *
 * PRODUCTION REGRESSION (found live, post-deploy, same day as this
 * task): this originally called `abort(404)`, throwing
 * `NotFoundHttpException` into Laravel's normal exception pipeline.
 * Under real production config (`APP_DEBUG=false`), `Webkul\Core\
 * Exceptions\Handler` catches it and renders a themed `shop::errors.*`
 * Blade view, which unconditionally queries the `locales` table - a
 * table that only exists in a TENANT database, never in the central one
 * this central-only route runs against. The result was a raw 500, not
 * the intended clean 404 - exactly the same failure family already
 * found and fixed once for the sibling `EnsureCentralDomain` middleware
 * on this identical route group (RISK_REGISTER.md R54). The fix is the
 * same principle: return the 404 response DIRECTLY, with no exception
 * thrown at all, so the request never enters Bagisto's exception-
 * handler pipeline in the first place - not a Bagisto/Laravel bug to
 * patch, a Platform-owned middleware that must not throw here. Mirrors
 * `EnsureCentralDomain::handle()`'s own response shape exactly (both
 * `Platform\Admin\Http\Middleware\EnsureCentralDomain` and `Platform\
 * Signup\Http\Middleware\EnsureCentralDomain`) for the identical reason
 * and on the identical route group, so a client sees one consistent 404
 * shape regardless of which check rejected the request. Confirmed via
 * `EnsurePublicSignupEnabledRealHandlerTest.php` (a real subprocess PHP
 * server test, `APP_DEBUG=false`, that reproduces this failure without
 * the fix and passes with it - an in-process Pest request never hits
 * Laravel's real top-level exception-handler pipeline the way a genuine
 * HTTP request does, which is why the original bug shipped past every
 * other test in this task undetected).
 */
class EnsurePublicSignupEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.signup.enabled')) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return $next($request);
    }
}
