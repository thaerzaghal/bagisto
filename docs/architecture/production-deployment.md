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

**R77 - CLOSED.** The first production deployment (TASK-OPS-019 itself)
verified every mechanism above live; the required SUBSEQUENT-deploy evidence
that growth stays bounded on an ongoing basis - not merely that it was reset
once - arrived via TASK-MVP-018's own real, source-changing production
deployment: dependency-install layers all reported `CACHED`, root filesystem
usage stayed exactly flat (66%→66%, 29G free before and after), the age-
bounded builder prune correctly reclaimed 0B (nothing had aged past 7 days
yet), the image prune removed only one genuinely dangling image, rollback
metadata advanced correctly, and mysql/redis were confirmed untouched. This
closes the specific "bounded across real deploys" criterion, not a claim that
disk usage can never grow - a deploy that genuinely changes `composer.lock`/
`package.json` will still correctly invalidate and rebuild those layers, and
the daily cron backstop above remains in place regardless. See
RISK_REGISTER.md R77 for the full evidence chain.

## Q. `packages/Webkul/*` is not our customization surface

Every command, config change, and deployment step in this document operates entirely on `Platform\*` packages, root config files, and `bootstrap/app.php`. Nothing in this document requires, and nothing should ever require, editing `packages/Webkul/*` - that remains Bagisto's own unmodified upstream code for the lifetime of this project.

## R. Monitoring / alerting

