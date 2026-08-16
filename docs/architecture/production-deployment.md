# Production Deployment

**Status: APPLICATION-READY (TASK-MVP-004A, 2026-08-16).** The authoritative deployment document for this platform. Covers the recommended pilot topology, domain/TLS model, environment configuration, database credential model, and bootstrap/upgrade sequences. Written entirely from what this codebase's own architecture actually requires (see [TASK-MVP-004's investigation](../..) findings) - no infrastructure has been provisioned, no real domain/server/DNS/TLS/SMTP has been configured, and no production docker-compose exists yet. Those remain TASK-MVP-004B (actual pilot deployment) work, gated on real domain/hosting decisions only the product owner can make.

## A. Recommended pilot topology

```
Internet
  -> DNS
  -> Reverse Proxy / TLS termination
  -> Laravel/PHP (this application)
  -> MySQL
  -> Redis
  -> persistent storage (tenant uploads, image cache)
```

**One Linux server (Docker Compose or plain PHP-FPM/MySQL/Redis) is sufficient for a 1-5 merchant controlled pilot.** Nothing in this codebase's actual behavior requires more: `QUEUE_CONNECTION=sync` needs no separate worker process (see section I), there is no multi-server assumption anywhere in `Platform\*`, and no code path depends on Octane/Horizon/Kubernetes. Do not introduce any of those without a concrete, evidenced need.

## B. Domain model

- `PLATFORM_BASE_DOMAIN` - the parent hostname tenant subdomains attach to (`{slug}.{PLATFORM_BASE_DOMAIN}`, e.g. `app.example.com` -> `mystore.app.example.com`). See `config/platform.php`.
- `PLATFORM_CENTRAL_DOMAINS` - the explicit hostname(s) serving the central app (Platform Admin, signup) only. See `config/tenancy.php`. **Deliberately a separate configuration from `PLATFORM_BASE_DOMAIN`** - a real deployment could run its central app on the exact same host tenants are subdomained under, or on an entirely different one; only the operator knows which, so nothing is derived automatically.
- Wildcard tenant DNS: a single `*.{PLATFORM_BASE_DOMAIN}` DNS record covering every current and future tenant subdomain, plus one record for the central domain(s) themselves.

Choosing the actual domain and whether the central app shares a host with tenants is a human decision (section... see the TASK-MVP-004 investigation report) - not made here.

## C. HTTPS / TLS

**A single wildcard TLS certificate for `*.{PLATFORM_BASE_DOMAIN}` (covering the central domain and every tenant subdomain) is sufficient for MVP.** True per-tenant custom-domain SSL remains post-MVP. Any reverse proxy with automatic ACME wildcard issuance and renewal (e.g. Caddy, or nginx + certbot with a DNS-01 challenge plugin) is a reasonable choice - this is entirely a reverse-proxy-layer concern; the application needs no change beyond section D (trusted proxies) to correctly detect `https` from the proxy's forwarded headers. HTTP -> HTTPS redirects are standard reverse-proxy configuration, not application code.

## D. TRUSTED_PROXIES (RISK_REGISTER.md R20)

