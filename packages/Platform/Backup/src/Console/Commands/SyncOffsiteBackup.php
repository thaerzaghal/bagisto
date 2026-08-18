<?php

declare(strict_types=1);

namespace Platform\Backup\Console\Commands;

use Illuminate\Console\Command;
use Platform\Backup\Exceptions\OffsiteSyncFailedException;
use Platform\Backup\Services\OffsiteSyncRunner;

/**
 * TASK-MVP-005. Syncs one already-finalized local backup to the configured
 * offsite (S3-compatible, e.g. Cloudflare R2) destination and verifies it
 * landed correctly - never re-runs any database dump (`platform:backup:run`
 * already did that; see `OffsiteSyncRunner`'s own docblock for the full
 * design rationale).
 *
 * `{backup?}` defaults to the newest finalized local backup when omitted -
 * the normal cron usage. A specific timestamp can be passed to retry a
 * particular backup's offsite sync independently of the daily schedule.
 *
 * EXIT CODE / STATUS VISIBILITY (task section 9): local backup success and
 * offsite sync success are reported as clearly separate outcomes - this
 * command exits non-zero on FAILURE, exits zero (with a visible "DISABLED"
 * message) when offsite sync is turned off, and exits zero with a visible
 * "SUCCESS" summary (object count, verified checksums) otherwise. Nothing
 * about a local backup is ever touched or re-evaluated here.
 */
class SyncOffsiteBackup extends Command
{
    protected $signature = 'platform:backup:sync-offsite {backup? : Specific backup timestamp (Y-m-d_His) to sync; defaults to the newest finalized local backup}';

    protected $description = 'Syncs one finalized local backup to the configured offsite (S3-compatible) destination and verifies it via a full checksum round trip. Never re-runs any database dump.';

    public function handle(OffsiteSyncRunner $runner): int
    {
        $timestamp = $this->argument('backup') ?? $this->newestFinalizedLocalBackup();

        if ($timestamp === null) {
            $this->error('No finalized local backup found to sync. Run platform:backup:run first.');

            return self::FAILURE;
        }

        $this->info("Syncing backup [{$timestamp}] offsite...");

        try {
            $result = $runner->sync($timestamp);
        } catch (OffsiteSyncFailedException $e) {
            $this->error('Offsite sync FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === 'disabled') {
            $this->warn('Offsite sync is DISABLED (BACKUP_OFFSITE_ENABLED is not true) - nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Offsite sync SUCCEEDED.');
        $this->table(['Field', 'Value'], [
            ['Backup', $result['timestamp']],
            ['Remote path', $result['remote_path']],
            ['Objects synced', $result['object_count']],
            ['Finished', $result['finished_at']],
        ]);

        foreach ($result['objects'] as $object) {
            $verified = $object['sha256_verified'] !== null ? 'checksum verified' : 'size verified (no checksum on this file)';
            $this->line(" - {$object['file']} ({$object['size_bytes']} bytes, {$verified})");
        }

        return self::SUCCESS;
    }

    protected function newestFinalizedLocalBackup(): ?string
    {
        $dailyRoot = rtrim((string) config('platform-backup.root'), '/').'/daily';

        if (! is_dir($dailyRoot)) {
            return null;
        }

        $finalized = array_values(array_filter(
            scandir($dailyRoot) ?: [],
            fn (string $name): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name)
        ));

        if ($finalized === []) {
            return null;
        }

        sort($finalized);

        return $finalized[array_key_last($finalized)];
    }
}