**Status: IMPLEMENTED, LOCALLY TESTED, NOT DEPLOYED, NOT ACTIVATED, NOT PRODUCTION VERIFIED (TASK-OPS-MONITORING-001, hardened by a review pass - TASK-OPS-MONITORING-001A - that found and fixed several real defects before any activation was considered).** `php artisan platform:production:monitor` is a thin, opt-in alerting layer around section E/the existing `php artisan platform:production:check` (section 12/§12 of `docs/project-state/CURRENT.md`'s own "health checking vs. monitoring" gap). This section documents the mechanism, its configuration, and the exact activation procedure - none of which has been performed against the real production server. No cron entry has been installed; no real notification has ever been sent; `MONITOR_ALERT_ENABLED` remains unset/false everywhere. **This document does not claim the implementation is "fully reviewed, only activation remains"** - a second review pass already found real defects once; treat this as a living record of what is currently believed true, not a closed-out certification.

### What it does

`platform:production:monitor` calls the exact same `ProductionReadinessCheck::collectResults()` the console command itself uses (a small, added structured-result refactor - see that class's own docblock; the console table's rendered output is byte-for-byte unchanged) and compares this run's statuses against its own small, persisted JSON state file. It emails a single configured operator recipient exactly when something is worth attention: a genuinely new WARN/FAIL, a severity change (WARN↔FAIL), an unresolved incident that has passed the configured reminder interval, or a recovery (a previously-notified check returning to PASS/INFO) - never a duplicate for the same unchanged status, and never fingerprinted on volatile detail text (ages, timestamps, hashes).

A permanent 20th pseudo-check, **"Production monitor,"** is always present alongside the real 19 - PASS whenever `collectResults()` returns a valid result set this run, FAIL whenever it throws or returns something invalid (empty, or duplicate check labels). This is what lets a failure of the monitoring mechanism itself go through the identical new/changed/reminder/recovered notification pipeline as any real check, rather than silently vanishing the moment collection resumes (TASK-OPS-MONITORING-001A finding 3) - if `platform:production:check` itself starts throwing, you get exactly one alert about that, a reminder if it stays broken, and exactly one recovery notice once it starts working again. The real 19 checks' own prior bookkeeping is always carried forward untouched on a run where they were not observed (a monitor-level failure never resets, re-notifies, or silently marks a genuine check as healthy).

**Configuration is validated up front, independent of whether anything is currently WARN/FAIL** (TASK-OPS-MONITORING-001A finding 2): if `MONITOR_ALERT_ENABLED=true` but the recipient is blank or not a syntactically valid email address (checked locally, no external call), every run reports this - including a run where all 20 checks are healthy - and the command's own exit code is non-zero. A fully healthy system with a broken alerting configuration never looks like "nothing to report."

### Configuration (`.env`, see `.env.example` for the full block with inline comments)

| Variable | Default | Purpose |
|---|---|---|
| `MONITOR_ALERT_ENABLED` | `false` | Master switch. Opt-in, disabled everywhere until an operator deliberately sets it. |
| `MONITOR_ALERT_RECIPIENT` | *(blank)* | The one operator inbox. Never inferred from tenant/merchant data. Required before enabling, and must be a syntactically valid email address (checked locally via `filter_var(..., FILTER_VALIDATE_EMAIL)` - never a live/external check) - the command fails visibly, never silently, if blank or malformed while enabled, on EVERY run, not just one where an incident happens to exist. |
| `MONITOR_REMINDER_INTERVAL_MINUTES` | `360` (6h) | How long an unresolved incident waits before a reminder repeats. |
| `MONITOR_MAIL_TIMEOUT` | `10` (seconds) | Bounds one delivery attempt via `config('mail.mailers.smtp.timeout')` - a real, already-supported Laravel/Symfony Mailer option, not an invented one. |
| `MONITOR_STATE_PATH` | `<BACKUP_ROOT>/monitoring` | Where the JSON state file + overlap-lock file live. Defaults to a sibling of the already-durable, already-bind-mounted backup volume - zero new infrastructure. **A real defect, found and fixed (TASK-OPS-MONITORING-001A finding 2)**: leaving this line present-but-blank in `.env` (`MONITOR_STATE_PATH=`, exactly what `.env.example` ships) used to silently resolve to an EMPTY path (`env()` only falls back to a default for a genuinely unset variable, never a blank one) - `config/platform-monitoring.php` now explicitly trims and treats a blank value the same as unset, and `Platform\Tenancy\Services\ProductionMonitorState` independently rejects an empty/relative/root-only resolved path before any filesystem operation, as a second layer. |

Mail is sent through the plain `smtp` mailer (`config('mail.mailers.smtp.*')`) - the exact same central config `checkMail()` already inspects, resolved via `Mail::mailer('smtp')->to(...)->send(...)` specifically (not `Mail::to(...)->send(...)`, which would let Laravel's own `Mailer::sendMailable()` silently force the message through `config('mail.default')` instead - `bagisto-dynamic-smtp`, Bagisto's per-tenant transport, which requires an active channel and throws in central context; a real, reproduced finding during this task's own local testing, documented in `ProductionAlertMail`'s own docblock). Never initializes tenant context; never touches tenant business data.

**Secret disclosure - a real defect found and fixed (TASK-OPS-MONITORING-001A finding 1).** Neither the notification email, the persisted state file, nor this command's own console output ever includes a raw caught-exception message - `ProductionMonitorRunner::describe()` includes only the exception's class name, never `getMessage()`, since a caught exception can originate anywhere in the application and is fundamentally unbounded, unreviewed input. Separately, `ReadinessCheckResult::detail` text (first-party, reviewed code, but not assumed safe merely because of that - two of `platform:production:check`'s own existing checks, `checkRedis()`/`checkThemeStaticAssets()`, already embed a raw exception message into their own `detail` string) passes through `Platform\Tenancy\Support\NotificationRedactor` before reaching a notification - a generic, pattern-based redactor for common credential shapes (`password=`/`token=`/`Bearer <token>`/URI userinfo), applied only at this boundary, never changing `platform:production:check`'s own console rendering.

### Execution user - identify least privilege, verify before choosing, never default to root by imitation

**`docker compose exec app <command>` runs as the container's default user, which source inspection confirms is root, not `www-data`.** `Dockerfile.production` never sets `USER www-data` at the container level (its own comment explains why: `entrypoint.sh` - which itself runs as root - prepares `storage/`/`backups/` ownership on every container start, then hands off to php-fpm, which drops only its own **worker** processes to `www-data` via the pool config; a `docker compose exec` command is a separate process the Docker daemon execs directly into the container, never spawned through that php-fpm worker pool at all). This is a real, sourced correction to this document's own adjacent §N/backup-and-recovery.md prose ("the container's own internal process still runs as www-data/php-fpm exactly as every other artisan command in this project does") - that characterization does not hold for `docker compose exec`-invoked commands specifically; not verified or corrected against the live production server as part of this task (no production access), and not silently rewritten in the other document (out of this task's scope) - flagged here for whoever activates this next.

**The least-privileged CANDIDATE is `www-data`, on its own merits, not by analogy to the backup jobs.** Every readiness check itself needs no elevated privilege at all - plain config reads, a Redis ping, an internal HTTP call, and reads of the already `www-data`-owned app source tree (`Dockerfile.production`: `COPY --chown=www-data:www-data . .`) and the already `www-data`-owned `/backups` directory (`entrypoint.sh`: `chown -R www-data:www-data /backups`). Nothing in `platform:production:monitor` writes anywhere except its own state directory, which it creates itself.

**Do not choose root "because the existing backup jobs use it" - run this read-only, deliberately non-destructive check against the real server FIRST, and let its result decide:**

```bash
# Read-only. Confirms www-data can actually read every artifact
# platform:production:check's own Backups/Offsite-Backup rows depend on -
# the one thing that could make www-data genuinely insufficient (an
# existing backup file/directory created by a root-run job, whose GROUP
# may not be www-data even though the /backups DIRECTORY itself is).
docker compose -f docker-compose.production.yml exec -u www-data app \
  sh -c 'test -r /backups && find /backups -maxdepth 3 -not -readable -print'
```

- **Empty output, exit 0**: `www-data` can read everything it needs. Use `-u www-data` for the cron entry below - the genuinely least-privileged choice, and the one that keeps this command from ever being able to write anywhere it shouldn't, structurally, not by discipline.
- **Any path printed, or a non-zero exit**: something under `/backups` is not `www-data`-readable (most likely a file/directory created by a root-run backup job whose group differs from its www-data-owned parent). In that specific, verified case, match the existing backup/offsite-sync/cleanup cron jobs' own convention instead - no `-u` override, i.e. the container's default (root) - rather than leaving `checkBackupHealth()`/`checkOffsiteBackupHealth()` silently unable to read what they need to report on. This is a documented fallback taken only after the check above actually fails, not the default recommendation.

This task does not change backup file ownership/permissions to make either option pass - whichever the pre-flight check above indicates is used as-is.

### Prepared production scheduling (NOT installed)

```cron
# TASK-OPS-MONITORING-001 (execution bound added TASK-OPS-MONITORING-001A) -
# platform:production:monitor, every 5 minutes. NOT YET INSTALLED - add
# only as part of a deliberate activation step, after
# MONITOR_ALERT_ENABLED/MONITOR_ALERT_RECIPIENT are set in the real
# production .env, the execution-user pre-flight check above has been run,
# and the disable/rollback steps below are understood.
#
# Replace <EXEC_USER> with `-u www-data` or nothing (root), per the
# pre-flight check above's actual result on the real server.
*/5 * * * * cd /opt/estore/app && /usr/bin/docker compose -f docker-compose.production.yml exec -T <EXEC_USER> app timeout 90 php artisan platform:production:monitor >> /opt/estore/monitor-run.log 2>&1
```

Every 5 minutes is a reasonable initial interval: fast enough to catch a real regression quickly, far below the 6-hour reminder interval (so a still-broken check is never re-emailed on every single 5-minute tick).

**Execution bound - a real gap, closed at the process level, not claimed to already exist internally (TASK-OPS-MONITORING-001A finding 4).** The mail-delivery timeout and the readiness checks' own 3-second HTTP timeout each bound ONE internal step, not the whole run - nothing in this project's source configuration puts an explicit connect/read bound on the Redis client `checkRedis()` uses, and a genuinely unresponsive (not merely refused) dependency anywhere in the 20-check pipeline could otherwise block indefinitely. The fix is the `timeout 90` immediately before `php artisan` above, applied INSIDE the `docker compose exec` invocation - i.e. inside the SAME container/process tree as the command it wraps, not a separate host-side wrapper. This distinction is load-bearing: killing only the HOST's `docker compose exec` client (e.g. a naive `timeout 90 docker compose exec ...` wrapping the whole line from the host side) does **not** kill the process it exec'd into inside the container - Docker does not propagate that termination automatically - leaving a real, orphaned PHP process inside `app`, still holding the overlap lock forever, blocking every subsequent scheduled run indefinitely. Proven directly, not merely reasoned about: `tests/Feature/Platform/ProductionMonitorTimeoutTest.php` spawns a real, deliberately-nonresponsive fixture process holding a real `flock()`, confirms an external `timeout` wrapper in the SAME process tree terminates it and the lock becomes immediately re-acquirable, and separately confirms (a negative control) that the identical fixture does NOT self-terminate without that wrapper. `90` seconds is deliberately generous relative to the 5-minute schedule interval (leaves real margin before the next tick) while still well short of it. **Do not** attempt to add a global HTTP/Redis timeout change to close this instead - that would affect the tenant application at large, which this task explicitly must not do; wrapping this one command is the smallest change that reliably bounds the whole run regardless of which internal step is the slow one.

**Overlap handling**: a non-blocking `flock()` on `<MONITOR_STATE_PATH>/.monitor.lock` - if a run is still in progress when the next 5-minute tick fires (should not normally happen given the command's own short runtime, and now hard-bounded by the `timeout` above regardless), the new invocation exits 0 immediately with a "Skipped: another invocation currently holds the lock" message, logged but never treated as a failure.

**Failure visibility - a cron exit code is not, by itself, an operator notification.** The command's own exit code is non-zero for a genuine monitor-level problem (an exception inside the readiness checks themselves, an invalid/empty result set, a configuration problem while enabled, or a real delivery failure while alerting is enabled) - but a non-zero cron exit only reaches an operator if something is actually watching `monitor-run.log` or cron's own mail. **If the failure is in the monitor's own delivery path itself (the exact scenario most likely to also mean "the alert email for a REAL incident didn't go out either"), this MVP has no separate, independent channel to escalate through** - this is the same structural limit described below under "what this MVP detects." Treat a periodic, manual (or eventually automated) glance at `monitor-run.log`'s exit-code history as part of operating this feature, not an optional nicety.

**Log retention**: `/opt/estore/monitor-run.log` grows once every 5 minutes forever, exactly the same unbounded-growth shape section R's own sibling job logs have - add it to the existing `docker-disk-hygiene`-adjacent logrotate configuration this project already uses for `docker-hygiene.log` (see section R's "Docker disk hygiene" above), rather than leaving it to grow indefinitely:

```
# /etc/logrotate.d/platform-monitor - NOT YET INSTALLED, add at activation time
/opt/estore/monitor-run.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```

### State location

`<MONITOR_STATE_PATH>/state.json` - one JSON object, `checks` (per-check status/onset-time/last-successfully-notified status+timestamp) and `meta` (last run time, last monitor-level error if any, last delivery-skip reason if any). Contains no secrets, no tenant/customer data, no stack traces - only the same secret-safe check labels/statuses/detail text `platform:production:check`'s own console table has always shown, plus timestamps. Survives container recreation (the same bind-mounted volume `BACKUP_ROOT` already uses).

### Delivery failure handling

A failed send (unreachable relay, bad credentials, missing recipient) never marks an incident as notified - the exact same batch is recomputed and retried on the next scheduled run, with no in-process retry/backoff loop (see `ProductionMonitorRunner`'s own docblock for why: a single bounded attempt per invocation keeps a cron-triggered run short and simple; the next 5-minute tick **is** the retry).

### Safe verification (before real activation)

**A note on `--dry-run`'s own real, disclosed side effect (TASK-OPS-MONITORING-001A finding 5)**: `--dry-run` never writes `state.json` and never sends mail, but it DOES still acquire the overlap lock, which means it creates `<MONITOR_STATE_PATH>/` and `.monitor.lock` if they do not already exist. This is deliberate, not a bug to silently work around - a preview run must not be able to race a real run (or another preview run) for the lock, so it participates in the SAME locking exactly like a real run does. Documented here accurately rather than claimed away.

**A note on `.env` changes and `env_file` (TASK-OPS-MONITORING-001A finding 5)**: `docker-compose.production.yml`'s `app` service uses `env_file: - .env` - environment variables are injected into the container ONLY at container creation, not re-read while it keeps running. Editing `.env` on the host and running `php artisan config:clear` INSIDE the already-running container does **not** pick up a changed `MONITOR_ALERT_ENABLED`/`MONITOR_ALERT_RECIPIENT` value - `config:clear` only clears Laravel's own cached config files; it has no effect on the container's OS-level environment, which is fixed for that container's lifetime. Step 4 below requires actually recreating the `app` container (the same host-source-first `docker/production/deploy.sh` sequence section P already documents, since a plain `.env`-only change still needs the container recreated) - never merely `config:clear`.

```bash
# 1. Dry run - computes and prints the full decision, sends no mail,
#    writes no state.json (still creates the state directory/lock file -
#    see the note above). Safe to run against real production at any
#    time, including before MONITOR_ALERT_ENABLED is ever set.
php artisan platform:production:monitor --dry-run

# 2. A real state-tracking run with alerting still disabled - persists
#    state (so the diffing/reminder logic can be observed building up
#    over a few real runs) but still sends no mail.
php artisan platform:production:monitor

# 3. Inspect the resulting state file directly - confirm it looks correct
#    (real check labels, no secrets, no tenant data) before enabling mail.
cat <MONITOR_STATE_PATH>/state.json

# 4. Set MONITOR_ALERT_ENABLED=true and MONITOR_ALERT_RECIPIENT in the
#    real production .env, then RECREATE the app container via the
#    supported deploy sequence (section P) - a bare `config:clear` inside
#    the already-running container does NOT pick up the new values
#    (see the env_file note above).
docker/production/deploy.sh <current-commit-sha>

# 5. Send exactly one clearly-labeled test email to the now-configured
#    recipient, through the real central SMTP path, WITHOUT running any
#    readiness check and WITHOUT touching real incident/reminder/recovery
#    state at all - the safe, explicit, deliberately-invoked path this
#    task added specifically so a real incident never has to be induced
#    just to prove delivery works.
php artisan platform:production:monitor --test-notification
# Confirm the operator inbox actually received it before proceeding.

# 6. Only once step 5 is confirmed: install the cron entry above.
```

### Disable / rollback

- **The only real disable mechanism is `MONITOR_ALERT_ENABLED=false`** (in `.env`, followed by the same container-recreation step as any other `.env` change - see the `env_file` note above). **Blanking `MONITOR_ALERT_RECIPIENT` while leaving `MONITOR_ALERT_ENABLED=true` is NOT an alternative way to disable this feature** (TASK-OPS-MONITORING-001A finding 5/2) - as of the fix in finding 2, that combination is an explicit, visible, non-zero-exit-code CONFIGURATION ERROR on every run, not a quiet no-op. To genuinely go quiet, set `MONITOR_ALERT_ENABLED=false`; the command then keeps running (if still scheduled) and keeps tracking state, but sends nothing and reports no configuration problem.
- **Stop the command from running at all**: remove the cron line above (`crontab -e`, delete the one line) - nothing else in this project depends on it.
- **Before deleting or resetting monitoring state, stop first, confirm nothing is running, only then delete** - deletion is **not** safe "at any time" while the cron entry is still active:
  1. Remove the cron line (above) - or otherwise ensure no future scheduled invocation will fire during this procedure.
  2. Confirm no invocation currently holds the lock - a real, live check, not an assumption:
     ```bash
     docker compose -f docker-compose.production.yml exec -T <EXEC_USER> app \
       sh -c 'exec 9>/backups/monitoring/.monitor.lock; flock -n 9 && echo "lock is free" || echo "STILL HELD - do not delete yet"'
     ```
     (adjust the path if `MONITOR_STATE_PATH` was overridden). If it reports "STILL HELD," wait and re-check rather than proceeding.
  3. Only then: `rm -rf <MONITOR_STATE_PATH>` is safe - the next run starts from a clean baseline (a genuinely first-ever run: any current WARN/FAIL is treated as brand-new, never a false "recovered" notice for anything, matching the same rule a real first-ever run already follows).
- This feature makes **no change** to `platform:production:check` itself beyond the internal `collectResults()` refactor (console output, check semantics, and the deploy-gating exit code are all unchanged and regression-tested) - disabling or removing the monitor never affects deploys, backups, or any other existing operational mechanism.

### What this MVP detects, and what it structurally cannot

An application-based monitor running on the same host, through the same application bootstrap, using the same central SMTP path it is monitoring, **cannot guarantee notification of every possible failure** - some failure modes take the monitor itself down along with whatever it was supposed to report on.

**Detected**: any condition `platform:production:check`'s own 19 checks already cover (misconfiguration, stale/missing backups, broken static assets, Redis unreachable, etc.), plus the monitoring mechanism's own health (the 20th "Production monitor" pseudo-check, an invalid/empty/duplicate result set, and an enabled-but-misconfigured recipient) - while the `app` container and its cron are both still running, the external `timeout` wrapper is in place, and the process can reach the configured SMTP relay within its bound - including the relay being slow/flaky (bounded and terminated by `timeout`, retried on the next tick) and the checks themselves throwing an unexpected exception (treated as a monitor-level FAIL, still alerted, per `ProductionMonitorRunner`'s own exception handling).

**NOT detected / structurally out of reach for this mechanism**:
- The `app` container itself crashed, was never started, or the whole host is down - there is no process left to run `platform:production:monitor` at all.
- Cron itself is broken/stopped on the host.
- The configured SMTP relay is down or unreachable **for a sustained period** - each run is bounded and retries, but only every 5 minutes via the same host; a relay outage that also correlates with whatever caused the underlying incident could delay notification indefinitely.
- Anything before this mechanism could plausibly run at all (e.g. the host's disk is completely full, docker itself cannot exec into the container).
- **A cron-level failure of the monitor itself has no independent escalation path in this MVP** (see "Failure visibility" above) - if the monitor's own delivery mechanism is what's broken, its own non-zero exit code has no separate notification channel either; discovering it still depends on an operator (or a future scheduled check) actually looking at `monitor-run.log`.

**A genuine, independent uptime/heartbeat monitor - a third party or separate infrastructure periodically confirming this host/application is alive from OUTSIDE it - is the only way to close these gaps, and is explicitly out of scope for this task** (task instruction: do not introduce an external monitoring service without approval). This MVP is a real, meaningful improvement over the current "nothing" (see `docs/project-state/CURRENT.md` §12's own "pulled, never pushed" finding) - it is not, and does not claim to be, full outage coverage.
