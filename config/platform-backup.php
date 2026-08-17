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
];
