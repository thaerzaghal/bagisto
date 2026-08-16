<?php

declare(strict_types=1);

use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Support\EnvList;
use Stancl\Tenancy\Database\Models\Domain;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => Stancl\Tenancy\UUIDGenerator::class,

    'domain_model' => Domain::class,

    /**
     * The list of domains hosting your central app - i.e. the hosts
     * `Platform\Admin`/`Platform\Signup`'s own `EnsureCentralDomain`
     * middleware accept, and the hosts `Stancl\Tenancy\Middleware\
     * InitializeTenancyByDomain` (bootstrap/app.php) will never attempt
     * tenant resolution against.
     *
     * TASK-MVP-004A. PLATFORM_CENTRAL_DOMAINS (comma-separated) is an
     * EXPLICIT, separate configuration source - deliberately NOT derived
     * from `config('platform.base_domain')` (the parent hostname tenant
     * subdomains attach to). The two are related but distinct concepts:
     * a real deployment could legitimately run its central app on the
     * exact same host tenants are subdomained under (`app.example.com`
     * central + `{slug}.app.example.com` tenants) or on an entirely
     * different one (`platform.example.com` central + `{slug}.
     * stores.example.com` tenants) - only the operator knows which,
     * so nothing here guesses. `127.0.0.1`/`localhost` are always kept
     * so local development needs zero additional setup.
     */
    'central_domains' => array_values(array_unique(array_merge(
        ['127.0.0.1', 'localhost'],
        EnvList::parse(env('PLATFORM_CENTRAL_DOMAINS')),
    ))),

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,

        /**
         * TASK-ARCH-004 (RISK_REGISTER.md R15/R21): re-enabled. Root cause of the
         * earlier crash: Stancl\Tenancy\CacheManager::__call() unconditionally
         * routes EVERY cache call (get/put/remember/forget/...) through
         * `$this->store()->tags($tags)->$method(...)` - tagging is not optional,
         * it's how this bootstrapper achieves isolation. Laravel's `file` and
         * `database` cache stores do not implement Illuminate\Cache\TaggableStore
         * (confirmed by reading Illuminate\Cache\Repository - untaggable stores
         * throw BadMethodCallException on ->tags()); `array`, `redis`, `memcached`
         * do. This is why CACHE_STORE must be one of the latter (see .env / R15
         * mitigation in RISK_REGISTER.md) - not a bug to work around, a hard
         * structural requirement of tag-based cache isolation. No code change was
         * needed in CatalogApiCache.php, PhonePe.php, or any repository: they all
         * resolve the cache through app('cache')/the Cache facade, which this
         * bootstrapper transparently swaps for the duration of tenancy - see
         * docs/architecture/caching.md for the full before/after analysis.
         */
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,

        /**
         * TASK-ARCH-005 (RISK_REGISTER.md R16): enabled. Remaps the ROOT of every
         * disk listed in 'filesystem.disks' below to a tenant-specific subtree
         * for the duration of tenancy - Storage::put/get/delete/exists calls in
         * unmodified Bagisto code (ProductImage, CategoryRepository,
         * CustomerRepository, RMAImageRepository, ThemeCustomizationRepository,
         * TinyMCEController, ChannelRepository, DataTransfer, ...) all resolve
         * through Storage::disk(...)/the default disk, so every one of them
         * becomes tenant-isolated automatically. Confirmed by a full repo audit
         * (TASK-ARCH-005): every one of these consumers stores a bare relative
         * path like `product/{id}/{file}` with NO store/tenant segment - under
         * database-per-tenant, numeric IDs are LOCAL to each tenant's own
         * database (fresh auto-increment), so the flat-path convention is only
         * safe because the DISK ROOT itself is now tenant-unique, not because
         * any path was changed. See docs/architecture/storage.md for the two
         * narrow exceptions this alone does not cover (config('imagecache.paths')
         * being frozen at boot time, and ThemeCustomizationRepository's
         * hardcoded 'storage/' URL prefix) and how they were addressed without
         * any packages/Webkul modification.
         */
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,

        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),

        /**
         * Connection used as a "template" for the dynamically created tenant database connection.
         * Note: don't name your template connection tenant. That name is reserved by package.
         *
         * TASK-ARCH-002 (RISK_REGISTER.md R18): deliberately NOT the central `mysql`
         * connection. `tenant_provisioning` (config/database.php) is a separate,
         * elevated-privilege connection used ONLY to CREATE DATABASE / CREATE USER
         * (via PermissionControlledMySQLDatabaseManager below) when a tenant is first
         * provisioned. The resulting per-tenant runtime connection does NOT inherit
         * these elevated credentials - PermissionControlledMySQLDatabaseManager
         * generates a fresh, narrowly-scoped MySQL user per tenant database (see
         * $grants below) and stores it on the tenant row (in `data`, via
         * DatabaseConfig::makeCredentials()), which DatabaseTenancyBootstrapper uses
         * for actual per-request queries. Ordinary central-connection queries
         * (resolving tenant-by-domain, listing tenants, etc.) use the normal `mysql`
         * connection and never see these elevated credentials at all.
         * See docs/architecture/provisioning.md "Database provisioning credentials".
         */
        'template_tenant_connection' => 'tenant_provisioning',

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',

        /**
         * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
         */
        'managers' => [
            'sqlite' => Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
            'mariadb' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
            'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,

        /**
         * TASK-ARCH-002 (RISK_REGISTER.md R18): using the permission-controlled
         * manager (not the plain MySQLDatabaseManager) so every tenant database
         * gets its own dedicated MySQL user with grants scoped to that one
         * database (ALTER, CREATE, DELETE, DROP, INSERT, SELECT, UPDATE, etc. -
         * see Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::$grants
         * - notably NOT including the global CREATE/DROP DATABASE or CREATE USER
         * privileges that only the `tenant_provisioning` template connection's own
         * credentials have). This is what keeps elevated provisioning credentials
         * out of the request path entirely.
         */
            'mysql' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, and enable the one below if you
         * want to separate tenant DBs by schemas rather than databases.
         */
            // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         *
         * TASK-ARCH-005: 'private' added - Webkul\DataTransfer (import/export
         * source files, error reports, downloaded images) and
         * Webkul\Product\Repositories\ProductDownloadableLinkRepository both
         * write real tenant data to this disk (see the filesystem audit in
         * docs/architecture/storage.md). Left with no root_override entry
         * below, so it falls back to FilesystemTenancyBootstrapper's default
         * (`{original_root}/{suffix}` = storage/app/private/tenant{id}) -
         * still fully tenant-unique, just not nested under the
         * already-suffixed storage_path() the way local/public are.
         */
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
            'private',
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        'asset_helper_tenancy' => true,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            // 'default',
        ],
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => true,

    /**
     * Parameters used by the tenants:migrate command.
     *
     * TASK-ARCH-002 (RISK_REGISTER.md R17): Platform\Tenancy\Services\TenantProvisioner
     * is the intended way to migrate a tenant database, and it ALWAYS passes an
     * explicit `--path` of `app('migrator')->paths()` computed at call time (after
     * every Webkul package's ServiceProvider has already booted and registered its
     * own migrations directory via loadMigrationsFrom()) - so it needs zero manually
     * maintained package list and stays correct automatically as Bagisto packages
     * are added/removed/renamed across upgrades. `Migrator::paths()` never includes
     * database_path('migrations') (that's a MigrateCommand-only fallback, added
     * separately and only when `--path` is NOT given) - so the central-only
     * tenants/domains migrations can never leak into a tenant database through this
     * mechanism, without us having to exclude anything by hand.
     *
     * The static `--path` below is only a FALLBACK for `tenants:migrate` invoked
     * directly (bypassing TenantProvisioner) - `stancl/tenancy`'s own Migrate
     * command only applies a config value when the caller did NOT already pass
     * `--path` explicitly (see Stancl\Tenancy\Commands\Migrate::handle()), so
     * TenantProvisioner's explicit, dynamic value always wins over this static one.
     * This fallback keeps the original spike-era static glob (documented in
     * TASK-ARCH-001) since config files are loaded before any ServiceProvider
     * boots, so `app('migrator')->paths()` cannot be computed here.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => array_merge(
            glob(base_path('packages/Webkul/*/src/Database/Migrations')),
            [database_path('migrations/tenant')]
        ),
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class
        // '--force' => true, // This needs to be true to seed tenant databases in production
    ],
];
