<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Carbon\CarbonImmutable;
use Platform\Tenancy\Support\ReadinessStatus;
use RuntimeException;
use Throwable;

/**
 * TASK-OPS-MONITORING-001 (path safety hardened in TASK-OPS-MONITORING-001A).
 * Plain filesystem I/O for `platform:production:monitor`'s own bounded
 * operational state - deliberately the ONLY class in this feature that
 * touches a filesystem path directly, so `ProductionMonitorRunner` (the
 * decision logic) can be tested against a plain in-memory array without
 * ever touching disk.
 *
 * Never a database migration, never Redis (task instruction: "do not
 * require a database migration or Redis availability merely to remember
 * that Redis is down" - Redis being unreachable is itself one of the
 * conditions this monitor must be able to alert on). A single JSON file
 * under `config('platform-monitoring.state_path')` - see that config
 * file's own docblock for why this defaults to a sibling of the already
 * durable, already bind-mounted `platform-backup.root` volume rather than
 * anything inside the tenant-suffixed `storage/` tree.
 *
 * State shape (all keys always present once written):
 * ```
 * {
 *   "checks": {
 *     "<check label>": {
 *       "status": "warn"|"fail"|"pass"|"info",
 *       "since": "<ISO-8601>",
 *       "last_notified_status": "warn"|"fail"|null,
 *       "last_notified_at": "<ISO-8601>"|null
 *     }, ...
 *   },
 *   "meta": {
 *     "last_run_at": "<ISO-8601>",
 *     "last_monitor_error": "<bounded, class-only string>"|null,
 *     "last_delivery_skip_reason": "<bounded string>"|null,
 *     "configuration_error": "<bounded string>"|null
 *   }
 * }
 * ```
 */
final class ProductionMonitorState
{
    /**
     * TASK-OPS-MONITORING-001A. A real, reproduced finding: `.env.example`
     * ships `MONITOR_STATE_PATH=` (present, blank) - `env('MONITOR_STATE_PATH',
     * $default)` returns that blank STRING, not `$default` (`env()` only
     * falls back on a genuinely UNSET variable, never an empty one) - see
     * `config/platform-monitoring.php`'s own fix for the config-level half
     * of this. This class's OWN half: never trust the resolved value is
     * non-empty/safe just because config() returned something - reject an
     * unsafe path here too, as a second, independent layer, before any
     * filesystem operation.
     */
    public function __construct(private readonly string $directory) {}

    public function stateFilePath(): string
    {
        return $this->normalizedDirectory().'/state.json';
    }

    public function lockFilePath(): string
    {
        return $this->normalizedDirectory().'/.monitor.lock';
    }

