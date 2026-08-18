<?php

declare(strict_types=1);

/**
 * TASK-MVP-003A. Configuration for `Platform\Backup` (`platform:backup:run`/
 * `platform:backup:cleanup`) - central DB, every tenant DB, and every
 * tenant's persistent storage, per docs/implementation/backup-and-recovery.md.
 *
 * `root` is deliberately a path OUTSIDE `storage_path()` (default: a
 * `backups/` sibling of `storage_path()`, i.e. NOT inside the tenant-scoped/
 * tenant-suffixed storage tree at all) - INCIDENT-001's own lesson applies
 * here just as much as to the live database: a backup destination nested
 * inside the same mutable tree it is meant to protect against is not a real
 * backup. Production sets `BACKUP_ROOT=/backups`, a dedicated bind mount
 * (`../backups:/backups`, docker-compose.production.yml) landing on
 * `/opt/estore/backups/` on the HOST - a sibling of `/opt/estore/app/` and
 * `/opt/estore/mysql-data/`, never nested inside either.
 */
return [
    'root' => env('BACKUP_ROOT', dirname(storage_path()).'/backups'),

    /**
     * Daily backups older than this many days are deleted by
     * `platform:backup:cleanup` - see that command's own docblock for the
     * exact, deliberately conservative safety rules (never deletes the
     * newest backup, never deletes anything outside `root`).
     */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

    /**
     * Real binary names, not full paths - resolved via the shell's own
     * PATH at execution time (both are installed system packages in the
     * production image - see Dockerfile.production - not vendored).
     * Overridable purely so a local/CI environment with differently-named
     * binaries (e.g. a `mariadb-dump` alias) can still run the backup
     * command manually without editing this file.
     */
    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BIN', 'mysqldump'),
    'tar_binary' => env('BACKUP_TAR_BIN', 'tar'),

    /**
     * TASK-MVP-005. Off-server sync of already-finalized local backups (see
     * `Platform\Backup\Services\OffsiteSyncRunner`) - protects against the
     * one gap plain local backups can never close: loss of the server/disk
     * these backups themselves live on. `driver` is deliberately generic
     * ("s3", not "r2") - Cloudflare R2 is this project's CURRENT choice of
     * S3-compatible provider (see docs/implementation/backup-and-recovery.md
     * "Offsite sync"), not an architectural commitment; AWS S3/Wasabi/
     * Backblaze B2's S3-compatible endpoint/MinIO all work unchanged through
     * the same `Platform\Backup\Services\S3CompatibleOffsiteDestination` by
     * only changing `endpoint`/`region`/credentials below.
     *
     * Disabled by default (`BACKUP_OFFSITE_ENABLED` unset/false) - a pilot
     * with no offsite destination configured yet must never have
     * `platform:backup:sync-offsite` fail loudly; it reports DISABLED and
     * exits successfully instead (see that command's own docblock).
     */
    'offsite' => [
        'enabled' => (bool) env('BACKUP_OFFSITE_ENABLED', false),
        'driver' => env('BACKUP_OFFSITE_DRIVER', 's3'),
        'bucket' => env('BACKUP_OFFSITE_BUCKET', ''),
        'endpoint' => env('BACKUP_OFFSITE_ENDPOINT', ''),
        'region' => env('BACKUP_OFFSITE_REGION', 'auto'),
        'access_key' => env('BACKUP_OFFSITE_ACCESS_KEY', ''),
        'secret_key' => env('BACKUP_OFFSITE_SECRET_KEY', ''),

        /**
         * Object key prefix every offsite backup is written under
         * (`<prefix>/daily/<timestamp>/...`) - lets a single bucket be
         * safely shared with other, unrelated content without this
         * package's own cleanup command ever touching it (see
         * `Platform\Backup\Services\OffsiteKeyGuard`).
         */
        'prefix' => env('BACKUP_OFFSITE_PREFIX', 'estore-backups'),

        /**
         * R2 (and most non-AWS S3-compatible providers) require path-style
         * bucket addressing (`https://endpoint/bucket/key`, not
         * `https://bucket.endpoint/key`) - true by default for that reason;
         * a real AWS S3 destination would typically set this to false.
         */
        'use_path_style' => (bool) env('BACKUP_OFFSITE_USE_PATH_STYLE', true),

        /**
         * Offsite retention is deliberately independent of, and normally
         * LONGER than, local `retention_days` above (task section 12) -
         * storage cost is not a practical constraint at this project's
         * current backup size (well under 1 MB - see the task's own final
         * report), so a more generous remote window costs effectively
         * nothing while giving more recovery options after a real disaster.
         */
        'retention_days' => (int) env('BACKUP_OFFSITE_RETENTION_DAYS', 30),
    ],
];
