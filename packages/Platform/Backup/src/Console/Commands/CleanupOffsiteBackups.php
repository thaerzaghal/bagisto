<?php

declare(strict_types=1);

namespace Platform\Backup\Console\Commands;

use Illuminate\Console\Command;
use Platform\Backup\Contracts\OffsiteBackupDestination;
use Platform\Backup\Services\BackupRetention;
use Platform\Backup\Services\OffsiteKeyGuard;

/**
 * TASK-MVP-005. Deletes offsite backups older than
 * `config('platform-backup.offsite.retention_days')` - the remote
 * equivalent of `platform:backup:cleanup`, deliberately reusing the exact
 * same `Platform\Backup\Services\BackupRetention` selection logic (task
 * section 12: "Do not create complex grandfather-father-son retention" -
 * the local retention rule, "delete anything older than N days except the
 * single newest", is already the right amount of complexity for an MVP
 * remote policy too, just with its own, typically longer, retention
 * window).
 *
 * SAFETY (task section 13, "mirror BackupPathGuard"): every single remote
 * key this command is about to delete is checked through
 * `OffsiteKeyGuard::assertSafeBackupKey()` immediately beforehand - a
 * failed check aborts the whole command rather than being silently skipped,
 * matching `CleanupBackups`'s own established discipline for its local
 * `BackupPathGuard` check.
 */
class CleanupOffsiteBackups extends Command
{
    protected $signature = 'platform:backup:cleanup-offsite {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Deletes offsite backups older than the configured offsite retention window. Never deletes the newest offsite backup or anything outside the configured offsite prefix.';

    public function handle(OffsiteBackupDestination $destination): int
    {
        if (! config('platform-backup.offsite.enabled')) {
            $this->info('Offsite backup is disabled - nothing to clean up.');

            return self::SUCCESS;
        }

        $guard = OffsiteKeyGuard::fromConfig();
        $prefix = trim((string) config('platform-backup.offsite.prefix'), '/');

        $keys = $destination->listKeysWithPrefix("{$prefix}/daily/");

        $timestamps = [];
        foreach ($keys as $key) {
            $timestamp = $guard->timestampFromKey($key);

            if ($timestamp !== null) {
                $timestamps[$timestamp] = true;
            }
        }

        $names = array_keys($timestamps);

        if ($names === []) {
            $this->info('No offsite backups found - nothing to clean up.');

            return self::SUCCESS;
        }

        $retentionDays = (int) config('platform-backup.offsite.retention_days');
        $toDelete = (new BackupRetention($retentionDays))->selectForDeletion($names, now()->toImmutable());

        if ($toDelete === []) {
            $this->info(count($names)." offsite backup(s) present, none older than the {$retentionDays}-day retention window (or only one exists).");

            return self::SUCCESS;
        }

        foreach ($toDelete as $timestamp) {
            $keysForTimestamp = array_filter(
                $keys,
                fn (string $key): bool => str_starts_with($key, "{$prefix}/daily/{$timestamp}/") || $key === "{$prefix}/daily/{$timestamp}"
            );

            foreach ($keysForTimestamp as $key) {
                // Never swallow this - a failed containment/shape check
                // must abort the whole command, not silently skip to the
                // next object.
                $guard->assertSafeBackupKey($key);

                if ($this->option('dry-run')) {
                    $this->line("Would delete: {$key}");

                    continue;
                }

                $destination->delete($key);
                $this->line("Deleted: {$key}");
            }
        }

        $retained = count($names) - count($toDelete);
        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ').count($toDelete)." offsite backup(s), {$retained} retained.");

        return self::SUCCESS;
    }
}