    public function ensureDirectoryExists(): void
    {
        $this->assertSafeDirectory();

        $directory = $this->normalizedDirectory();

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create monitoring state directory [{$directory}].");
        }
    }

    /**
     * A missing file (first-ever run - nothing has ever been written yet)
     * is a safe empty baseline. TASK-OPS-MONITORING-001B fix 3: a file
     * that EXISTS but is not valid/readable JSON in the expected top-level
     * shape now THROWS instead of silently returning that same empty
     * baseline - a real, reproduced problem with the previous behavior:
     * treating "nothing has ever run" and "something real is on disk and
     * unreadable" identically meant `ProductionMonitorRunner::run()` would
     * go on to `write()` a fresh near-empty state directly over a
     * genuinely corrupt file, permanently destroying whatever incident
     * history it held (task instruction: "do not silently overwrite
     * unreadable state with a fresh baseline"). The two cases are now
     * distinguished; `run()` treats this exception like any other monitor
     * failure and deliberately skips its own final `write()` call when it
     * occurs, leaving the file exactly as found for a human to inspect.
     *
     * TASK-OPS-MONITORING-001A (hardened in 001B): also validates each
     * INDIVIDUAL check entry SEMANTICALLY, not merely its PHP type - a
     * real, reproduced finding showed a `status`/`last_notified_status`
     * of any string, or a `last_notified_at`/`since` of any string
     * (including one that is not a parseable timestamp at all, e.g.
     * `"NOT_A_DATE"`), previously passed validation here and only failed
     * much later, outside this class's controlled failure handling, when
     * `CarbonImmutable::parse()` finally choked on it. `sanitizeChecks()`
     * below now additionally rejects: a `status`/`last_notified_status`
     * that is not a real `ReadinessStatus` value; a `since`/
     * `last_notified_at` that does not actually parse as a timestamp; a
     * `last_notified_status` that is not itself a WARN/FAIL-shaped status
     * (PASS/INFO is never what this class itself writes there); and a
     * `last_notified_status`/`last_notified_at` pair that is not both-null
     * or both-non-null (the only shape this class or `ProductionMonitorRunner`
     * ever produces). A failing entry is DROPPED, never fatal to the run
     * (task instruction: "do not let malformed entries crash outside
     * failure handling") - every OTHER valid entry is preserved untouched
     * (task instruction: "preserve valid unrelated incident history"), and
     * the number dropped is returned as a plain count so a caller can make
     * corruption visible without ever exposing raw file content.
     *
     * @return array{checks: array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>, meta: array<string, mixed>, dropped_entries: int}
     */
    public function read(): array
    {
        $this->assertSafeDirectory();

        $path = $this->stateFilePath();

        if (! is_file($path)) {
            return ['checks' => [], 'meta' => [], 'dropped_entries' => 0];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['checks']) || ! is_array($decoded['checks'])) {
            throw new RuntimeException("Monitoring state file [{$path}] exists but is not valid JSON in the expected shape - refusing to treat it as an empty baseline (that would silently discard it on the next write). See application log for the raw parse failure; the state file's own contents are not included here.");
        }

        [$checks, $dropped] = $this->sanitizeChecks($decoded['checks']);

        return [
            'checks' => $checks,
            'meta' => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
            'dropped_entries' => $dropped,
        ];
    }

    /**
     * Atomic write (write-to-temp-then-rename, the same pattern
     * `Platform\Backup\Services\BackupRunner` and the production deploy
     * scripts' own `ROLLBACK_COMMIT`/`APP_COMMIT` markers already
     * establish for this project) - a crash mid-write can never leave a
     * half-written, corrupt state.json for the next invocation to trip
     * over; `read()`'s own corruption fallback above is a second,
     * independent layer of the same defense, not a substitute for it.
     *
     * @param  array{checks: array<string, mixed>, meta: array<string, mixed>}  $state
     */
    public function write(array $state): void
    {
        $this->ensureDirectoryExists();

        $path = $this->stateFilePath();
        $temp = $path.'.'.getmypid().'.tmp';

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Could not encode monitoring state as JSON.');
        }

        file_put_contents($temp, $encoded);
        chmod($temp, 0640);
        rename($temp, $path);
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array{0: array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>, 1: int}
     */
    private function sanitizeChecks(array $checks): array
    {
        $clean = [];
        $dropped = 0;

        foreach ($checks as $label => $entry) {
            if (! is_string($label) || $label === '' || ! is_array($entry)) {
                $dropped++;

                continue;
            }

            if (! isset($entry['status']) || ! is_string($entry['status']) || ReadinessStatus::tryFrom($entry['status']) === null) {
                $dropped++;

                continue;
            }

            if (! isset($entry['since']) || ! is_string($entry['since']) || ! $this->isValidTimestamp($entry['since'])) {
                $dropped++;

                continue;
            }

            $lastNotifiedStatus = $entry['last_notified_status'] ?? null;
            $lastNotifiedAt = $entry['last_notified_at'] ?? null;

            // The only shape this class (via ProductionMonitorRunner::
            // commitDelivery()/processOne()) ever actually writes: both
            // null (never notified, or just recovered back to a clean
            // slate) or both non-null (a real, previously delivered
            // notification) - never one without the other.
            if (($lastNotifiedStatus === null) !== ($lastNotifiedAt === null)) {
                $dropped++;

                continue;
            }

            if ($lastNotifiedStatus !== null) {
                if (! is_string($lastNotifiedStatus)) {
                    $dropped++;

                    continue;
                }

                $notifiedStatus = ReadinessStatus::tryFrom($lastNotifiedStatus);

                // last_notified_status only ever records a status this
                // class actually NOTIFIED about - always an incident
                // (WARN/FAIL); PASS/INFO here cannot correspond to any
                // real prior send.
                if ($notifiedStatus === null || ! $notifiedStatus->isIncident()) {
                    $dropped++;

                    continue;
                }
            }

            if ($lastNotifiedAt !== null) {
                if (! is_string($lastNotifiedAt) || ! $this->isValidTimestamp($lastNotifiedAt)) {
                    $dropped++;

                    continue;
                }
            }

            $clean[$label] = [
                'status' => $entry['status'],
                'since' => $entry['since'],
                'last_notified_status' => $lastNotifiedStatus,
                'last_notified_at' => $lastNotifiedAt,
            ];
        }

        return [$clean, $dropped];
    }

    /**
     * `CarbonImmutable::parse()` throws `InvalidFormatException` (a real,
     * reproduced finding - TASK-OPS-MONITORING-001B finding 3) on a string
     * that is not a real timestamp at all (e.g. `"NOT_A_DATE"`) - a case
     * `is_string()` alone can never catch. An empty/whitespace-only string
     * is rejected explicitly first: `strtotime('')` (which `Carbon::parse()`
     * falls back to for some inputs) does not reliably throw for it, but it
     * is never a value this class itself writes.
     */
    private function isValidTimestamp(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizedDirectory(): string
    {
        return rtrim($this->directory, '/');
    }

    /**
     * TASK-OPS-MONITORING-001A. Rejects a blank, whitespace-only, relative,
     * or filesystem-root(-ish) path BEFORE any filesystem operation is
     * ever attempted - task instruction: "Reject unsafe/invalid resolved
     * paths before filesystem operations. Do not change backup ownership/
     * permissions or write at filesystem root." Deliberately simple,
     * string-level rules only (no `realpath()` - the directory frequently
     * does not exist yet on first run, which `realpath()` cannot resolve
     * at all): non-empty after trimming, absolute (`/`-prefixed - a
     * relative path is ambiguous under cron, where the working directory
     * is not guaranteed), and not itself the bare root or a root-only
     * sequence of slashes.
     */
    private function assertSafeDirectory(): void
    {
        $trimmed = trim($this->directory);
        $normalized = rtrim($trimmed, '/');

        if ($trimmed === '' || $normalized === '' || $trimmed[0] !== '/') {
            throw new RuntimeException(
                "Refusing to use monitoring state path [{$this->directory}] - it must be a non-empty, absolute path, never the filesystem root. Check MONITOR_STATE_PATH/BACKUP_ROOT."
            );
        }
    }
}
