<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Platform\Tenancy\Services\ProductionMonitorOutcome;
use Platform\Tenancy\Services\ProductionMonitorRunner;
use Platform\Tenancy\Services\ProductionMonitorState;
use RuntimeException;
use Throwable;

/**
 * TASK-OPS-MONITORING-001. `platform:production:monitor` - a thin, opt-in
 * alerting wrapper around the existing, read-only `platform:production:
 * check` (`ProductionReadinessCheck`, unchanged in behavior - see that
 * class's own docblock for the one internal refactor, `collectResults()`,
 * this command relies on). This command itself remains read-only against
 * `platform:production:check`'s own checks; the only thing it writes is
 * its own small, bounded operational state file (see
 * `ProductionMonitorState`) and, only when explicitly enabled, one email
 * per run through the existing central SMTP mailer
 * (`Platform\Tenancy\Mail\ProductionAlertMail`).
 *
 * NOT registered on any Laravel schedule and NOT installed on any cron by
 * this task (see docs/architecture/production-deployment.md "Monitoring /
 * alerting" for the prepared-but-not-activated host-cron instructions).
 *
 * Never initializes tenant context, never reads/writes tenant business
 * data - everything this command touches is central config, the local
 * filesystem paths `platform:production:check` itself already reads
 * (APP_COMMIT, Vite manifests, backup manifests), and its own state file.
 */
class ProductionMonitor extends Command
{
    protected $signature = 'platform:production:monitor
        {--dry-run : Compute and print what this run WOULD do - no mail sent, no state persisted}';

    protected $description = 'Opt-in operator alerting around platform:production:check - detects new/changed/unresolved incidents and recoveries, emails a configured operator recipient (disabled by default). Read-only against the readiness checks themselves; writes only its own bounded state file.';

    public function handle(ProductionMonitorRunner $runner, ProductionMonitorState $state): int
    {
        try {
            $lockHandle = $this->openLockFile($state);
        } catch (Throwable $e) {
            // A genuine environment problem (state directory unwritable,
            // disk full, etc.) - distinct from "another instance is
            // running" and must not be silently swallowed as if it were
            // that. Surfaces exactly like any other monitor failure.
            $this->error("Could not open the monitor lock file: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->line('Skipped: another platform:production:monitor invocation currently holds the lock.');

            return self::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');
            $outcome = $runner->run($dryRun);

            $this->render($outcome, $dryRun);

            if ($outcome->monitorException !== null) {
                return self::FAILURE;
            }

            if ($outcome->notifications !== [] && ! $dryRun
                && (bool) config('platform-monitoring.enabled', false)
                && ! $outcome->notificationsSent
            ) {
                // Alerting was ENABLED, something genuinely needed sending,
                // and the send did not succeed - a real operational
                // problem (bad SMTP config, unreachable relay, missing
                // recipient) worth a non-zero cron exit code, distinct
                // from "disabled" or "nothing to send" (neither is a
                // failure of this command's own job).
                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Opens (creating if needed) the plain lock file `flock()` is taken
     * on - non-blocking `LOCK_EX|LOCK_NB` locking on a real file is the
     * same class of overlap protection `docker/production/deploy.sh`/
     * `docker-disk-hygiene.sh` already establish at the shell level for
     * this project's other scheduled operations, applied here at the PHP
     * level since this is a single artisan command, not a shell script
     * pair. Deliberately NOT `Cache::lock()` (Redis) - Redis being
     * unreachable is itself one of the conditions this monitor must be
     * able to alert on; a lock mechanism that depends on the exact
     * resource under suspicion would be self-defeating.
     *
     * @return resource
     */
    private function openLockFile(ProductionMonitorState $state)
    {
        $state->ensureDirectoryExists();

        $handle = fopen($state->lockFilePath(), 'c');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$state->lockFilePath()}] for locking.");
        }

        return $handle;
    }

    private function render(ProductionMonitorOutcome $outcome, bool $dryRun): void
    {
        $incidentCount = count(array_filter($outcome->results, fn ($r) => $r->status->isIncident()));

        $this->line("platform:production:check: {$incidentCount} of ".count($outcome->results).' check(s) currently WARN/FAIL.');

        if ($outcome->monitorException !== null) {
            $this->error('platform:production:check itself threw an exception - treated as a monitor failure, not a healthy run.');
        }

        if ($outcome->notifications === []) {
            $this->info('No new/changed/reminder-due/recovered incidents this run - nothing to notify.');

            return;
        }

        foreach ($outcome->notifications as $notification) {
            $this->line("[{$notification['reason']}] {$notification['check']} -> {$notification['status']}");
        }

        if ($dryRun) {
            $this->comment('--dry-run: no mail sent, no state persisted.');

            return;
        }

        if ($outcome->notificationsSent) {
            $this->info('Notification email sent.');

            return;
        }

        $this->comment('Notification NOT sent: '.($outcome->deliverySkippedReason ?? 'unknown reason').' (state still recorded; will retry/reflect next run).');
    }
}
