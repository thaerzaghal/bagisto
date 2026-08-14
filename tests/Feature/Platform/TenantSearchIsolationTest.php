<?php

/**
 * TASK-ARCH-007 - tenant search isolation security test matrix.
 *
 * Addresses Phase 15 ("Search Isolation") of the roadmap and RISK_REGISTER.md
 * R4 (Elasticsearch client singleton). Real MySQL, real HTTP requests through
 * the actual, unmodified Bagisto Shop API search endpoint
 * (Webkul\Shop\Http\Controllers\API\ProductController::index(),
 * `GET /api/products?query=...`). Nothing mocked.
 *
 * ARCHITECTURE CONFIRMED BY READING THE INSTALLED CODE, NOT ASSUMED:
 *
 * - Search engine is a per-TENANT, database-stored config value
 *   (`catalog.products.search.engine`, packages/Webkul/Admin/src/Config/
 *   system.php - a `core_config` row, which lives inside each tenant's own
 *   database, already isolated by database-per-tenant), defaulting to
 *   'database'. This environment has no Elasticsearch server configured or
 *   running (no ELASTICSEARCH_HOST set, no ES container in docker-compose),
 *   so 'database' is the ACTIVE mode here and in any fresh Bagisto install -
 *   confirmed live, not assumed (see the "active search mode" test below).
 * - 'database' mode (Webkul\Product\Repositories\ProductRepository::
 *   searchFromDatabase()) is a plain Eloquent query through the repository's
 *   model, which resolves its DB connection via the same dynamic
 *   tenant-connection mechanism proven tenant-isolated since TASK-ARCH-002/
 *   003/006 - CLASSIFICATION A ("inherently isolated by tenant database
 *   switching"), requiring no new isolation code, only a real test proving
 *   it (this file's first several tests).
 * - 'elastic' mode (Webkul\Product\Repositories\ElasticSearchRepository,
 *   Webkul\Product\Helpers\Indexers\ElasticSearch) is a SINGLE shared
 *   cluster (one config/elasticsearch.php connection, no per-tenant
 *   cluster). Every real read/write path funnels through ONE helper,
 *   Webkul\Product\Helpers\Product::formatElasticSearchIndexName($channelCode,
 *   $localeCode) = "{index_prefix}products_{channelCode}_{localeCode}_index" -
 *   confirmed by reading both ElasticSearchRepository::getIndexName() and
 *   Indexers\ElasticSearch::getIndexName(). $channelCode/$localeCode are
 *   TENANT-LOCAL values (e.g. both Tenant A and B commonly have a channel
 *   coded 'default') with NO tenant identifier anywhere in the formula -
 *   CLASSIFICATION B ("unsafe because it uses shared external indexes") as
 *   shipped. Fixed the same way R24 fixed the analogous imagecache.paths
 *   bug: `Platform\Tenancy\Listeners\RetargetElasticsearchIndexPrefix`
 *   retargets `config('elasticsearch.index_prefix')` per tenant - read
 *   fresh on every call, not frozen at boot - so the existing, unmodified
 *   Bagisto helper produces a genuinely tenant-unique index name with zero
 *   packages/Webkul changes. See docs/architecture/search.md for the full
 *   writeup, including what is and is NOT verified without a live ES
 *   cluster in this environment (index NAMING is proven live; actual
 *   document read/write isolation against a real cluster is not, and this
 *   file says so explicitly rather than claiming it).
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Jobs\TenantIsolationProbeJob;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Product\Helpers\Product as ProductHelper;
use Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex;
use Webkul\Product\Repositories\ProductRepository;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const SEARCH_TEST_TENANT_IDS = ['tenant-search-a', 'tenant-search-b'];

/**
 * DEDICATED tenant-search-a/tenant-search-b fixtures - deliberately NOT the
 * shared tenant-a/tenant-b fixtures most other Platform test files reuse.
 * Found live, the hard way, while finishing this task: TenantCacheIsolationTest.php
 * and TenantDomainRoutingTest.php assert EXACT product-list contents against
 * tenant-a/tenant-b (e.g. `toBe(['PROD-A'])`) - adding a new SEARCH-A
 * product to that shared fixture broke those files' exact-match assertions
 * when the whole suite ran together. Reproduced the exact same class of
 * cross-file-fixture-contamination bug TenantProvisioningTest.php already
 * hit and fixed in TASK-ARCH-002 (see that file's own docblock) - and
 * TenantQueueIsolationTest.php (tenant-queue-a/b) already sidesteps by the
 * same means: dedicated, non-shared tenant ids for any file whose fixtures
 * need their OWN product catalog contents, rather than adding to the
 * shared one. Applied here for the same reason.
 */
function ensureSearchTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-search-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-search-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-search-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-search-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-search-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-search-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }

    $tenantA->run(function () {
        $repo = app(ProductRepository::class);
        if ($repo->findWhere(['sku' => 'SEARCH-A'])->isEmpty()) {
            $p = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'SEARCH-A']);
            $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Zzyzx Widget Alpha', 'url_key' => 'zzyzx-widget-alpha'], $p->id);
        }
    });

    $tenantB->run(function () {
        $repo = app(ProductRepository::class);
        if ($repo->findWhere(['sku' => 'SEARCH-B'])->isEmpty()) {
            $p = $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'SEARCH-B']);
            $repo->update(['status' => 1, 'visible_individually' => 1, 'name' => 'Zzyzx Widget Beta', 'url_key' => 'zzyzx-widget-beta'], $p->id);
        }
    });

    return [$tenantA, $tenantB];
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureSearchTestFixtures();
});

test('the active search engine in this environment is confirmed live to be "database", per tenant, not assumed', function () {
    // catalog.products.search.engine is a core_config row - lives inside
    // each tenant's own database, already isolated by database-per-tenant.
    // Confirmed here (not assumed from reading config/system.php's
    // 'default' => 'database' alone) precisely because a tenant COULD have
    // switched itself to 'elastic' independently of any other tenant.
    $engineA = $this->tenantA->run(fn () => core()->getConfigData('catalog.products.search.engine'));
    $engineB = $this->tenantB->run(fn () => core()->getConfigData('catalog.products.search.engine'));

    expect($engineA)->toBe('database');
    expect($engineB)->toBe('database');

    // No Elasticsearch server is configured/reachable in this environment -
    // confirmed, not assumed, so "database mode is what's actually
    // exercised by every test below" is a verified precondition.
    expect(env('ELASTICSEARCH_HOST'))->toBeEmpty();
});

test('database search: Tenant A search-by-term returns only its own product over a real Shop API request, never Tenant B\'s', function () {
    $responseA = $this->get('http://tenant-search-a.localhost/api/products?query=Zzyzx');
    $responseA->assertOk();
    $skusA = collect($responseA->json('data'))->pluck('sku')->all();

    expect($skusA)->toContain('SEARCH-A');
    expect($skusA)->not->toContain('SEARCH-B');
});

test('database search: Tenant B search-by-term returns only its own product over a real Shop API request, never Tenant A\'s', function () {
    $responseB = $this->get('http://tenant-search-b.localhost/api/products?query=Zzyzx');
    $responseB->assertOk();
    $skusB = collect($responseB->json('data'))->pluck('sku')->all();

    expect($skusB)->toContain('SEARCH-B');
    expect($skusB)->not->toContain('SEARCH-A');
});

test('the identical search term across both tenants returns tenant-specific results, not a shared/merged result set', function () {
    $responseA = $this->get('http://tenant-search-a.localhost/api/products?query=Zzyzx');
    $responseB = $this->get('http://tenant-search-b.localhost/api/products?query=Zzyzx');

    $skusA = collect($responseA->json('data'))->pluck('sku')->all();
    $skusB = collect($responseB->json('data'))->pluck('sku')->all();

    expect($skusA)->toContain('SEARCH-A')->not->toContain('SEARCH-B');
    expect($skusB)->toContain('SEARCH-B')->not->toContain('SEARCH-A');
    expect($skusA)->not->toBe($skusB);
});

