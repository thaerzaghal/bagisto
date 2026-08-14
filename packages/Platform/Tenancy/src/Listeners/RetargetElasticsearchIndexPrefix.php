<?php

declare(strict_types=1);

namespace Platform\Tenancy\Listeners;

/**
 * TASK-ARCH-007 (RISK_REGISTER.md, tenant search isolation): Elasticsearch
 * is a single, shared cluster (one `ELASTICSEARCH_HOST`, config/elasticsearch.php)
 * - there is no per-tenant cluster or connection. `Webkul\Product\Helpers\
 * Product::formatElasticSearchIndexName($channelCode, $localeCode)` (used by
 * both `Webkul\Product\Repositories\ElasticSearchRepository` for reads and
 * `Webkul\Product\Helpers\Indexers\ElasticSearch` for writes/reindex/purge -
 * every real Elasticsearch code path in Bagisto funnels through this one
 * helper) builds the index name as:
 *
 *     config('elasticsearch.index_prefix').'products_'.$channelCode.'_'.$localeCode.'_index'
 *
 * `$channelCode`/`$localeCode` are tenant-LOCAL values (each tenant's own
 * `channels`/`locales` rows, e.g. both Tenant A and Tenant B's default
 * channel are commonly coded 'default') - with no tenant identifier in the
 * formula at all, two tenants with the same channel/locale codes would read
 * and write the EXACT SAME Elasticsearch index if this were ever enabled.
 * `index_prefix` is the one part of that formula not hardcoded to a
 * per-request value - and it is read fresh via `config('elasticsearch.
 * index_prefix')` on every call (not frozen at boot, unlike the imagecache.
 * paths bug fixed in R24), so retargeting it per tenant here is sufficient
 * to fully namespace every real index-touching code path with zero
 * packages/Webkul changes - the exact same fix shape as
 * RetargetImageCachePaths for R24.
 *
 * Prefix format: lowercased, non-alphanumeric characters (other than `-`/
 * `_`) stripped, to stay within Elasticsearch's index-naming restrictions
 * regardless of what a tenant id happens to contain (UUIDs and this
 * project's manually-chosen slugs are already safe, but this does not rely
 * on that remaining true forever).
 */
class RetargetElasticsearchIndexPrefix
{
    public function bootstrapped(): void
    {
        config(['elasticsearch.index_prefix' => self::prefixFor(tenant()->getTenantKey())]);
    }

    public function reverted(): void
    {
        config(['elasticsearch.index_prefix' => '']);
    }

    protected static function prefixFor(string $tenantId): string
    {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $tenantId));

        return 'tenant_'.$safe.'_';
    }
}
