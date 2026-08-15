# Tenant Provisioning

## State machine

```
PENDING → PROVISIONING → READY
                ↓
              FAILED (retryable → back to PROVISIONING)

READY → SUSPENDED → READY (reactivation)
READY → DELETING → DELETED
```

Each transition is recorded in `tenant_provisioning_events` (see [database-per-tenant.md](database-per-tenant.md)) so the pipeline can determine "what was the last successful step" rather than needing its own separate progress-tracking mechanism. `tenants.status` always reflects current state; the events table is the audit trail.

## Merchant self-service signup — IMPLEMENTED (TASK-MVP-001)

Steps 1 and 10 below were Phase 0 speculation ("Platform user signs up", "insert into the tenant DB's `admins` table with the signup user's actual email... rather than Bagisto's Installer default") - a real implementation now exists, in `packages/Platform/Signup` (namespace `Platform\Signup`, depending ONLY on `Platform\Tenancy`).

**Flow**: `GET/POST /join` (central domain only, `EnsureCentralDomain` + the `platform` middleware group - same structural "can never initialize tenancy" guarantee Platform Admin already has) → `Platform\Signup\Services\MerchantOnboarding::register()` creates the central `Tenant`(Pending)+`Domain` rows in one transaction, then calls `TenantProvisioner::provision($tenant, $ownerAdmin)` synchronously (no queue for MVP - see below) → on success, a plain HTTP redirect to the merchant's own tenant Admin login (`{slug}.{base_domain}/{admin_url}/login?welcome=1`); no cross-domain auto-login is attempted (central and tenant sessions are deliberately isolated - see [security.md](security.md)).

