<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Platform\Tenancy\Console\Commands\ProductionReadinessCheck;
use Platform\Tenancy\Mail\ProductionAlertMail;
use Platform\Tenancy\Support\NotificationRedactor;
use Platform\Tenancy\Support\ReadinessCheckResult;
use Platform\Tenancy\Support\ReadinessStatus;
use RuntimeException;
use Throwable;

/**
 * TASK-OPS-MONITORING-001 (hardened in TASK-OPS-MONITORING-001A, then
 * TASK-OPS-MONITORING-001B - see the numbered fixes called out inline
 * below). The decision logic behind `platform:production:monitor` -
 * reuses `ProductionReadinessCheck::collectResults()` verbatim (never
 * re-implements a single check, never parses console output) and decides,
 * against the LAST PERSISTED state, whether anything is actually worth an
 * operator's attention this run.
 *
 * TASK-OPS-MONITORING-001B fix 2 - a notification NEVER carries
 * `ReadinessCheckResult::detail` (free-form, exception-derived text - two
 * of `platform:production:check`'s own checks embed a raw caught
 * exception's message into it) at all. This is a STRUCTURAL guarantee,
 * not a regex-based one: `processOne()` below builds every real
 * (new/changed/reminder/recovered) notification array with only `check`/
 * `reason`/`status`/`since` - fixed-shape, always-safe fields - and no
 * code path in this class ever adds a `detail` key to one. A real,
 * reproduced finding (TASK-OPS-MONITORING-001A's own `NotificationRedactor`
 * pattern list) showed pattern-matching cannot be trusted as the primary
 * guarantee - `{"password":"..."}`, `password="two words"`, and
 * `Authorization: Basic ...` all slipped past that regex list untouched.
 * `NotificationRedactor` still exists and is still applied (`describe()`
 * below) as a genuine defense-in-depth layer, never as the boundary this
 * class relies on. The ONE notification that still carries a `detail` key
 * is the synthetic `--test-notification` message
 * (`sendTestNotification()`) - a single, hardcoded, compile-time string
 * literal, never derived from any exception or external input, and
 * therefore safe by construction rather than by inspection.
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
 * was before this run, so the next scheduled invocation naturally retries
 * the SAME batch - this is the "retry", not an in-process retry/backoff
 * loop.
 *
 * TASK-OPS-MONITORING-001A fix 3 - "Production monitor" is now a
 * PERMANENT, always-present 20th pseudo-check, alongside the real 19:
 * PASS whenever `collectResults()` returns a valid result set, FAIL
 * (via `syntheticFailureResult()`) whenever it throws OR returns
 * something invalid (empty, or duplicate check labels - see
 * `assertValidResults()`). This is what makes a monitor-level failure
 * behave EXACTLY like any other tracked incident - notified once as
 * 'new', reminded on the same interval if still failing, and explicitly
 * 'recovered' the moment collection succeeds again - instead of silently
 * vanishing from the result set the instant collection resumes. On a
 * monitor-level failure, the real 19 checks are simply NOT OBSERVED this
 * run: their prior bookkeeping is carried forward into `$newChecks`
 * UNCHANGED (never reset, never re-notified, never reinterpreted as
 * healthy - task instruction: "do not interpret unknown/unobserved
 * checks as healthy").
 *
 * Exactly ONE email per run covering every notification decided this run
 * (never one email per check) - see `Platform\Tenancy\Mail\
 * ProductionAlertMail`'s own docblock.
 */
final class ProductionMonitorRunner
{
    private const MONITOR_CHECK_LABEL = 'Production monitor';

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

        // TASK-OPS-MONITORING-001A fix 3: reading prior state can itself
        // fail (an unsafe/unreadable configured path - see
        // ProductionMonitorState::assertSafeDirectory()) - this must be a
        // monitor failure like any other, never an uncaught crash, and
        // must never be treated as "no prior state, therefore everything
        // is brand new" if state genuinely exists but could not be read
        // this run. A read failure carries forward NOTHING (there is
        // nothing safe to carry forward) - it degrades to the same
        // behavior as collectResults() failing.
        $priorChecks = [];
        $monitorException = null;
        $droppedEntries = 0;

