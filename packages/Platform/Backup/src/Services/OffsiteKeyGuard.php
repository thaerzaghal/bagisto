<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use RuntimeException;

/**
 * TASK-MVP-005. The remote-storage equivalent of `BackupPathGuard` (task
 * section 13: "Mirror the safety philosophy of BackupPathGuard") - the ONE
 * place that decides whether a remote object key is safely inside the
 * configured offsite backup prefix, in the exact `daily/<timestamp>/...`
 * shape this package actually writes, before `CleanupOffsiteBackups` (or
 * anything else) is allowed to delete it.
 *
 * A remote key has no filesystem/`realpath()` to resolve (S3-compatible
 * stores have no real directories, no symlinks, no `../` traversal at the
 * storage layer itself), so this is a pure string-shape check rather than
 * `BackupPathGuard`'s filesystem-resolution one - but the same discipline:
 * fail closed, dependency-free, independently unit-testable, never trusted
 * to "look right" without an explicit test.
 */
class OffsiteKeyGuard
{
    public function __construct(protected string $prefix) {}

    public static function fromConfig(): self
    {
        return new self((string) config('platform-backup.offsite.prefix'));
    }

    /**
     * True only if `$key` is exactly `<prefix>/daily/<Y-m-d_His>` or a real
     * file underneath it (`<prefix>/daily/<Y-m-d_His>/...`) - the exact
     * shape `OffsiteSyncRunner` writes and nothing else. A key equal to the
     * prefix itself, or missing the timestamp segment entirely, is NOT
     * considered safe - deleting "everything under the prefix" is never a
     * single operation this guard permits.
     */
    public function isSafeBackupKey(string $key): bool
    {
        $normalizedPrefix = trim($this->prefix, '/');
        $pattern = '#^'.preg_quote($normalizedPrefix, '#').'/daily/\d{4}-\d{2}-\d{2}_\d{6}(/.+)?$#';

        return (bool) preg_match($pattern, trim($key, '/'));
    }

    /**
     * @throws RuntimeException if `$key` is not a safely-scoped backup
     *                          object - callers about to delete something
     *                          MUST call this first and let the exception
     *                          propagate, never swallow it.
     */
    public function assertSafeBackupKey(string $key): void
    {
        if (! $this->isSafeBackupKey($key)) {
            throw new RuntimeException("Refusing to delete a remote key outside the configured offsite backup shape [{$this->prefix}/daily/<timestamp>/...]: {$key}");
        }
    }

    /**
     * Extracts the `<Y-m-d_His>` timestamp segment from a safe backup key
     * (`<prefix>/daily/<timestamp>` or `<prefix>/daily/<timestamp>/...`).
     * Returns null for anything `isSafeBackupKey()` itself would reject.
     */
    public function timestampFromKey(string $key): ?string
    {
        $normalizedPrefix = trim($this->prefix, '/');
        $pattern = '#^'.preg_quote($normalizedPrefix, '#').'/daily/(\d{4}-\d{2}-\d{2}_\d{6})(?:/.+)?$#';

        return preg_match($pattern, trim($key, '/'), $matches) ? $matches[1] : null;
    }
}