**Tenant `id` IS the merchant-chosen slug** - no separate slug column, matching every existing fixture/CLI convention in this codebase (`tenant-a`, `tenant-planmgmt-a`, ...). Validated against `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$` (3-32 chars) before it ever reaches `Tenant::create()` or the physical database name (`'tenant'.$id`, backtick-quoted but never escaped by `stancl/tenancy`'s own `MySQLDatabaseManager` - RISK_REGISTER.md R19) - this closes R19 at the input boundary rather than patching vendor code. Also checked against a small reserved-word list (`config('platform.signup.reserved_slugs')`) merged with `config('tenancy.central_domains')`.

**Owner admin identity (Phase 0's step 10, now real)**: `TenantProvisioner::provision()` gained an OPTIONAL, additive `$ownerAdmin` parameter (`['name', 'email', 'password']`, plaintext). When given, a new final pipeline step, `ensureOwnerAdminSeeded()`, OVERWRITES the seeded placeholder admin (`admin@example.com`/`admin123`, `AdminsTableSeeder`'s hardcoded, identical-across-every-tenant default) with the real merchant identity, hashed at the point of use. Every existing caller (`tenant:provision` CLI, Platform Admin's provision/retry button) passes nothing and is completely unaffected - see the class's own docblock for why this extends the canonical pipeline rather than a signup-package-owned side call (owner-identity-setting needed to be genuinely retry-safe, matching every other `ensure*()` step).

**Synchronous provisioning (deliberate, approved MVP decision)**: no queue, no polling UI, matching Platform Admin's own provision button exactly. The one operational cost - a blocking POST for the duration of real database creation/migration/seeding - is accepted for MVP; a production reverse proxy/PHP-FPM deployment (TASK-MVP-004) MUST raise its request timeout for this one route accordingly (do not weaken timeout configuration globally). Converting to a queued flow later requires zero change to `TenantProvisioner`'s own contract (already anticipated by `tenant:provision`'s own docblock).

**Retry authorization - the required security adjustment**: knowledge of a tenant's slug/domain (public information) is NEVER sufficient to trigger a retry. `GET/POST /join/retry/{tenant}` both require Laravel's `signed` middleware - a cryptographically signed, 24-hour time-limited URL that exists only because it was rendered directly on the merchant's OWN failed-signup response page. The retry form always asks the merchant to re-enter their password: **the plaintext password is never persisted anywhere** (not on `tenants`, not in `data`, not in cache, not in a log, not even briefly in session) - it lives only in the PHP call stack of whichever single request supplied it. A failed attempt therefore has nothing to silently resume with except asking again. `TenantProvisioner::provision()`'s own pre-existing `if (status === Ready) return;` early return additionally makes a stale/leaked/reused signed retry link against an already-Ready tenant a safe no-op by construction - the owner-admin step is never reached a second time regardless.

**Known, documented limitation**: if a merchant loses their one-time retry link (no email delivery exists in this MVP) after a failed attempt, a Platform-Admin-triggered resume (the existing `provision()` action, unchanged) will still complete provisioning but cannot know the merchant's chosen credentials - the tenant becomes Ready with the DEFAULT seeded admin (`admin@example.com`/`admin123`) still in place, since Platform Admin never had the plaintext password. An operator would need a separate, not-yet-built mechanism to reset that tenant's admin credentials. Accepted for MVP given the constraint against persisting plaintext passwords anywhere.

**Plan/subscription**: unchanged - every self-registered tenant starts on `config('platform.plans.default_code')` (the same default CLI-provisioned tenants get), Active, no trial, through the exact same `SubscriptionLifecycle::start()` call `ensureInitialSubscriptionStarted()` already made before this task. No plan selection at signup (explicit MVP scope decision) - upgrading uses the already-built TASK-ARCH-019 checkout flow after signup.

## Lifecycle steps (mapped to the brief's 14-step sequence)

1. Platform user signs up → creates a central-DB record only, `status = PENDING`. No tenant database exists yet.
2. Platform (or the signup flow itself) transitions `status = PROVISIONING` and dispatches the provisioning job chain.
3. Generate tenant identifier — `slug` uniqueness check, `uuid` assigned.
4. Generate database name — deterministic from tenant id/uuid (e.g. `tenant_{id}`), stored in `tenants.db_name` for auditability rather than re-derived every time.
5. **Create tenant database** — `CreateTenantDatabase` job. Idempotent: checks `SHOW DATABASES LIKE ...` (or equivalent) before issuing `CREATE DATABASE`; a re-run after partial failure is a no-op if the DB already exists.
6. **Configure tenant database connection** — dynamically register the connection config (`config(['database.connections.tenant' => [...]])` + `DB::purge('tenant')`), the mechanism `stancl/tenancy`'s `DatabaseTenancyBootstrapper` provides.
7. **Run Bagisto migrations** — `migrate` (not `migrate:fresh` — `migrate:fresh` drops all tables first, which is what Bagisto's own Installer does for a *single* app install; it is wrong for tenant provisioning where we want a clean, idempotent, additive migrate) against the tenant connection, covering every `packages/Webkul/*/src/Database/Migrations` folder Concord already knows about.
8. **Run required Bagisto seeders** — reuse `BagistoDatabaseSeeder` (from `packages/Webkul/Installer/src/Database/Seeders`) bound to the tenant connection — seeds default channel, locale, currency, roles. Pass whatever parameters the Installer flow passes for `skip_admin_creation: true`, since we create the admin ourselves in step 10 with tenant-specific details rather than Bagisto's hardcoded `admin@example.com`.
9. **Run our SaaS tenant migrations** — only if Phase 9's tenant-side snapshot table (see [database-per-tenant.md](database-per-tenant.md)) is actually implemented; otherwise this step is a no-op by design.
10. **Create initial tenant admin** — insert into the tenant DB's `admins` table with the signup user's actual email, not Bagisto's Installer default (`admin@example.com`, `id=1`) — this is the one place we deliberately diverge from copying the Installer flow verbatim, since a real platform can't hardcode every tenant's admin email to the same address.
11. **Configure default channel/store** — already covered by step 8's seeder; verify (not re-seed) that a default channel with the correct `hostname` (the tenant's assigned subdomain) exists — Bagisto's seeded default channel needs its `hostname` set to the tenant's actual domain, which is tenant-specific and can't come from the generic seeder, so this is a small explicit step after seeding.
12. **Configure tenant domain/subdomain** — create the `domains` row (central DB) linking `{slug}.platform.<domain>` to this tenant, `is_primary = true`.
13. **Mark provisioning as completed** — `status = READY`.
14. **Tenant can access store/admin** — no further action; the domain-routing middleware (see [domain-routing.md](domain-routing.md)) now resolves this tenant normally.

## Idempotency / safe retry

Every job in the chain (`CreateTenantDatabase`, `MigrateTenantDatabase`, `SeedTenantDatabase`, `CreateTenantAdmin`) checks the tenant's current recorded state before acting and no-ops if its step already succeeded, rather than relying on the job queue's own retry semantics alone. This directly avoids the trap identified in [RISK_REGISTER.md](../../RISK_REGISTER.md) R11: Bagisto's own Installer determines "is this installed?" via a flat file (`storage_path('installed')`) and an `admins` table row count (`DatabaseManager::isInstalled()`) — both are fragile, global-state checks unsuited to N independently-provisioned tenants. Our pipeline instead trusts `tenants.status` plus the `tenant_provisioning_events` audit trail as the single source of truth for "what step are we on."

If provisioning fails, `status = FAILED` with the triggering error recorded on the relevant `tenant_provisioning_events` row. A `platform:tenants:reap-failed` scheduled command identifies tenants stuck in `FAILED` (or stuck in `PROVISIONING` past a timeout, indicating a crashed worker) and either automatically retries or surfaces them to platform admins for manual intervention/cleanup, per the brief's requirement to "identify state, retry safely, avoid duplicate data, allow cleanup/rollback."

## Tenant-scoped Laravel scaffolding migrations (TASK-ARCH-010, RISK_REGISTER.md R33)

Step 7 above ("Run Bagisto migrations... covering every `packages/Webkul/*/src/Database/Migrations` folder") is accurate but incomplete in one specific way, found live: `Platform\Tenancy\Services\TenantProvisioner::ensureMigrated()` migrates every path `app('migrator')->paths()` returns - every `packages/Webkul/*` migration directory, **plus** `database/migrations/tenant/` (registered by `TenancyServiceProvider::boot()`'s own `loadMigrationsFrom()` call) - but deliberately **never** `database_path('migrations')` root, since that's where central-only tables (`tenants`, `domains`, `plans`, ...) live and must stay (R17's own fix relies on this exclusion).

The gap: a handful of stock Laravel scaffolding tables also live in that root directory (`sessions`, `password_resets`, `users`, `personal_access_tokens`, `jobs`/`failed_jobs`/`job_batches`) - and while `jobs`/`failed_jobs`/`job_batches` are *correctly* central-only by explicit design (R6/R7, TASK-ARCH-006), and `users`/`password_resets`/`personal_access_tokens` are genuinely unused by Bagisto (which has its own `admins`/`customers` tables and its own `admin_password_resets`/`customer_password_resets` tables, both already `packages/Webkul`-registered and correctly tenant-migrated), `sessions` is **not** unused - `Illuminate\Session\Middleware\StartSession` (part of the `web` middleware group every Bagisto Shop/Admin route runs under) reads/writes it against whatever the *current* connection is, which is the tenant's own connection by the time that middleware runs (`InitializeTenancyByDomain` runs earlier in the same pipeline). Under `SESSION_DRIVER=database` (the real `.env` value), no tenant database had a `sessions` table at all - a real `Illuminate\Database\QueryException` on every single tenant web request, invisible throughout this whole engagement because every Platform test forces `SESSION_DRIVER=array` (phpunit.xml).

**Fix**: `database/migrations/tenant/2026_08_14_150000_create_sessions_table.php` - an exact copy of the central `sessions` schema, placed in the already-tenant-scoped directory. `TenantProvisioner` needed zero code change for new tenants (the path is already dynamically discovered); `php artisan platform:tenants:migrate-pending` (idempotent, safe on any tenant regardless of status, any number of times - Laravel's own per-tenant `migrations` tracking table is what makes this safe) repairs tenants provisioned before this fix existed. See RISK_REGISTER.md R33 for the full reproduction and fix detail.

**General lesson for any future "does this stock Laravel table need to be tenant-scoped" question**: check whether the table is written to during an ordinary request under this project's REAL `.env` settings (not the test suite's overrides) - `array`/`sync`-style test defaults mask an entire class of bug that only a real, production-representative configuration exposes.

## Tenant lifecycle traffic matrix — IMPLEMENTED (TASK-ARCH-014)

Every `TenantStatus` case now has an explicit, centrally-enforced traffic policy — `Platform\Tenancy\Http\Middleware\TenantAccessGate` (generalized from TASK-ARCH-013's `Suspended`-only `BlockSuspendedTenants`) resolves the tenant from a plain central query and rejects any non-Ready request before `InitializeTenancyByDomain` — and therefore any tenant database connection — ever runs. This closes RISK_REGISTER.md R41.

| Status | Reachable by real HTTP traffic? | Response | Reachable by Platform Admin / CLI (`$tenant->run()`)? |
|---|---|---|---|
| `Pending` | No | 503 Service Unavailable | Yes — `platform.tenants.provision` |
| `Provisioning` | No | 503 Service Unavailable | Yes — provisioning is itself the code running against it |
| `Ready` | **Yes** | normal Bagisto response | Yes |
| `Failed` | No | 503 Service Unavailable | Yes — `platform.tenants.provision` (retry) |
| `Suspended` | No | 423 Locked | Yes — `platform.tenants.migrate-pending`, `reactivate` |
| `Deleting` | No | 503 Service Unavailable | N/A — deletion itself not yet implemented (see below) |
| `Deleted` | No | 503 Service Unavailable | N/A — deletion itself not yet implemented (see below) |
| *(unrecognized/future status)* | No (fail closed) | 503 Service Unavailable | n/a |

Only `Ready` is request-eligible. `Suspended` keeps the distinct `423 Locked` response TASK-ARCH-013 established (an explicit, reversible, administrative lock — see [security.md](security.md) and DECISION_LOG.md C28); every other non-Ready status — including any status this file doesn't yet enumerate, by construction of the gate's fail-closed `match` — gets a generic `503 Service Unavailable` (DECISION_LOG.md C30), since none of them represent an administrative lock on an otherwise-working store, only "not currently servable." Neither response body ever names the tenant, its database, or its provisioning/error state.

**Why HTTP middleware, not a check inside `TenantProvisioner`/`DatabaseTenancyBootstrapper`**: the gate must reject real inbound traffic while still letting Platform Admin's own trusted `$tenant->run()`-based maintenance calls (provision/retry, remigrate) reach a non-Ready tenant on purpose — see [domain-routing.md](domain-routing.md)'s TASK-ARCH-014 section for the full request-flow proof and reasoning.

`SUSPENDED` specifically: tenant database and data are untouched; the gate refuses to resolve the tenant to a working store (returns a suspension response instead) — enforced centrally, before any tenant DB connection is even established, so suspension cannot be bypassed by a request that skips some later check (see [security.md](security.md) and [domain-routing.md](domain-routing.md) for the exact request flow).

**Lifecycle service**: `Platform\Tenancy\Services\TenantLifecycle` owns the only two transitions built so far:

```
Ready      -> Suspended    (suspend())
Suspended  -> Ready        (reactivate())
```

Both are plain, explicit methods — no controller ever sets `$tenant->status` directly. An invalid transition (suspending a non-Ready tenant, reactivating a non-Suspended one) throws `Platform\Tenancy\Exceptions\InvalidTenantTransitionException` rather than silently no-op-ing or overwriting state. Both methods are pure central-`tenants`-table metadata writes — neither ever calls `$tenant->run()` or touches the tenant's own database, which is exactly what makes reactivation instantaneous and safe: there is nothing to reprovision, reseed, or re-migrate, because suspension never modified the tenant database in the first place. Proven live: a product created before suspension is byte-for-byte identical (same id, same `created_at`) after reactivation, and the tenant's own `migrations` table is unchanged.

**Reusable outside the HTTP layer by design** (task requirement, not yet exercised in anger): `TenantLifecycle` takes no request/session dependency, so a future billing-driven automation can call `TenantLifecycle::suspend()`/`reactivate()` directly (e.g. "subscription delinquent → suspend()", "payment recovered → reactivate()") with zero changes to this class — see DECISION_LOG.md for the specific record. No such automation exists yet; only `Platform\Admin\Http\Controllers\TenantController::suspend()/reactivate()` calls it today, gated by the same `auth:platform` guard every other Platform Admin action uses. **Update (TASK-ARCH-016)**: a real `Subscription`/`SubscriptionStatus` domain now exists (see docs/architecture/subscriptions.md), but this specific automation is still deliberately not built - `TenantStatus` and `SubscriptionStatus` remain two fully independent state machines by design (docs/architecture/subscriptions.md "TenantStatus independence"); canceling a subscription does not call `TenantLifecycle::suspend()` today, and a future explicit billing-policy task would be the place to wire that, not something either domain does implicitly.

**Interaction with the provisioning state machine**: `Suspended` was never provisionable (`TenantStatus::isProvisionable()` only ever returned true for `Pending`/`Provisioning`/`Failed` — confirmed unchanged by this task) and Platform Admin's own Provision/Retry button already conditions on that same check, so a Suspended tenant correctly never shows a Provision button and `TenantProvisioner::provision()` correctly refuses to run against one. `TenantProvisioner::remigrate()` (`platform:tenants:migrate-pending`) deliberately remains callable against a Suspended tenant regardless (its own established contract: "safe to run against any tenant, any number of times, regardless of status", TASK-ARCH-010/R33) — this is why suspension enforcement had to be a `web`-group HTTP middleware rather than a listener on `Stancl\Tenancy\Events\InitializingTenancy` (which fires identically for both a real inbound request AND `remigrate()`'s own internal `$tenant->run()` call); see `Platform\Tenancy\Http\Middleware\TenantAccessGate`'s own docblock for the full reasoning.

## Deletion — NOT YET IMPLEMENTED

`DELETING` → `DELETED`: drops the tenant database after (a) an explicit confirmation step and (b) optionally an export/backup step, per the brief's future-scale requirement for tenant export. The exact backup mechanism is out of scope for Phase 0 architecture and should be designed alongside Phase 18 (production deployment) once actual backup infrastructure is chosen. This task remains out of scope — deletion has real, unresolved data-loss/backup-strategy questions no task has answered yet. TASK-ARCH-014 only ensures that if a tenant record already carries `Deleting`/`Deleted` (enum cases that exist today even though nothing transitions a tenant into them yet), traffic fails closed — never that either state is actually reachable through any real workflow yet.

## `bagisto:install` is not a supported workflow (INCIDENT-001)

`php artisan bagisto:install` is Webkul-authored and assumes it owns the
entire database it targets (`db:wipe` then `migrate:fresh`, unconditionally,
against whatever the current default connection is). It is **not** a
supported workflow for this SaaS fork once the central database is real —
i.e. after initial upstream Bagisto setup. See
[docs/incidents/INCIDENT-001-central-db-wipe.md](../incidents/INCIDENT-001-central-db-wipe.md)
for the full incident this caused and RISK_REGISTER.md R44 for the
safeguard now in place (`Platform\Tenancy\Services\CentralDatabaseWipeGuard`
statically prohibits `db:wipe`/`migrate:fresh`/`migrate:refresh`/`migrate:reset`
against the real central database by name, independent of `APP_ENV`).

The supported central bootstrap sequence for this project is:

```
php artisan platform:migrate:central
php artisan platform:plans:seed
php artisan platform:admin:create
```

Tenant creation/migration always goes through `TenantProvisioner` (above),
never through `bagisto:install`.
