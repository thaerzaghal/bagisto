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

**Known limitation, stated plainly**: `/opt/estore/backups/` is still on the
SAME physical server/disk as the live application and database. This is a
real, deliberate first layer, not a claim of true disaster-recovery
readiness - if this server's disk fails, the backups fail with it. Copying/
syncing this directory to an off-server destination (S3, another host, etc.)
is the natural next step and was explicitly kept out of this task's scope
(task section 21) - the directory structure here is deliberately simple
(plain timestamped subdirectories) specifically so a future `rsync`/`aws s3
sync ... /opt/estore/backups/` needs no changes to this implementation at
all to start working.

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

- **Same-server backup destination** (see "Destination" above) - the single
  biggest real gap versus true disaster-recovery readiness. Off-server sync
  is the natural next step, deliberately not built here.
- **No backup encryption at rest** - `/opt/estore/backups/` relies entirely
  on filesystem permissions (`750`/`0640`), not encryption. Acceptable for a
  1-5 merchant pilot on a single trusted server; revisit before scaling.
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
