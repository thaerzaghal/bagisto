# Search Isolation

**Status: RESOLVED (TASK-ARCH-007, 2026-08-14).** Addresses Phase 15 of the roadmap and RISK_REGISTER.md R4 (Elasticsearch client singleton). Covers every real search entry point in Bagisto — Shop API product listing/search, category listing, the storefront search bar — and both search modes Bagisto ships.

## Active search engine

**Database** — confirmed live, not assumed. `catalog.products.search.engine` (`packages/Webkul/Admin/src/Config/system.php`) is a `core_config` row: a **per-tenant, database-stored** value (each tenant's own `core_config` table, already isolated by database-per-tenant), defaulting to `'database'`. This environment has no `ELASTICSEARCH_HOST` configured and no Elasticsearch/OpenSearch container running — confirmed via `env('ELASTICSEARCH_HOST')` being empty in a live test, not inferred from `.env.example` alone. A tenant *could* independently switch itself to `'elastic'` (the config is genuinely per-tenant), but none does by default.

## Bagisto search architecture (read from the installed code)

Every real search entry point — `Webkul\Shop\Http\Controllers\API\ProductController::index()`, `API\CategoryController`, `SearchController` — follows the identical pattern:

```php
$searchEngine = 'database';
if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
    $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
}
$productRepository->setSearchEngine($searchEngine)->getAll([...]);
```

`Webkul\Product\Repositories\ProductRepository::getAll()` dispatches to exactly one of two methods based on that value:

- **`searchFromDatabase()`** — a plain Eloquent query through the repository's model (`$this->with([...])->scopeQuery(...)`), which resolves its DB connection via the same dynamic tenant-connection swap (`Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper`) already proven tenant-isolated since TASK-ARCH-002/003/006. No search-specific code, no new isolation mechanism — the *general* database-per-tenant guarantee already covers it.
- **`searchFromElastic()`** — delegates to `Webkul\Product\Repositories\ElasticSearchRepository`, which talks to a **single, shared** Elasticsearch cluster (one `config('elasticsearch.connections.default')`, no per-tenant cluster or credentials).

Only **products** are Elasticsearch-indexed. Categories are filtered by `category_id` within product search; customers and orders have no search-engine integration at all (confirmed by the same repo-wide search that found the 20 indexed-model candidates — none matched).

## Current security posture (classification, proven with real tests)

| Mode | Classification | Evidence |
|---|---|---|
| `database` (active, default) | **A — inherently isolated by tenant database switching** | `tests/Feature/Platform/TenantSearchIsolationTest.php`: real HTTP requests to `GET /api/products?query=...` on `tenant-a.localhost`/`tenant-b.localhost`, identical search term, each tenant's own product returned, the other's never returned — both uncached (search-by-term) and cached (no-query listing) paths, no cache cleared between tenants |
| `elastic` (supported, not active by default, but per-tenant-selectable) | **B as shipped — unsafe, shared external index; fixed to A via a Platform-side listener** | See "External search engine" below |

## External search engine (Elasticsearch) — the real, confirmed risk and its fix

Both `ElasticSearchRepository::getIndexName()` and `Webkul\Product\Helpers\Indexers\ElasticSearch::getIndexName()` (the read path and the index/write path — confirmed to be the *only* two call sites, both funneling through one shared helper) compute the index name as:

```php
// packages/Webkul/Product/src/Helpers/Product.php
config('elasticsearch.index_prefix').'products_'.strtolower($channelCode).'_'.strtolower($localeCode).'_index';
```

`$channelCode`/`$localeCode` are **tenant-local** values — every tenant's own `channels`/`locales` rows, commonly coded identically (`'default'`/`'en'`) across tenants, since these are per-tenant seed defaults, not globally unique identifiers. **There is no tenant identifier anywhere in this formula.** If Elasticsearch were ever enabled by any tenant with the default `index_prefix` (empty string, per `config/elasticsearch.php`), two tenants with identically-coded channels would read and write the **exact same Elasticsearch index** — a real, structural, confirmed cross-tenant data leak vector, not a hypothetical one.

### The fix

`config('elasticsearch.index_prefix')` is the one part of that formula not itself derived from per-request/per-tenant data — and, critically, it is read **fresh on every call** (`config('elasticsearch.index_prefix')` evaluated inside `formatElasticSearchIndexName()`'s own body, not cached or frozen at boot, unlike the `imagecache.paths` bug R24 found and fixed). This makes it the exact same class of fix as R24: a Platform-side listener retargets a config value tenancy-aware, with **zero `packages/Webkul` changes**.

`Platform\Tenancy\Listeners\RetargetElasticsearchIndexPrefix`, hooked to the same `Events\TenancyBootstrapped`/`Events\RevertedToCentralContext` events `RetargetImageCachePaths` already uses:

```php
public function bootstrapped(): void
{
    config(['elasticsearch.index_prefix' => 'tenant_'.$safeTenantId.'_']);
}

public function reverted(): void
{
    config(['elasticsearch.index_prefix' => '']);
}
```

The tenant id is sanitized (lowercased, non-`[a-zA-Z0-9_-]` characters replaced) before use, so index naming stays deterministic and safe regardless of what a tenant id happens to contain — not derived from a Host header, request parameter, or any user-controlled store name; it comes from the trusted, already-initialized `Tenant` object.

**Verified live** (`TenantSearchIsolationTest.php`, "the Elasticsearch index-prefix retargeting listener..."): calling the real, unmodified `Product::formatElasticSearchIndexName('default', 'en')` — the exact collision scenario, identical channel/locale codes — under two different tenants produces two genuinely different index name strings (`tenant_tenant-a_products_default_en_index` vs. `tenant_tenant-b_products_default_en_index`), and the prefix resets to empty in central context.

### What is and is NOT verified about the external engine

**Verified live**: index *naming* is tenant-unique, using the real Bagisto helper, under real tenant contexts, including through a real queued indexing job in a real multi-tenant worker loop (see "Indexing / reindexing" below).

**NOT verified**: actual document read/write isolation against a live Elasticsearch cluster. **No Elasticsearch/OpenSearch server exists in this environment** — no container in the docker setup, `ELASTICSEARCH_HOST` unset. Standing one up was judged out of scope for this task (a real infrastructure addition, not a code change) and unnecessary to prove the isolation *mechanism*, which does not depend on cluster behavior — only on the index name string being correct, which is what's actually tested. If Elasticsearch is enabled in a real deployment, the very first real indexing run against it should be spot-checked (e.g. `GET {es_host}/_cat/indices` showing `tenant_{id}_products_...` names, one set per tenant) before relying on it in production. This is stated plainly here rather than claimed as proven.

## Index naming

Derived exclusively from the trusted, already-initialized `Tenant` object's own key (`tenant()->getTenantKey()`), never from a Host header, request parameter, or any user-controlled value — the same trust boundary already established for tenant queue payloads (TASK-ARCH-006) and tenant identification generally (`InitializeTenancyByDomain`). Sanitized for Elasticsearch's index-naming restrictions (lowercase, `[a-z0-9_-]` only).

## Indexing / reindexing

Audited: product save/update/delete indexing (`Webkul\Product\Listeners\Product`, dispatches `UpdateCreateIndex`/`DeleteIndex`/inventory/price index jobs), bulk indexing (`Indexers\ElasticSearch::reindexBatch()`), the `indexer:index` console command, and queue-based indexing.

- **Save/update/delete indexing**: fires only from `ProductRepository::create()`/`update()`/`delete()`, which only ever run from an already-tenant-initialized context (a real Admin request, or a queued job — the latter already tenant-tagged per TASK-ARCH-006). No new risk found; the jobs themselves inherit the same queue-tenancy guarantees.
- **Bulk indexing / `reindexBatch()`**: loops over `getChannels()` (the *current* tenant's own channels — resolved through the tenant-scoped `ChannelRepository`) and calls `getIndexName()` per channel/locale — automatically tenant-unique once the index-prefix listener is active, with zero changes to this method itself.
- **`indexer:index` command**: a global command, no tenant awareness. Run bare, it executes centrally — and fails loudly (`products` table doesn't exist; the central database carries no Bagisto commerce tables, per R17) rather than silently touching some arbitrary tenant. Fail-closed, but with no way to target one tenant. See "CLI commands" below.

## CLI commands

`Webkul\Product\Console\Commands\Indexer` (`indexer:index {--type=*} {--mode=*}`) is global and untargeted. A minimal, single-tenant wrapper was added — `Platform\Tenancy\Console\Commands\ReindexTenant` (`php artisan tenant:index {tenant} {--type=*} {--mode=*}`), mirroring `tenant:provision`'s existing shape: looks up the named tenant, runs the real `indexer:index` command inside `$tenant->run()`, forwards its options and output. Deliberately **not** a fleet-wide orchestrator — no loop over all tenants, no scheduling, no queue-backed pipeline — per this task's explicit instruction not to over-engineer one without a demonstrated operational need. Verified live: reindexes only the named tenant's `product_flat` table; an unknown tenant name fails safely (exit code 1, no exception, no fallback to central or another tenant); the console process is never left tenant-initialized afterward.

## Long-lived workers (queue-based indexing)

Verified against a **real, continuous, multi-job worker loop** (`Illuminate\Queue\Worker::daemon()`, `--stop-when-empty` — the same TRUE daemon-worker proof TASK-ARCH-006 established, not just separate `--once` calls): dispatched the real `Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex` job for Tenant A then Tenant B, drained both in one continuous loop, and captured `config('elasticsearch.index_prefix')` at the moment each job actually executed (via a `JobProcessing` listener registered just for the test). Result: each job observed its own tenant's prefix, never the other's, and the prefix reset to empty once the loop finished. No stale search-index-namespace state persists from one tenant's job into the next.

## Search client singletons

`Elastic\Elasticsearch\Client::class` is registered as a container singleton in `Webkul\Core\Providers\CoreServiceProvider` (`packages/Webkul/Core/src/Providers/CoreServiceProvider.php:131-134`), flagged in Phase 0/R4 as a staleness risk under long-lived processes. Investigated fully, not just re-flagged:

- **The real code path never uses it.** `ElasticSearchRepository` and `Indexers\ElasticSearch` exclusively call through the `Webkul\Core\Facades\ElasticSearch` **facade**, whose `__call()` (`packages/Webkul/Core/src/ElasticSearch.php`) rebuilds a brand-new `Client` via `makeConnection()` on **every single method call**, reading `config('elasticsearch.connections.*')` fresh each time — confirmed by a repo-wide grep for `Client::class`/`app(Client::class)` usage across `packages/Webkul`: zero real consumers.
- **Stronger than "unused": the singleton binding is actually broken.** `ElasticSearch::getFacadeApplication()->connection()` (`CoreServiceProvider.php:133`) calls `->connection()` on the Laravel `Application`/container instance — not a `DatabaseManager`, the only class with that method. Resolving `app(Client::class)` throws `BadMethodCallException` immediately. Verified live (`TenantSearchIsolationTest.php`, "the Elasticsearch Client::class container singleton..."). This is independent, harder evidence than "nothing calls it": if anything *did* try to use this singleton, in any context, the application would fatal immediately — so nothing exercised in this codebase can be relying on it.
- **Not fixed** — a `packages/Webkul` bug, pre-existing, unrelated to tenancy, and unreachable/dead code, not a tenant-isolation defect. Out of scope for this task's core-isolation rule either way.

## Cache interaction

Search isolation verified to hold **both** cached and uncached, with no cache cleared between tenant requests in the primary tests (matching TASK-ARCH-004's established methodology): the no-query product listing (`Webkul\Shop\Helpers\CatalogApiCache`, tenant-tagged since R21/TASK-ARCH-004) stays correctly isolated across repeated, interleaved tenant requests; the search-by-term path is confirmed to never cache at all (per the controller's own design — "the search-term job must run on every request"), so isolation there comes purely from the always-fresh per-tenant database query.

## Recommendation for a future task

If Elasticsearch is ever actually adopted in a real deployment, spot-check real index creation against a live cluster once (see "What is and is NOT verified" above) before relying on this fix in production — the mechanism is proven, the live cluster behavior is not.
