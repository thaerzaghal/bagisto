# INCIDENT-001: Central database wipe via `bagisto:install`

**Date**: 2026-08-15
**Severity**: Critical (real, non-reproducible development data lost), bounded blast radius (no tenant data affected)
**Status**: Resolved, safeguards in place
**Related**: [RISK_REGISTER.md R44](../../RISK_REGISTER.md)

## What happened

While performing legitimate TASK-ARCH-017 CI-audit work — verifying that
`Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema` (R30's
guard) would correctly reject `bagisto:install`'s internal `migrate:fresh`
call against the central connection — the command was run via:

```
docker run ... -e DB_DATABASE=bagisto_ci_probe ... php artisan bagisto:install --no-interaction
```

The intent was to scope the run to a disposable database. The override did
**not** protect the real database: `bagisto:install` ran `db:wipe` then
`migrate:fresh` against the real, persistent `bagisto_central` database,
dropping its tables, before R30's guard correctly aborted the subsequent
`migrate` step on the first non-central migration file.

## Root cause

`packages/Webkul/Installer/src/Helpers/EnvironmentManager.php` (Bagisto
core, not modified by this engagement):

- `getEnvVariable()` reads the raw `.env` **file** from disk via
  `file(base_path('.env'))` — it does not use Laravel's `env()` or read the
  process's OS environment at all.
- `loadEnvConfigs()` (called early in `Installer::handle()`) uses those
  file-read values to explicitly rebind the database connection:
  `DB::purge()`, then `config(["database.connections.{$connection}.host/port/database/username/password/prefix" => ...])`
  for every field, then `DB::reconnect()`.

Because the literal `.env` file on disk was never edited (only a
process-level `-e DB_DATABASE=...` override was set), `loadEnvConfigs()`
silently discarded that override and rebound the connection back to
`.env`'s real value (`bagisto_central`) before `db:wipe`/`migrate:fresh`
ran. This was confirmed by direct reproduction: a plain `env('DB_DATABASE')`/
`config('database.connections.mysql.database')` check (bypassing
`bagisto:install` entirely) correctly picked up the same docker-level
override — proving the override reaches the process fine, and the failure
is specific to this command's own internal `.env`-file-reading logic.

`db:wipe` drops tables via `Schema::dropAllTables()` directly — it fires no
`MigrationStarted` event, so R30's guard (which listens on exactly that
event) was never in a position to prevent it. The guard did correctly fire
on `migrate:fresh`'s subsequent `migrate` step, aborting on the first
non-central (Webkul package) migration file — proof R30 itself was working
exactly as designed; it was simply never the layer that could have stopped
this specific danger.

## Why tenant databases initially appeared to be gone too

The first investigation pass queried `SHOW DATABASES` as the app's normal
`sail` MySQL user, which has only ever held grants on `bagisto_central` (per
`docker-compose.yml`'s `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD`) and
structurally never had visibility into the separately-credentialed,
dynamically-created tenant databases (each tenant gets its own dedicated
MySQL user — `PermissionControlledMySQLDatabaseManager`, TASK-ARCH-002).
That was a privilege-visibility artifact of the investigation itself, not
evidence of data loss. Re-querying as MySQL `root` showed every tenant
database, and every tenant-scoped MySQL user with intact grants, still
present.

## Actual blast radius

**Lost** (central registry data, not reproducible by replay): `tenants`,
`domains`, `plans`, `plan_features`, `platform_users`, `subscriptions`,
central `sessions` rows, plus two unrelated tables
(`agent_conversations`/`agent_conversation_messages`) and a test-only table
(`central_queue_isolation_probes`, trivially test-suite-reproducible).

**Not lost**: every tenant database's schema and data — confirmed via
`information_schema.SCHEMATA` and direct `SHOW TABLES`/row-count checks as
MySQL root across a representative sample. All ~35 tenant-scoped MySQL
users and their database-specific grants remained intact.

**Classification of the 32 surviving tenant databases** (read-only
investigation, see the recovery session's own working notes): all 32 match
this project's own Pest test-fixture naming convention
(`tenant-<scenario>-<a|b|check>`) and share the identical seeder-default
admin account (`admin@example.com`) with trivial product counts — no
manually-created/business-named tenant was found among them. All 32 are
Category A (disposable test fixtures); none required manual central-registry
reconstruction.

## Recovery performed

1. **Safety backup first**: full `mysqldump` of `bagisto_central` in its
   damaged state, plus one dump per surviving database (33 total),
   MySQL user/grant listing — stored outside the Docker volume and outside
   git, verified via a real restore-and-inspect round trip before any
   further action.
2. **Central schema rebuilt** via the supported workflow only:
   `php artisan platform:migrate:central` (never `bagisto:install`,
   `migrate`, or `migrate:fresh`).
3. **Bootstrap data reseeded**: `php artisan platform:plans:seed`.
4. **Tenant registry**: no manual reconstruction performed — every
   surviving tenant database is a disposable test fixture that the Platform
   test suite recreates via its own `ensure*Tenant()` helpers on next run,
   per the task's own guidance to prefer that over manually restoring
   registry rows for disposable fixtures.
5. **Platform Admin**: a new local development account was created via
   `php artisan platform:admin:create` with a randomly generated password
   (never logged, printed, or committed).
6. **Subscriptions**: no reconstruction needed — no real/manual tenant
   existed to reconstruct a Subscription for.

## Safeguards added

See [RISK_REGISTER.md R44](../../RISK_REGISTER.md) for the full detail.
Summary: `Platform\Tenancy\Services\CentralDatabaseWipeGuard` statically
prohibits `db:wipe`/`migrate:fresh`/`migrate:refresh`/`migrate:reset`
whenever the current process's default database resolves to the real,
fixed central database name (`bagisto_central`) — independent of `APP_ENV`,
and independent of the overridable `database.connections.*.database` config
the danger itself lives in. Tenant databases and databases explicitly
prefixed `bagisto_test_`/`bagisto_ci_`/`bagisto_probe_` remain fully
wipeable. A companion listener gives `bagisto:install` a clean rejection
message in real CLI usage. Proven via 9 real-MySQL integration tests
(`tests/Feature/Platform/CentralDatabaseWipeGuardTest.php`) covering: the
guarded commands failing before any DROP against central, the guard not
interfering with tenant provisioning/migration or
`platform:migrate:central`, and the guard correctly allowing an explicitly
disposable database to be wiped.

## `bagisto:install` is no longer a supported workflow

For this SaaS fork, `php artisan bagisto:install` is **not** a supported
workflow once the central database is real (i.e. after initial upstream
Bagisto setup). It is Webkul-authored and assumes it owns the entire
database it targets. The supported central bootstrap sequence is:

```
php artisan platform:migrate:central
php artisan platform:plans:seed
php artisan platform:admin:create
```

Tenant creation/migration goes through `Platform\Tenancy\Services\TenantProvisioner`
(`php artisan tenant:provision` or the Platform Admin UI), never through
`bagisto:install`.

## Lesson: no backup existed

This project has no automated database backup mechanism. The safety-backup
step in this recovery was the first one ever taken. Worth a deliberate,
future decision on whether local development warrants a lightweight
periodic `mysqldump` cron/script — out of scope for this recovery itself,
noted here so it isn't lost.
