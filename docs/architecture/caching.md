# Cache Isolation

**Status: RESOLVED (TASK-ARCH-004, 2026-08-14).** This document originally recorded Phase 0's theoretical prediction (repo-reading only). It now records what was actually built, and — critically — what was empirically confirmed both broken and then fixed, via live reproduction in both directions. Nothing below is inferred from code reading alone unless explicitly marked as such.

## Before (confirmed broken, TASK-ARCH-001 through TASK-ARCH-003)

Default cache store was `file`. `Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper` was disabled from TASK-ARCH-001 onward because it hard-crashed against `file` (see "Root cause" below). Three concrete leak vectors were identified by code reading (R1–R3, Phase 0) and one was **reproduced live** with real HTTP requests through the real, unmodified Bagisto Shop API (R21, TASK-ARCH-003):

- `Webkul\Shop\Helpers\CatalogApiCache` keys its no-query storefront listing cache by channel id + locale + currency + params — not tenant. Two tenants' default channels both get id=1 (fresh per-database auto-increment), locale=`en`, currency=`USD` — an exact key collision. **Reproduced**: `tenant-b.localhost/api/products` returned tenant A's cached `["PROD-A"]` instead of tenant B's own `["PROD-B"]` — see the git history of `tests/Feature/Platform/TenantDomainRoutingTest.php`'s "section 9" test, which asserted the bug before TASK-ARCH-004 and asserts the fix now.
- `Webkul\PhonePe\Payment\PhonePe::getAccessToken()` caches an OAuth token under the flat key `phonepe_access_token` — a credential leak vector across tenants, not reproduced live (no sandbox credentials available) but fixed by the same mechanism, see below.
- `prettus/l5-repository`'s `CacheableRepository` trait (used by `Webkul\Core\Eloquent\Repository`) builds cache keys as `{RepositoryClass}@{method}-{md5(args+criteria+$request->fullUrl())}` — including the request URL gave *accidental*, *fragile* protection for HTTP-triggered calls to tenant subdomains (different tenant → different URL → different key) but **none at all** for CLI/console-triggered calls (e.g., inside `TenantProvisioner`), where `Request::fullUrl()` is a synthetic default shared by every process.

## Root cause of the `CacheTenancyBootstrapper` crash (confirmed by reading `Stancl\Tenancy\CacheManager`)

`CacheTenancyBootstrapper::bootstrap()` replaces the `'cache'` container binding with `Stancl\Tenancy\CacheManager`, whose `__call()` **unconditionally** routes every cache method through `$this->store()->tags($tags)->$method(...)` — tagging is not optional, it *is* the isolation mechanism. Laravel's `file` and `database` cache stores do not extend `Illuminate\Cache\TaggableStore` (confirmed by reading Laravel's own `Cache/*.php`); `array`, `redis`, and `memcached` do. Calling `->tags()` on a non-taggable store throws `BadMethodCallException` — not a bug to patch around, a hard structural requirement.

## Fix (implemented, verified both broken→fixed and fixed→broken)

