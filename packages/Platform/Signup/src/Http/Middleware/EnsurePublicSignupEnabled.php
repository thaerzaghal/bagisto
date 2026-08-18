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
 * `abort(404)`, not 403/a "signup disabled" message - deliberate: there
 * is no reason to advertise the existence of an inactive registration
 * endpoint to an anonymous prober. A route that always exists but
 * 404s here (rather than conditionally omitting the route registration
 * entirely) is route-cache-agnostic and trivially testable via an
 * ordinary HTTP request - no route-cache invalidation subtlety to reason
 * about if this project ever adopts `route:cache` later.
 */
class EnsurePublicSignupEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.signup.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
