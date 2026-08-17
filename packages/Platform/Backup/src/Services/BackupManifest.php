<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

/**
 * TASK-MVP-003A. Read/write helpers for a backup run's `manifest.json` -
 * the human- and machine-readable record RISK_REGISTER.md/this task's own
 * instructions require: what was included, sizes, SHA-256 checksums,
 * success/failure status. Deliberately never writes anything that could be
 * a secret (no DB passwords, no tenant DB usernames, no `.env` values) -
 * only names, sizes, checksums, and status strings, all of which are safe
 * to leave world-readable inside an otherwise-restricted backup directory.
 */
class BackupManifest
{
    public static function write(string $path, array $manifest): void
    {
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );
    }

    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function sha256(string $filePath): string
    {
        return (string) hash_file('sha256', $filePath);
    }
}
