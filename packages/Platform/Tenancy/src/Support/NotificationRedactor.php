<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-OPS-MONITORING-001A. A small, generic defense-in-depth safety net
 * applied at the ONE boundary where free-text `ReadinessCheckResult::detail`
 * strings leave this application's own trusted console table and enter
 * `platform:production:monitor`'s own notification email
 * (`Platform\Tenancy\Services\ProductionMonitorRunner::processOne()`) -
 * never applied to, and never changing, `platform:production:check`'s own
 * console rendering (`ProductionReadinessCheck::handle()`/`collectResults()`
 * are completely untouched by this class).
 *
 * Why this exists even though every individual check's `detail` text is
 * first-party, reviewed code: a real, reproduced finding (TASK-OPS-
 * MONITORING-001A review) showed `ProductionReadinessCheck::checkRedis()`/
 * `checkThemeStaticAssets()` already embed a raw `$e->getMessage()` into
 * their own `detail` string for a genuinely unbounded exception (a real
 * connection failure, a real HTTP client exception) - a value this class
 * never controls and must not assume is safe merely because it currently
 * only ever contains benign text in this project's own test environment.
 * "Do not assume existing console output is safe to persist or email"
 * (task instruction) - this redacts common credential SHAPES generically
 * (a pattern, not a specific known secret), as a second, independent layer
 * behind `ProductionMonitorRunner::describe()`'s own stricter "never
 * include an arbitrary exception message at all" rule for exceptions this
 * class catches directly.
 */
final class NotificationRedactor
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var array<string, string>
     *
     * Order is load-bearing: patterns run sequentially, and a later
     * pattern only ever sees what an earlier one left behind. The
     * "Bearer <token>" pattern MUST run before the generic key=value
     * pattern below - "Authorization: Bearer <token>" has TWO tokens
     * after its colon (the word "Bearer" and the actual secret), but the
     * generic pattern's `\S+` only ever consumes ONE; running it first
     * would redact the word "Bearer" alone and leave the real token
     * completely untouched immediately after it (a real, caught bug
     * during this task's own test run - see git history/PR discussion).
     */
    private const PATTERNS = [
        // a bearer-token-shaped long opaque string - checked before the
        // generic key=value pattern, see the docblock above.
        '/\bBearer\s+[A-Za-z0-9._-]{10,}/i' => 'Bearer '.self::REDACTED,
        // key=value / key: value style secrets - password, secret, token,
        // api key, authorization, credential, dsn.
        '/\b(password|secret|token|api[_-]?key|authorization|credential|dsn)\b\s*[:=]\s*\S+/i' => '$1='.self::REDACTED,
        // userinfo embedded directly in a URI, e.g. redis://user:pass@host,
        // mysql://root:hunter2@127.0.0.1.
        '/([a-z][a-z0-9+.-]*:\/\/)[^\/\s:@]+:[^\/\s@]+@/i' => '$1'.self::REDACTED.'@',
    ];

    public static function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
