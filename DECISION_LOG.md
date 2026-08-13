# Decision Log

Status legend: **STANDING** = accepted per the original brief, confirmed compatible with repo evidence. **PROPOSED** = new decision surfaced during discovery, needs approval. **HUMAN DECISION REQUIRED** = cannot proceed without explicit sign-off.

## Standing decisions (from original brief, confirmed against repo evidence)

**C1. Bagisto remains the commerce core.**
Confirmed feasible: no model/repository/DataGrid class hardcodes a DB connection, and the Concord + repository-pattern architecture is coherent enough to leave untouched.

**C2. SaaS functionality is implemented as custom modules/packages.**
Confirmed pattern exists to copy: every Webkul package is `Providers/{Name}ServiceProvider.php` + `Providers/ModuleServiceProvider.php`, registered in `bootstrap/providers.php` + `config/concord.php`. Our packages will follow the same shape but live under `packages/Platform/*` (see C11 below).

**C3. Database-per-tenant is the selected isolation strategy.**
Confirmed as viable. `stancl/tenancy` v3.10.1 supports it natively and is compatible with this repo's Laravel 12 / PHP 8.3–8.4 stack (verified against Packagist: `php: ^8.0`, `illuminate/support: ^10.0|^11.0|^12.0|^13.0`, last updated 2026-08-08).

**C4. Central database stores platform-level SaaS data.**
Confirmed. See [docs/architecture/database-per-tenant.md](docs/architecture/database-per-tenant.md) for schema.

**C5. Tenant databases contain Bagisto commerce data.**
Confirmed. Standard Bagisto schema, unmodified, migrated per-tenant using the same package migrations Bagisto already ships (`Database/Migrations` per package, Concord-registered).

**C6. Tenancy package selection is based on actual compatibility.**
Done — see ADR-003.

**C7. Payment provider is initially an architectural abstraction.**
Confirmed appropriate: `laravel/cashier ^16.0` is already a dependency but is completely unused in this codebase (no `Billable` trait, no `Laravel\Cashier` import anywhere) — a clean slate. See ADR-004.

