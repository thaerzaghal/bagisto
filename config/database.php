<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => env('DB_PREFIX', ''),
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => env('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |----------------------------------------------------------------------
        | Tenant provisioning connection (TASK-ARCH-002, RISK_REGISTER.md R18)
        |----------------------------------------------------------------------
        |
        | Used ONLY by Platform\Tenancy\Services\TenantProvisioner (via
        | config/tenancy.php's database.template_tenant_connection) to
        | CREATE DATABASE / CREATE USER when a tenant is first provisioned.
        | Never used for ordinary application queries and never becomes a
        | tenant's own runtime connection (see config/tenancy.php for why).
        |
        | Required grants for this MySQL user (documented, not automated -
        | production secret management/user creation is deferred per the
        | task brief): CREATE, DROP, CREATE USER, GRANT OPTION - at minimum
        | `ON *.*` since the target tenant database does not exist yet at
        | CREATE DATABASE time. This is narrower than root (no DML/DDL on
        | unrelated schemas is implied by these grants) but is still a
        | meaningfully privileged account and must not be used for anything
        | else. Defaults to the same credentials as the `mysql` connection
        | so local/spike environments keep working unconfigured; production
        | MUST set DB_PROVISION_USERNAME/DB_PROVISION_PASSWORD to a distinct,
        | narrowly-granted user - see docs/architecture/provisioning.md.
        |
        | TASK-MVP-004B (RISK_REGISTER.md R56): `database` deliberately left
        | empty here, NOT `env('DB_DATABASE')` (the central database name).
        | Found live on the real pilot server, during the first-ever
        | production signup: the real `estore_provisioner` user (correctly
        | scoped, per the docblock above - CREATE/DROP on `tenant%` only,
        | no grant on the central database at all) failed to connect/query
        | at all with `SQLSTATE[HY000] [1044] Access denied for user
        | 'estore_provisioner'@'%' to database 'bagisto_central'` - thrown
        | the moment `Stancl\Tenancy\TenantDatabaseManagers\
        | MySQLDatabaseManager::databaseExists()` ran its first query on
        | this connection, even though that query (`SELECT ... FROM
        | INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?`) targets a
        | completely different, not-yet-existing tenant database name and
        | has no dependency whatsoever on this connection's own default
        | database (confirmed by reading MySQLDatabaseManager's full
        | source - createDatabase()/deleteDatabase() are equally
        | fully-qualified, `` `{$database}` ``, never relying on any
        | ambient default schema). The `database` value only controls
        | which schema gets selected via `dbname=` in the PDO DSN at
        | CONNECT time - with it set to `bagisto_central`, MySQL's own
        | access-control check at connection/session-context time fires
        | immediately for a user with zero grants there, before any actual
        | query runs. Never caught before this: every prior environment's
        | own `DB_PROVISION_USERNAME` was `root` (unset in .env, falling
        | back to the `mysql` connection's username default) - root has
        | universal access, so this exact privilege boundary was
        | structurally unreachable until a real, narrowly-scoped
        | production provisioning user was used for the first time.
        | Verified empirically before this fix: a raw PDO connection using
        | the exact DSN Laravel builds for an empty `database` config
        | (`mysql:host=...;port=...;dbname=`) connects and runs the
        | identical failing query successfully as `estore_provisioner`.
        |
        */

        'tenant_provisioning' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => '',
            'username' => env('DB_PROVISION_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_PROVISION_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => env('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => env('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => env('DB_PREFIX', ''),
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_SESSION_DATABASE', '2'),
        ],

    ],

];