Implemented in `bootstrap/app.php` (TASK-MVP-004A): `TRUSTED_PROXIES` is a comma-separated list of IP addresses/CIDR ranges (parsed by `Platform\Tenancy\Support\EnvList::parse()` - whitespace trimmed, empty entries dropped; CIDR notation passes through untouched since Symfony's own trusted-proxy IP matching, which `Illuminate\Http\Middleware\TrustProxies` delegates to, natively understands it).

- **Unset/blank** (local dev default) -> falls back to `'*'`, the exact previous behavior, zero setup required.
- **Set** (production, required) -> only requests physically arriving from those IP(s) have their `X-Forwarded-For`/`X-Forwarded-Host`/`X-Forwarded-Port`/`X-Forwarded-Proto` headers honored. A request reaching the app by any other path gets its own real connection's host/scheme/IP - never a value an arbitrary client could spoof.

**PRODUCTION MUST set `TRUSTED_PROXIES` to the real reverse proxy's address(es).** This closes R20 at the application level; the actual IP value is only known once the real proxy exists (TASK-MVP-004B).

## E. Production environment checklist

| Variable | Requirement | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **Must not remain `true`** - leaks stack traces/config on error pages. |
| `APP_URL` | real central URL | |
| `APP_KEY` | fresh, generated per environment | Never reused from dev. |
| `TRUSTED_PROXIES` | real proxy IP(s)/CIDR | See section D. |
| `PLATFORM_BASE_DOMAIN` | real tenant parent domain | See section B. |
| `PLATFORM_CENTRAL_DOMAINS` | real central hostname(s) | See section B. |
| `DB_*` | real central DB credentials | Central app user - see section F. |
| `DB_PROVISION_USERNAME`/`DB_PROVISION_PASSWORD` | real provisioning credentials | **Must not be MySQL root** - see section F. |
| `CACHE_STORE` | `redis` | See section G. |
| `QUEUE_CONNECTION` | `sync` for initial pilot | See section I. |
| `SESSION_DRIVER` | `database` | See section H. |
| `RESPONSE_CACHE_ENABLED` | `false` | See section K. Do not flip without closing R1 for real. |
| `REDIS_*` | real Redis connection | See section G. |
| Mail (`MAIL_*`) | real SMTP fallback strongly recommended | See section L. |
| `BILLING_PROVIDER`/`STRIPE_*` | leave Stripe keys blank for the pilot | See section L of the TASK-MVP-004 report / `.env.example`'s own comment. |

Run `php artisan platform:production:check` (TASK-MVP-004A, section F below) against the real production environment before going live - it reports exactly this checklist's status, read-only.

## F. Database credential model

**The application must never use MySQL root.** Two distinct users:

- **Central application user** (`DB_USERNAME`/`DB_PASSWORD`): DML + DDL rights scoped to the central database only (`bagisto_central`, or whatever it's named) - DDL is included so this same user can run `platform:migrate:central`/`platform:plans:seed` during deploys without a second migration-only credential, while never touching another database.
- **Provisioning user** (`DB_PROVISION_USERNAME`/`DB_PROVISION_PASSWORD`, `config/database.php`'s `tenant_provisioning` connection): needs `CREATE`, `DROP`, `CREATE USER`, `DROP USER`, `GRANT OPTION`, plus every grant `Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::$grants` lists (it cannot grant privileges it doesn't itself hold) - ideally scoped to a `tenant%`.* wildcard if your MySQL/hosting setup supports wildcarded `GRANT` targets, rather than a fully global `*.*` grant. **Exact `GRANT` statements are intentionally not prescribed here** - verify against `PermissionControlledMySQLDatabaseManager`'s actual requirements at deploy time rather than trusting a possibly-stale list in this document.
- Every **per-tenant runtime** database already gets its own narrowly-scoped, automatically-generated MySQL user (`PermissionControlledMySQLDatabaseManager`) - already correct, no change needed.

`php artisan platform:production:check` flags a literal `root` `DB_PROVISION_USERNAME` as a failure.

## G. Redis / cache

**`CACHE_STORE=redis` is required for production.** `Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper` (always active, `config/tenancy.php`) requires a taggable cache store - `array` is safe (no cross-tenant leak) but provides **no real cross-request caching benefit under PHP-FPM** (each worker process has its own private, empty array). A real, running Redis instance is required for the cache layer to do anything useful in production; it does not need to also serve as the queue backend for the pilot (`QUEUE_CONNECTION=sync`, section I).

## H. Sessions

**`SESSION_DRIVER=database`** - the proven, already-tested architecture (tenant sessions in each tenant's own database, Platform Admin sessions in the central database, both real isolated `sessions` tables, no extra isolation code). Do not move to Redis-backed sessions just because Redis is available for cache - that would add a NEW isolation mechanism (`RedisTenancyBootstrapper`, currently disabled and never exercised in this codebase) to solve a problem that does not exist.

## I. Queue / workers

**`QUEUE_CONNECTION=sync` is the recommended posture for the initial pilot - no worker process is required.** Under `sync`, every "queued" job (including Bagisto's own order-confirmation mailables) executes inline within the same HTTP request. The cost is real but small at pilot scale: checkout/order-placement requests block for the duration of mail sending. If pilot volume or responsiveness later demands an async connection, use `redis` (already proven tenant-isolated, see `docs/architecture/queues.md`) with a **single shared central worker process** (never per-tenant) restarted after every deploy, supervised by a Docker restart policy (`restart: unless-stopped`) or systemd if not containerized.

## J. Scheduler

**Not required.** `bootstrap/app.php`'s `withSchedule()` closure is empty; no `Schedule::` call exists anywhere in this codebase; no scheduled task (e.g. a `reap-failed`/subscription-renewal command) has been built. `schedule:run`/`schedule:work` add nothing until a real scheduled task exists.

## K. Response cache (RISK_REGISTER.md R1)

**`RESPONSE_CACHE_ENABLED=false` for MVP production.** The shipped Bagisto default of `true` would reintroduce R1's cross-tenant leak risk (the full-page cache key hasher is not tenant-scoped). Do not flip this without first closing R1 for real (a dedicated tenant-scoped-key task) - `CacheTenancyBootstrapper`/Redis being in place (section G) addresses ordinary `Cache::` facade isolation only, not this separate mechanism.

## L. Mail

Bagisto's mail transport (`bagisto-dynamic-smtp`, `Webkul\Core\Mail\Transport\DynamicSmtpTransport`) reads per-tenant SMTP settings first (merchant-configurable via Admin -> Configuration -> Emails), falling back to central `.env`/`config('mail.mailers.smtp.*')` if unset, and throws a clean exception if neither is configured. **A working central SMTP fallback is strongly recommended before the first external pilot merchant** - without it, a merchant who has not yet configured their own SMTP will not get order-confirmation emails (and, since `QUEUE_CONNECTION=sync` runs mail inline, the exact effect on the checkout HTTP response itself should be verified directly against a real unconfigured environment before launch, not assumed). `php artisan platform:production:check` warns if the central fallback still points at the local-dev Mailpit catcher (`127.0.0.1:2525`).

## M. Persistent storage

Must survive restart/redeploy: tenant uploads (`storage/tenant{id}/app/public`, `.../app/private`) and generated image-cache files. Does not need to survive: application code (redeployed from source), logs, the `public/storage` symlink (recreated by a deploy step). **A single persistent volume/bind mount covering the app's `storage/` directory is sufficient for a one-server pilot.** The current `docker-compose.yml` (Laravel Sail's local dev stack) has **no volume for `storage/` at all** - a production compose file (TASK-MVP-004B) must add one.

## N. Backup requirements (not implemented in TASK-MVP-004A - see below)

Minimum pilot policy:

- Central DB: daily dump.
- All tenant DBs: daily dump (looping the central `tenants` table, same mechanism used during INCIDENT-001 recovery).
- Tenant storage files: daily filesystem-level backup.
- Off-server/off-volume destination (never only on the same disk the application runs on - INCIDENT-001's own precedent).
- Retention: e.g. 7 daily + 4 weekly (a reasonable starting point, not a hard requirement).
- Encryption at rest at the backup destination (dumps contain real customer/order data).
- Periodic (e.g. monthly) real restore-verification, not just "backup exists."

**Execution/scheduling of this policy (an actual backup script, a cron/systemd-timer, an off-server destination) is TASK-MVP-004B work** - it depends on the real production topology, which does not exist yet. This document records the policy; it does not implement it.

## O. First-production bootstrap sequence

Uses **only** supported Platform commands - never `bagisto:install`, a bare `php artisan migrate`, `migrate:fresh`, or `db:wipe` against the central production database (already structurally blocked by `Platform\Tenancy\Services\CentralDatabaseWipeGuard`/the `PreventCentralMigrationOfTenantSchema` listener, but the sequence below never attempts them regardless):

```
1. Provision server; install Docker/PHP/MySQL/Redis (or equivalent managed services)
2. Deploy source; composer install --no-dev --optimize-autoloader
3. Configure .env with real production values (section E) - APP_KEY generated fresh
4. php artisan platform:mark-installed
5. php artisan platform:migrate:central
6. php artisan platform:plans:seed
7. php artisan platform:admin:create   (interactive - password is never logged)
8. Ensure the storage/ persistent volume exists with correct permissions; php artisan storage:link if needed
9. Confirm Redis is reachable (CACHE_STORE=redis)
10. (Only if an async queue connection is chosen - section I) start a queue worker
11. Reverse proxy + wildcard TLS in front of the app (section C)
12. DNS: PLATFORM_BASE_DOMAIN + wildcard record pointed at the proxy
13. php artisan platform:production:check   (verify the checklist above before announcing the pilot live)
```

## P. Ongoing deployment / upgrade sequence

```
1. Pull/deploy new source
2. composer install --no-dev --optimize-autoloader
3. php artisan platform:migrate:central          (central schema changes)
4. php artisan platform:tenants:migrate-pending  (existing tenants pick up new tenant-scoped migrations)
5. php artisan config:cache / route:cache / view:cache   (optional, standard Laravel perf step)
6. Restart the queue worker, only if one is running (section I) - it must reload new code
7. Rebuild frontend assets (Admin/Shop themes), only if this deploy changed frontend source
```

Reuses `platform:migrate:central`/`platform:tenants:migrate-pending` exactly as already built - no new deployment framework.

## Q. `packages/Webkul/*` is not our customization surface

Every command, config change, and deployment step in this document operates entirely on `Platform\*` packages, root config files, and `bootstrap/app.php`. Nothing in this document requires, and nothing should ever require, editing `packages/Webkul/*` - that remains Bagisto's own unmodified upstream code for the lifetime of this project.
