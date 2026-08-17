<?php

declare(strict_types=1);

namespace Platform\Backup\Console\Commands;

use Illuminate\Console\Command;
use Platform\Backup\Exceptions\BackupFailedException;
use Platform\Backup\Services\BackupRunner;

/**
 * TASK-MVP-003A. Runs ONE full backup: central DB, every tenant DB, every
 * tenant's persistent files, a manifest with checksums.
 *
 * ATOMIC SUCCESS/FAILURE CONTRACT (task section 9), implemented in
 * `BackupRunner::run()`, not here - this command only reports the result:
 *
 *   <timestamp>.in-progress/   (created first, while dumping/archiving)
 *       -> ALL steps succeed  -> manifest.json written -> renamed to
 *          <timestamp>/ (no suffix) - the ONLY directory shape
 *          platform:backup:cleanup's retention selection and
 *          platform:production:check's backup-health check ever recognize
 *          as a real, successful backup.
 *       -> ANY step fails     -> manifest.json written with status=failed
 *          -> renamed to <timestamp>.failed/ - never left looking like a
 *             success, never silently deleted either (a human should be
 *             able to inspect what was captured before the failure).
 *
 * Exit code is non-zero on ANY failure - suitable for cron/systemd-timer
 * failure detection (task section 12) without parsing output.
 */
class RunBackup extends Command
{
    protected $signature = 'platform:backup:run';

    protected $description = 'Runs one full backup (central DB + every tenant DB + every tenant\'s persistent files) with a manifest and checksums. Fails loudly; never leaves a false-success marker.';

    public function handle(BackupRunner $runner): int
    {
        $this->info('Starting backup...');

        try {
            $manifest = $runner->run();
        } catch (BackupFailedException $e) {
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup succeeded.');
        $this->table(['Field', 'Value'], [
            ['Started', $manifest['started_at']],
            ['Finished', $manifest['finished_at']],
            ['App commit', $manifest['app_commit'] ?? '(unknown)'],
            ['Central DB', $manifest['central']['file']],
            ['Tenants backed up', $manifest['tenant_count']],
            ['Tenants skipped', count($manifest['skipped_tenants'])],
        ]);

        foreach ($manifest['skipped_tenants'] as $skipped) {
            $this->warn("Skipped tenant [{$skipped['id']}] (status: {$skipped['status']}): {$skipped['reason']}");
        }

        return self::SUCCESS;
    }
}