**C8. No unnecessary Bagisto core modifications.**
Confirmed achievable for everything identified in Phase 0. The one item requiring the most care is the Elasticsearch client singleton (`packages/Webkul/Core/src/Providers/CoreServiceProvider.php:131-134`) — addressed via `app()->forgetInstance()` from our own listener, not a core edit. No other core edit has been identified as necessary. If one is discovered later, it must follow the process in the brief (identify reason → why extension can't solve it → smallest possible diff → document as ADR → assess upgrade impact) before being made.

**C9. Retail is the initial vertical.** Unchanged — no repo evidence bears on this; it's a product decision, not a technical one.

**C10. Restaurants are future scope.** Unchanged, same reasoning as C9.

## New decisions surfaced by repository evidence (PROPOSED — need approval)

**C11. Custom SaaS packages live under `packages/Platform/*`, not `packages/Webkul/*`.**
Why: `packages/Webkul/*` is upstream Bagisto's own namespace. Every future `bagisto/bagisto` release can add, rename, or restructure packages inside it. Placing our code in that namespace risks a direct collision on `git pull upstream` (e.g., if Webkul ever ships a package literally named `Tenant` or `Billing`). A sibling `packages/Platform/*` directory with its own Composer PSR-4 namespace (`Platform\`) guarantees upstream merges can never touch our code, and vice versa. This mirrors C8/C1's "don't touch core" intent one level deeper than the brief specified.

**C12. A third auth guard (`platform`) is added for platform administrators, kept in the central database, entirely separate from Bagisto's `admin` guard/model.**
Why: Bagisto's `Role`/ACL system (`packages/Webkul/User/src/Models/Role.php`) has no channel/tenant/store scoping column — it was built for one Bagisto instance's admins. Trying to overload it to also represent "platform staff who can see all tenants" would require a core schema change. Database-per-tenant naturally solves this instead: platform admins are rows in a central-DB table under their own guard, tenant admins remain ordinary Bagisto `admins` rows inside each tenant's own database, completely unmodified. See ADR-002.

**C13. Tenant provisioning reuses Bagisto's own `BagistoDatabaseSeeder` and package migrations, not a bespoke reimplementation, but does NOT reuse the `packages/Webkul/Installer` artisan command or its `.env`-rewriting / flat-file "installed" marker.**
Why: `Installer/src/Console/Commands/Installer.php` writes DB credentials into the app's single `.env` file and marks completion with `storage_path('installed')` — both are global, single-tenant assumptions that cannot represent "N tenants, each independently provisioned." The *seeders* it calls (`Installer/src/Database/Seeders/*/DatabaseSeeder.php` via `BagistoDatabaseSeeder`) are reusable as-is against a dynamically-bound tenant connection. See ADR-001 and [docs/architecture/provisioning.md](docs/architecture/provisioning.md).

**C14. Default cache store must move from `file` to a taggable/prefixable store (Redis recommended) before multi-tenant cache isolation can be guaranteed in production.**
Why: `stancl/tenancy`'s `CacheTenancyBootstrapper` needs a store it can safely key-prefix per tenant. `file` cache store works but the FPC/response-cache full-page cache (`RESPONSE_CACHE_ENABLED=true` by default) and several `Cache::remember()` call sites (`Webkul\Shop\Helpers\CatalogApiCache`, `Webkul\PhonePe\Payment\PhonePe`) build cache keys today without any tenant component. This is not a core-modification question — it's an infrastructure/config decision. Flagged **HUMAN DECISION REQUIRED** below because it affects hosting cost and ops complexity, not just code.

**C15. Full-page response caching (`spatie/laravel-responsecache` / `Webkul\FPC`) is disabled by default for the SaaS build until tenant-scoped cache keys are verified end-to-end, and re-enabled per-tenant only after Phase 13 (cache isolation) testing passes.**
Why: `RESPONSE_CACHE_ENABLED=true` ships as the Bagisto default and the cache key hasher (`packages/Webkul/FPC/src/Hasher/DefaultHasher.php:30-38`) scopes only by channel/locale/currency **code strings** — if two tenants both use a channel coded `default` (a very likely default value), their full-page HTML could collide in a shared cache store. This is the single highest-severity cross-tenant leak risk found in Phase 0 discovery. See [RISK_REGISTER.md](RISK_REGISTER.md) R1.

## HUMAN DECISION REQUIRED

1. **Cache store for production** (relates to C14): approve moving the default cache store to Redis (with `stancl/tenancy`'s cache-tenancy bootstrapping) for the SaaS build, understanding this is an infrastructure cost/ops decision, not just code.
2. **Payment provider for SaaS billing** (relates to C7): no provider is selected yet. `laravel/cashier` (Stripe-only) is already a dependency and the path of least resistance, but the brief explicitly says not to assume Stripe. Needs a business decision on supported countries/currencies for *charging tenants* before ADR-004 can be finalized.
3. **Custom-domain SSL strategy**: not resolved in Phase 0. Requires deciding whether SSL is automated (e.g., wildcard cert for subdomains + on-demand cert issuance for custom domains via a proxy like Caddy/Traefik or a CDN) or manually provisioned per tenant. This has real infrastructure cost implications and is out of scope for application-layer architecture alone.
4. **Whether to enable Laravel Octane at all for this project.** It's a bundled dependency but dormant. Running under Octane would require resetting several PHP-process-lifetime caches (`Core` facade instance state, `SystemConfig`, the Elasticsearch client singleton) on every tenant switch — extra engineering work with a real correctness risk if missed. Recommend deferring Octane adoption until after the MVP (Phase 18+), running under standard PHP-FPM/Octane-off for the initial launch.