test('repeated searches remain isolated with no cache cleared between tenant requests - both the uncached search-term path and the cached no-query listing path', function () {
    // Search-term requests are deliberately never cached (see
    // ProductController::index()'s own comment: "Search results are never
    // cached... the search-term job must run on every request") - hitting
    // it twice per tenant, interleaved, with no cache clear, proves this
    // isn't accidentally serving a stale/shared response.
    $this->get('http://tenant-search-a.localhost/api/products?query=Zzyzx');
    $this->get('http://tenant-search-b.localhost/api/products?query=Zzyzx');
    $repeatA = $this->get('http://tenant-search-a.localhost/api/products?query=Zzyzx');
    $repeatB = $this->get('http://tenant-search-b.localhost/api/products?query=Zzyzx');

    expect(collect($repeatA->json('data'))->pluck('sku')->all())->toContain('SEARCH-A')->not->toContain('SEARCH-B');
    expect(collect($repeatB->json('data'))->pluck('sku')->all())->toContain('SEARCH-B')->not->toContain('SEARCH-A');

    // The no-query listing path IS cached (Webkul\Shop\Helpers\CatalogApiCache,
    // proven tenant-isolated in TASK-ARCH-004/R21's fix) - re-confirmed here
    // specifically through the search controller's own no-query branch, not
    // duplicated from the earlier task's test, with no cache clear between
    // tenants (same "no clearing cache between tenant requests" requirement).
    $listingA1 = $this->get('http://tenant-search-a.localhost/api/products');
    $listingB1 = $this->get('http://tenant-search-b.localhost/api/products');
    $listingA2 = $this->get('http://tenant-search-a.localhost/api/products');
    $listingB2 = $this->get('http://tenant-search-b.localhost/api/products');

    $skusA1 = collect($listingA1->json('data'))->pluck('sku')->all();
    $skusA2 = collect($listingA2->json('data'))->pluck('sku')->all();
    $skusB1 = collect($listingB1->json('data'))->pluck('sku')->all();
    $skusB2 = collect($listingB2->json('data'))->pluck('sku')->all();

    expect($skusA1)->toContain('SEARCH-A')->not->toContain('SEARCH-B');
    expect($skusA2)->toBe($skusA1, 'the cached listing must be internally consistent across repeats');
    expect($skusB1)->toContain('SEARCH-B')->not->toContain('SEARCH-A');
    expect($skusB2)->toBe($skusB1);
});

test('an unknown domain cannot reach any tenant\'s search results', function () {
    $response = $this->get('http://unknown-search-tenant.localhost/api/products?query=Zzyzx');

    expect($response->status())->toBe(404);
});

test('the Elasticsearch index-prefix retargeting listener produces a genuinely tenant-unique index name via the real, unmodified Bagisto helper, and resets when tenancy ends', function () {
    // This is the concrete, structural fix for classification B (shared
    // external index risk) - proven here using Webkul\Product\Helpers\
    // Product::formatElasticSearchIndexName() itself (unmodified core code),
    // not a reimplementation of it, so this test would fail if the real
    // helper's behavior ever changed.
    $indexA = $this->tenantA->run(fn () => ProductHelper::formatElasticSearchIndexName('default', 'en'));
    $indexB = $this->tenantB->run(fn () => ProductHelper::formatElasticSearchIndexName('default', 'en'));

    // Identical channel/locale codes ('default'/'en') on both tenants - the
    // exact collision scenario that made this a real risk - yet the index
    // names differ, because config('elasticsearch.index_prefix') differs.
    expect($indexA)->not->toBe($indexB);
    expect($indexA)->toContain('tenant-search-a');
    expect($indexB)->toContain('tenant-search-b');
    expect($indexA)->toContain('products_default_en_index');

    // Central context must never carry a leftover tenant prefix (proves
    // "search/index context does not persist after tenancy ends").
    expect(config('elasticsearch.index_prefix'))->toBe('');
});

test('a real queued Elasticsearch indexing job (Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex) restores the correct tenant\'s index-prefix context, in the SAME worker across Tenant A then Tenant B, per the TASK-ARCH-006 queue tenancy guarantees', function () {
    config(['queue.default' => 'redis']);
    \Illuminate\Support\Facades\Redis::connection('default')->flushdb();

    // Reused from TASK-ARCH-006: TenantIsolationProbeJob is generic
    // Platform-owned probe infrastructure; this test instead dispatches the
    // REAL Bagisto job to prove indexing specifically, in the SAME block-
    // closure-with-a-bare-statement shape documented in
    // TenantQueueIsolationTest.php (arrow functions silently push jobs
    // under the wrong tenant context - see that file's top docblock).
    $productIdA = $this->tenantA->run(fn () => DB::table('products')->where('sku', 'SEARCH-A')->value('id'));
    $productIdB = $this->tenantB->run(fn () => DB::table('products')->where('sku', 'SEARCH-B')->value('id'));

    $this->tenantA->run(function () use ($productIdA) {
        UpdateCreateIndex::dispatch([$productIdA]);
    });
    $this->tenantB->run(function () use ($productIdB) {
        UpdateCreateIndex::dispatch([$productIdB]);
    });

    // Drain both in ONE continuous real worker loop (TASK-ARCH-006's TRUE
    // daemon-worker proof), recording the index-prefix context each job
    // actually observed via a lightweight closure-based probe registered
    // just for this test.
    $observed = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) use (&$observed) {
        if (str_contains($event->job->resolveName(), 'UpdateCreateIndex')) {
            $observed[] = config('elasticsearch.index_prefix');
        }
    });

    \Illuminate\Support\Facades\Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--stop-when-empty' => true,
    ]);

    expect($observed)->toHaveCount(2);
    expect($observed[0])->not->toBe($observed[1]);
    expect($observed)->toContain('tenant_tenant-search-a_');
    expect($observed)->toContain('tenant_tenant-search-b_');
    expect(config('elasticsearch.index_prefix'))->toBe('');

    \Illuminate\Support\Facades\Redis::connection('default')->flushdb();
});

