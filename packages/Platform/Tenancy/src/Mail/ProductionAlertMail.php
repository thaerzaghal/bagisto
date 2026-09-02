<?php

declare(strict_types=1);

namespace Platform\Tenancy\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-OPS-MONITORING-001. The single, plain-text notification
 * `Platform\Tenancy\Services\ProductionMonitorRunner` sends when
 * `platform:production:monitor` finds one or more new/changed/reminder-due
 * incidents, or a recovery, in the current `platform:production:check`
 * result set. One email per monitor RUN, never one per check - a run that
 * finds five simultaneous new FAILs sends one email listing all five, not
 * five separate messages.
 *
 * Deliberately NOT `ShouldQueue` - `QUEUE_CONNECTION=sync` in production
 * (docs/architecture/production-deployment.md section I), so queuing would
 * add complexity with no real effect; `ProductionMonitorRunner` already
 * treats a single synchronous send attempt as the whole delivery unit (see
 * that class's own docblock for the "no in-process retry" reasoning).
 *
 * Central-only by construction: nothing in this class or its caller ever
 * initializes tenant context, reads a tenant model, or reads tenant
 * business data.
 *
 * TASK-OPS-MONITORING-001B fix 2: a `$notifications` entry built from a
 * real check (reason `new`/`changed`/`reminder`/`recovered`) NEVER carries
 * free-form `ReadinessCheckResult::detail` text - `Platform\Tenancy\
 * Services\ProductionMonitorRunner::processOne()` structurally never adds
 * a `detail` key for those. A real, reproduced finding showed
 * `NotificationRedactor`'s pattern list (the ORIGINAL safety mechanism
 * here) could be bypassed by shapes it simply didn't recognize
 * (`{"password":"..."}`, `password="two words"`, `Authorization: Basic
 * ...`) - regex can never be a complete guarantee against arbitrary,
 * exception-derived text. `body()` below therefore only ever renders
 * `check`/`reason`/`status`/`since` for a real notification, plus one
 * fixed, static instruction line (not per-notification, never dynamic)
 * pointing the operator at the server itself for full diagnostic detail.
 * The ONE exception is the synthetic `--test-notification` message, whose
 * own `detail` is a single hardcoded string literal (see
 * `ProductionMonitorRunner::sendTestNotification()`) - safe by
 * construction, not by inspection - so `body()` renders it when present.
 *
 * Body built as a plain pre-rendered string (`Content::$htmlString`),
 * deliberately NOT a Blade view - a real, reproduced finding while
 * building this class: Bagisto registers at least one application-wide
 * View::composer() that unconditionally resolves the current CHANNEL
 * (`Webkul\Core\Core::getCurrentChannel()`, a real `channels` query
 * against whatever the ambient DB connection is) for EVERY rendered view,
 * not just Shop/Admin ones. This command runs in central context, where
 * no `channels` table exists at all (`bagisto_central`) - rendering ANY
 * Blade view here throws a real `QueryException`, confirmed live. Using
 * `htmlString` bypasses Laravel's view resolution/composer pipeline
 * entirely, closing this off structurally rather than by remembering not
 * to add a Blade view later - and keeps this class correctly free of any
 * `packages/Webkul` view/composer dependency.
 */
final class ProductionAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{check: string, reason: string, status: string, since: string, detail?: string}>  $notifications
     *                                                                                                                           `reason` is one of 'new'|'changed'|'reminder'|'recovered'|'test';
     *                                                                                                                           `status`/`since` are already-safe scalar strings (a
     *                                                                                                                           `ReadinessStatus::value` and an ISO-8601 timestamp respectively),
     *                                                                                                                           never a raw exception/object. `detail` is deliberately OPTIONAL -
     *                                                                                                                           present only for a synthetic 'test' entry (a fixed string
     *                                                                                                                           literal), never for a real check - see class docblock.
     */
    public function __construct(
        public readonly array $notifications,
        public readonly string $appLabel,
    ) {
        // Always the plain `smtp` mailer - the same central config
        // `ProductionReadinessCheck::checkMail()` already inspects - never
        // `bagisto-dynamic-smtp` (Bagisto's own per-TENANT transport,
        // config('mail.default')). This is what makes "central sender
        // identity, tenant-independent mail path" true by construction
        // rather than by caller discipline.
        $this->mailer('smtp');
    }

    public function envelope(): Envelope
    {
        // TASK-OPS-MONITORING-001A: a deliberately-triggered
        // `--test-notification` (see ProductionMonitorRunner::
        // sendTestNotification()) is never a real incident/recovery - a
        // distinct subject line makes that unambiguous to the reader,
        // rather than counting it as a real "issue" alongside genuine
        // WARN/FAIL notifications.
        if (count($this->notifications) === 1 && $this->notifications[0]['reason'] === 'test') {
            return new Envelope(
                subject: "[{$this->appLabel}] platform:production:monitor - test notification",
            );
        }

        $newOrChanged = count(array_filter($this->notifications, fn (array $n): bool => $n['reason'] !== 'recovered'));
        $recovered = count($this->notifications) - $newOrChanged;

        $summary = match (true) {
            $newOrChanged > 0 && $recovered > 0 => "{$newOrChanged} issue(s), {$recovered} recovered",
            $newOrChanged > 0 => "{$newOrChanged} issue(s)",
            default => "{$recovered} recovered",
        };

        return new Envelope(
            subject: "[{$this->appLabel}] platform:production:check - {$summary}",
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body());
    }

    /**
     * Plain, unstyled HTML (escaped values inside `<pre>`) - an operator
     * ops alert, not a themed merchant/customer email; no CSS/layout is
     * warranted. `e()` escapes every value.
     *
     * TASK-OPS-MONITORING-001B fix 2: `detail` is rendered ONLY when the
     * notification actually carries one (`isset()`, not `??`, so a
     * present-but-empty string still renders and an absent key never
     * fabricates one) - true for the single synthetic `--test-notification`
     * entry, never for a real check. A fixed, static footer line (never
     * per-notification, never built from any check/exception text) points
     * the operator at the server itself for full diagnostic detail, since
     * this email deliberately no longer carries it.
     */
    private function body(): string
    {
        $lines = [];

        foreach ($this->notifications as $notification) {
            $line = sprintf(
                "[%s] %s -&gt; %s\n",
                e(strtoupper($notification['reason'])),
                e($notification['check']),
                e(strtoupper($notification['status'])),
            );

            if (isset($notification['detail'])) {
                $line .= sprintf("    %s\n", e($notification['detail']));
            }

            $line .= sprintf('    since: %s'."\n", e($notification['since']));

            $lines[] = $line;
        }

        $body = e($this->appLabel).' - platform:production:check'."\n\n".implode("\n", $lines);
        $body .= "\n--\nThis is an automated message from platform:production:monitor. Unresolved\n"
            .'issues repeat on the configured reminder interval; a resolved check sends'
            ."\none RECOVERED notice and then stays quiet until it changes again. Run"
            ."\nphp artisan platform:production:check on the server for full diagnostic detail.";

        return '<pre>'.$body.'</pre>';
    }
}
