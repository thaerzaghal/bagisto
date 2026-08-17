<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use Illuminate\Http\Request;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantUnavailableResponder;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-MVP-003B (RISK_REGISTER.md R58). Thrown from `Platform\Tenancy\
 * Providers\TenancyServiceProvider`'s early `RouteMatched` listener the
 * moment a resolved tenant is found to be non-Ready - BEFORE Laravel's own
 * `Route::gatherMiddleware()` gets a chance to eagerly, wastefully
 * instantiate the matched controller (the exact mechanism RISK_REGISTER.md
 * R57 already documents crashes with a raw, uncaught central-connection
 * SQL error for certain Bagisto controllers). Never thrown for a Ready
 * tenant, an unknown domain, or a central/'platform'-group route - see that
 * listener's own docblock for the full decision tree.
 *
 * WHY A CUSTOM EXCEPTION WITH ITS OWN `render()` METHOD, NOT A GLOBAL
 * `bootstrap/app.php` `$exceptions->render()` REGISTRATION: confirmed by
 * reading `Illuminate\Foundation\Exceptions\Handler::render()`'s own
 * source directly - `method_exists($e, 'render')` is checked and, if it
 * returns a response, used UNCONDITIONALLY, structurally BEFORE
 * `renderViaCallbacks()` (where every `$exceptions->render()` registration,
 * including `Webkul\Core\Exceptions\Handler::register()`'s own catch-all,
 * actually lives). RISK_REGISTER.md R59 already proved, live, that a NEW
 * `$exceptions->render()` registration can silently lose a registration-
 * order race against that catch-all under real `APP_DEBUG=false` - this
 * exception's own `render()` method is immune to that race entirely, by
 * construction, not by luck: it is checked before `renderViaCallbacks()`
 * ever runs at all, regardless of what order anything else got registered
 * in. R53 solved an analogous problem with stancl's own `$onFail` hook -
 * no equivalent hook exists at this exact point (a plain application
 * exception, not one thrown by vendor middleware), so this is the correct,
 * narrowest, first-class Laravel mechanism for THIS specific case.
 *
 * WHY THROWING PROPAGATES CORRECTLY FROM A `RouteMatched` LISTENER:
 * confirmed by reading `Illuminate\Events\Dispatcher::invokeListeners()`'s
 * own source - it calls each listener in a plain, unguarded loop (no
 * try/catch anywhere in that method), so an exception thrown here
 * propagates completely unmodified through `Router::runRoute()`'s
 * `$this->events->dispatch(new RouteMatched(...))` call, out through
 * `dispatchToRoute()`/`dispatch()`, and reaches Laravel's HTTP Kernel's own
 * outer exception handling exactly the way any other uncaught exception in
 * the request lifecycle would - `render()` above is what turns that into
 * the correct 423/503 response instead of a raw 500. (Also confirmed
 * `RouteMatched` is dispatched with `$halt` defaulted to `false` - a
 * listener's RETURN value is discarded entirely, which is why this
 * exception THROWS rather than returning a response from the listener
 * closure; that would have done nothing.)
 *
 * Delegates the actual response body to `Platform\Tenancy\Services\
 * TenantUnavailableResponder` - the SAME class `Platform\Tenancy\Http\
 * Middleware\TenantAccessGate` uses - so this exception and that
 * middleware can never independently drift into two different 423/503
 * response bodies.
 */
class TenantNotReadyHttpException extends RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct("Tenant [{$tenant->getTenantKey()}] is not Ready (status: {$tenant->status?->value}).");
    }

    public function render(Request $request): Response
    {
        return app(TenantUnavailableResponder::class)->respondTo($this->tenant, $request);
    }
}
