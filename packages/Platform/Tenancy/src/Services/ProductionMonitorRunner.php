<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Platform\Tenancy\Console\Commands\ProductionReadinessCheck;
use Platform\Tenancy\Mail\ProductionAlertMail;
use Platform\Tenancy\Support\ReadinessCheckResult;
use Platform\Tenancy\Support\ReadinessStatus;
use Throwable;

/**
 * TASK-OPS-MONITORING-001. The decision logic behind `platform:production:
 * monitor` - reuses `ProductionReadinessCheck::collectResults()` verbatim
 * (never re-implements a single check, never parses console output) and
 * decides, against the LAST PERSISTED state, whether anything is actually
 * worth an operator's attention this run.
 *
 * Rules (task instruction, restated as code):
 * - INFO/PASS are never incidents.
 * - A brand-new WARN/FAIL (nothing tracked for this check yet, or the
 *   check was previously healthy) -> notify, reason 'new'.
 * - A tracked incident whose STATUS changes (warn->fail or fail->warn)
 *   -> notify, reason 'changed'. This is a genuinely different situation
 *   from the same status persisting, so it is never suppressed by the
 *   reminder interval.
 * - A tracked incident whose status is UNCHANGED since it was last
 *   successfully notified -> suppressed, UNLESS `reminder_interval_minutes`
 *   has elapsed since the last successful notification, in which case one
 *   reminder is sent, reason 'reminder'.
 * - A previously-notified incident that resolves (the check now reports
 *   PASS/INFO) -> exactly one 'recovered' notice, then the check's
 *   tracking resets to a clean slate (a later re-occurrence is 'new'
 *   again, not suppressed as if it were still the old incident).
 * - Deduplication keys on `(check label, status)` ONLY - never on
 *   `detail`, which legitimately changes every run (ages, hashes,
 *   timestamps) without being a new incident. This is the concrete
 *   mechanism behind the task's own "do not fingerprint changing
 *   timestamps/backup age text" instruction.
 *
 * Delivery is gated separately from tracking (task instruction: "do not
 * mark a notification as delivered before successful transport
 * acceptance... handle delivery failure without losing the pending
 * alert"): every check's `status`/`since` in the persisted state always
 * reflects this run's REAL observed state, but `last_notified_status`/
 * `last_notified_at` - the fields that suppress future duplicate sends -
 * are only ever advanced AFTER `Mail::send()` returns without throwing.
 * A failed send leaves every queued notification's tracking exactly as it
 * was before this run, so the next scheduled invocation (5 minutes later,
 * per the documented cron interval) naturally retries the SAME batch -
 * this is the "retry", not an in-process retry/backoff loop.
 *
 * Exactly ONE email per run covering every notification decided this run
 * (never one email per check) - see `Platform\Tenancy\Mail\
 * ProductionAlertMail`'s own docblock.
 */
final class ProductionMonitorRunner
{
    public function __construct(
        private readonly ProductionReadinessCheck $readinessCheck,
        private readonly ProductionMonitorState $state,
    ) {}

