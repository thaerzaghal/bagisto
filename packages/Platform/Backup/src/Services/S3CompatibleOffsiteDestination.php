<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Platform\Backup\Contracts\OffsiteBackupDestination;

/**
 * TASK-MVP-005. The real off-server destination - a thin wrapper around
 * Laravel's own `Storage::build(['driver' => 's3', ...])` runtime-disk
 * mechanism (`league/flysystem-aws-s3-v3`, already the vendor Laravel ships
 * for its stock `s3` filesystem driver - no custom HTTP/SigV4 signing code
 * written here). "S3-compatible" is deliberate, not "R2-specific": R2,
 * AWS S3, Wasabi, Backblaze B2's S3-compatible endpoint, MinIO, etc. all
 * speak the same S3 API - swapping providers later is a `.env` change
 * (`BACKUP_OFFSITE_ENDPOINT`/`BACKUP_OFFSITE_REGION`/keys), never a new
 * class. See `config/platform-backup.php`'s `offsite` section for the exact
 * config keys.
 *
 * Built via `Storage::build()` (a fresh, unregistered disk instance),
 * exactly like `BackupPathGuard::fromConfig()` reads `config()` fresh at
 * resolution time rather than caching a value from application boot - a
 * runtime env change (or a test's own `config()` override) is picked up
 * correctly without needing `config:clear`.
 */
class S3CompatibleOffsiteDestination implements OffsiteBackupDestination
{
    protected Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::build([
            'driver' => 's3',
            'key' => (string) config('platform-backup.offsite.access_key'),
            'secret' => (string) config('platform-backup.offsite.secret_key'),
            'region' => (string) config('platform-backup.offsite.region'),
            'bucket' => (string) config('platform-backup.offsite.bucket'),
            'endpoint' => (string) config('platform-backup.offsite.endpoint'),
            'use_path_style_endpoint' => (bool) config('platform-backup.offsite.use_path_style'),
            'throw' => true,
        ]);
    }

    public function upload(string $localPath, string $remoteKey): void
    {
        $stream = fopen($localPath, 'r');

        try {
            $this->disk->writeStream($remoteKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function download(string $remoteKey, string $localPath): void
    {
        File::ensureDirectoryExists(dirname($localPath), 0750);

        $stream = $this->disk->readStream($remoteKey);

        if ($stream === null) {
            throw new \RuntimeException("Remote object not found: {$remoteKey}");
        }

        $local = fopen($localPath, 'w');

        try {
            stream_copy_to_stream($stream, $local);
        } finally {
            fclose($stream);
            fclose($local);
        }
    }

    public function exists(string $remoteKey): bool
    {
        return $this->disk->exists($remoteKey);
    }

    public function size(string $remoteKey): ?int
    {
        return $this->disk->exists($remoteKey) ? $this->disk->size($remoteKey) : null;
    }

    public function delete(string $remoteKey): void
    {
        $this->disk->delete($remoteKey);
    }

    public function listKeysWithPrefix(string $prefix): array
    {
        return $this->disk->allFiles($prefix);
    }
}
