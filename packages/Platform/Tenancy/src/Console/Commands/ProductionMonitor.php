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
 * TASK-OPS-MONITORING-001 (hardened TASK-OPS-MONITORING-001A).
 * `platform:production:monitor` - a thin, opt-in alerting wrapper around
 * the existing, read-only `platform:production:check` (`ProductionReadinessCheck`,
 * unchanged in behavior - see that class's own docblock for the one
 * internal refactor, `collectResults()`, this command relies on). This
 * command itself remains read-only against `platform:production:check`'s
 * own checks; the only thing it writes is its own small, bounded
 * operational state file (see `ProductionMonitorState`) and, only when
 * explicitly enabled, one email per run through the existing central SMTP
 * mailer (`Platform\Tenancy\Mail\ProductionAlertMail`).
 *
 * NOT registered on any Laravel schedule and NOT installed on any cron by
 * this task (see docs/architecture/production-deployment.md "Monitoring /
 * alerting" for the prepared-but-not-activated host-cron instructions,
 * including the external `timeout` wrapper this command's own execution
 * bound depends on - see that document for why the bound is not, and
 * cannot correctly be, established inside this class alone).
 *
 * Never initializes tenant context, never reads/writes tenant business
 * data - everything this command touches is central config, the local
 * filesystem paths `platform:production:check` itself already reads
 * (APP_COMMIT, Vite manifests, backup manifests), and its own state file.
 */
class ProductionMonitor extends Command
{
    protected $signature = 'platform:production:monitor
        {--dry-run : Compute and print what this run WOULD do - no mail sent, no state persisted (still acquires/releases the overlap lock and creates the state directory if missing - see docs)}
        {--test-notification : Send one clearly-labeled test email to the configured recipient and exit - runs no readiness checks, changes no incident/reminder/recovery state}';

    protected $description = 'Opt-in operator alerting around platform:production:check - detects new/changed/unresolved incidents and recoveries, emails a configured operator recipient (disabled by default). Read-only against the readiness checks themselves; writes only its own bounded state file.';

    public function handle(ProductionMonitorRunner $runner, ProductionMonitorState $state): int
    {
        // TASK-OPS-MONITORING-001B finding 1: checked FIRST, before any
        // filesystem operation (the lock file included) or delivery
        // attempt - a real, reproduced defect: with BOTH flags present,
        // the previous version checked --test-notification before
        // --dry-run and silently sent a real test email despite --dry-run
        // being requested. The two flags express mutually exclusive
        // intents (preview nothing-sent vs. deliberately send one email)
        // - reject the combination outright rather than silently
        // prioritizing either one.
        if ((bool) $this->option('dry-run') && (bool) $this->option('test-notification')) {
            $this->error('--dry-run and --test-notification cannot be combined - --dry-run previews without sending mail, --test-notification deliberately sends one. Use exactly one of the two.');

            return self::INVALID;
        }

        try {
            $lockHandle = $this->openLockFile($state);
        } catch (Throwable $e) {
            // A genuine environment problem (state directory unwritable,
            // disk full, an unsafe configured path - see
            // ProductionMonitorState::assertSafeDirectory()) - distinct
            // from "another instance is running" and must not be silently
            // swallowed as if it were that. TASK-OPS-MONITORING-001A:
            // never the raw exception message here either - the same
            // "do not assume existing console output is safe" principle
            // this task applied to the notification/state boundary
            // applies to this command's own console output too.
            // TASK-OPS-MONITORING-001B fix 4: report() makes the
            // "(see application log for details)" text below actually
            // true, rather than an unbacked claim.
            report($e);
            $this->error('Could not open the monitor lock file: '.$e::class.' (see application log for details).');

            return self::FAILURE;
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->line('Skipped: another platform:production:monitor invocation currently holds the lock.');

            return self::SUCCESS;
        }

        try {
            if ((bool) $this->option('test-notification')) {
                return $this->handleTestNotification($runner);
            }

            $dryRun = (bool) $this->option('dry-run');
            $outcome = $runner->run($dryRun);

            $this->render($outcome, $dryRun);

            if ($outcome->monitorException !== null) {
                return self::FAILURE;
            }

            if ($outcome->configurationProblem !== null) {
                // TASK-OPS-MONITORING-001A fix 2: surfaced even on an
                // otherwise fully healthy run (zero WARN/FAIL, zero
                // notifications) - an operator must never see this
                // command exit 0 while alerting is enabled but cannot
                // possibly fire.
                return self::FAILURE;
            }

            if ($outcome->notifications !== [] && ! $dryRun
                && (bool) config('platform-monitoring.enabled', false)
                && ! $outcome->notificationsSent
            ) {
                // Alerting was ENABLED, something genuinely needed sending,
                // and the send did not succeed - a real operational
                // problem (bad SMTP config, unreachable relay) worth a
                // non-zero cron exit code, distinct from "disabled" or
                // "nothing to send" (neither is a failure of this
                // command's own job).
                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * TASK-OPS-MONITORING-001A fix 5. See `ProductionMonitorRunner::
     * sendTestNotification()`'s own docblock for the full contract.
     */
    private function handleTestNotification(ProductionMonitorRunner $runner): int
    {
        [$sent, $reason] = $runner->sendTestNotification();

        if ($sent) {
            $this->info('Test notification sent to the configured recipient. No incident/reminder/recovery state was changed.');

            return self::SUCCESS;
        }

        $this->error('Test notification NOT sent: '.($reason ?? 'unknown reason').'.');

        return self::FAILURE;
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
     * resource under suspicion would be self-defeating. The lock is only
     * ever actually RELEASED by this process exiting or reaching the
     * `finally` block above - see docs/architecture/production-
     * deployment.md "Monitoring / alerting" "Execution bound" for why an
     * EXTERNAL `timeout` wrapper (not a PHP-internal one) is required to
     * guarantee that happens even if some internal call never returns.
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

        $this->line("platform:production:check: {$incidentCount} of ".count($outcome->results).' check(s) currently WARN/FAIL (includes this monitor\'s own "Production monitor" pseudo-check).');

        if ($outcome->monitorException !== null) {
            $this->error('platform:production:check itself could not complete - treated as a monitor failure, not a healthy run.');
        }

        if ($outcome->corruptedEntriesDropped > 0) {
            // TASK-OPS-MONITORING-001B fix 3: a count only, never the
            // dropped entries' own content - see ProductionMonitorOutcome's
            // own docblock.
            $this->comment("{$outcome->corruptedEntriesDropped} persisted check entry(ies) failed validation and were dropped from monitoring state.");
        }

        if ($outcome->configurationProblem !== null) {
            $this->error($outcome->configurationProblem);
        }

        if ($outcome->notifications === []) {
            $this->info('No new/changed/reminder-due/recovered incidents this run - nothing to notify.');
        } else {
            foreach ($outcome->notifications as $notification) {
                $this->line("[{$notification['reason']}] {$notification['check']} -> {$notification['status']}");
            }

            if ($dryRun) {
                $this->comment('--dry-run: no mail sent, no state persisted.');
            } elseif ($outcome->notificationsSent) {
                $this->info('Notification email sent.');
            } else {
                $this->comment('Notification NOT sent: '.($outcome->deliverySkippedReason ?? 'unknown reason').' (state still recorded; will retry/reflect next run).');
            }
        }
    }
}