    /**
     * @param  bool  $dryRun  compute and return everything this run WOULD
     *                        do, but never send mail and never persist state - a safe preview
     *                        mechanism (task's own "safe verification" documentation
     *                        requirement), not merely "send disabled".
     */
    public function run(bool $dryRun = false): ProductionMonitorOutcome
    {
        $now = CarbonImmutable::now();
        $prior = $this->state->read();
        $priorChecks = $prior['checks'];

        $monitorException = null;

        try {
            $results = $this->readinessCheck->collectResults();
        } catch (Throwable $e) {
            $monitorException = $e;
            $results = [$this->syntheticFailureResult($e)];
        }

        $newChecks = [];
        $notifications = [];

        foreach ($results as $result) {
            $this->processOne($result, $priorChecks[$result->check] ?? null, $now, $newChecks, $notifications);
        }

        $deliverySkippedReason = null;
        $sent = false;

        if ($notifications !== [] && ! $dryRun) {
            if (! (bool) config('platform-monitoring.enabled', false)) {
                $deliverySkippedReason = 'monitoring alerts disabled (MONITOR_ALERT_ENABLED is not true) - no mail sent.';
            } else {
                [$sent, $deliverySkippedReason] = $this->deliver($notifications);

                if ($sent) {
                    $this->commitDelivery($notifications, $now, $newChecks);
                }
            }
        }

        if (! $dryRun) {
            $this->state->write([
                'checks' => $newChecks,
                'meta' => [
                    'last_run_at' => $now->toIso8601String(),
                    'last_monitor_error' => $monitorException !== null ? $this->describe($monitorException) : null,
                    'last_delivery_skip_reason' => $deliverySkippedReason,
                ],
            ]);
        }

        return new ProductionMonitorOutcome($results, $notifications, $sent, $deliverySkippedReason, $monitorException, $dryRun);
    }

    /**
     * @param  array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}|null  $prior
     * @param  array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>  $newChecks
     * @param  array<int, array{check: string, reason: string, status: string, detail: string, since: string}>  $notifications
     */
    private function processOne(ReadinessCheckResult $result, ?array $prior, CarbonImmutable $now, array &$newChecks, array &$notifications): void
    {
        if (! $result->status->isIncident()) {
            $wasTrackedIncident = $prior !== null && ($prior['last_notified_status'] ?? null) !== null;

            if ($wasTrackedIncident) {
                $notifications[] = [
                    'check' => $result->check,
                    'reason' => 'recovered',
                    'status' => $result->status->value,
                    'detail' => $result->detail,
                    'since' => $now->toIso8601String(),
                ];

                // Tentative: keep the PRIOR notified-incident bookkeeping
                // until a real send succeeds - an undelivered recovery
                // notice must be retried next run, not silently dropped
                // (same "commit only after successful transport
                // acceptance" rule as any other notification).
                $newChecks[$result->check] = [
                    'status' => $result->status->value,
                    'since' => $now->toIso8601String(),
                    'last_notified_status' => $prior['last_notified_status'],
                    'last_notified_at' => $prior['last_notified_at'],
                ];

                return;
            }

            $newChecks[$result->check] = [
                'status' => $result->status->value,
                'since' => $now->toIso8601String(),
                'last_notified_status' => null,
                'last_notified_at' => null,
            ];

            return;
        }

        // WARN or FAIL from here on.
        $since = $now->toIso8601String();

        if ($prior !== null && $prior['status'] === $result->status->value) {
            $since = $prior['since'];
        }

        $reason = match (true) {
            $prior === null || ($prior['last_notified_status'] ?? null) === null => 'new',
            $prior['last_notified_status'] !== $result->status->value => 'changed',
            $this->reminderDue($prior['last_notified_at'], $now) => 'reminder',
            default => null,
        };

        if ($reason !== null) {
            $notifications[] = [
                'check' => $result->check,
                'reason' => $reason,
                'status' => $result->status->value,
                'detail' => $result->detail,
                'since' => $since,
            ];
        }

        $newChecks[$result->check] = [
            'status' => $result->status->value,
            'since' => $since,
            // Tentative when $reason !== null - only advanced after a
            // real successful send, see commitDelivery(). Unchanged when
            // suppressed (nothing to commit).
            'last_notified_status' => $prior['last_notified_status'] ?? null,
            'last_notified_at' => $prior['last_notified_at'] ?? null,
        ];
    }

    private function reminderDue(?string $lastNotifiedAt, CarbonImmutable $now): bool
    {
        if ($lastNotifiedAt === null) {
            return false;
        }

        $intervalMinutes = max(1, (int) config('platform-monitoring.reminder_interval_minutes', 360));

        return CarbonImmutable::parse($lastNotifiedAt)->addMinutes($intervalMinutes)->lte($now);
    }

