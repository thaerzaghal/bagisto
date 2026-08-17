<?php

use App\Http\Middleware\EncryptCookies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Platform\Signup\Http\Middleware\FlagFirstLoginWelcome;
use Platform\Tenancy\Http\Middleware\TenantAccessGate;
use Platform\Tenancy\Support\EnvList;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Webkul\Core\Http\Middleware\SecureHeaders;
use Webkul\Installer\Http\Middleware\CanInstall;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /**
         * Remove the default Laravel middleware that prevents requests during maintenance mode. There are three
         * middlewares in the shop that need to be loaded before this middleware. Therefore, we need to remove this
         * middleware from the list and add the overridden middleware at the end of the list.
         *
         * As of now, this has been added in the Admin and Shop providers. I will look for a better approach in Laravel 11 for this.
         */
        $middleware->remove(PreventRequestsDuringMaintenance::class);

        /**
         * Remove the default Laravel middleware that converts empty strings to null. First, handle all nullable cases,
         * then remove this line.
         */
        $middleware->remove(ConvertEmptyStringsToNull::class);

        $middleware->append(SecureHeaders::class);
        $middleware->append(CanInstall::class);

        /**
         * Add the overridden middleware at the end of the list.
         */
        $middleware->replaceInGroup('web', BaseEncryptCookies::class, EncryptCookies::class);

        /**
         * TASK-ARCH-003: prepend tenant domain resolution to the 'web' middleware
         * GROUP DEFINITION itself (not just to our own routes/tenant.php, which is
         * a separate route file). Bagisto's Admin AND Shop packages both register
         * their routes with ['web', ...] by name (see
         * Webkul\Admin\Providers\AdminServiceProvider, Webkul\Shop\Providers\
         * ShopServiceProvider) - since Laravel resolves the 'web' alias to
         * whatever middleware list is registered here at boot time, this makes
         * EVERY real Bagisto admin/shop route tenant-aware automatically, with
         * zero changes to any packages/Webkul file. This is the same extension
         * point Bagisto's own team already uses one line above
         * (replaceInGroup('web', ...)) for the EncryptCookies override.
         *
         * Must run before StartSession (part of the base 'web' group) - the
         * `sessions` table lives inside each tenant's own database
         * (SESSION_DRIVER=database), so the DB connection must already be
         * switched before session middleware touches it. prependToGroup() puts
         * it first in the group; Platform\Tenancy\Providers\TenancyServiceProvider
         * ::makeTenancyMiddlewareHighestPriority() additionally pins its relative
         * priority, so both mechanisms agree on the ordering.
         *
         * Unresolved domains (including the central domains, since no real
         * platform routes exist yet - see docs/architecture/domain-routing.md)
         * throw Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException,
         * mapped to a plain 404 below - never a fallback to any tenant, and
         * never a raw 500 with a stack trace.
         */
        $middleware->prependToGroup('web', [
            InitializeTenancyByDomain::class,
        ]);

        /**
         * TASK-ARCH-011: the 'platform' middleware group for Platform Admin
         * (packages/Platform/Admin) - central-only routes that must NEVER run
         * InitializeTenancyByDomain (which, for the central domain host, would
         * throw TenantCouldNotBeIdentifiedException exactly like any unknown
         * host, since no tenant `domains` row is ever expected to exist for
         * it - see docs/architecture/domain-routing.md's "Unresolved-domain
         * handling"). Built from `getMiddlewareGroups()['web']` AFTER the
         * prependToGroup() call above and the EncryptCookies replaceInGroup()
         * above that, so it automatically tracks whatever the real 'web' group
         * resolves to (session/csrf/cookie handling) minus only the one
         * tenant-resolution entry - no separate, hand-maintained middleware
         * list to drift out of sync with 'web' over time. Platform\Admin's own
         * Platform\Admin\Http\Middleware\EnsureCentralDomain is the actual
         * host-boundary enforcement (see that class); this group only ensures
         * Platform Admin requests get ordinary session/CSRF/cookie handling
         * against whatever the CURRENT default (central) DB connection is,
         * without ever being able to trigger a tenant DB connection swap.
         */
        $middleware->group('platform', array_values(array_diff(
            $middleware->getMiddlewareGroups()['web'],
            [InitializeTenancyByDomain::class],
        )));

        /**
         * TASK-ARCH-013/014: prepended AFTER the 'platform' group is built
         * above (deliberately - Platform Admin's own EnsureCentralDomain
         * already owns its host boundary and must never attempt tenant
         * resolution at all, see that group's own comment), so this only
         * ever lands in 'web'. prependToGroup() always inserts at the very
         * front of whatever is currently in the group, so this correctly
         * runs BEFORE the InitializeTenancyByDomain prepended above,
         * without needing to touch that earlier line. TASK-ARCH-014
         * generalized this from TASK-ARCH-013's Suspended-only
         * BlockSuspendedTenants into TenantAccessGate, which rejects EVERY
         * non-request-eligible tenant status (Pending/Provisioning/Failed/
         * Deleting/Deleted with 503, Suspended with 423, fail-closed
         * default for any unrecognized status) - see that class's own
         * docblock for the full request flow and why this must be a
         * distinct, earlier middleware rather than a listener on
         * Stancl\Tenancy\Events\InitializingTenancy.
         */
        $middleware->prependToGroup('web', [
            TenantAccessGate::class,
        ]);

        /**
         * TASK-MVP-003. Appended (not prepended) to 'web' so it runs
         * AFTER StartSession/VerifyCsrfToken (both already part of
         * 'web'), guaranteeing session() is ready to write to. Its own
         * body is a no-op for every request except the one whose
         * CURRENT route is genuinely `admin.session.create` (Bagisto's
         * tenant Admin login page) with `?welcome=1` present - see
         * Platform\Signup\Http\Middleware\FlagFirstLoginWelcome's own
         * docblock for why this needed to be a 'web'-group member
         * rather than the R25-style per-route attachment this package
         * first attempted (which does not work for this specific route,
         * for reasons only partially isolated - RISK_REGISTER.md R50).
         * Runs on every Shop/tenant-Admin request - never Platform
         * Admin, which uses the entirely separate 'platform' group.
         */
        $middleware->appendToGroup('web', [
            FlagFirstLoginWelcome::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/*',

            /**
             * TASK-ARCH-019. Platform\Billing's own Stripe webhook
             * endpoint - a server-to-server call with no browser session,
             * where CSRF protection has no meaning. Deliberately a
             * DIFFERENT path from the pre-existing 'stripe/*' entry above
             * (packages/Webkul/Stripe's own, unrelated storefront-order
             * webhook) - never reused, to avoid any route collision or
             * conflating the two integrations.
             */
            'billing/webhook/*',
        ]);

        /**
         * TASK-MVP-004A (RISK_REGISTER.md R20): TRUSTED_PROXIES, comma-
         * separated IP addresses/CIDR ranges (Symfony's trusted-proxy IP
         * matching - which `Illuminate\Http\Middleware\TrustProxies`
         * delegates to via `Request::setTrustedProxies()` - natively
         * understands CIDR notation, so no extra parsing is needed beyond
         * EnvList::parse()'s trim/drop-empty). Empty/unset (today's local
         * dev default - no production value has been chosen yet) falls
         * back to '*', preserving the EXACT previous behavior with zero
         * required setup. A real production deployment must set this to
         * its actual reverse proxy's IP(s) - see docs/architecture/
         * production-deployment.md - at which point ONLY forwarded
         * headers from those specific IPs are honored; a request that
         * reaches the app directly, or via any other IP, gets its own
         * real connection's host/scheme/IP instead of whatever a
         * possibly-spoofed X-Forwarded-* header claims.
         *
         * TASK-MVP-004B (RISK_REGISTER.md R55): HEADER_X_FORWARDED_HOST
         * deliberately dropped from the trusted set - Host resolution
         * relies solely on the raw `Host` request header instead. Found
         * live on the real pilot deployment's Apache edge: with
         * `ProxyPass ... ProxyPreserveHost On` (the real production
         * reverse-proxy config - see docs/architecture/
         * production-deployment.md), the raw Host header Laravel receives
         * is ALREADY the exact Host Apache's own vhost matched the
         * request against, immune to header spoofing by construction (a
         * client can influence which of OUR vhosts gets matched only by
         * sending a Host our ServerAlias actually lists, at which point
         * "spoofing" is meaningless - it just becomes a normal request for
         * that host). Trusting X-Forwarded-Host on top of that added a
         * real, exploitable gap with no corresponding benefit for this
         * single-hop topology: `mod_proxy_http` merges (comma-appends)
         * its own detected value onto whatever a client already sent
         * rather than replacing it, and Symfony's trusted-header
         * resolution for X-Forwarded-Host reads the FIRST (client-supplied,
         * spoofable) entry, not the last (proxy-appended, real) one -
         * confirmed live: a real external request to
         * `app.technify.dev/platform/login` carrying a forged
         * `X-Forwarded-Host: evil-spoofed.example.com` header caused
         * `EnsureCentralDomain` to reject the request as non-central
         * (`$request->getHost()` had resolved to the forged value), which
         * is what surfaced R54. Rewriting X-Forwarded-Host at the Apache
         * layer to a trusted value was attempted first but abandoned -
         * `RequestHeader set ... "%{Host}i"` did not evaluate as
         * documented on this server's Apache/mod_headers build (produced
         * a literal `i=<counter>` value instead of the Host header content)
         * - not something worth fighting when simply not trusting the
         * header at all is both simpler and strictly safer for this
         * topology. X-Forwarded-For/Proto/Port remain trusted - none of
         * them can override which vhost/tenant a request resolves to.
         */
        $trustedProxies = EnvList::parse(env('TRUSTED_PROXIES'));

        $middleware->trustProxies(
            at: $trustedProxies !== [] ? $trustedProxies : '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withSchedule(function (Schedule $schedule) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /**
         * TASK-MVP-004B (RISK_REGISTER.md R51): DEFENSE-IN-DEPTH ONLY as of
         * this task - the primary interception point is now
         * Platform\Tenancy\Providers\TenancyServiceProvider::
         * handleUnresolvedTenantDomains(), which sets Stancl\Tenancy\
         * Middleware\InitializeTenancyByDomain::$onFail so the exception
         * never reaches this handler at all for the normal 'web' HTTP path.
         * This registration stays in place for any other path that might
         * still funnel the exception into Laravel's own exception-handler
         * pipeline directly - but under APP_DEBUG=false it was proven to
         * silently lose a registration-order race against Webkul\Core\
         * Exceptions\Handler's own catch-all `Throwable` renderable (see
         * that provider method's own docblock for the full, source-verified
         * root cause). Do not rely on this alone for anything reachable via
         * the 'web' middleware group.
         */
        $exceptions->render(function (TenantCouldNotBeIdentifiedException $e, $request) {
            return response()->json(['message' => 'Not Found'], 404);
        });

        /**
         * RISK_REGISTER.md R59 (superseding the TASK-MVP-004A/R52 design
         * that used to live here). A global `$exceptions->render()`
         * registration for `Platform\Billing\Exceptions\
         * MissingProviderCredentialsException` used to be registered at
         * this exact point, on the theory that it was the only place able
         * to intercept the exception (it fires during `Illuminate\Routing\
         * ResolvesRouteDependencies`' method-parameter resolution, before
         * `CheckoutController::store()`'s own body runs). That theory was
         * correct about WHEN the exception fires, but the registration
         * mechanism itself was proven live, under real `APP_DEBUG=false`
         * production config, to silently lose the exact same registration-
         * order race this file's own `TenantCouldNotBeIdentifiedException`
         * handler above was ALSO found to lose (R53) - `Webkul\Core\
         * Exceptions\Handler`'s own catch-all `Throwable` renderable,
         * registered synchronously during Handler construction, always
         * wins under that config, producing a raw generic 500 instead of
         * this handler's intended graceful redirect. Unlike R53 (a
         * vendor-thrown exception with a purpose-built `$onFail` extension
         * point to intercept it before it ever reaches this pipeline),
         * `MissingProviderCredentialsException` is thrown from this
         * codebase's own `Platform\Billing\Adapters\StripePaymentProvider`
         * constructor - so R59's fix instead restructured `Checkout
         * Controller::store()` itself to resolve `CheckoutService`
         * manually, as an ordinary statement inside its own method body
         * (not a Laravel-auto-resolved typed method parameter), so the
         * exception is an entirely normal, locally-catchable PHP exception
         * at the one real call site that can ever throw it (confirmed via
         * a full-codebase audit: `CheckoutController::store()` is the ONLY
         * place `Platform\Billing\Services\CheckoutService`/the
         * `PaymentProvider` contract is ever resolved). This registration
         * is therefore intentionally REMOVED, not left in place as a
         * second, now-redundant/contradictory mechanism - see that
         * controller's own docblock for the replacement.
         */
    })->create();
