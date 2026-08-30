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

## N. Backups

**IMPLEMENTED (TASK-MVP-003A).** Central DB, every tenant DB, and every
tenant's persistent files - daily, via `platform:backup:run`/
`platform:backup:cleanup` (`packages/Platform/Backup`), scheduled via a root
crontab entry. Full implementation record, manifest/checksum design,
security/permissions, restore-verification proof, and the disaster-recovery
runbook: [docs/implementation/backup-and-recovery.md](../implementation/backup-and-recovery.md).
`php artisan platform:production:check` includes a `Backups` row reporting
the newest successful backup's age.

**Off-server sync - IMPLEMENTED (TASK-MVP-005).** `platform:backup:sync-offsite`
copies every finalized local backup to an independent, S3-compatible offsite
destination (Cloudflare R2 as the current provider - see
[docs/implementation/backup-and-recovery.md](../implementation/backup-and-recovery.md)
"Offsite sync" for the full architecture, configuration, verification, and
retention design). The local destination (`/opt/estore/backups/`) remains
the SAME physical server as the live application/database by design - it is
still the first, primary restore source; the offsite copy is disaster-recovery
protection against loss of that server specifically, not a replacement for
it. `php artisan platform:production:check` includes an `Offsite Backup` row
alongside the existing `Backups` row.

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
1. Update the host source tree (/opt/estore/app/) to the exact target commit -
   tar+scp+sha256-verify on both ends (see "Deployment source of truth" below).
   This step is NOT performed by deploy.sh itself - it must happen first.
2. php artisan platform:migrate:central          (central schema changes)
3. php artisan platform:tenants:migrate-pending  (existing tenants pick up new tenant-scoped migrations)
4. docker/production/deploy.sh <git-commit-sha>  (TASK-MVP-017, RISK_REGISTER.md R76,
   DECISION_LOG.md C92) - the one canonical script for everything after source is in
   place: writes APP_COMMIT, builds app+web TOGETHER, recreates app+web TOGETHER
   (--no-deps - mysql/redis are never touched), clears config/route/view caches, then
   runs `php artisan platform:production:check` as its own final gate. A non-zero
   check exit fails the script's own exit code too - see "app/web asset consistency"
   below. No automatic rollback: a failed gate needs a human to read the check output
   and decide the right recovery step.