    /**
     * @param  array<int, array{check: string, reason: string, status: string, detail: string, since: string}>  $notifications
     * @param  array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>  $newChecks
     */
    private function commitDelivery(array $notifications, CarbonImmutable $now, array &$newChecks): void
    {
        foreach ($notifications as $notification) {
            if ($notification['reason'] === 'recovered') {
                $newChecks[$notification['check']]['last_notified_status'] = null;
                $newChecks[$notification['check']]['last_notified_at'] = null;

                continue;
            }

            $newChecks[$notification['check']]['last_notified_status'] = $notification['status'];
            $newChecks[$notification['check']]['last_notified_at'] = $now->toIso8601String();
        }
    }

    /**
     * Exactly one send attempt, one email, always the plain `smtp` mailer
     * (see `ProductionAlertMail`'s own constructor). No in-process retry -
     * see class docblock. Returns [delivered, skipReason|null].
     *
     * @param  array<int, array{check: string, reason: string, status: string, detail: string, since: string}>  $notifications
     * @return array{0: bool, 1: string|null}
     */
    private function deliver(array $notifications): array
    {
        $recipient = trim((string) config('platform-monitoring.recipient', ''));

        if ($recipient === '') {
            // Task instruction: "Missing required configuration must be
            // visible when monitoring is explicitly enabled; do not
            // silently claim successful delivery." Returned, never thrown -
            // this is an expected, reportable configuration state, not a
            // monitor crash.
            return [false, 'MONITOR_ALERT_RECIPIENT is not configured - enabled but no operator recipient set, cannot send.'];
        }

        // Bounds this one delivery attempt (task instruction: "Bound
        // mail-delivery timeouts and retries") - the plain `smtp` mailer's
        // own already-real, already-honored `timeout` config key
        // (config/mail.php), never an invented transport option.
        config(['mail.mailers.smtp.timeout' => max(1, (int) config('platform-monitoring.mail_timeout_seconds', 10))]);

        try {
            // Deliberately `Mail::mailer('smtp')->to(...)->send(...)`, NOT
            // `Mail::to(...)->send(...)` - a real, reproduced finding while
            // building this class: `Illuminate\Mail\Mailer::sendMailable()`
            // calls `$mailable->mailer($this->name)` using the mailer the
            // message was dispatched THROUGH, unconditionally overwriting
            // whatever `ProductionAlertMail`'s own constructor set - so
            // `Mail::to(...)` (which resolves `config('mail.default')`,
            // Bagisto's tenant-aware `bagisto-dynamic-smtp`) would silently
            // force this central-only alert through the SAME transport
            // that requires an active channel/tenant context to resolve
            // its own credentials, defeating the Mailable's own explicit
            // `smtp` choice and throwing in central context. Resolving the
            // `smtp` mailer FIRST makes `$this->name` correctly equal
            // `'smtp'` for that overwrite, so it becomes a harmless no-op
            // instead of a silent corruption.
            Mail::mailer('smtp')->to($recipient)->send(new ProductionAlertMail($notifications, (string) config('app.name', 'Technify')));
        } catch (Throwable $e) {
            return [false, $this->describe($e)];
        }

        return [true, null];
    }

    private function syntheticFailureResult(Throwable $e): ReadinessCheckResult
    {
        return new ReadinessCheckResult(
            'Production monitor',
            ReadinessStatus::Fail,
            'platform:production:check itself threw an exception and could not complete: '.$this->describe($e)
        );
    }

    /**
     * Class + a truncated, whitespace-collapsed message - deliberately
     * never a stack trace (task instruction: "Avoid ... raw exception
     * dumps ... in notifications or persistent monitoring state"). The
     * full exception is still visible via Laravel's own normal error
     * log (unaffected by anything in this class), for a human who needs
     * it.
     */
    private function describe(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return Str::limit($e::class.': '.$message, 300);
    }
}
