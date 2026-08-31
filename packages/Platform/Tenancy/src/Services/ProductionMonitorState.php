<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use RuntimeException;

/**
 * TASK-OPS-MONITORING-001. Plain filesystem I/O for `platform:production:
 * monitor`'s own bounded operational state - deliberately the ONLY class
 * in this feature that touches a filesystem path directly, so
 * `ProductionMonitorRunner` (the decision logic) can be tested against a
 * plain in-memory array without ever touching disk.
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
 *     "last_error": "<bounded, generic string>"|null
 *   }
 * }
 * ```
 */
final class ProductionMonitorState
{
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
     * @return array{checks: array<string, array{status: string, since: string, last_notified_status: string|null, last_notified_at: string|null}>, meta: array<string, mixed>}
     */
    public function read(): array
    {
        $path = $this->stateFilePath();

        if (! is_file($path)) {
            return ['checks' => [], 'meta' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['checks']) || ! is_array($decoded['checks'])) {
            return ['checks' => [], 'meta' => []];
        }

        return [
            'checks' => $decoded['checks'],
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

    private function normalizedDirectory(): string
    {
        return rtrim($this->directory, '/');
    }
}
