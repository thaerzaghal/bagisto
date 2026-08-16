<?php

declare(strict_types=1);

namespace Platform\Signup\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * TASK-MVP-003. Makes the `?welcome=1` marker `Platform\Signup\Http\
 * SignupResultResponder` already appends to the post-signup redirect
 * (`{tenant}/admin/login?welcome=1`) survive through Bagisto's OWN login
 * flow to the dashboard, with zero `packages/Webkul` modification and
 * zero persisted state anywhere.
 *
 * WHY THIS IS NEEDED (verified from Bagisto source, not assumed):
 * `Webkul\Admin\Http\Controllers\User\SessionController::create()` (the
 * real GET /admin/login handler) unconditionally overwrites
 * `session('url.intended')` on every render, based on `url()->previous()`
 * (the HTTP Referer header) - never the current request's own query
 * string. Since the merchant arrives here via a cross-domain redirect
 * from the CENTRAL `/join` flow, `url()->previous()` never contains
 * 'admin', so Bagisto's own logic always falls back to a bare
 * `route('admin.dashboard.index')` with no query string - `?welcome=1`
 * would be silently dropped before `SessionController::store()`'s own
 * `redirect()->intended(...)` ever runs.
 *
 * This middleware runs `$next($request)` FIRST (letting Bagisto's own
 * controller execute and set its own `url.intended` value exactly as
 * before), then - only when `?welcome=1` is present on the login GET
 * request - OVERWRITES `url.intended` with the dashboard route carrying
 * the SAME marker forward. A session write made after `$next()` returns
 * is still captured by `Illuminate\Session\Middleware\StartSession`'s
 * own save step, which runs strictly after this middleware's own
 * `handle()` returns - no timing race.
 *
 * REGISTRATION, and why the R25 precedent's own mechanism did NOT work
 * here: the first implementation attempted the identical `$this->app->
 * booted(fn () => $router->getRoutes()->getByName('admin.session.create')
 * ->middleware([...]))` pattern `Platform\Tenancy\Providers\
 * TenancyServiceProvider::attachTenancyToImageCacheRoute()` (TASK-ARCH-005/
 * R25) already uses successfully for a DIFFERENT route (`imagecache`).
 * Empirically verified (real debug logging, both via `artisan tinker` and
 * an actual failing Pest run) that `admin.session.create` genuinely does
 * NOT exist yet in the router's route collection at the moment ANY
 * `$this->app->booted()` callback fires in this application - even
 * though `Webkul\Admin\Providers\AdminServiceProvider::boot()` registers
 * it via a plain, unconditional, synchronous `require 'auth-routes.php'`
 * with no lazy/conditional loading found anywhere in that call chain.
 * The root mechanism was not fully isolated (a real, if narrow,
 * unresolved finding - see RISK_REGISTER.md R50) and no working fix was
 * found using that approach, so it was abandoned in favor of this one,
 * which needs no such timing assumption at all.
 *
 * INSTEAD: registered as an ORDINARY member of the 'web' middleware
 * GROUP itself (`bootstrap/app.php`'s `appendToGroup('web', [...])`
 * call - the SAME, already-established extension point TASK-ARCH-003
 * used for `InitializeTenancyByDomain`, and TASK-ARCH-014 for
 * `TenantAccessGate` - a project file, never `packages/Webkul`).
 * Appended (not prepended) so it runs AFTER `StartSession`/
 * `VerifyCsrfToken` (both already part of 'web'), guaranteeing `session()`
 * is ready to write to. Runs on EVERY 'web'-group request (Shop, tenant
 * Admin - never Platform Admin, which uses the entirely separate
 * 'platform' group), but its own body is a no-op for every request
 * except the one whose CURRENT route is genuinely `admin.session.create`
 * with `?welcome=1` present - checked via `Request::routeIs()`, which is
 * safe to call from 'web'-group middleware because Laravel has already
 * matched the route by the time group middleware runs. A normal login
 * (no `?welcome=1`) or any other 'web' request is byte-for-byte
 * unaffected.
 */
class FlagFirstLoginWelcome
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->routeIs('admin.session.create') && $request->boolean('welcome')) {
            session()->put('url.intended', route('admin.dashboard.index', ['welcome' => 1]));
        }

        return $response;
    }
}
