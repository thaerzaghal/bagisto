<?php

declare(strict_types=1);

namespace Platform\Tenancy\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Platform\Tenancy\Console\Commands\MarkPlatformInstalled;
use Platform\Tenancy\Console\Commands\ProvisionTenant;
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
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
            Events\SyncedResourceChangedInForeignDatabase::class => [],
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
