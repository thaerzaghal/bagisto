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
 * business data - every `$notifications` entry is built directly from
 * `Platform\Tenancy\Support\ReadinessCheckResult`, whose own `detail`
 * strings are already the same secret-safe, tenant-data-free text
 * `platform:production:check`'s own console table has always rendered
 * (see `tests/Feature/Platform/ProductionReadinessCheckTest.php` test 5's
 * own long-standing "never prints a secret value" proof - unchanged by
 * this task).
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
     * @param  array<int, array{check: string, reason: string, status: string, detail: string, since: string}>  $notifications
     *                                                                                                                          `reason` is one of 'new'|'changed'|'reminder'|'recovered';
     *                                                                                                                          `status`/`since` are already-safe scalar strings (a
     *                                                                                                                          `ReadinessStatus::value` and an ISO-8601 timestamp respectively),
     *                                                                                                                          never a raw exception/object.
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
     * warranted. `e()` escapes every value, including `detail`, which is
     * config/infrastructure text (never tenant/customer data - see class
     * docblock) but is still escaped as a matter of course for anything
     * rendered as HTML.
     */
    private function body(): string
    {
        $lines = [];

        foreach ($this->notifications as $notification) {
            $lines[] = sprintf(
                "[%s] %s -&gt; %s\n    %s\n    since: %s\n",
                e(strtoupper($notification['reason'])),
                e($notification['check']),
                e(strtoupper($notification['status'])),
                e($notification['detail']),
                e($notification['since']),
            );
        }

        $body = e($this->appLabel).' - platform:production:check'."\n\n".implode("\n", $lines);
        $body .= "\n--\nThis is an automated message from platform:production:monitor. Unresolved\n"
            .'issues repeat on the configured reminder interval; a resolved check sends'
            ."\none RECOVERED notice and then stays quiet until it changes again.";

        return '<pre>'.$body.'</pre>';
    }
}
