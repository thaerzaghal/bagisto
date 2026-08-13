# System Overview

## Current Bagisto Stack (verified against repo)

- **Bagisto**: 2.4.x (`CLAUDE.md:7`)
- **Laravel**: `^12.0` (`composer.json`)
- **PHP**: `>=8.3 <8.5` (`composer.json`)
- **Database**: MySQL is the only actively-used connection type; `config/database.php` also defines `sqlite`, `mariadb`, `pgsql`, `sqlsrv` but the app defaults to `mysql` (`env('DB_CONNECTION', 'mysql')`) and nothing in `packages/Webkul` assumes a specific driver beyond standard Eloquent/query-builder usage.
- **Package count**: ~40 packages under `packages/Webkul/`, each self-contained (models, controllers, routes, views, migrations, service providers) — see `AGENTS.md`'s repository map for the full list.

## Module System — Konekt Concord

`config/concord.php` registers every package's `ModuleServiceProvider` under a `modules` array, with a shared convention class:

```php
'convention' => \Webkul\Core\CoreConvention::class,
```

`CoreConvention` (extends `Konekt\Concord\Conventions\ConcordDefault`) fixes the migrations folder (`Database/Migrations`) and manifest file (`Resources/manifest.php`) convention every package follows. Each package's `ModuleServiceProvider` extends Bagisto's own `Webkul\Core\Providers\CoreModuleServiceProvider`, which in `boot()` drives `registerMigrations()`, `registerModels()`, `registerEnums()`, `registerRequestTypes()`, `registerRoutes()` — all Concord-conventions-driven, not manual per-package boilerplate.

Every entity is a **three-component system**: a `Contract` (interface, e.g. `Webkul\Product\Contracts\Product`), a concrete `Model` (e.g. `Webkul\Product\Models\Product`), and a `Proxy` (e.g. `Webkul\Product\Models\ProductProxy extends Konekt\Concord\Proxies\ModelProxy`). Code across packages always type-hints the Contract or references the Proxy, never the concrete Model directly — this is what lets a downstream package (including ours, if ever needed) swap the concrete implementation app-wide via Concord's container binding, without touching the package that defines the Contract. This binding happens once at boot, application-wide — it is **not** naturally per-request/per-tenant, so it isn't itself a tenancy mechanism, but it's a clean extension point we can rely on for any future case where a tenant-specific product/order type is needed.

## Repository Pattern

All data access goes through `prettus/l5-repository`-based repository classes, never raw model queries in controllers (this is an explicit rule in `CLAUDE.md`: "Never use models directly for queries in controllers"). The shared base is `Webkul\Core\Eloquent\Repository`, which implements `CacheableInterface` via the `CacheableRepository` trait (repository-level result caching, config-driven under `repository.cache.*`). Example: `Webkul\Product\Repositories\ProductRepository extends Webkul\Core\Eloquent\Repository`, whose `model()` method returns the **Contract** interface (`Webkul\Product\Contracts\Product::class`), which Concord resolves to the currently-bound concrete class.

**Critical finding for multi-tenancy**: a repo-wide grep for `DB::connection(`, `->connection(`, and `config('database.default')` across `packages/Webkul` returns effectively nothing relevant — the only two hits are an unrelated Elasticsearch client reference and `Webkul\Installer\Helpers\DatabaseManager` calling `DB::connection()->getPDO()` with no explicit connection name (i.e., using whatever the default is). No model sets `$connection`. This means every repository, model, and DataGrid query in the entire codebase rides Laravel's *default* database connection — which is exactly the connection `stancl/tenancy` swaps at runtime. This is the single most important piece of evidence supporting the feasibility of database-per-tenant without core modification.

## Service Provider Boot Order

`bootstrap/providers.php` lists ~38 concrete `*ServiceProvider` classes (the `Illuminate\Foundation\ComposerScripts` + `AppServiceProvider` first, then all Webkul providers in a fixed but not obviously meaningful order — `CoreServiceProvider` lands roughly mid-list). Note that `ModuleServiceProvider`s (the Concord registrations) are *not* listed here — they're wired through `config/concord.php` and bootstrapped by Concord's own composer-discovered service provider.

Container singletons found: Elasticsearch `Client` (`CoreServiceProvider.php:131-134`), Laravel's `blade.compiler` (`CoreServiceProvider.php:136-139`), `image_manager` (`ImageCacheServiceProvider.php:21`), OAuth `Factory` (`SocialLoginServiceProvider.php:38`), `view.finder`/`ViewRenderEventManager` (`ThemeServiceProvider.php:21,29`). None of these directly hold tenant/connection state, but see [caching.md](caching.md) and [search.md](search.md) for the facade/singleton risks that *do* matter (`Core`, `SystemConfig`, the Elasticsearch client).

## Auth Guards

`config/auth.php` defines exactly two guards, both session-driver: `admin` (provider `Webkul\User\Models\Admin`) and `customer` (provider `Webkul\Customer\Models\Customer`). No `sanctum`/`api` guard is defined despite `laravel/sanctum ^4.0` being a dependency. Admin authorization/ACL is enforced by a single custom middleware, `Webkul\User\Http\Middleware\Bouncer`, not a per-guard `Authenticate` class — see [security.md](security.md).

## Channels, Locales, Currencies

`Webkul\Core\Models\Channel` is Bagisto's existing **multi-store-in-one-database** primitive (`hostname`, `code`, `theme`, `default_locale_id`, `base_currency_id`, `root_category_id`, `is_maintenance_on`, `allowed_ips`, plus many-to-many `locales`/`currencies`). Resolution is HTTP-Host-header based, `Webkul\Core\Core::getCurrentChannel()`. This coexists with our tenant model rather than conflicting with it — see [tenancy.md](tenancy.md) for the required middleware ordering.

## Installer Package (provisioning template)

`packages/Webkul/Installer` is Bagisto's own first-run setup wizard: server requirement checks → write `.env` → `migrate:fresh` → `BagistoDatabaseSeeder::run()` (seeds default channel/locale/currency/role) → optional demo product seed + Elasticsearch reindex → insert first admin (`id=1, role_id=1`) → write a flat `storage/installed` marker file. The seeder classes are reusable as-is against a dynamically bound tenant connection; the command's `.env`-rewriting and flat-file completion marker are global, single-instance assumptions and are **not** reused — see [provisioning.md](provisioning.md).
