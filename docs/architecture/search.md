# Search Isolation

## Current state (verified)

Elasticsearch is **actually wired up**, not just a bundled dependency — `config/elasticsearch.php` exists with `default`/`api`/`cloud` connection configs, and notably already defines an `index_prefix` (env `ELASTICSEARCH_INDEX_PREFIX`) whose doc comment explicitly anticipates this exact problem: *"Useful when multiple Bagisto instances share the same Elasticsearch cluster."* This is a ready-made hook for tenant isolation that upstream Bagisto already built for a related (multi-instance) use case.

`Webkul\Core\Providers\CoreServiceProvider` binds the Elasticsearch `Client` as a **container singleton**, resolved once from `ElasticSearch::getFacadeApplication()->connection()`. Index naming (`Webkul\Product\Helpers\Product::formatElasticSearchIndexName()`) is `{index_prefix}products_{channel}_{locale}_index` — channel/locale-scoped but not tenant-scoped by default.

Elasticsearch usage is **opt-in per store** — gated by `core()->getConfigData('catalog.products.search.engine') == 'elastic'` inside the indexing jobs; the default fallback is plain MySQL `LIKE` search in `Webkul\Product\Repositories\ProductRepository`. This matters for risk sizing: a tenant using the MySQL fallback has zero cross-tenant search risk (it's a normal query against their own already-isolated tenant connection); the risk is specific to tenants who opt into Elasticsearch.

## Isolation strategy

1. Set `elasticsearch.index_prefix` per tenant (e.g. `tenant_{id}_`) via a listener on `stancl/tenancy`'s tenancy-initialized event, mutating `config(['elasticsearch.index_prefix' => ...])`.
2. In the same listener, call `app()->forgetInstance(\Elastic\Elasticsearch\Client::class)` — this forces the next resolution of the singleton to rebuild the client from the now-tenant-specific config, **without modifying `CoreServiceProvider`** (a core file). This is the concrete example referenced throughout this document set of solving a singleton-state problem via our own listener rather than a vendor edit.
3. Every job that indexes/queries Elasticsearch (`Webkul\Product\Jobs\ElasticSearch\{DeleteIndex,UpdateCreateIndex}`) must run under proper queue tenancy (see [queues.md](queues.md)) so that step 1–2 have actually executed by the time the job's `handle()` runs on a worker.

## Verification

- Provision two tenants, both with `catalog.products.search.engine = elastic`, both indexing a product with the same channel/locale codes (the realistic collision case, mirroring the cache-key collision scenario in R1). Assert tenant A's search index name and tenant B's differ (the `index_prefix` took effect) and that a search on tenant A's storefront never returns tenant B's product.
- Confirm the MySQL `LIKE` fallback path requires no changes — it already queries through the tenant-swapped connection like every other repository call.
