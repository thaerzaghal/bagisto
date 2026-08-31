<?php

declare(strict_types=1);

/**
 * TASK-OPS-MONITORING-001. Configuration for `Platform\Tenancy`'s
 * `platform:production:monitor` command - a thin, opt-in alerting layer
 * around the existing, read-only `platform:production:check`
 * (`Platform\Tenancy\Console\Commands\ProductionReadinessCheck`). This
 * package owns no new infrastructure: no queue, no external service, no
 * database migration - just a small JSON state file (see `state_path`
 * below) and, when explicitly enabled, one plain email through the
 * already-existing central SMTP mailer `platform:production:check` itself
 * already inspects (`checkMail()`).
 *
 * Matches `config/platform-backup.php`'s own root-config-file convention
 * (Platform packages use plain root config files, not package-published/
 * merged config - see that file's own docblock).
 */
return [

    /**
     * Master switch for sending real notifications. Deliberately
     * OPT-IN/false by default - this task's own explicit instruction:
     * "Make sending explicitly opt-in and disabled by default." When
     * false, `platform:production:monitor` still runs the readiness
     * checks and updates its own persisted state (so an operator can
     * verify the mechanism safely before turning real mail on), but never
     * calls `Mail::send()`.
     */
    'enabled' => (bool) env('MONITOR_ALERT_ENABLED', false),

    /**
     * The single operator inbox that receives alert mail. Deliberately
     * NOT inferred from any tenant/merchant owner_email or existing
     * business data (task instruction) - an explicit, operator-owned
     * address only. Left blank by default; `platform:production:monitor`
     * refuses to silently "succeed" if `enabled=true` and this is blank
     * (see `Platform\Tenancy\Services\ProductionMonitorRunner`).
     */
    'recipient' => env('MONITOR_ALERT_RECIPIENT', ''),

    /**
     * How long an UNRESOLVED incident (a check still WARN/FAIL, already
     * notified once) waits before a reminder email is sent again. Task
     * instruction: "provide a documented, configurable reminder interval
     * for unresolved incidents." Default 6 hours - long enough that a
     * 5-minute polling interval (see docs/architecture/production-
     * deployment.md "Monitoring / alerting") never spams the inbox, short
     * enough that a genuinely unresolved problem is not forgotten for a
     * full day.
     */
    'reminder_interval_minutes' => (int) env('MONITOR_REMINDER_INTERVAL_MINUTES', 360),

    /**
     * Bounds a single mail-delivery attempt (task instruction: "Bound
     * mail-delivery timeouts and retries"). Applied to the plain `smtp`
     * mailer's own already-real, already-honored `timeout` config key
     * (config/mail.php) immediately before sending - never a new,
     * invented transport option. `platform:production:monitor` makes
     * exactly ONE send attempt per invocation; a failed attempt leaves
     * the incident's notification state untouched (see
     * ProductionMonitorRunner) so the NEXT scheduled invocation is the
     * real "retry" - no in-process retry/backoff loop is implemented,
     * deliberately, to keep a single monitor run short and bounded.
     */
    'mail_timeout_seconds' => (int) env('MONITOR_MAIL_TIMEOUT', 10),

    /**
     * Where `platform:production:monitor` persists its own bounded
     * operational state (last-known status/notification timestamps per
     * check, plus its own overlap lock file) - see
     * `Platform\Tenancy\Services\ProductionMonitorState`. Deliberately
     * NOT derived from `config('platform-backup.root')` at config-load
     * time (Laravel's config files have no guaranteed load order this
     * project wants to depend on) - the default instead duplicates that
     * file's own `BACKUP_ROOT` env fallback expression directly, so this
     * reuses the SAME already-durable, already-bind-mounted volume
     * (`docker-compose.production.yml`'s `../backups:/backups`) with zero
     * new infrastructure, while remaining independently overridable via
     * its own `MONITOR_STATE_PATH` if ever needed. This is intentionally
     * OUTSIDE any tenant-suffixed storage path (`storage/tenant{id}/...`)
     * - the monitor never initializes tenant context and never wants its
     * own bookkeeping to collide with `FilesystemTenancyBootstrapper`.
     *
     * TASK-OPS-MONITORING-001A: a plain `env('MONITOR_STATE_PATH', $default)`
     * is NOT enough - a real, reproduced finding - `.env.example` (and any
     * real `.env` copied from it before this value is deliberately set)
     * ships the line `MONITOR_STATE_PATH=` (present, blank, never
     * commented out). `phpdotenv` sets the actual process environment
     * variable to an empty STRING in that case, and `env()` only falls
     * back to `$default` for a genuinely UNSET variable - an empty string
     * is not null, so the untrimmed call below would have silently
     * resolved to `''`, and every path this class builds
     * (`Platform\Tenancy\Services\ProductionMonitorState::stateFilePath()`/
     * `lockFilePath()`) would have been relative to nothing (effectively
     * the current working directory). Trimming and explicitly treating a
     * blank result as "not configured" restores the documented default in
     * exactly that case; `ProductionMonitorState` itself independently
     * rejects an unsafe resolved path too (defense in depth, never
     * trusting this file's own arithmetic alone).
     */
    'state_path' => (static function (): string {
        $configured = trim((string) env('MONITOR_STATE_PATH', ''));

        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) env('BACKUP_ROOT', dirname(storage_path()).'/backups'), '/').'/monitoring';
    })(),

];
