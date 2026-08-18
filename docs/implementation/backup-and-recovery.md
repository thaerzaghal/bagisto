# Platform Backup & Recovery (TASK-MVP-003A)

Real implementation record - central DB, every tenant DB, every tenant's
persistent files. `docs/architecture/production-deployment.md` section N
recorded the POLICY but deliberately did not implement it ("TASK-MVP-004B
work" - later re-scoped to this dedicated task once the pilot's real backup
requirement became concrete). This document describes what actually exists.

## What gets backed up, and why

| Data | Included? | Mechanism | Why |
|---|---|---|---|
| Central DB (`tenants`, `domains`, `plans`, `plan_prices`, `subscriptions`, `payments`, `billing_provider_events`, `platform_users`, central `sessions`, ...) | Yes | `mysqldump --single-transaction` | The entire SaaS registry - losing it loses the ability to even know which tenants exist. |
| Every tenant DB (`products`, `orders`, `customers`, `admins`, tenant `sessions`, ...) | Yes | `mysqldump --single-transaction`, per tenant, using **that tenant's own already-generated scoped MySQL credentials** (`Tenant::database()->getUsername()/getPassword()`) | This is the actual merchant commerce data - the entire reason a pilot merchant trusts this platform with their store. Using the tenant's own credentials (rather than a new elevated "backup" user) needed zero new grants and inherently respects the per-tenant privilege boundary already built (TASK-ARCH-002/R18). |
| Tenant persistent files (`storage/tenant{id}/app/...` - `local`+`public` disks; `storage/app/private/tenant{id}/...` - `private` disk) | Yes | `tar -czf`, two archives per tenant | Product images, CMS/media uploads, `Webkul\DataTransfer` import/export files, downloadable link sources - real merchant assets with no other copy. |
| Tenant framework cache/sessions/views/logs (`storage/tenant{id}/framework/*`, `storage/tenant{id}/logs`) | **No** | - | Fully ephemeral/regenerable - `sessions` here is the FILE cache leftover from `FilesystemTenancyBootstrapper`'s suffixed `storage_path()`, not the real session store (`SESSION_DRIVER=database` - see below). Backing these up would only bloat every archive with churn. |
| Redis (cache + `sync` queue's transient state) | **No** | - | See "Redis decision" below. |
| Application code/`vendor/`/`node_modules`/Docker image layers | **No** | - | Fully recoverable from source control + a fresh `composer install`/image build - see the disaster-recovery runbook. |
| `.env` / deployment secrets | **No**, not inside the normal backup archive | Preserved separately - see "Secrets" below | Backups land in `/opt/estore/backups/`, a directory this document does not claim is encrypted at rest (see "Known limitations") - a plaintext DB password sitting next to plaintext DB dumps is an unnecessary combined blast radius. |

### Redis decision

**Redis is deliberately NOT backed up.** Its only two real uses in this
project (`docs/architecture/production-deployment.md` sections G/I) are the
tenant-tagged `Cache::` store (`CacheTenancyBootstrapper`) and, only if a
future task ever switches off `QUEUE_CONNECTION=sync`, an async queue - both
are, by this project's own design, disposable/rebuildable: a cold cache
simply gets repopulated by the next request that needs each key; a queue
connection currently isn't even in use (`sync` needs no broker at all this
pilot). Nothing in `Platform\*`/`Webkul\*` treats Redis as a system of
record - every value that matters long-term already lives in MySQL. If this
project later adopts Redis-backed sessions or an async queue with
in-flight-job durability requirements, this decision should be revisited
then, not preemptively.

## Architecture

```
platform:backup:run
   |
   v
<BACKUP_ROOT>/daily/<timestamp>.in-progress/
   |-- central.sql.gz
   |-- tenants/<tenant-id>.sql.gz              (one per tenant)
   |-- tenant-files/<tenant-id>-app.tar.gz     (one per tenant, if any files exist)
   |-- tenant-files/<tenant-id>-private.tar.gz (one per tenant, if any files exist)
   |-- manifest.json
   |
   v  (only after EVERY step above succeeds)
<BACKUP_ROOT>/daily/<timestamp>/                <- finalized, the only shape
                                                    cleanup/health-check ever
                                                    recognize as a success
```

