<?php

use App\Http\Middleware\EncryptCookies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
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

        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withSchedule(function (Schedule $schedule) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TenantCouldNotBeIdentifiedException $e, $request) {
            return response()->json(['message' => 'Not Found'], 404);
        });
    })->create();
