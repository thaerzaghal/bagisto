<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use RuntimeException;

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
     * Never throws on a missing/corrupt file - a first-ever run (no state
     * file yet) and a genuinely corrupted one are both treated the same
     * way: an empty baseline, not a monitor crash. Corruption is
     * self-healing on the very next successful write.
     *
     * TASK-OPS-MONITORING-001A: also validates each INDIVIDUAL check entry
     * has the expected shape, not just that the top-level JSON parsed -
     * a malformed single entry (e.g. hand-edited, or written by a future
     * incompatible version of this class) is dropped, never allowed to
     * crash `ProductionMonitorRunner::processOne()` outside this class's
     * own controlled failure handling (task instruction: "Validate
     * persisted state shape safely where needed; do not let malformed
     * entries crash outside failure handling").
     *
     * @return array{checks: array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>, meta: array<string, mixed>}
     */
    public function read(): array
    {
        $this->assertSafeDirectory();

        $path = $this->stateFilePath();

        if (! is_file($path)) {
            return ['checks' => [], 'meta' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['checks']) || ! is_array($decoded['checks'])) {
            return ['checks' => [], 'meta' => []];
        }

        return [
            'checks' => $this->sanitizeChecks($decoded['checks']),
            'meta' => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
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
     * @return array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>
     */
    private function sanitizeChecks(array $checks): array
    {
        $clean = [];

        foreach ($checks as $label => $entry) {
            if (! is_string($label) || $label === '' || ! is_array($entry)) {
                continue;
            }

            if (! isset($entry['status']) || ! is_string($entry['status'])) {
                continue;
            }

            if (! isset($entry['since']) || ! is_string($entry['since'])) {
                continue;
            }

            $lastNotifiedStatus = $entry['last_notified_status'] ?? null;
            $lastNotifiedAt = $entry['last_notified_at'] ?? null;

            if ($lastNotifiedStatus !== null && ! is_string($lastNotifiedStatus)) {
                continue;
            }

            if ($lastNotifiedAt !== null && ! is_string($lastNotifiedAt)) {
                continue;
            }

            $clean[$label] = [
                'status' => $entry['status'],
                'since' => $entry['since'],
                'last_notified_status' => $lastNotifiedStatus,
                'last_notified_at' => $lastNotifiedAt,
            ];
        }

        return $clean;
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