`Platform\Backup\Services\BackupRunner::run()` is the whole implementation;
`platform:backup:run` (`Platform\Backup\Console\Commands\RunBackup`) is a
thin CLI wrapper around it. See that class's own docblock for the exact
credential-safety and shell-pipe-failure-propagation mechanics
(`--defaults-extra-file`, `bash -c 'set -o pipefail; ...'` - **verified
empirically**, not assumed: a deliberately-failing mysqldump piped into a
succeeding gzip under plain `/bin/sh` [dash, this image's default] reports
exit code 0 - a silent false success; the identical command under `bash -c
'set -o pipefail; ...'` correctly reports the real failure).

### Tenant discovery

The authoritative source is the central `tenants` table (`Tenant::query()`),
never a hand-maintained list - `Deleted` tenants are skipped outright (their
physical database is already dropped). Every other status is **attempted**,
not assumed reachable: `BackupRunner::tenantDatabaseAccessible()` does a
real, cheap connection check using the tenant's own generated credentials
before attempting any dump, so a Pending/Provisioning/Failed tenant with no
physical database yet (or a stale fixture row with no accessible database at
all - common in this project's own dev/test databases) is recorded in
`skipped_tenants`, never a hard failure of the whole run.

**Emergency fallback**, if the central registry itself is ever unavailable
during a real disaster recovery: every physical tenant database can still be
enumerated directly against MySQL, e.g.:

```sql
SHOW DATABASES LIKE 'tenant%';
```

(matching `config('tenancy.database.prefix')` - `'tenant'` in this project),
run as a MySQL user with broad enough privilege to see them (the central app
user cannot - see "Why the central app user can't just run SHOW DATABASES"
below). Each tenant's own dedicated MySQL user/credentials are NOT
recoverable this way if the central `tenants` row (which stores them in its
`data` JSON column) is also lost - in that specific worst case, restoring
the CENTRAL database dump first (which is a separate, complete backup with
those same credentials preserved in it) reconstitutes them.

### Why the central app user can't just run `SHOW DATABASES`

`docs/architecture/production-deployment.md` section F is explicit: the
central app user (`DB_USERNAME`) is scoped to the central database **only**.
`SHOW DATABASES` only ever lists databases the connected user has some
privilege on - so running it as the central app user would show nothing
useful about tenant databases regardless of whether they exist. This is why
`tenantDatabaseAccessible()` connects using each tenant's OWN credentials
instead: it proves both "does the database exist" and "can it actually be
dumped with these exact credentials" in a single, cheap step, using
privileges this project already deliberately grants per tenant.

## Backup script/command

```bash
php artisan platform:backup:run
php artisan platform:backup:cleanup [--dry-run]
```

Both are Platform-owned artisan commands (`packages/Platform/Backup`) - no
new deployment framework, no external backup tool.

## Destination

`config('platform-backup.root')` (`BACKUP_ROOT` env var). Production:
`/backups`, bind-mounted from `../backups` relative to
`docker-compose.production.yml` - lands on `/opt/estore/backups/` on the
host, a **sibling** of `/opt/estore/app/` and `/opt/estore/mysql-data/`,
never nested inside either (INCIDENT-001's own lesson applied deliberately -
a backup destination inside the same mutable tree it protects is not a real
backup).

**Formerly a known limitation, now addressed (TASK-MVP-005)**: `/opt/estore/backups/`
is still on the SAME physical server/disk as the live application and
database - the LOCAL backup destination was always meant to be a first
layer, not full disaster-recovery readiness on its own. `platform:backup:sync-offsite`
(below) copies every already-finalized local backup to an independent,
off-server destination, closing this gap without changing anything about
the local backup itself.

## Offsite sync (TASK-MVP-005)

**Local backups remain the first restore source.** The offsite copy exists
purely as disaster-recovery protection against loss of the server/disk the
local backups themselves live on (this server's disk failing, the whole VPS
being lost, etc.) - it is never read from in the normal course of operating
this platform.

### Architecture

```
platform:backup:run                    (unchanged - see "Architecture" above)
   |
   v  (only after local finalization - never re-runs any dump)
platform:backup:sync-offsite [<timestamp>]
   |
   v
Platform\Backup\Services\OffsiteSyncRunner
   |
   v
Platform\Backup\Contracts\OffsiteBackupDestination   <- the one seam
   |
   v
Platform\Backup\Services\S3CompatibleOffsiteDestination
   |  (Laravel's own Storage::build(['driver' => 's3', ...]) -
   |   league/flysystem-aws-s3-v3, no custom HTTP/SigV4 code)
   v
<BACKUP_OFFSITE_PREFIX>/daily/<timestamp>/
   |-- central.sql.gz
   |-- tenants/<tenant-id>.sql.gz
   |-- tenant-files/<tenant-id>-app.tar.gz / -private.tar.gz
   |-- manifest.json
```

`OffsiteBackupDestination` is a small, provider-neutral contract
(`upload`/`download`/`exists`/`size`/`delete`/`listKeysWithPrefix`) -
deliberately not an S3 SDK wrapper. **Cloudflare R2 is this project's
CURRENT choice of S3-compatible provider, not an architectural
commitment**: R2/AWS S3/Wasabi/Backblaze B2's S3-compatible endpoint/MinIO
all speak the same S3 API, so `S3CompatibleOffsiteDestination` already
covers every one of them - switching providers later is a `.env` change
(`BACKUP_OFFSITE_ENDPOINT`/`BACKUP_OFFSITE_REGION`/credentials), never a
code change. A genuinely different kind of destination (e.g. rsync to a
second physical host) would only need a second class implementing the same
contract; nothing in `OffsiteSyncRunner`/the console commands would change.

### Commands

```bash
php artisan platform:backup:sync-offsite [<timestamp>]
php artisan platform:backup:cleanup-offsite [--dry-run]
```

`sync-offsite` defaults to the newest finalized local backup when no
timestamp is given (the normal cron usage); a specific timestamp can be
passed to retry a particular backup's offsite sync independently.
**Deliberately two commands, not one automatic step tacked onto
`platform:backup:run`** (task section 10) - a finalized local backup is the
ONLY input `OffsiteSyncRunner` ever reads, and it never re-runs any
mysqldump/tar, so offsite sync can be retried as many times as needed
(e.g. after a transient network failure) without touching the local backup
at all.

### Configuration

`config('platform-backup.offsite.*')` / `.env`:

| Key | Purpose |
|---|---|
| `BACKUP_OFFSITE_ENABLED` | `false` by default - `sync-offsite` reports DISABLED and exits successfully (not an error) until this is `true`. |
| `BACKUP_OFFSITE_DRIVER` | `s3` (generic - see above). |
| `BACKUP_OFFSITE_BUCKET` / `_ENDPOINT` / `_REGION` / `_ACCESS_KEY` / `_SECRET_KEY` | The real R2 (or other S3-compatible provider) credentials - **never committed**, production sets these directly in its own untracked `.env` only. |
| `BACKUP_OFFSITE_PREFIX` | Object key prefix every offsite backup is written under (default `estore-backups`) - lets a bucket be safely shared with unrelated content; also the safety boundary `OffsiteKeyGuard` enforces before any remote delete. |
| `BACKUP_OFFSITE_USE_PATH_STYLE` | `true` by default - R2 (and most non-AWS S3-compatible providers) require path-style bucket addressing. |
| `BACKUP_OFFSITE_RETENTION_DAYS` | `30` by default - deliberately longer than local (`BACKUP_RETENTION_DAYS`, `7`); storage cost is not a practical constraint at this project's current backup size (well under 1 MB total). |

### Idempotency

The remote path is fully deterministic from the local backup's own
timestamp name (`<prefix>/daily/<timestamp>/...`). Before uploading each
file, `OffsiteSyncRunner` checks whether an object of the same name and
size already exists remotely - if so, it is skipped, not re-uploaded.
Running `sync-offsite` twice in a row (or retrying after a partial failure)
never creates a duplicate or inconsistent remote copy.

### Integrity verification

Upload success alone (an API call returning without error) is **not**
treated as proof of a correct remote copy. For every uploaded file,
`OffsiteSyncRunner`:

1. Confirms the remote object's size matches the local file's size.
2. Downloads the object back to a disposable temp file and recomputes its
   SHA-256, comparing it against the LOCAL manifest's own already-proven
   checksum (never a provider's ETag - S3-compatible ETags are not
   guaranteed to be a SHA-256, or even a hash of the plaintext content at
   all for multipart uploads).

This full round-trip is practical because this project's actual backup size
is currently well under 1 MB total (see the task's own final report for the
real numbers) - a genuinely large future backup would need a sampling
strategy instead, not attempted here since it isn't yet needed.

### Failure semantics

**A local backup succeeding and an offsite sync succeeding are two
completely independent outcomes**, reported separately:

- `platform:backup:run` failing does not affect any previous offsite sync.
- `platform:backup:sync-offsite` failing (a network error, an upload
  failure, a checksum mismatch) never touches, corrupts, or "half-deletes"
  the local backup it was syncing - the local `<timestamp>/` directory
  `BackupRunner` already finalized is read-only from `OffsiteSyncRunner`'s
  perspective.
- The result of every sync attempt (success, failure with a reason, or
  disabled) is written to a SIBLING file next to the local backup directory
  - `<BACKUP_ROOT>/daily/<timestamp>.offsite-status.json` - never inside the
    finalized backup directory itself (the same "never mutate a finalized
    backup" discipline `BackupRunner`'s `.failed`/`.in-progress` suffixes
    already establish for local backups).
- `platform:backup:sync-offsite` exits non-zero on failure, non-zero being
  the same cron/systemd-timer failure-detection signal `platform:backup:run`
  already uses - offsite failure is never silently swallowed.

### Remote retention

`platform:backup:cleanup-offsite` reuses the exact same selection algorithm
as local cleanup (`Platform\Backup\Services\BackupRetention` - "delete
anything older than N days, except the single newest, ever") against
`BACKUP_OFFSITE_RETENTION_DAYS` instead of `BACKUP_RETENTION_DAYS` -
deliberately not a more complex grandfather-father-son policy (task section
12), since the simple rule is already the right amount of complexity for
this project's actual scale.

### Delete safety

`Platform\Backup\Services\OffsiteKeyGuard` is the remote-storage equivalent
of `BackupPathGuard` - the ONE place that decides whether a remote object
key is safe to delete, in the exact `<prefix>/daily/<timestamp>/...` shape
this package actually writes. `CleanupOffsiteBackups` asserts every single
key through this guard immediately before deleting it; a failed check
aborts the whole command rather than being silently skipped. The bare
prefix, `<prefix>/daily` with no timestamp, and anything outside the
configured prefix are all rejected - "delete everything under the prefix"
is never a single operation this guard permits. See
`tests/Feature/Platform/OffsiteKeyGuardTest.php` for the full test matrix.

### Production-check integration

`php artisan platform:production:check` includes an **Offsite Backup** row,
read purely from the LOCAL `<timestamp>.offsite-status.json` sibling file
`OffsiteSyncRunner` already writes on every attempt - never a live call to
the offsite provider (task section 14: no expensive provider calls on every
execution). Reports disabled/INFO, WARN (never synced yet, last attempt
failed, or stale - more than 48h since the last success), or PASS with the
newest successful sync's age and object count.

### Security

- Bucket/credentials are configured via `.env` only, never committed, never
  printed/logged by any command in this package.
- The R2 bucket (or equivalent) MUST be private - not publicly browsable,
  no public bucket policy, no signed public URLs ever generated by this
  package (nothing in `Platform\Backup` ever calls a public-URL-generating
  method).
- Backup contents (database dumps, tenant file archives) contain real
  customer/merchant PII, identical to what the local backup already
  contains - see "Security / permissions" above for what's already true of
  the local copy.

### Encryption at rest

**Provider-side encryption at rest is relied upon, not re-implemented
client-side.** Cloudflare R2 encrypts all stored objects at rest by
default, at the storage layer, with no configuration needed - the same is
true of AWS S3 and most other S3-compatible providers. This project does
NOT add its own client-side backup encryption on top of that (task section
19) - evaluated and deliberately deferred: it would add real key-management
complexity (where does the encryption key live, how is it rotated, how is
it itself backed up) for a 1-5 merchant pilot where the local backup
already carries the identical PII exposure and already relies on
provider/filesystem-level protection rather than client-side encryption.
**Revisit before scaling** - this is an explicit, recorded decision (see
DECISION_LOG.md), not an oversight.

### Schedule

Added to the existing root crontab (unchanged Let's Encrypt jobs at
02:31/10:37, unchanged local backup/cleanup at 03:15/03:45):

```cron
# TASK-MVP-005 - Platform offsite backup sync (03:30, after the local backup above finishes) and offsite cleanup (03:50)
30 3 * * * cd /opt/estore/app && /usr/bin/docker compose -f docker-compose.production.yml exec -T app php artisan platform:backup:sync-offsite >> /opt/estore/backups/backup-run.log 2>&1
50 3 * * * cd /opt/estore/app && /usr/bin/docker compose -f docker-compose.production.yml exec -T app php artisan platform:backup:cleanup-offsite >> /opt/estore/backups/backup-run.log 2>&1
```

`sync-offsite` runs 15 minutes after the local backup (03:15) to give it
time to finish; `cleanup-offsite` runs after local cleanup (03:45), at
03:50. Safe to have installed before real R2 credentials exist - both
commands report DISABLED and exit successfully until `BACKUP_OFFSITE_ENABLED=true`
is set.

### Offsite restore procedure

```
1. Download the desired <timestamp> from the offsite destination
   (a disposable local directory - never a live restore target directly)
2. Verify manifest.json / checksums (php artisan platform:backup:sync-offsite
   already proved this for the copy that was uploaded; re-verify after
   download if restoring from a truly independent recovery scenario)
3. Follow the exact same "Disaster recovery runbook" steps below, using the
   downloaded artifacts in place of a locally-copied backup directory -
   nothing else in that runbook changes.
```

### Real verification result (2026-08-18, TASK-MVP-005)

Proven live against the real pilot server and a real Cloudflare R2 bucket -
not just the automated fake-destination test suite:

- A real, scheduler-produced finalized backup (`2026-08-18_031502`) was
  synced to R2: 5 objects, ~753 KB total.
- Independently re-listed directly from R2 (not the sync command's own
  self-report): exactly those 5 objects, all under the configured prefix,
  nothing else.
- Every checksummed object round-trip-verified (download + SHA-256
  recompute against the local manifest) - not an ETag substitute.
- Re-syncing the same backup a second time was idempotent: still exactly 5
  objects, 1 distinct backup, no duplicate/re-upload.
- An unauthenticated GET against a real object in the bucket returned
  `400` (rejected) - the bucket is not publicly readable.
- A full restore drill succeeded: downloaded fresh from R2, checksums
  re-verified again independently, central dump restored into a disposable
  `bagisto_probe_restore_central` database (1 tenant/`pilot-smoke`/`ready`,
  3 plans, 1 subscription), tenant dump restored into a disposable
  `bagisto_probe_restore_tenant` database (138 tables, 1 product, 2 orders,
  1 admin), tenant files extracted with a representative file's checksum
  verified. All disposable targets were cleaned up; the real local and R2
  backups were untouched throughout.
- `php artisan platform:production:check` reports both `Backups` and
  `Offsite Backup` PASS.

See RISK_REGISTER.md R64 (now CLOSED) and the task's own final report for
the complete evidence chain.

### Known limitations (offsite)

- **No automated, unattended remote restore-verification job** - proven
  manually (see the task's own final report for the real R2 sync +
  restore-drill result), matching the exact same limitation already stated
  for local restores above.
- **Single offsite provider/region** - one R2 bucket, not multi-region or
  multi-provider replication. Acceptable for a 1-5 merchant pilot; revisit
  if/when a stronger RPO/RTO requirement emerges.
- **No client-side encryption** - see "Encryption at rest" above.

## Security / permissions

- `/backups` itself: `750`, `www-data:www-data` (prepared on every container
  start by `docker/production/entrypoint.sh`, mirroring the existing
  `storage/` ownership-preparation pattern - deliberately MORE restrictive
  than `storage/`'s `775`/`664`, since this directory holds real customer/
  order DB dumps, not already-public tenant asset files).
- Every dump/archive file `platform:backup:run` writes is explicitly
  `chmod(0640)` immediately after creation (`BackupRunner::mysqldumpToGzip()`/
  `tarDirectory()`) - `gzip`/`tar`'s own default mode (governed by the
  process umask) is not trusted to be restrictive enough on its own.
- No MySQL credential ever appears as a `mysqldump`/`mysql` command-line
  argument (visible to any other user on the host via `ps`) - a per-run,
  mode-`0600` `--defaults-extra-file` temp file carries them instead,
  deleted immediately after each dump in a `finally` block.
- `manifest.json` contains no credentials, tenant DB usernames, or any other
  secret - only names, sizes, SHA-256 checksums, and status strings (see
  `tests/Feature/Platform/BackupManifestTest.php`'s own regression guard
  against a future accidental change feeding it something sensitive).
- Nothing under `/backups` is ever committed to git (matches this
  repository's existing `.gitignore` conventions for anything under a
  runtime-only path).

## Secrets

`.env` and `docker/production/secrets/*` are **not** included in the normal
backup archive - see the "What gets backed up" table above for why (a
plaintext credential file sitting next to plaintext DB dumps in the same
un-encrypted destination is an unnecessary combined blast radius, not a
safety net). Recovering secrets after a real disaster is a **separate,
manual** step - see the disaster-recovery runbook's own explicit callout
below. Whoever provisioned the original `.env`/`docker/production/secrets/*`
values (a human, not this backup system) is the actual recovery path;
`docs/architecture/production-deployment.md` section E documents every value
that needs to be re-supplied.

## Manifest / checksums

Every finalized backup has a `manifest.json` (`Platform\Backup\Services\
BackupManifest`) - example shape (values illustrative):

```json
{
    "started_at": "2026-08-17T23:21:19+05:30",
    "finished_at": "2026-08-17T23:21:37+05:30",
    "app_commit": null,
    "status": "success",
    "central": {
        "database": "bagisto_central",
        "file": "central.sql.gz",
        "size_bytes": 29776,
        "sha256": "..."
    },
    "tenants": [
        {
            "id": "pilot-smoke",
            "status": "ready",
            "database": "tenantpilot-smoke",
            "db_file": "tenants/pilot-smoke.sql.gz",
            "db_size_bytes": 41234,
            "db_sha256": "...",
            "files_app": {"source": "...", "file": "pilot-smoke-app.tar.gz", "size_bytes": 812345, "sha256": "..."},
            "files_private": null
        }
    ],
    "tenant_count": 1,
    "skipped_tenants": [
        {"id": "some-pending-tenant", "status": "pending", "reason": "no accessible physical database (not yet provisioned, or provisioning failed before the database was created)"}
    ]
}
```

`app_commit` is **best-effort** (task section 8, "if practical") - the
production image deliberately excludes `.git` (`.dockerignore`, smaller/
faster builds), so there is no git metadata to read inside the running
container. If a future deploy step writes `base_path('APP_COMMIT')`
(a one-line `git rev-parse HEAD > APP_COMMIT` before the image build), this
field starts populating automatically - no backup-code change needed.

`files_app`/`files_private` are `null` when a tenant genuinely has zero
files under that disk yet (a real, common state for a very new tenant) - not
a failure.

## Failure semantics

Atomic, per task section 9: `BackupRunner::run()` writes into a
`<timestamp>.in-progress/` directory; ANY exception (a failed mysqldump, a
failed tar, a filesystem error) is caught once, at the top level, and
results in `manifest.json` (`status: "failed"`, the error message) being
written and the directory renamed to `<timestamp>.failed/` - never left
looking like a success, never silently deleted either (a human should be
able to inspect what was captured before the failure). `platform:backup:run`
exits non-zero in this case - safe for cron/systemd-timer failure detection
without parsing output.

`platform:backup:cleanup` deliberately never touches `.in-progress`/
`.failed` directories - only exact `Y-m-d_His`-named (finalized-success)
directories are retention-eligible. A long-lived `.failed` directory is
itself worth a human looking at, not something silently swept away on the
next cleanup pass.

## Retention

`config('platform-backup.retention_days')` (`BACKUP_RETENTION_DAYS`, default
`7`) - a conservative starting point chosen for a 1-5 merchant pilot's real
backup size (a few tens of MB/day observed locally - see "Real pilot backup
result" in the task's own final report). Selection logic
(`Platform\Backup\Services\BackupRetention`) is pure, dependency-free, and
unit-tested in isolation (`tests/Feature/Platform/BackupRetentionTest.php`) -
**the single newest backup is never selected for deletion, regardless of its
own age or how `retention_days` is configured** (protects against an
operator setting `0`, a stalled schedule, or a clock-skew edge case ever
leaving zero backups behind). `platform:backup:cleanup`'s own deletion step
additionally re-validates every candidate through `BackupPathGuard::
assertInsideRoot()` immediately before deleting it - a defense-in-depth
check, not the primary selection logic.

## Schedule

Installed as a root crontab entry (chosen over aaPanel's own panel-cron UI,
which stores jobs as opaque hash-named scripts under `/www/server/cron/` -
this task's own instruction explicitly said not to disturb the two existing
Let's Encrypt renewal jobs there, and a plain, readable root crontab line is
simpler to audit than adding a third opaque aaPanel-managed script):

```cron
# TASK-MVP-003A - Platform daily backup (03:15 local server time, low-traffic)
15 3 * * * cd /opt/estore/app && /usr/bin/docker compose -f docker-compose.production.yml exec -T app php artisan platform:backup:run >> /opt/estore/backups/backup-run.log 2>&1
45 3 * * * cd /opt/estore/app && /usr/bin/docker compose -f docker-compose.production.yml exec -T app php artisan platform:backup:cleanup >> /opt/estore/backups/backup-run.log 2>&1
```

- **Schedule**: daily, 03:15 (backup) / 03:45 (cleanup) server-local time -
  chosen as a low-traffic window distinct from the existing 02:31/10:37
  Let's Encrypt renewal jobs.
- **Execution user**: root's crontab (needed to run `docker compose exec`
  against the `app` container - the container's own internal process still
  runs as www-data/php-fpm exactly as every other artisan command in this
  project does; root here only owns the HOST-level cron trigger, not the
  backup logic itself).
- **Output/log location**: `/opt/estore/backups/backup-run.log` (plain
  append-only text, both commands' stdout/stderr) - a human operator's first
  place to look if `platform:production:check`'s own "Backups" row ever
  reports FAIL/WARN.

## Backup monitoring

No monitoring platform - `platform:production:check` now includes a
`Backups` row (`Platform\Tenancy\Console\Commands\ProductionReadinessCheck::
checkBackupHealth()`) that reads the newest finalized backup's own
`manifest.json` directly (plain `json_decode`, deliberately no dependency on
`Platform\Backup`'s own classes - `Platform\Backup` itself depends on
`Platform\Tenancy`'s `Tenant` model, so a reverse dependency would be
circular; this mirrors the exact pattern `checkStripe()`/`checkMail()`
already use for a different package's config) and reports:

- **PASS** - newest successful backup's name, age in hours, tenant count.
- **WARN** - no backup directory / no successful backup ever found.
- **WARN** - newest backup's manifest is missing or not `status: "success"`.
- **WARN** - newest successful backup is more than 48 hours old (STALE - a
  daily schedule should never be this far behind; 48h rather than 24h gives
  one missed run's worth of slack before warning).

Deliberately only ever WARNs, never FAILs the command's own exit code - a
missing/stale backup is a real operational concern worth surfacing, but
should not block an otherwise-legitimate deploy/emergency-fix workflow
(`platform:production:check`'s own established philosophy - see that
command's class docblock).

## Restore verification (proven, not assumed)

Proven twice: once locally (disposable `bagisto_probe_`-prefixed databases -
the exact disposable-naming convention `Platform\Tenancy\Services\
CentralDatabaseWipeGuard` already recognizes as safe, INCIDENT-001's own
lesson), then for real against the actual pilot server's own real backup -
see the task's own final report for the real production numbers (row counts,
sizes, exit codes). Every restore test used a disposable target and cleaned
it up afterward - **never** restored over the real live central or tenant
database.

**Credentials needed for A/B below**: creating/dropping an arbitrary
`bagisto_probe_*` database needs real `CREATE`/`DROP DATABASE` privilege
that is NOT scoped to an existing database name - confirmed live, on the
real pilot server, that `tenant_provisioning`'s own credentials (`GRANT ...
ON \`tenant%\`.*`, per `docs/architecture/production-deployment.md` section
F) are **not** sufficient for this (they can only create/drop databases
matching the `tenant` prefix, by design - a probe/restore-drill database
deliberately does not match it). Use real elevated MySQL credentials for
A/B specifically (e.g. the root credentials `docker-compose.production.yml`
already provisions via `MYSQL_ROOT_PASSWORD_FILE`) - never printed, read
directly from `docker/production/secrets/mysql_root_password.txt`
server-side into a mode-`0600` `--defaults-extra-file`, the same pattern
`BackupRunner::mysqldumpToGzip()` itself uses. C needs no database
credentials at all.

### A. Central restore

```bash
mysql --defaults-extra-file=<0600 option file, root creds> -e "CREATE DATABASE bagisto_probe_restore_central"
gunzip -c central.sql.gz | mysql --defaults-extra-file=<same> bagisto_probe_restore_central
# verify: SELECT COUNT(*) FROM tenants/plans/subscriptions/plan_prices;
mysql --defaults-extra-file=<same> -e "DROP DATABASE bagisto_probe_restore_central"
```

### B. Tenant restore

```bash
mysql --defaults-extra-file=<0600 option file, root creds> -e "CREATE DATABASE bagisto_probe_restore_tenant"
gunzip -c tenants/<tenant-id>.sql.gz | mysql --defaults-extra-file=<same> bagisto_probe_restore_tenant
# verify: SELECT COUNT(*) FROM products/orders/admins; table count sane (~137+ tables)
mysql --defaults-extra-file=<same> -e "DROP DATABASE bagisto_probe_restore_tenant"
```

### C. File restore

```bash
mkdir /tmp/restore-proof && tar -xzf tenant-files/<tenant-id>-app.tar.gz -C /tmp/restore-proof
# verify: representative files present, checksums match manifest.json
rm -rf /tmp/restore-proof
```

`bagisto_probe_*`/`bagisto_test_*`/`bagisto_ci_*` are the ONLY database name
prefixes `CentralDatabaseWipeGuard` (INCIDENT-001) treats as genuinely
disposable - any restore-verification database MUST use one of these
prefixes, never a name that could collide with (or be confused for) the real
`bagisto_central` or a real `tenant{id}` database.

## Disaster recovery runbook

```
1. Provision a new server/MySQL host (or repair the existing one)
2. Copy the LATEST finalized backup directory
   (<BACKUP_ROOT>/daily/<newest timestamp>/) to the new host
3. RESTORE SECRETS SEPARATELY, MANUALLY (never part of the normal backup
   archive - see "Secrets" above):
     - Re-supply .env (docs/architecture/production-deployment.md section E
       lists every required value)
     - Re-supply docker/production/secrets/mysql_root_password.txt
4. Deploy the matching application version
     - if manifest.json's app_commit is populated, deploy exactly that
       commit; otherwise deploy the most recent known-good release
5. Start MySQL/Redis; restore the central DB:
     gunzip -c central.sql.gz | mysql bagisto_central
6. Restore EVERY tenant DB (loop tenants/*.sql.gz):
     for each tenants/<id>.sql.gz:
         mysql -e "CREATE DATABASE IF NOT EXISTS tenant<id>"
         gunzip -c tenants/<id>.sql.gz | mysql tenant<id>
     (tenant DB users/passwords are already present in the restored
     central DB's tenants.data JSON column - Stancl\Tenancy\Database\
     Concerns\HasDatabase reads them from there; no separate credential
     restore step is needed as long as the central DB was restored first)
7. Restore every tenant's persistent files:
     for each tenant-files/<id>-app.tar.gz / <id>-private.tar.gz:
         tar -xzf ... -C storage/tenant<id>/app        (app archive)
         tar -xzf ... -C storage/app/private/tenant<id> (private archive)
8. php artisan platform:production:check
     - confirm APP_DEBUG/CACHE_STORE/SESSION_DRIVER/DB_PROVISION_USERNAME/
       Redis/Backups all report as expected for THIS restored environment
9. Verify a real tenant domain/storefront/Admin login end-to-end
   (the same real-HTTP verification style already used throughout this
   engagement's own pilot smoke test - not just "the command exited 0")
```

Deliberately a **human-followed** sequence, not automated end-to-end
disaster recovery (task section 21, explicitly out of scope) - each step
above is a single, previously-proven-safe command this document already
lists elsewhere, not new tooling.

## Known limitations

- **Same-server LOCAL backup destination remains true and is by design** -
  `/opt/estore/backups/` is still the first, primary restore source (see
  "Destination" above); it was never meant to stop being local. The
  disaster-recovery gap this used to represent is now addressed by offsite
  sync - see "Offsite sync" above and its own "Known limitations (offsite)".
- **No backup encryption at rest for the LOCAL copy** - `/opt/estore/backups/`
  relies entirely on filesystem permissions (`750`/`0640`), not encryption.
  Acceptable for a 1-5 merchant pilot on a single trusted server; revisit
  before scaling. The offsite copy relies on the provider's own storage-layer
  encryption at rest instead - see "Encryption at rest" above.
- **No automated restore-verification job** - restores were proven manually,
  twice (local + real pilot server), not wired into a recurring, unattended
  "restore and diff" check. Task section 20 explicitly recommends "periodic
  (e.g. monthly) real restore-verification" as a policy; this task proves
  the mechanism works, it does not automate re-proving it on a schedule.
- **`app_commit` is best-effort/often null** - see "Manifest / checksums"
  above.
- **A tenant mid-provisioning at the exact moment a backup runs** gets a
  `--single-transaction` consistent snapshot of whatever exists at that
  instant (or is gracefully skipped if no database exists yet at all) - not
  a special case this system detects/warns about beyond the ordinary
  `skipped_tenants` list.
