<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use Illuminate\Support\Facades\File;
use Platform\Backup\Contracts\OffsiteBackupDestination;
use Platform\Backup\Exceptions\OffsiteSyncFailedException;
use Throwable;

/**
 * TASK-MVP-005. Syncs ONE already-finalized local backup (produced by
 * `BackupRunner::run()`, never re-run here - task section 7: "Do not
 * independently re-run dumps for the off-server sync") to the configured
 * offsite destination, then verifies it landed correctly.
 *
 * DESIGN CHOICE (task section 10, "Command design"): local backup and
 * offsite sync are two independent commands/steps, not one command that
 * always does both. `BackupRunner::run()` is completely untouched by this
 * class - a finalized `<timestamp>/` directory is the ONLY input this class
 * ever reads, and it never mutates it (task section 7: "Do not mutate
 * artifacts after checksums were written"). This means offsite sync can be
 * retried independently, as many times as needed, without re-running any
 * database dump - exactly the "strong preference" the task itself states.
 *
 * SYNC STATUS lives in a SIBLING file next to the local backup directory
 * (`<root>/daily/<timestamp>.offsite-status.json`), never inside the
 * finalized `<timestamp>/` directory itself - the same "never mutate a
 * finalized backup" discipline `BackupRunner`'s own `.failed`/`.in-progress`
 * suffix convention already establishes for local backups.
 *
 * IDEMPOTENCY (task section 11): before uploading each file, its remote
 * size is checked first - a file already present with the exact same size
 * is skipped, not re-uploaded. The remote path is fully deterministic from
 * the local backup's own timestamp name, so running this twice in a row (or
 * after a partial failure) never creates a second, inconsistent copy.
 *
 * VERIFICATION (task section 8): for this project's actual backup size
 * (currently under 1 MB total - see the task's own final report), full
 * round-trip verification is practical and is exactly what this class does:
 * every uploaded file is downloaded back to a disposable temp directory,
 * SHA-256 is recomputed, and compared against the LOCAL manifest's own
 * already-proven checksum (never trusting a provider's ETag as a SHA-256
 * equivalent - S3-compatible ETags are not guaranteed to be SHA-256, or
 * even a hash of the plaintext content at all for multipart uploads).
 */
class OffsiteSyncRunner
{
    public function __construct(
        protected OffsiteBackupDestination $destination,
    ) {}

    /**
     * @return array<string, mixed> the sync result, in the same
     *                              spirit/shape as `BackupRunner::run()`'s
     *                              own manifest return value
     */
    public function sync(string $timestamp): array
    {
        $dailyRoot = rtrim((string) config('platform-backup.root'), '/').'/daily';
        $backupDir = "{$dailyRoot}/{$timestamp}";
        $statusFile = "{$dailyRoot}/{$timestamp}.offsite-status.json";

        if (! config('platform-backup.offsite.enabled')) {
            $result = [
                'timestamp' => $timestamp,
                'status' => 'disabled',
                'synced_at' => now()->toIso8601String(),
            ];

            File::put($statusFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            return $result;
        }

        $manifest = BackupManifest::read("{$backupDir}/manifest.json");

        if ($manifest === null) {
            throw new OffsiteSyncFailedException("No manifest found for local backup [{$timestamp}] at {$backupDir}/manifest.json - has this backup finished/finalized?");
        }

        if (($manifest['status'] ?? null) !== 'success') {
            throw new OffsiteSyncFailedException("Local backup [{$timestamp}] is not a successful, finalized backup (manifest status: ".($manifest['status'] ?? 'unknown').') - refusing to sync it offsite.');
        }

        $prefix = trim((string) config('platform-backup.offsite.prefix'), '/');
        $remoteBase = "{$prefix}/daily/{$timestamp}";

        $files = $this->filesToSync($manifest);

        $result = [
            'timestamp' => $timestamp,
            'status' => 'running',
            'synced_at' => now()->toIso8601String(),
            'remote_path' => $remoteBase,
            'objects' => [],
        ];

        try {
            foreach ($files as $relativePath => $expectedSha256) {
                $localPath = "{$backupDir}/{$relativePath}";
                $remoteKey = "{$remoteBase}/{$relativePath}";

                if (! is_file($localPath)) {
                    throw new OffsiteSyncFailedException("Local artifact listed in manifest is missing on disk: {$localPath}");
                }

                $localSize = filesize($localPath);

                if ($this->destination->size($remoteKey) !== $localSize) {
                    $this->destination->upload($localPath, $remoteKey);
                }

                $remoteSize = $this->destination->size($remoteKey);

                if ($remoteSize !== $localSize) {
                    throw new OffsiteSyncFailedException("Uploaded object size mismatch for [{$remoteKey}]: local={$localSize}, remote=".($remoteSize ?? 'missing'));
                }

                $verifiedSha256 = $expectedSha256 !== null
                    ? $this->verifyChecksum($remoteKey, $expectedSha256)
                    : null;

                $result['objects'][] = [
                    'file' => $relativePath,
                    'remote_key' => $remoteKey,
                    'size_bytes' => $remoteSize,
                    'sha256_verified' => $verifiedSha256,
                ];
            }
        } catch (Throwable $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            $result['finished_at'] = now()->toIso8601String();

            File::put($statusFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            throw new OffsiteSyncFailedException("Offsite sync for backup [{$timestamp}] failed: ".$e->getMessage(), previous: $e);
        }

        $result['status'] = 'success';
        $result['object_count'] = count($result['objects']);
        $result['finished_at'] = now()->toIso8601String();

        File::put($statusFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $result;
    }

    /**
     * Downloads the remote object to a disposable temp file, recomputes its
     * SHA-256, compares against the expected (already-proven, local
     * manifest) value, then deletes the temp file regardless of outcome.
     *
     * @throws OffsiteSyncFailedException on mismatch
     */
    protected function verifyChecksum(string $remoteKey, string $expectedSha256): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'offsite-verify-').'.tmp';

        try {
            $this->destination->download($remoteKey, $tempPath);
            $actual = BackupManifest::sha256($tempPath);

            if ($actual !== $expectedSha256) {
                throw new OffsiteSyncFailedException("Checksum mismatch after round-trip download for [{$remoteKey}]: expected {$expectedSha256}, got {$actual}");
            }

            return $actual;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Builds the exact set of relative-path => expected-sha256 entries to
     * sync, read directly from the LOCAL manifest `BackupRunner` already
     * wrote (never re-derived by re-scanning the directory) - `manifest.json`
     * itself is included (no self-checksum to verify against, so its entry
     * maps to null - existence/size is still checked).
     *
     * @return array<string, string|null>
     */
    protected function filesToSync(array $manifest): array
    {
        $files = [
            $manifest['central']['file'] => $manifest['central']['sha256'],
        ];

        foreach ($manifest['tenants'] ?? [] as $tenant) {
            $files[$tenant['db_file']] = $tenant['db_sha256'];

            if ($tenant['files_app'] !== null) {
                $files['tenant-files/'.$tenant['files_app']['file']] = $tenant['files_app']['sha256'];
            }

            if ($tenant['files_private'] !== null) {
                $files['tenant-files/'.$tenant['files_private']['file']] = $tenant['files_private']['sha256'];
            }
        }

        $files['manifest.json'] = null;

        return $files;
    }
}