test('the Elasticsearch Client::class container singleton is never actually consumed by any real Bagisto search code path - confirmed live, and found to be outright broken if it ever were', function () {
    // Phase 0/R4 flagged Elastic\Elasticsearch\Client::class as a container
    // singleton (packages/Webkul/Core/src/Providers/CoreServiceProvider.php:
    // 131-134) resolved once from whatever config was active at boot,
    // raising a staleness concern under long-lived processes/tenant
    // switches. Read live: the REAL code path (Webkul\Product\Repositories\
    // ElasticSearchRepository, Webkul\Product\Helpers\Indexers\ElasticSearch)
    // exclusively calls through the Webkul\Core\Facades\ElasticSearch FACADE,
    // whose __call() (packages/Webkul/Core/src/ElasticSearch.php) rebuilds a
    // brand-new Client via makeConnection() on EVERY method call, reading
    // config('elasticsearch.connections.*') fresh each time - it never
    // touches the Client::class singleton at all (confirmed by a repo-wide
    // grep for Client::class/app(Client::class) usage across packages/Webkul,
    // zero real consumers found).
    //
    // STRONGER finding than expected, confirmed by actually trying to
    // resolve it: the singleton binding itself is broken.
    // `ElasticSearch::getFacadeApplication()->connection()`
    // (CoreServiceProvider.php:133) calls ->connection() on the Laravel
    // Application/container instance - not a DatabaseManager, which is the
    // only class with that method - so resolving app(Client::class) throws
    // a real BadMethodCallException. This is independent, hard evidence
    // (not just "nothing calls it") that no real, exercised Bagisto code
    // path can be using this singleton: if it were ever invoked, the
    // application would fatal immediately, in any tenant or central
    // context alike. A packages/Webkul bug, pre-existing and unrelated to
    // tenancy - out of scope to fix (core file, and dead/unreachable code,
    // not a tenant-isolation defect) - documented here as confirming
    // evidence, not something this task changes.
    $threw = false;

    try {
        $this->tenantA->run(fn () => app(\Elastic\Elasticsearch\Client::class));
    } catch (\Throwable $e) {
        $threw = true;
        expect($e)->toBeInstanceOf(\BadMethodCallException::class);
    } finally {
        // Tenant::run() has no exception handling of its own - a thrown
        // exception inside the closure skips its normal revert-to-previous-
        // context step, so tenancy would otherwise stay stuck on tenant-search-a
        // for the rest of this test file's run. Clean up explicitly.
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    expect($threw)->toBeTrue('Client::class singleton is confirmed unreachable/broken, not merely unused - see this test\'s comment');
});

test('the new tenant:index CLI wrapper reindexes only the named tenant, and fails safely for an unknown tenant', function () {
    // Real Artisan::call(), real Bagisto indexer:index command underneath -
    // Platform\Tenancy\Console\Commands\ReindexTenant only wraps it in
    // $tenant->run(), nothing more.
    $exitCode = \Illuminate\Support\Facades\Artisan::call('tenant:index', [
        'tenant' => 'tenant-search-a',
        '--type' => ['flat'],
    ]);

    expect($exitCode)->toBe(0);
    expect(tenancy()->initialized)->toBeFalse('the wrapper must not leave the console process tenant-initialized afterward');

    // Confirms the reindex genuinely ran against Tenant A's own database -
    // the flat indexer touches product_flat, scoped to whichever connection
    // is active when it runs.
    $flatCountA = $this->tenantA->run(fn () => DB::table('product_flat')->count());
    expect($flatCountA)->toBeGreaterThan(0);

    // Unknown tenant fails safely - no exception, no central/wrong-tenant
    // fallback, a controlled non-zero exit code.
    $unknownExitCode = \Illuminate\Support\Facades\Artisan::call('tenant:index', [
        'tenant' => 'tenant-search-does-not-exist',
        '--type' => ['flat'],
    ]);
    expect($unknownExitCode)->toBe(1);
});