5. Restart the queue worker, only if one is running (section I) - it must reload new code
```

Reuses `platform:migrate:central`/`platform:tenants:migrate-pending` exactly as already built - no new deployment framework. **`docker/production/deploy.sh` is now the only supported way to rebuild/recreate the running containers for a deploy** - do not hand-type `docker compose build app` (or any single-service build/recreate) directly; see "app/web asset consistency" below for exactly why.

### Deployment source of truth (RISK_REGISTER.md R65)

**The host source tree (`/opt/estore/app/` on the real pilot server) is the
one thing every deployment - a plain `docker cp` config patch, a full
`docker compose build`, a container recreate - is ultimately built from.**
A real incident (2026-08-18, R65) proved what happens when this is violated:
a hotfix (R63, `config/tenancy.php`'s `asset_helper_tenancy`) was applied
directly into a RUNNING CONTAINER's writable layer only, via `docker cp`,
without also updating the host source tree. The running application looked
completely correct - verified live, in a real browser - for days. A later,
entirely unrelated, legitimate deployment (TASK-MVP-005, which needed a real
image rebuild for a new Composer dependency) rebuilt the image from the
still-stale host source tree, silently discarding the hotfix the moment the
container was recreated. Nothing about that later deployment was wrong in
isolation; the actual defect was introduced days earlier, the moment the
hotfix was applied to the container instead of the source.

**The rule, going forward: a hotfix is never "done" until the host source
tree matches it.** If a fix is urgent enough to apply directly to a running
container first (acceptable for restoring service quickly), the VERY NEXT
step - before considering the incident closed - is applying the identical
change to the host source tree (`/opt/estore/app/`), not "later" or "next
deploy." `docker cp`/`docker exec` patches are a legitimate FAST PATH to
restore service; they are never a substitute for updating the source of
truth a future rebuild will read from.

**Drift detection.** Two small, deliberately narrow additions (not a general
config-audit framework):

1. **`APP_COMMIT` marker** (step 2 above) - a plain text file containing the
   git commit hash actually deployed, written fresh by the deploy process
   itself (never committed to git - it would go stale the instant a new
   commit landed; see `.gitignore`). Already read by
   `Platform\Backup\Services\BackupRunner::appCommit()` (previously always
   `null`, since no deploy step had ever written it) and now also reported
   by `php artisan platform:production:check`'s new `Deployed source` row -
   WARN if the marker is missing, PASS with the commit hash if present. This
   does not itself prove the deployed FILES match that commit (no `.git`
   exists inside the production image, by design - see `.dockerignore`) -
   it closes the narrower, more important gap that let R65 go undetected:
   there was previously no way to even ASK "what commit is this server
   running" without manually inspecting individual files by hand.
2. **A concrete regression guard** for the exact value that actually broke:
   `platform:production:check`'s new `asset_helper_tenancy` row FAILS the
   command outright if `config('tenancy.filesystem.asset_helper_tenancy')`
   is ever anything other than `false` - not a generic audit, one targeted
   assertion for one already-proven-dangerous value (RISK_REGISTER.md R63).

### app/web asset consistency (RISK_REGISTER.md R76, DECISION_LOG.md C92)

**`APP_COMMIT`/"Deployed source" (above) proves `app` matches the deployed
commit. It does NOT prove the whole running system does.** A real, live
incident (2026-08-20, discovered during TASK-MVP-016's own production
verification) proved exactly that gap: `Dockerfile.production` builds `web`
(nginx, the container that actually SERVES `/themes/.../build/...` static
assets) with a **build-time** `COPY --from=app /var/www/html/public
/var/www/html/public` - a one-time copy baked into `web`'s own image, never a
runtime-shared volume with `app`. Every deployment in this project's history
before this task rebuilt/recreated `app` alone, by hand - no documentation
had ever written the complete "rebuild both" command, so the omission of
`web` was pure undocumented habit. `web` went 10 days without being rebuilt
while `app` was redeployed 3 times, silently serving a 10-day-stale JS/CSS
bundle until Admin's freshly-rebuilt Vite manifest referenced a hashed
filename `web`'s own stale image never had - a direct 404 from nginx itself,
never reaching PHP. The Merchant Admin Vue SPA never mounted; Login/Reset
Password were completely inert across every tenant. `platform:production:
check` reported PASS with the exact correct `APP_COMMIT` the entire time - it
only ever runs inside `app`, so it structurally had zero visibility into
`web`'s own separately-built state.

**Two durable safeguards, added together (never rely on only one):**

1. **`docker/production/deploy.sh`** (section P above) is now the one
   canonical script for rebuilding/recreating containers - it always builds
   and recreates `app` AND `web` together, never one alone. Docker's own
   content-addressed build cache guarantees `web`'s internally-rebuilt `app`
   stage matches exactly whenever both are built back-to-back from the same
   source - directly proven during this incident's own recovery (rebuilding
   `web` alone, immediately after `app` had already been correctly rebuilt,
   produced byte-identical asset checksums between the two containers).
2. **`platform:production:check`'s `Admin static assets`/`Shop static
   assets` rows** (`ProductionReadinessCheck::checkThemeStaticAssets()`)
   detect a recurrence even if a future deploy somehow bypasses the script.
   For each theme, the check reads the REAL Vite manifest on disk
   (`public/themes/{admin,shop}/default/build/manifest.json`) - never a
   hardcoded generated filename - to find the CURRENT hashed CSS+JS
   entry-point files, then makes a real internal HTTP request to
   `http://web/themes/{admin,shop}/default/build/{file}` (the same Docker
   Compose internal network `app`↔`web` already uses for
   `fastcgi_pass app:9000`, so this requires no public DNS/TLS) to prove
   `web` is actually SERVING that exact file today, not merely that it
   exists inside `app`. A 3-second timeout, zero retries (a deterministic
   failure must stay deterministic, not get masked by a lucky retry).
   **FAILs** (citing this section/R76 directly) on a reachable `web`
   returning a non-200 for a CURRENT manifest asset, on a wrong
   Content-Type for that asset, AND (corrected after the initial
   TASK-MVP-017 implementation - see RISK_REGISTER.md R76's own row) on
   `web` being genuinely unreachable at all: a real manifest already
   existing on `app`'s own filesystem proves this IS a production-shaped
   deployment, so an unreachable `web` at that point is a real
   production-readiness failure, not an ambiguous "maybe there's no
   app/web split here" signal. **INFO** if no manifest exists at all in
   this environment - the correct, non-incident read for local Sail dev,
   where no `web`-named host or real Vite build exists in the first place.
   Both Admin AND Shop are checked (Shop's own CSS happened to survive the
   real incident by pure luck - its filename hadn't changed across those 3
   `app` deploys - not because of any structural protection); JS and CSS are
   both checked for each, since both are cheap, structurally identical
   manifest lookups and the incident's own near-miss on Shop shows relying
   on "JS alone" would not have been a safe minimum invariant.

### Docker disk hygiene (RISK_REGISTER.md R77, DECISION_LOG.md C93)

**Root filesystem reached 94% used (~30GB under `/var/lib/docker` alone) with
no disk lifecycle policy anywhere** (TASK-OPS-018, discovered as an urgent
operational finding, unrelated to any application code defect). TASK-OPS-019
root-caused and fixed it, closing the loop this section describes.

**Root cause, fixed - not merely a bigger cleanup.** `Dockerfile.production`'s
`assets`/`app` stages both ran `COPY . .` (the entire repository) BEFORE
`npm install`/`composer install`. Since the build context changes on nearly
every real deploy, this invalidated the cache for those expensive layers -
and everything after them - on essentially every deployment, REGARDLESS of
whether `composer.lock`/`package.json` actually changed (confirmed directly
from TASK-MVP-017's own real build transcript: `composer.lock` was
byte-identical to the prior deploy, yet a full 172-package
download/extract still ran). Each theme's `package.json` and the root
`composer.json`/`composer.lock` are now copied and their respective install
commands run BEFORE the full source `COPY` - those layers are now cached on
manifest content alone, reused whenever a deploy doesn't actually touch
dependencies. Proven with a real local two-build test (not merely asserted):
a second build containing only a source-only change showed `npm install`
(both themes) and `composer install` all reporting `CACHED`, while the full
`COPY . .`/`npm run build`/`composer dump-autoload` steps correctly reran.

The Composer split runs `composer install --no-dev --no-scripts
--no-autoloader` first (needs only `composer.json`/`composer.lock` - proven
by direct inspection that the declared `path` repository, `packages/*/*`,
resolves ZERO actual packages; `Webkul\*`/`Platform\*` are wired entirely
through the plain `autoload.psr-4` map instead), then, once the full source
exists, `composer dump-autoload --optimize --no-dev --no-scripts`.
`--no-scripts` is deliberately kept on BOTH commands, exactly matching the
original single-step command's own already-proven-safe behavior - confirmed
via `git show` that the original always passed `--no-scripts` too, meaning
`artisan package:discover` has never once run during any production build in
this project's history. A real local build failure hit during this exact
implementation ("Please provide a valid cache path" from Laravel's Blade
view compiler, triggered by eagerly running that script before
`storage/framework/views` is configured) directly proved why introducing it
now would have been unsafe.

**Cleanup is bounded, not unconditional.** Because the Dockerfile fix makes
cross-deploy cache genuinely worth keeping, `deploy.sh` does NOT run a
blanket `docker builder prune -f` after every deploy. Instead, only after a
full deployment success and a passing `platform:production:check`, it
delegates to `docker/production/docker-disk-hygiene.sh` (a small, shared,
always-best-effort script - never fails the deployment itself), which runs:

1. `docker builder prune -f --filter "until=168h"` - age-bounded (7 days),
   never unconditional. `--filter until=` was verified directly against
   this project's real Docker/BuildKit version (not guessed): a real local
   before/after test proved it filters by each cache entry's own
   LAST-ACCESSED time, and never touches cache still backing an existing
   image/build regardless of age.
2. `docker image prune -f` - narrow, dangling-only, never `-a`, never
   touches a tagged/in-use image (structurally guaranteed by Docker
   itself), so `estore-app:latest`/`estore-web:latest`/`estore-app:
   rollback`/`estore-web:rollback`/`mysql:8.0`/`redis:7-alpine`/any
   unrelated tagged image already on the host are all untouched.

Neither ever runs `docker system prune`, `docker image prune -a`, or any
volume-affecting command.

**Fail-closed disk pre-flight gate.** Before any build, `deploy.sh` now
reads the real `df` state (never Docker's own "reclaimable" byte accounting
- directly observed, during TASK-OPS-018's own cleanup, to re-inflate after
a real prune while the actual filesystem stayed flat) for the filesystem
BACKING DOCKER'S OWN DATA ROOT - resolved dynamically via `docker info
--format '{{.DockerRootDir}}'`, never hardcoded as `/var/lib/docker`, since
that is what `docker compose build`/BuildKit actually consumes storage on.
If that cannot even be resolved, the gate fails closed rather than
guessing. The host source tree's own filesystem is checked too, but only as
a genuinely separate check when `df` reports a different backing device -
avoiding a redundant duplicate check on today's shared-filesystem topology,
while staying correct if that topology ever changes. Refuses to start if
free space is below **8GB** or usage is above **92%** on either checked
filesystem - roughly 3x headroom over this project's own observed
worst-case single-build transient footprint (~2-3GB), deliberately not
tight for this ~83GB disk. A **75%** usage crossing is reported as an
informational warning (before the build, and again after cleanup) but never
fails anything on its own.

**Concurrency lock.** `deploy.sh` and `docker-disk-hygiene.sh` share one
`flock`-based exclusive lock (`${APP_ROOT}/.docker-deploy.lock`, `flock`
from `util-linux` - confirmed already installed on the real production host,
no new package required), held by `deploy.sh` for its ENTIRE run. This
closes a real race: a scheduled hygiene run's own `docker image prune -f`
could otherwise sweep the very image `deploy.sh` is about to preserve as a
rollback target, since that image sits briefly dangling between the build
reassigning `:latest` and the later rollback-tag-advance step (potentially
minutes later, after the full recreate + verification sequence) - a window
BuildKit's own in-use cache tracking does not protect, since that only ever
covers active build cache, never a plain dangling image. Run standalone by
cron, `docker-disk-hygiene.sh` acquires the lock itself (non-blocking) and
safely skips its own run (exit 0, not an error) if a deployment currently
holds it - the next scheduled cycle covers it. When invoked as `deploy.sh`'s
own child process (`DOCKER_HYGIENE_LOCK_HELD=1`), it does NOT try to
acquire the lock again itself, avoiding a self-deadlock. Verified with real
`flock` in a Linux environment matching production (not merely reasoned
about): both directions were directly tested.

**Scheduled backstop, independent of `deploy.sh` - INSTALLED.** `docker-
disk-hygiene.sh` also runs on its own daily schedule, so growth stays
bounded even if `deploy.sh` itself is ever bypassed - the same
"undocumented habit" failure mode R76/C92 already closed for the app+web-
together problem, applied here to disk hygiene. Installed in root's
crontab (`sudo crontab -e`, same style/privilege context as the existing
backup jobs - `crontab -l` shows those at 03:15-03:50), at a different
time and a dedicated log file, never mixed with `/opt/estore/backups/
backup-run.log`, using the script's own canonical absolute path directly
(no `cd`/`bash` wrapper needed - the script is executable with a correct
shebang, and it resolves its own paths internally regardless of the
caller's working directory):

```
# TASK-OPS-019 - Docker disk hygiene backstop (04:00, after backup jobs finish at 03:50, independent of deploy.sh - see RISK_REGISTER.md R77)
0 4 * * * /opt/estore/app/docker/production/docker-disk-hygiene.sh >> /opt/estore/docker-hygiene.log 2>&1
```

**Log rotation - INSTALLED.** `/opt/estore/docker-hygiene.log` is bounded
by a dedicated `/etc/logrotate.d/docker-disk-hygiene` config (weekly,
8 rotations kept, compressed) - this project's real production host
already runs `logrotate` daily via its own systemd timer (confirmed
directly, not assumed), so this reuses existing, already-scheduled
infrastructure rather than adding a new mechanism. No `copytruncate`/
signal handling is needed: nothing holds the log file open persistently,
each cron run simply appends via a fresh `>>` redirection. (The pre-
existing `/opt/estore/backups/backup-run.log` has no rotation configured
- a real, disclosed, pre-existing gap this task did not create and does
not fix, since it is unrelated to this task's own scope.)

**Rollback pair.** `estore-app:rollback`/`estore-web:rollback` (plus a
`ROLLBACK_COMMIT` marker file at `/opt/estore/app/ROLLBACK_COMMIT`,
mirroring `APP_COMMIT`'s own established convention, written via
temp-file-then-rename so it is never observed half-written) replace the
one-off `estore-app:rollback-pre-mvp017`/`estore-web:rollback-pre-mvp017`
tags TASK-OPS-018 manually created on the real server. The pointer is
captured BEFORE each deploy's own build runs, and advanced ONLY after that
deploy fully succeeds and passes `platform:production:check` - so it always
means "the deployment immediately preceding the one just verified," never
merely "whatever the build happened to leave dangling." The PREVIOUSLY-valid
rollback record is ALSO captured before anything is touched; all three
pieces of the new record are re-read and cross-checked after writing, and if
advancing to it fails or is inconsistent, a best-effort restore of the
previously-valid record is attempted and independently re-validated - the
exact outcome (new record confirmed / previous record restored / no
previous record existed / metadata remains inconsistent) is always reported
explicitly, never silently claiming a valid pair exists. None of this ever
fails the deployment itself (the primary deployment has already succeeded
by that point - a rollback-bookkeeping gap is a safety-net concern, not a
production-health one).

**Image rollback alone is explicitly NOT sufficient, and remains manual.**
Retagging `:rollback` onto `:latest` and recreating containers reverts only
the application code/asset layer - never the host source tree (a separate
R65/C76 process) or database migrations (never reversible by any tooling in
this project). Before ever using the rollback pair, confirm no
schema-affecting migration ran in the deployment being reverted. This
project does not implement or endorse automatic rollback in any form.

The prior manually-created `rollback-pre-mvp017` tags remain on the host
until the canonical `:rollback` pair is proven valid by a real production
deployment - not removed as a side effect of this change.

## Q. `packages/Webkul/*` is not our customization surface

Every command, config change, and deployment step in this document operates entirely on `Platform\*` packages, root config files, and `bootstrap/app.php`. Nothing in this document requires, and nothing should ever require, editing `packages/Webkul/*` - that remains Bagisto's own unmodified upstream code for the lifetime of this project.
