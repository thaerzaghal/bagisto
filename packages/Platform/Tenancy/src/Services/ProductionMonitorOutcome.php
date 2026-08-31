<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Platform\Tenancy\Support\ReadinessCheckResult;
use Throwable;

/**
 * TASK-OPS-MONITORING-001. What one `ProductionMonitorRunner::run()` call
 * produced - the small, typed value `Platform\Tenancy\Console\Commands\
 * ProductionMonitor::handle()` renders to the console. Carries no
 * filesystem/mail-transport concern of its own; both already happened (or
 * were deliberately skipped) by the time this is constructed.
 */
final class ProductionMonitorOutcome
{
    /**
     * @param  array<int, ReadinessCheckResult>  $results  every check's result this run - the real checks plus the synthetic "Production monitor" pseudo-check (TASK-OPS-MONITORING-001A) that is always present, Pass when collection succeeded, Fail when it did not
     * @param  array<int, array{check: string, reason: string, status: string, detail: string, since: string}>  $notifications  the batch this run decided was worth notifying about (new/changed/reminder-due/recovered) - may be non-empty even when nothing was actually sent (disabled, dry-run, or a delivery failure)
     * @param  string|null  $configurationProblem  TASK-OPS-MONITORING-001A: a description of an invalid *enabled* configuration (missing/malformed recipient) found BEFORE evaluating any incident - present even on a run with zero WARN/FAIL checks, so an operator can never see "all healthy" while alerting is silently unable to fire
     */
    public function __construct(
        public readonly array $results,
        public readonly array $notifications,
        public readonly bool $notificationsSent,
        public readonly ?string $deliverySkippedReason,
        public readonly ?Throwable $monitorException,
        public readonly bool $dryRun,
        public readonly ?string $configurationProblem = null,
    ) {}

    public function hasIncidents(): bool
    {
        foreach ($this->results as $result) {
            if ($result->status->isIncident()) {
                return true;
            }
        }

        return false;
    }
}