1. `config/tenancy.php`: `CacheTenancyBootstrapper` is enabled.
2. `.env` / `.env.example`: `CACHE_STORE` set to a taggable store — `array` for local dev/automated tests (in-process, zero new infra), `redis` for production (`.env.example`'s shipped default — already provisioned in `docker-compose.yml`, just needed enabling).

**No code changes were needed in `CatalogApiCache.php`, `PhonePe.php`, or any repository class.** All three resolve the cache through `app('cache')`/the `Cache` facade, which `CacheTenancyBootstrapper` transparently swaps for the duration of an initialized tenancy — every consumer in the app rides the fix for free. This is why the brief's "prefer configuration/extension points over new code" guidance was fully achievable here.

**Verification, not assumption**: the fix was confirmed by literally flipping the previously-failing R21 assertion and watching it fail with the fix commented out, then pass with it enabled (`tests/Feature/Platform/TenantDomainRoutingTest.php`, `TenantCacheIsolationTest.php`) — 17 tests, 78+ assertions, run against real MySQL and a real cache store, nothing mocked.

## An important nuance found while writing the tests: `array` store and bootstrap-cycle persistence

`CacheTenancyBootstrapper::bootstrap()` creates a **brand-new** `Stancl\Tenancy\CacheManager` instance every time tenancy initializes (confirmed via `spl_object_hash()` — a different object on every separate `tenant->run()` call or HTTP request). Laravel's `array` store keeps its data in PHP process memory *on the store object itself*; a fresh manager instance means a fresh, empty in-memory store. Consequence: **a value written in one tenancy-bootstrap cycle does not survive into the next one, even for the same tenant, when `CACHE_STORE=array`.** This is a property of the `array` driver's in-process nature, not an isolation bug — it doesn't cause any cross-tenant leak (confirmed: values also never leak to a *different* tenant), it just means `array` cannot prove or provide cross-request cache *persistence*, only cross-tenant *isolation*.

Confirmed directly against a real Redis container (`docker compose up -d redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=predis` — no PHP extension needed, `predis/predis` is already a dependency) that this limitation is `array`-specific: raw `Redis::connection('cache')->keys('*')` after writing from both tenants showed exactly the expected structure —

```
tag:tenanttenant-a:entries
tag:tenanttenant-b:entries
{hash-a}:clean-probe   # tenant A's value, a different hash than tenant B's
{hash-b}:clean-probe   # tenant B's value
```

— distinct tag-membership sets and cryptographically distinct value keys per tenant (Laravel's standard tagged-cache implementation), with values correctly surviving across separate bootstrap cycles (external, out-of-process storage, unlike `array`). This is the direct evidence behind the production recommendation, not just the general "Redis is usually better" heuristic.

## Cache classification: GLOBAL vs. TENANT-SCOPED

No manual allow-list or key-prefixing scheme was built, and none was needed. The classification falls out of *when* code runs relative to tenancy initialization, which `CacheTenancyBootstrapper` already respects structurally:

- **TENANT-SCOPED** (automatically, once initialized): anything reached while a tenant's context is active — `CatalogApiCache`, repository caching for the cache-enabled repositories (`Channel`, `CoreConfig`, `Country`, `CountryState`, `Currency`, `Locale` per `config/repository.php`), `PhonePe`'s token cache, and any future tenant-context cache usage. All of it, automatically, with no per-call-site opt-in.
- **GLOBAL** (automatically, by construction): anything reached *outside* an initialized tenancy — central/platform-context code (none exists yet; Phase 8) never triggers `CacheTenancyBootstrapper::bootstrap()` at all, so it always uses the plain, untagged cache manager. Verified directly: a `Cache::put()`/`Cache::get()` round-trip performed with no `tenant->run()` wrapper behaves like ordinary Laravel caching (`tests/Feature/Platform/TenantCacheIsolationTest.php`, "central-context cache usage" test).

## Cache invalidation

`Webkul\Shop\Listeners\CatalogCache::flush()` (wired to `catalog.product.create/update.after` and several other catalog events in `packages/Webkul/Shop/src/Providers/EventServiceProvider.php`) bumps `CatalogApiCache`'s version counter, which is itself now tenant-tagged like everything else — so Tenant A's product change invalidates only Tenant A's cached listings, verified live: Tenant A adds a product and dispatches the exact event Bagisto's real admin controller dispatches on save, and only Tenant A's next request sees the new product; Tenant B's cached response is untouched. Note the invalidating event is dispatched by `Webkul\Admin\Http\Controllers\Catalog\ProductController`, not by the repository/type layer — a product created via `ProductRepository::create()` directly (as our tests and any future `TenantProvisioner`-style code do) does **not** itself trigger cache invalidation; that's an existing Bagisto behavior (admin UI is the trigger point), not something this task changed or needed to change.

## Residual, low-severity, undocumented-elsewhere finding

`Prettus\Repository\Helpers\CacheKeys` (l5-repository's cache-key bookkeeping for bulk invalidation) writes a plain file, `storage/framework/cache/repository-cache-keys.json`, **outside the `Cache::` facade entirely** — `CacheTenancyBootstrapper` cannot and does not cover it. With `FilesystemTenancyBootstrapper` still disabled (R16, Phase 12, out of scope here), every tenant's repository-cache key *names* (not values — just which `{RepositoryClass}@{method}-{hash}` strings have been used) accumulate in one shared file. This is low-sensitivity (key-name metadata only, no tenant data), not fixed in this task, and will resolve naturally once Phase 12 enables filesystem isolation.

## Elasticsearch client caching — unrelated, see [search.md](search.md); it's a container singleton, not a `Cache`-facade entry, so `CacheTenancyBootstrapper` does not (and structurally cannot) cover it. Still tracked as R4, Phase 15.
