<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * TASK-MVP-008 (RISK_REGISTER.md R68). A decorator around whatever
 * `ExceptionHandler` is actually bound (`Webkul\Core\Exceptions\Handler`,
 * see `Webkul\Core\Providers\CoreServiceProvider::registerOverrides()`),
 * registered via `Container::extend()` in `TenancyServiceProvider::boot()`
 * - never a `packages/Webkul/*` change.
 *
 * ROOT CAUSE: `Webkul\Core\Exceptions\Handler::handleHttpException()`
 * forces any status OUTSIDE `{401, 403, 404, 503}` to `$errorCode = 500`,
 * then renders `shop::errors.{$errorCode}` (falling back to
 * `shop::errors.index` when that specific view doesn't exist - it never
 * does for 405/419/422/429/500). That fallback view unconditionally
 * queries tenant-only tables (`locales`, channel/currency data). Any
 * request for which tenancy was never initialized - a central Platform
 * route (CSRF failure, throttle, an ordinary application exception) OR a
 * genuinely unmatched route for ANY host (no route = no route-group
 * middleware = tenancy is never even attempted, regardless of Host) -
 * has no tenant database to satisfy that query, so the "friendly"
 * fallback page itself crashes, producing a raw 500 with no friendly
 * page at all. Confirmed via real, read-only production probes: a
 * missing/invalid CSRF token on `/join` or `/platform/login`, a
 * genuinely unmatched central POST, and a method-not-allowed request all
 * reproduce this identically; the same request shapes as GET happen to
 * be incidentally protected today only because Bagisto's own Shop
 * package registers a broad GET catch-all route that routes them through
 * `InitializeTenancyByDomain` -> the ALREADY-FIXED (R51/R53)
 * `TenantCouldNotBeIdentifiedException` `$onFail` interception below -
 * a protection that is real but purely coincidental to GET, and does not
 * generalize to POST/PUT/DELETE or to Platform's own routes.
 *
 * WHY A CONTAINER::EXTEND() DECORATOR, NOT ANOTHER `bootstrap/app.php`
 * `$exceptions->render()` REGISTRATION: `handleUnresolvedTenantDomains()`
 * above's own docblock already documents, from reading the actual
 * framework source, WHY that mechanism is unreliable here -
 * `Illuminate\Foundation\Exceptions\Handler::__construct()` calls
 * `$this->register()` SYNCHRONOUSLY, during construction, so
 * Webkul's own catch-all renderable is always added to its internal
 * callback list FIRST; `bootstrap/app.php`'s `$exceptions->render()`
 * closures are only applied later, via the container's
 * `afterResolving()` hook, and `renderViaCallbacks()` returns the FIRST
 * type-match in registration order - so a later, more specific
 * registration can never win against an earlier, broader one for the
 * SAME shared callback list (this is the exact race R53/R59 both
 * document losing). `Container::extend()` sidesteps this ENTIRELY: it
 * does not compete for a slot in Webkul's own `$renderCallbacks` array
 * at all - it wraps the fully-constructed Handler object itself
 * (register() has already run on it by the time extend() receives it),
 * so THIS class's own `render()` is what `Illuminate\Foundation\Http\
 * Kernel::renderException()` actually calls (`$this->app[ExceptionHandler
 * ::class]->render(...)`, resolved fresh at the moment an exception needs
 * rendering - long after every provider has booted, so registration
 * order between this provider and Webkul's is irrelevant). This class
 * decides FIRST whether to intercept; only when it delegates does
 * Webkul's own `render()` (with its own already-populated callback list)
 * ever run - not a second, competing entry in the same list.
 *
 * ACTIVATION CONDITION: `! tenancy()->initialized`, NOT "is this a
 * configured central domain" - deliberately broader, for two reasons.
 * First, a genuinely unmatched route reaches this class with tenancy
 * uninitialized regardless of which host it was for (central, unknown,
 * or even a typo'd path under a real tenant subdomain) - restricting to
 * central domains only would leave that identical crash unfixed for an
 * unknown host, an arbitrary distinction with no correctness benefit.
 * Second, this condition provably does NOT change any ALREADY-ESTABLISHED
 * unknown-domain behavior: R51/R53's `TenantCouldNotBeIdentifiedException`
 * handling (`handleUnresolvedTenantDomains()` above) and R54's
 * `EnsureCentralDomain` both return a response DIRECTLY, from inside
 * their own middleware, and NEVER throw into the exception-handler
 * pipeline at all when they succeed at rejecting a request - this class
 * is never even invoked for those cases; there is no overlap, only
 * coverage of what was previously unhandled. The one adjacent, PRE-
 * EXISTING, explicitly-not-fixed-here finding this condition happens to
 * also touch is R62 (an unknown domain hitting an eager-controller-
 * construction route, e.g. `/admin/dashboard`, still crashes because
 * `Route::gatherMiddleware()`'s eager probe runs before ANY middleware,
 * including tenancy resolution, ever gets a chance) - since that probe's
 * own real `QueryException` is a generic `Throwable` with
 * `tenancy()->initialized` still false when it reaches this class, its
 * SEVERITY is incidentally softened (a clean, generic 500 instead of a
 * connection-killing raw crash) as an unavoidable consequence of this
 * class's own general principle - but R62 ITSELF remains open and
 * unfixed: the response is still 500, not the 404 a real fix would need
 * to produce, and nothing here was written to specifically target it.
 * Every request with tenancy genuinely initialized (a real tenant or
 * real Bagisto Admin/Storefront error) is untouched - this class always
 * delegates for those, byte-for-byte the same as today.
 *
 * Only activates when `config('app.debug') === false` - the exact same
 * condition `Webkul\Core\Exceptions\Handler::register()` itself already
 * uses to decide whether to register anything at all - so local
 * development behavior (Laravel's own Ignition/debug page) is completely
 * unaffected either way.
 *
 * CRITICAL EXCLUSION, found during implementation (not merely assumed):
 * `Platform\Tenancy\Exceptions\TenantNotReadyHttpException` (R57/R58) is
 * thrown from `preInitializeTenancyOnRouteMatch()` above PRECISELY when
 * tenancy is deliberately NOT initialized (a non-Ready tenant must never
 * be initialized before its request is rejected) - meaning
 * `! tenancy()->initialized` is true for that exception too, and this
 * class's own interception, if applied uninformed, would silently
 * bypass that exception's own purpose-built `render(Request $request)`
 * method (which produces the correct 423/503 via the shared
 * `TenantUnavailableResponder`), breaking R57/R58. `Illuminate\
 * Foundation\Exceptions\Handler::render()` itself already checks
 * `method_exists($e, 'render')` and, if it returns a response, uses it
 * UNCONDITIONALLY - structurally BEFORE `renderViaCallbacks()` ever
 * runs (confirmed by reading that method's own source, the same
 * confirmation `TenantNotReadyHttpException`'s own docblock already
 * relies on). This class mirrors that exact same short-circuit, in the
 * exact same position, before doing anything else - so any exception
 * with its own `render()` method (now or in the future) is ALWAYS
 * delegated to the inner handler, never intercepted here, regardless of
 * tenancy state.
 */
class CentralSafeExceptionHandler implements ExceptionHandler
{
    public function __construct(protected ExceptionHandler $inner) {}

    public function report(Throwable $e): void
    {
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        // Mirrors Illuminate\Foundation\Exceptions\Handler::render()'s own
        // FIRST check - an exception that knows how to render itself
        // (e.g. TenantNotReadyHttpException, R57/R58) always wins,
        // unconditionally, regardless of tenancy state. Never intercepted
        // here.
        if (method_exists($e, 'render')) {
            return $this->inner->render($request, $e);
        }

        if ($this->shouldRenderDirectly()) {
            return $this->renderDirectly($e);
        }

        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }

    /**
     * Defensively fails to `false` (delegate to the inner handler,
     * today's existing behavior) if anything about evaluating the
     * condition itself goes wrong - this class must never be the reason
     * a response fails to render at all.
     */
    protected function shouldRenderDirectly(): bool
    {
        if (config('app.debug')) {
            return false;
        }

        try {
            return ! tenancy()->initialized;
        } catch (Throwable) {
            return false;
        }
    }

    protected function renderDirectly(Throwable $e): Response
    {
        if ($e instanceof TokenMismatchException) {
            return response()->json(['message' => 'CSRF token mismatch.'], 419);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return response()->json(
                ['message' => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error')],
                $status,
                $e->getHeaders()
            );
        }

        return response()->json(['message' => 'Server Error'], 500);
    }
}