        // TASK-OPS-MONITORING-001B fix 3: distinguishes a genuine
        // top-level-corrupt state file (state->read() throwing) from every
        // OTHER monitor failure - only this specific case skips this
        // method's own final write() below (see that call site), so a
        // corrupt-but-possibly-recoverable file is never silently
        // overwritten with a fresh baseline (task instruction).
        $stateReadFailed = false;

        try {
            $prior = $this->state->read();
            $priorChecks = $prior['checks'];
            $droppedEntries = $prior['dropped_entries'];
        } catch (Throwable $e) {
            // TASK-OPS-MONITORING-001B fix 4: report() (Laravel's own
            // already-configured exception-reporting path, e.g.
            // storage/logs/laravel.log) is the thing that actually makes
            // describe()'s "(see application log for details)" text true -
            // reported here, once, at the original catch site, with the
            // full real message/trace this class's own notification/state
            // boundary deliberately never sees.
            report($e);
            $monitorException = $e;
            $stateReadFailed = true;
        }

        $realResults = [];

        if ($monitorException === null) {
            try {
                $realResults = $this->readinessCheck->collectResults();
                $this->assertValidResults($realResults);
            } catch (Throwable $e) {
                report($e);
                $monitorException = $e;
                $realResults = [];
            }
        }

        // The "Production monitor" pseudo-check - always present, see
        // class docblock. Feeds through the exact same processOne()
        // pipeline as any real check, which is what gives it new/
        // changed/reminder/recovered semantics for free.
        $monitorCheckResult = $monitorException === null
            ? new ReadinessCheckResult(self::MONITOR_CHECK_LABEL, ReadinessStatus::Pass, 'platform:production:check completed successfully.')
            : $this->syntheticFailureResult($monitorException);

        $results = [...$realResults, $monitorCheckResult];

        // TASK-OPS-MONITORING-001A fix 3: start from a COPY of everything
        // already known, not an empty array - a check genuinely not
        // observed this run (collection failed before reaching it) keeps
        // its exact prior bookkeeping untouched. processOne() below only
        // ever overwrites the entries for checks actually present in
        // $results this run.
        $newChecks = $priorChecks;
        $notifications = [];

        foreach ($results as $result) {
            $this->processOne($result, $priorChecks[$result->check] ?? null, $now, $newChecks, $notifications);
        }

        // TASK-OPS-MONITORING-001A fix 2: surfaced BEFORE deciding whether
        // to attempt delivery, and independent of whether any check is
        // currently WARN/FAIL - an operator must never see "all healthy,
        // nothing to notify" while alerting is enabled but structurally
        // unable to ever fire (task instruction: "Validate required
        // enabled configuration before deciding whether incidents need
        // notification... looks healthy when all checks pass").
        $configurationProblem = $this->configurationProblem();

        $deliverySkippedReason = null;
        $sent = false;

        if ($notifications !== [] && ! $dryRun) {
            if (! (bool) config('platform-monitoring.enabled', false)) {
                $deliverySkippedReason = 'monitoring alerts disabled (MONITOR_ALERT_ENABLED is not true) - no mail sent.';
            } elseif ($configurationProblem !== null) {
                $deliverySkippedReason = $configurationProblem;
            } else {
                [$sent, $deliverySkippedReason] = $this->deliver($notifications);

                if ($sent) {
                    $this->commitDelivery($notifications, $now, $newChecks);
                }
            }
        }

        if (! $dryRun && ! $stateReadFailed) {
            try {
                $this->state->write([
                    'checks' => $newChecks,
                    'meta' => [
                        'last_run_at' => $now->toIso8601String(),
                        'last_monitor_error' => $monitorException !== null ? $this->describe($monitorException) : null,
                        'last_delivery_skip_reason' => $deliverySkippedReason,
                        'configuration_error' => $configurationProblem,
                    ],
                ]);
            } catch (Throwable $e) {
                // A write failure (the same unsafe-path/permission class of
                // problem read() above already guards against, or a
                // genuinely full disk) must not crash this method - the
                // run's own in-memory outcome is still returned, correctly
                // reported, and correctly fails the command's exit code,
                // it just could not be durably recorded this time.
                report($e);
                $monitorException ??= $e;
            }
        }

