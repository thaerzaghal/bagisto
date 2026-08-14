<?php

declare(strict_types=1);

namespace Platform\Tenancy\Providers;

use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Platform\Tenancy\Console\Commands\MarkPlatformInstalled;
use Platform\Tenancy\Console\Commands\ProvisionTenant;
use Platform\Tenancy\Console\Commands\ReindexTenant;
use Platform\Tenancy\Listeners\EndTenancyAfterJobRelease;
use Platform\Tenancy\Listeners\RetargetElasticsearchIndexPrefix;
use Platform\Tenancy\Listeners\RetargetImageCachePaths;
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
}
