<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * TASK-ARCH-011. Laravel's stock `Illuminate\Auth\Middleware\Authenticate`
 * (used as `auth:platform`) redirects an unauthenticated guest to the
 * globally-named `login` route, which does not exist in this app (Bagisto
 * has no single global login route - 'admin' and 'customer' each have
 * their own guard-specific login flow, and now 'platform' does too). This
 * override only changes redirectTo() to point at `platform.login`, so an
 * unauthenticated request to any `auth:platform` route redirects there
 * instead of throwing a RouteNotFoundException.
 */
class Authenticate extends BaseAuthenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('platform.login');
    }
}
