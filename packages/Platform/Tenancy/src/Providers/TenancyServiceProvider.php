<?php

declare(strict_types=1);

namespace Platform\Tenancy\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Platform\Tenancy\Console\Commands\MarkPlatformInstalled;
use Platform\Tenancy\Console\Commands\MigrateCentral;
use Platform\Tenancy\Console\Commands\MigratePendingTenants;
use Platform\Tenancy\Console\Commands\ProductionReadinessCheck;
use Platform\Tenancy\Console\Commands\ProvisionTenant;
use Platform\Tenancy\Console\Commands\ReindexTenant;
use Platform\Tenancy\Console\Commands\RepairChannelHostname;
use Platform\Tenancy\Http\Middleware\TenantAccessGate;
use Platform\Tenancy\Listeners\EndTenancyAfterJobRelease;
use Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema;
use Platform\Tenancy\Listeners\RejectBagistoInstallAgainstProtectedDatabase;
use Platform\Tenancy\Listeners\RetargetElasticsearchIndexPrefix;
use Platform\Tenancy\Listeners\RetargetImageCachePaths;
use Platform\Tenancy\Services\CentralDatabaseWipeGuard;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],

            // NOTE: stancl's own scaffold wires TenantCreated -> CreateDatabase + MigrateDatabase
            // (and TenantDeleted -> DeleteDatabase) here via a JobPipeline. TASK-ARCH-002
            // deliberately does NOT do that: Platform\Tenancy\Services\TenantProvisioner owns
            // database creation/migration/seeding explicitly instead, so that provisioning is
            // observable (tenant status/last_error), retryable, and safe against partial failure
            // (see docs/architecture/provisioning.md) rather than an implicit, fire-and-forget
            // event side-effect of Tenant::create(). Creating a Tenant row is now a pure data
            // operation with no side effects; only TenantProvisioner::provision() touches
            // the database server.
            Events\TenantCreated::class => [],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],

            // TASK-ARCH-005 (R16): fires after every configured bootstrapper -
            // including FilesystemTenancyBootstrapper - has run, so
            // storage_path()/public_path() already reflect this tenant. See
            // Platform\Tenancy\Listeners\RetargetImageCachePaths for why this
            // is needed at all.
            //
            // TASK-ARCH-007: same event, retargets config('elasticsearch.
            // index_prefix') per tenant - see Platform\Tenancy\Listeners\
            // RetargetElasticsearchIndexPrefix for why (Webkul\Product's
            // Elasticsearch index name has no tenant identifier in it
            // otherwise).
            Events\TenancyBootstrapped::class => [
                [RetargetImageCachePaths::class, 'bootstrapped'],
                [RetargetElasticsearchIndexPrefix::class, 'bootstrapped'],
            ],

            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [
                [RetargetImageCachePaths::class, 'reverted'],
                [RetargetElasticsearchIndexPrefix::class, 'reverted'],
            ],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
            Events\SyncedResourceChangedInForeignDatabase::class => [],

            // TASK-ARCH-006 (R6/R7): a real, reproduced gap in Stancl\Tenancy\
            // Bootstrappers\QueueTenancyBootstrapper's own cleanup - it only
            // reverts tenancy on JobProcessed/JobFailed, neither of which fires
            // when a job throws but still has retries remaining (Laravel fires
            // JobReleasedAfterException instead in that case). See
            // Platform\Tenancy\Listeners\EndTenancyAfterJobRelease for the full
            // reproduction and reasoning.
            JobReleasedAfterException::class => [
                EndTenancyAfterJobRelease::class,
            ],

            // TASK-ARCH-008 cleanup round (R30): a defense-in-depth safety
            // net, not the primary mechanism (that's the dedicated
            // `platform:migrate:central` command) - see
            // PreventCentralMigrationOfTenantSchema's own docblock.
            MigrationStarted::class => [
                PreventCentralMigrationOfTenantSchema::class,
            ],

            // INCIDENT-001. Best-effort, real-CLI-usage-only companion to
            // CentralDatabaseWipeGuard::apply() (called from boot() below) -
            // see RejectBagistoInstallAgainstProtectedDatabase's own
            // docblock for why this listener is not, by itself, the tested
            // guarantee.
            CommandStarting::class => [
                RejectBagistoInstallAgainstProtectedDatabase::class,
            ],
        ];
    }

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->bootEvents();
        $this->mapRoutes();
        $this->attachTenancyToImageCacheRoute();
        $this->makeTenancyMiddlewareHighestPriority();
        $this->registerCommands();
        $this->handleUnresolvedTenantDomains();
        $this->preInitializeTenancyOnRouteMatch();

        // INCIDENT-001. Must run before any command's handle() executes -
        // every provider's boot() runs before the console Kernel dispatches
        // the requested command, so this ordering holds regardless of
        // provider registration order. See CentralDatabaseWipeGuard's own
        // docblock for the full reasoning.
        CentralDatabaseWipeGuard::apply();

        // TASK-ARCH-013/014: the 'tenancy::suspended' and 'tenancy::unavailable'
        // views TenantAccessGate renders for a non-ready tenant's non-JSON
        // requests.
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'tenancy');

        // Only the root database/migrations/tenant folder is registered here -
        // never packages/Platform/Tenancy/src/Database/Migrations (this package
        // has none yet, and never will for anything central-only; see the
        // migration file comment for why that distinction is load-bearing).
        // Every packages/Webkul/*/src/Database/Migrations path is ALREADY
        // registered globally by each Webkul package's own ServiceProvider -
        // TenantProvisioner discovers those dynamically via the migrator's own
        // registered-paths list rather than us re-declaring them here (see
        // docs/architecture/provisioning.md, "Bagisto tenant migration strategy").
        $this->loadMigrationsFrom(database_path('migrations/tenant'));
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionTenant::class,
                MarkPlatformInstalled::class,
                ReindexTenant::class,
                MigrateCentral::class,
                MigratePendingTenants::class,
                RepairChannelHostname::class,
                ProductionReadinessCheck::class,
            ]);
        }
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes()
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    /**
     * TASK-ARCH-005 finalization (RISK_REGISTER.md R25): Webkul\ImageCache\
     * Providers\ImageCacheServiceProvider::bootImageCache() registers its
     * `cache/{template}/{filename}` route directly on the router
     * ($this->app['router']->get(...)), from inside that provider's own
     * boot() method, entirely outside routes/web.php and outside any
     * Route::group() - confirmed live via route-object inspection during
     * TASK-ARCH-005 (its ->middleware() is an empty array). This app's
     * tenant resolution is only prepended to the 'web' middleware GROUP
     * (bootstrap/app.php), not appended globally, so that route never runs
     * it, for any Host header, central or tenant.
     *
     * The fix deliberately does NOT touch packages/Webkul/ImageCache (the
     * one-line change of adding ->middleware() to that route registration
     * would be trivial, but is still a Bagisto core file, and this task's
     * rule is zero exceptions regardless of size) and deliberately does NOT
     * make tenant resolution middleware globally applied (which would also
     * silently change behavior for /up and any other package that
     * registers routes the same direct-router way ImageCache does - a much
     * larger, unaudited blast radius than this one route needs).
     *
     * Instead: find the already-registered 'imagecache' named route
     * (Illuminate\Routing\Route objects support ->middleware() being called
     * AFTER registration - this is a fully supported, standard Laravel
     * mechanism, not a hack) and attach tenancy middleware to just that one
     * route. This must run inside $this->app->booted(...), exactly like
     * mapRoutes() above - Laravel boots every service provider's register()
     * then every provider's boot() in registration order BEFORE firing
     * 'booted' callbacks, so by the time this callback runs, EVERY
     * provider's routes (regardless of registration order, including
     * Webkul\ImageCache's, which boots after this provider) are guaranteed
     * to already be registered. Running this directly in boot() instead
     * (without the booted() wrapper) would silently no-op, since the
     * ImageCache route would not exist yet at that point.
     *
     * PreventAccessFromCentralDomains is included deliberately: ImageCache
     * serves tenant-owned media (product/category/theme images), and no
     * real central/platform route consumes this endpoint today (per
     * docs/architecture/domain-routing.md, no real platform UI exists yet)
     * - so there is no known legitimate central use case to preserve. The
     * one exception is the 'logo' template (fetches Bagisto's own remote
     * logo over HTTP, touches no tenant storage at all) - if a future
     * central platform admin UI needs that specific template, this
     * decision should be revisited then, not preemptively worked around
     * now for a use case that doesn't exist yet.
     */
    protected function attachTenancyToImageCacheRoute(): void
    {
        $this->app->booted(function () {
            $route = $this->app['router']->getRoutes()->getByName('imagecache');

            if (! $route) {
                // Webkul\ImageCache not installed, or its route name/config
                // changed - nothing to attach to. Fail open to "not
                // tenant-aware" rather than throwing, since this is a
                // best-effort integration with a package we don't own.
                return;
            }

            $route->middleware([
                Middleware\PreventAccessFromCentralDomains::class,
                Middleware\InitializeTenancyByDomain::class,
            ]);
        });
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        $tenancyMiddleware = [
            // TASK-ARCH-013/014: must run before EVERYTHING below it,
            // including PreventAccessFromCentralDomains - it makes its own
            // lifecycle-eligibility decision from a fresh central resolve
            // before any tenancy middleware (or the tenant DB connection
            // swap they trigger) ever runs. See that class's own docblock
            // for the full flow.
            TenantAccessGate::class,

            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[\Illuminate\Contracts\Http\Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }

    /**
     * TASK-MVP-004B (RISK_REGISTER.md R51). Fixes a real production bug
     * found during the pilot deployment: under APP_DEBUG=false, an unknown
     * Host header produced a raw 500 instead of the clean 404 bootstrap/app.php
     * already tries to configure via `$exceptions->render(TenantCouldNotBeIdentifiedException
     * ::class, ...)`.
     *
     * ROOT CAUSE (confirmed by reading the actual framework/vendor source,
     * not assumed): Illuminate\Foundation\Exceptions\Handler::__construct()
     * calls $this->register() synchronously, DURING construction. Since
     * Webkul\Core\Providers\CoreServiceProvider binds (not singletons)
     * ExceptionHandler::class to Webkul\Core\Exceptions\Handler, and that
     * class's own register() only registers a catch-all `Throwable`
     * renderable when config('app.debug') is false, its callback always
     * gets added to the handler's renderCallbacks list DURING construction -
     * before bootstrap/app.php's own `$exceptions->render(...)` closure ever
     * runs (that one only fires via the container's afterResolving() hook,
     * which necessarily happens AFTER construction completes -
     * Illuminate\Foundation\Configuration\ApplicationBuilder::withExceptions()).
     * Illuminate\Foundation\Exceptions\Handler::renderViaCallbacks() returns
     * the FIRST type-match in registration order, and Bagisto's callback
     * matches the universal `Throwable` type - so it always wins that race
     * under APP_DEBUG=false, downgrading what should be a clean 404 into
     * Bagisto's generic `shop::errors.500` view, which itself needs tenant
     * tables that were never initialized for an unresolved host - producing
     * a raw 500-on-500. This was never caught earlier in this engagement
     * because every prior test/local run used APP_DEBUG=true, under which
     * Webkul\Core\Exceptions\Handler::register() early-returns and never
     * registers anything at all.
     *
     * FIX: rather than trying to out-race that registration-order problem
     * inside Laravel's exception-handler pipeline, this sidesteps it
     * entirely by using stancl/tenancy's own purpose-built extension point.
     * Stancl\Tenancy\Middleware\IdentificationMiddleware::initializeTenancy()
     * (the base class InitializeTenancyByDomain extends) already wraps its
     * own tenant resolution in a try/catch keyed on exactly this exception,
     * and calls `static::$onFail` when it fires - left unset, that defaults
     * to `fn ($e) => throw $e`, which is what put the exception on the race
     * course above in the first place. Setting InitializeTenancyByDomain's
     * OWN static $onFail here intercepts the failure at its actual source,
     * before it is ever thrown into the exception-handler pipeline - so the
     * registration-order race described above never gets a chance to
     * happen, regardless of APP_DEBUG. This is the same pattern
     * Platform\Tenancy\Http\Middleware\TenantAccessGate already uses
     * successfully for the identical exception type, just applied at
     * stancl's own documented failure hook instead of a second, wrapping
     * middleware.
     *
     * The response returned matches bootstrap/app.php's own pre-existing
     * TenantCouldNotBeIdentifiedException render() callback byte-for-byte -
     * always JSON, regardless of Accept header, since a generic
     * {"message": "Not Found"} body leaks nothing and needs no view render
     * (which would otherwise require tenant tables that do not exist for an
     * unresolved host). That bootstrap/app.php handler is deliberately left
     * in place as a defense-in-depth fallback for any other code path that
     * might still reach the exception-handler pipeline directly (e.g. a
     * console/artisan context) - it simply becomes dead code for the normal
     * 'web' HTTP path now that this $onFail hook intercepts the exception
     * before it gets there.
     */
    protected function handleUnresolvedTenantDomains(): void
    {
        Middleware\InitializeTenancyByDomain::$onFail = function (
            \Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException $exception,
            $request
        ) {
            return response()->json(['message' => 'Not Found'], 404);
        };
    }

    /**
     * TASK-MVP-004B (RISK_REGISTER.md R57). A real, reproducible production
     * bug: a Ready tenant Admin's FIRST visit to `/admin/dashboard` (and,
     * by the same mechanism, `/admin/reports` - see below) raw-500s with
     * `SQLSTATE[42S02]: Base table or view not found: 1146 Table
     * 'bagisto_central.channels' doesn't exist`.
     *
     * ROOT CAUSE (confirmed by reading Laravel's own routing source, not
     * assumed, and by a real `DB::listen()` trace during investigation):
     * `Illuminate\Routing\Router::runRouteWithinStack()` calls
     * `Route::gatherMiddleware()` to determine a route's EFFECTIVE
     * middleware list BEFORE the middleware pipeline itself ever runs (this
     * happens strictly before `TenantAccessGate`/`InitializeTenancyByDomain`,
     * both 'web'-group middleware) - and `Route::controllerMiddleware()`
     * (part of that gathering step) must INSTANTIATE the target controller
     * via the container to read its own declared per-action middleware,
     * whenever `method_exists($controllerClass, 'getMiddleware')` is true.
     * That method is inherited, unconditionally, from Laravel's own base
     * `Illuminate\Routing\Controller` (confirmed by reading its source) -
     * meaning EVERY Bagisto Admin/Shop controller extending the classic
     * base controller is eagerly, wastefully instantiated this way for
     * EVERY request to EVERY such route, regardless of whether it declares
     * any middleware at all. This throwaway, discarded instance - never
     * used to actually handle the request - runs its full constructor
     * dependency chain while the database connection is still central.
     * For `Webkul\Admin\Http\Controllers\DashboardController` (constructor-
     * injects `Webkul\Admin\Helpers\Dashboard`, which constructor-injects
     * `Webkul\Admin\Helpers\Reporting\{Sale,Product,Customer}`) and
     * `Webkul\Admin\Http\Controllers\Reporting\Controller` (constructor-
     * injects `Webkul\Admin\Helpers\Reporting`, which constructor-injects
     * ALL FOUR `Reporting\{Sale,Product,Customer,Cart}` helpers), that
     * chain reaches `Webkul\Admin\Helpers\Reporting\AbstractReporting`'s
     * own constructor, which unconditionally calls `Webkul\Core\Core::
     * getAllChannels()` - a real query against a table that legitimately
     * only exists per-tenant. This is a GENERAL architectural risk, not a
     * single-controller quirk (confirmed by finding two independent real
     * controllers hitting it, both reachable via real Admin navigation) -
     * structurally unreachable in stock, single-database Bagisto (there is
     * nothing else for "central" to mean there), and never caught anywhere
     * in this engagement's history before a real multi-tenant production
     * signup, because every earlier admin-dashboard visit in this
     * codebase's tests reused an already-cache-warmed fixture tenant
     * rather than a genuinely fresh one on a genuinely separate PHP-FPM-
     * style request (Pest's in-process HTTP testing does not reproduce
     * this at all - confirmed empirically; a real `php artisan serve`
     * process was required).
     *
     * FIX: initialize tenancy as early as `Illuminate\Routing\Events\
     * RouteMatched` - fired by `Router::runRoute()`, BEFORE
     * `runRouteWithinStack()`'s middleware-gathering step ever runs - so
     * that by the time Laravel's throwaway controller probe executes for a
     * READY tenant, tenancy (and the real tenant database connection) is
     * already active, and the discarded probe's queries land on the
     * correct tenant database instead of central.
     *
     * CRITICAL SAFETY REQUIREMENT (TASK-ARCH-013/014's own invariant - a
     * non-ready tenant's database must never be touched before
     * `TenantAccessGate` rejects the request centrally): this listener
     * uses `Platform\Tenancy\Services\TenantHostResolver::isReady()` - the
     * SAME shared resolver `TenantAccessGate` itself uses for its own
     * status decision - and ONLY calls `tenancy()->initialize()` when the
     * resolved tenant is `TenantStatus::Ready`. A Suspended/Pending/
     * Provisioning/Failed/Deleting/Deleted tenant is deliberately left
     * uninitialized here; `TenantAccessGate` (which runs moments later, in
     * the normal 'web' middleware pipeline, using this exact same
     * resolver) remains the ONLY place that rejects a non-ready tenant,
     * and still does so before any tenant database access - verified live,
     * with query-connection instrumentation, for all 6 non-ready statuses
     * against both a route without the eager-construction issue (storefront
     * `/`, correctly 503/423, exactly as before) and a route with it
     * (`/admin/dashboard`, 500 either way - see the important caveat
     * below). Extracting the resolve+isReady decision into
     * `TenantHostResolver` (rather than duplicating a second Ready/
     * Suspended/etc. matrix here) is deliberate - see that class's own
     * docblock.
     *
     * IMPORTANT, HONEST CAVEAT (RISK_REGISTER.md R58, a separate, still-
     * open finding - NOT fixed by this listener, and not something this
     * listener could fix by itself): a non-ready tenant hitting
     * `/admin/dashboard` specifically still raw-500s, exactly as it did
     * BEFORE this fix existed (confirmed by reverting this listener and
     * re-testing) - because `gatherRouteMiddleware()`'s eager, crashing
     * construction happens BEFORE ANY middleware, including
     * `TenantAccessGate`, regardless of whether tenancy gets initialized
     * early or not; a non-ready tenant is deliberately never initialized
     * here, so the SAME central-connection `channels` crash that affects
     * a Ready tenant's first visit ALSO affects a non-ready tenant's visit
     * to this one specific route family - it was never reachable at all
     * from `TenantAccessGate`'s own 423/503 response before Laravel's
     * eager probe already threw. The security-critical property (no
     * tenant database access for a non-ready tenant) is fully preserved;
     * the UX property ("Suspended always shows the 423 lock page") is not
     * yet achieved for this specific route family, and requires a
     * follow-up fix (a shared, RouteMatched-level short-circuit reusing
     * `TenantAccessGate`'s own response-building - see R58).
     *
     * `Tenancy::initialize()` has its own built-in idempotency guard
     * (returns immediately, without re-running any bootstrapper, if
     * already initialized for the same tenant), so `InitializeTenancyByDomain`
     * running normally moments later in the real middleware pipeline is a
     * safe no-op, not a double-initialization - confirmed by reading
     * `Stancl\Tenancy\Tenancy::initialize()`'s own source, and the 4
     * active bootstrappers (`DatabaseTenancyBootstrapper`,
     * `CacheTenancyBootstrapper`, `FilesystemTenancyBootstrapper`,
     * `QueueTenancyBootstrapper`) each only ever run once per tenant per
     * request regardless. `Platform\Tenancy\Models\Tenant`/`Domain` (via
     * stancl's own `CentralConnection` trait, which hardcodes
     * `getConnectionName()` to always return the central connection name)
     * are immune to the current default connection being switched to
     * 'tenant' early, so `TenantAccessGate`'s own re-resolution moments
     * later is unaffected regardless of what this listener already did.
     * Scoped to 'web'-group routes only (checked via the route's own
     * declared middleware) so central-only ('platform'-group) requests -
     * Platform Admin, `/join`, billing webhooks - never attempt tenant
     * resolution at all, even with a manipulated Host header pointing at a
     * real tenant (verified live).
     */
    protected function preInitializeTenancyOnRouteMatch(): void
    {
        Event::listen(\Illuminate\Routing\Events\RouteMatched::class, function (\Illuminate\Routing\Events\RouteMatched $event) {
            if (tenancy()->initialized) {
                return;
            }

            if (! in_array('web', $event->route->middleware(), true)) {
                return;
            }

            $hostResolver = app(\Platform\Tenancy\Services\TenantHostResolver::class);
            $tenant = $hostResolver->resolve($event->request->getHost());

            // CRITICAL: only a tenant TenantAccessGate would ALSO let
            // through gets initialized here. A Suspended/Pending/
            // Provisioning/Failed/Deleting/Deleted tenant's database is
            // never touched by this listener - TenantAccessGate (which
            // runs moments later, in the normal 'web' middleware
            // pipeline, using this SAME shared TenantHostResolver) is
            // still the ONLY place that rejects a non-ready tenant, and
            // still does so before any tenant database access. See this
            // class's own docblock above `preInitializeTenancyOnRouteMatch`
            // for why this check cannot be skipped or weakened.
            if (! $hostResolver->isReady($tenant)) {
                return;
            }

            tenancy()->initialize($tenant);
        });
    }
}
