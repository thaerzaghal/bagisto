<?php

declare(strict_types=1);

namespace Platform\Backup\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\Backup\Services\BackupPathGuard;
use Platform\Backup\Services\BackupRetention;

/**
 * TASK-MVP-003A (task section 10). Deletes FINALIZED daily backups older
 * than `config('platform-backup.retention_days')`. Selection logic itself
 * lives in `Platform\Backup\Services\BackupRetention` (unit-tested in
 * isolation, see `tests/Feature/Platform/BackupRetentionTest.php`) - this
 * command's only real-world addition is the actual deletion, and the
 * `BackupPathGuard::assertInsideRoot()` safety check immediately before
 * every single one.
 *
 * ONLY considers directories matching the exact finalized-backup name
 * shape (`Y-m-d_His`, no suffix) - a stale `.in-progress`/`.failed`
 * directory from an interrupted/failed run is deliberately left alone
 * here, not auto-deleted (a human should be able to inspect a failed run;
 * auto-deleting it on the very next cleanup pass would defeat that). If
 * one accumulates for a long time, that is itself worth a human looking at
 * - not something this command silently cleans up.
 */
class CleanupBackups extends Command
{
    protected $signature = 'platform:backup:cleanup {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Deletes finalized daily backups older than the configured retention window. Never deletes the newest backup or anything outside the configured backup root.';

    public function handle(BackupPathGuard $guard): int
    {
        $dailyRoot = rtrim($guard->root(), '/').'/daily';

        if (! is_dir($dailyRoot)) {
            $this->info('No backups directory yet - nothing to clean up.');

            return self::SUCCESS;
        }

        $entries = array_values(array_filter(
            scandir($dailyRoot) ?: [],
            fn (string $name): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name)
        ));

        $retentionDays = (int) config('platform-backup.retention_days');
        $toDelete = (new BackupRetention($retentionDays))->selectForDeletion($entries, now()->toImmutable());

        if ($toDelete === []) {
            $this->info(count($entries)." backup(s) present, none older than the {$retentionDays}-day retention window (or only one backup exists).");

            return self::SUCCESS;
        }

        foreach ($toDelete as $name) {
            $path = "{$dailyRoot}/{$name}";

            // Never swallow this - a failed containment check must abort
            // the whole command, not silently skip to the next directory.
            $guard->assertInsideRoot($path);

            if ($this->option('dry-run')) {
                $this->line("Would delete: {$path}");

                continue;
            }

            File::deleteDirectory($path);
            $this->line("Deleted: {$path}");
        }

        $retained = count($entries) - count($toDelete);
        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ').count($toDelete)." backup(s), {$retained} retained.");

        return self::SUCCESS;
    }
}