        return new ProductionMonitorOutcome($results, $notifications, $sent, $deliverySkippedReason, $monitorException, $dryRun, $configurationProblem, $droppedEntries);
    }

    /**
     * TASK-OPS-MONITORING-001A fix 3. `collectResults()` succeeding
     * without throwing is not, on its own, proof of a usable result -
     * task instruction: "Reject empty, malformed, or duplicate-key result
     * sets as monitor failures rather than successful empty runs."
     *
     * @param  array<int, ReadinessCheckResult>  $results
     */
    private function assertValidResults(array $results): void
    {
        if ($results === []) {
            throw new RuntimeException('platform:production:check returned an empty result set.');
        }

        $seen = [];

        foreach ($results as $result) {
            if (! $result instanceof ReadinessCheckResult) {
                throw new RuntimeException('platform:production:check returned a malformed result entry.');
            }

            if (isset($seen[$result->check])) {
                throw new RuntimeException("platform:production:check returned a duplicate check label [{$result->check}].");
            }

            $seen[$result->check] = true;
        }
    }

    /**
     * @param  array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}|null  $prior
     * @param  array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>  $newChecks
     * @param  array<int, array{check: string, reason: string, status: string, since: string, detail?: string}>  $notifications
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
                    'since' => $now->toIso8601String(),
                    // Deliberately NO 'detail' key here - see class
                    // docblock "TASK-OPS-MONITORING-001B fix 2".
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
                'since' => $since,
                // Deliberately NO 'detail' key here - see class docblock
                // "TASK-OPS-MONITORING-001B fix 2".
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
     * @param  array<int, array{check: string, reason: string, status: string, since: string, detail?: string}>  $notifications
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
     * TASK-OPS-MONITORING-001A fix 2. Checked BEFORE any delivery attempt
     * and, separately, unconditionally every run (see `run()`) - the one
     * shared source of truth for "is this enabled configuration usable at
     * all," so `configurationProblem()`/`deliver()` can never disagree.
     * Purely local validation (`filter_var(..., FILTER_VALIDATE_EMAIL)`) -
     * task instruction: "Validate recipient syntax locally without
     * contacting an external service." Returns null when disabled -
     * an unset/invalid recipient is only a problem once alerting is
     * actually turned on.
     */
    private function configurationProblem(): ?string
    {
        if (! (bool) config('platform-monitoring.enabled', false)) {
            return null;
        }

        $recipient = trim((string) config('platform-monitoring.recipient', ''));

        if ($recipient === '') {
            return 'MONITOR_ALERT_ENABLED is true but MONITOR_ALERT_RECIPIENT is not configured - alerting cannot fire.';
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return 'MONITOR_ALERT_ENABLED is true but MONITOR_ALERT_RECIPIENT does not look like a valid email address - alerting cannot fire.';
        }

        return null;
    }

    /**
     * Exactly one send attempt, one email, always the plain `smtp` mailer
     * (see `ProductionAlertMail`'s own constructor). No in-process retry -
     * see class docblock. Returns [delivered, skipReason|null]. Never
     * called once `configurationProblem()` has already returned non-null
     * (see `run()`) - this method's own recipient checks are a second,
     * independent layer, not the only one.
     *
     * @param  array<int, array{check: string, reason: string, status: string, since: string, detail?: string}>  $notifications
     * @return array{0: bool, 1: string|null}
     */
    private function deliver(array $notifications): array
    {
        $problem = $this->configurationProblem();

        if ($problem !== null) {
            // Task instruction: "Missing required configuration must be
            // visible when monitoring is explicitly enabled; do not
            // silently claim successful delivery." Returned, never thrown -
            // this is an expected, reportable configuration state, not a
            // monitor crash.
            return [false, $problem];
        }

        $recipient = trim((string) config('platform-monitoring.recipient', ''));

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
            report($e);

            return [false, $this->describe($e)];
        }

        return [true, null];
    }

    /**
     * TASK-OPS-MONITORING-001A fix 5. `platform:production:monitor
     * --test-notification` - a deliberately-invoked, explicit path to
     * prove real delivery works, using the SAME configured recipient and
     * central `smtp` mailer as any real alert, WITHOUT running a single
     * readiness check and WITHOUT touching `state.json` at all - no real
     * incident's `since`/reminder/recovery bookkeeping is affected in any
     * way (task instruction: "not change real incident delivery
     * history"). Still gated by the exact same `configurationProblem()`
     * check as a real alert - opt-in requirements are never bypassed just
     * because this was manually requested.
     *
     * @return array{0: bool, 1: string|null}
     */
    public function sendTestNotification(): array
    {
        // Explicit, independent of configurationProblem()'s own "only a
        // problem once alerting is actually turned on" rule (which
        // deliberately returns null while disabled, for the NORMAL
        // run() path - see that method's own docblock) - a manually
        // requested test send must never become a backdoor around the
        // opt-in requirement itself. Task instruction: "respect opt-in
        // requirements."
        if (! (bool) config('platform-monitoring.enabled', false)) {
            return [false, 'MONITOR_ALERT_ENABLED is not true - enable monitoring before requesting a test notification.'];
        }

        $problem = $this->configurationProblem();

        if ($problem !== null) {
            return [false, $problem];
        }

        $recipient = trim((string) config('platform-monitoring.recipient', ''));
        config(['mail.mailers.smtp.timeout' => max(1, (int) config('platform-monitoring.mail_timeout_seconds', 10))]);

        $testNotification = [
            'check' => 'Test notification',
            'reason' => 'test',
            'status' => 'info',
            'detail' => 'This is a manually-triggered test from platform:production:monitor --test-notification. '
                .'No readiness check was run and no incident/reminder/recovery state was changed.',
            'since' => CarbonImmutable::now()->toIso8601String(),
        ];

        try {
            Mail::mailer('smtp')->to($recipient)->send(new ProductionAlertMail([$testNotification], (string) config('app.name', 'Technify')));
        } catch (Throwable $e) {
            report($e);

            return [false, $this->describe($e)];
        }

        return [true, null];
    }

    private function syntheticFailureResult(Throwable $e): ReadinessCheckResult
    {
        return new ReadinessCheckResult(
            self::MONITOR_CHECK_LABEL,
            ReadinessStatus::Fail,
            'platform:production:check itself could not complete: '.$this->describe($e)
        );
    }

    /**
     * TASK-OPS-MONITORING-001A fix 1. Deliberately NEVER includes
     * `$e->getMessage()` - task instruction: "Use safe exception summaries
     * without arbitrary exception messages." An arbitrary caught exception
     * (`collectResults()` can throw literally anything from anywhere in
     * the app; a delivery exception could include SMTP-server-supplied
     * text) is fundamentally unbounded input this class cannot safely
     * curate - only the exception's own CLASS name is included, never its
     * message or trace.
     *
     * TASK-OPS-MONITORING-001B fix 4: every exception this class catches
     * and describes is passed to Laravel's own `report()` helper AT ITS
     * ORIGINAL CATCH SITE (state read/collectResults/state write/deliver/
     * sendTestNotification - see `run()` and the two methods above), once,
     * before this method is ever reached. `report()` is the framework's
     * own already-configured exception-reporting path (typically
     * `storage/logs/laravel.log`) - the full exception, with its real
     * message and stack trace, therefore genuinely does reach a human who
     * needs it there. An earlier version of this docblock asserted this
     * without the code actually doing it; this is now true by construction,
     * not merely claimed. Only THIS notification/persisted-state boundary
     * (and this class's own console/email output) is deliberately blind to
     * the message text.
     */
    private function describe(Throwable $e): string
    {
        // NotificationRedactor::redact() is a genuine no-op against this
        // string today (it never contains anything redactable - only a
        // class name and a fixed suffix) - applied anyway as the
        // documented defense-in-depth layer (TASK-OPS-MONITORING-001A),
        // so this stays true even if a future change to this method ever
        // adds more text here.
        return NotificationRedactor::redact(Str::limit($e::class.' (see application log for details)', 300));
    }
}
