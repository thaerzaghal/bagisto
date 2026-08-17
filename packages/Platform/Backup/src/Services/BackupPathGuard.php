<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use RuntimeException;

/**
 * TASK-MVP-003A. The ONE place that decides whether a filesystem path is
 * safely inside the configured backup root (`config('platform-backup.root')`)
 * - used before `platform:backup:cleanup` ever deletes anything. Resolves
 * both the root and the candidate path to their real, symlink-free absolute
 * form (`realpath()`) before comparing, so neither a `../` path-traversal
 * component nor a symlink pointing outside the root can slip through a
 * naive string-prefix check.
 *
 * Deliberately its own small, dependency-free class (not a method on
 * `BackupRunner`/the cleanup command) specifically so it can be unit-tested
 * in isolation, per this task's own instruction not to trust deletion logic
 * without a dedicated safety test - see
 * `tests/Feature/Platform/BackupPathGuardTest.php`.
 */
class BackupPathGuard
{
    public function __construct(protected string $root)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('platform-backup.root'));
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * True only if `$path` resolves to a location inside (or exactly equal
     * to) the configured root. A path that does not exist on disk cannot be
     * resolved by `realpath()` and is therefore never considered "inside" -
     * fails closed, not open.
     */
    public function isInsideRoot(string $path): bool
    {
        $root = $this->resolve($this->root);
        $target = $this->resolve($path);

        if ($root === null || $target === null) {
            return false;
        }

        return $target === $root || str_starts_with($target, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * @throws RuntimeException if `$path` is not safely inside the
     *                           configured backup root - callers that are
     *                           about to delete something MUST call this
     *                           first and let the exception propagate,
     *                           never swallow it.
     */
    public function assertInsideRoot(string $path): void
    {
        if (! $this->isInsideRoot($path)) {
            throw new RuntimeException("Refusing to operate outside the configured backup root [{$this->root}]: {$path}");
        }
    }

    protected function resolve(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : rtrim($real, DIRECTORY_SEPARATOR);
    }
}
