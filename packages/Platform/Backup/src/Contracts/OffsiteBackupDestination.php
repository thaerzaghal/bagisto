<?php

declare(strict_types=1);

namespace Platform\Backup\Contracts;

/**
 * TASK-MVP-005. The one seam `Platform\Backup` depends on for off-server
 * backup sync - deliberately NOT an S3 SDK wrapper (task section 3: "Keep
 * the contract limited to actual use cases. Do not mirror an S3 SDK API").
 * Every method here maps directly to something `OffsiteSyncRunner`/
 * `CleanupOffsiteBackups` actually needs to do, nothing more.
 *
 * The real implementation (`S3CompatibleOffsiteDestination`) is a thin
 * wrapper around Laravel's own `Storage::build(['driver' => 's3', ...])`
 * runtime-disk mechanism - R2/S3/Wasabi/Backblaze are all S3-API-compatible,
 * so this one implementation already covers every provider in that family;
 * a genuinely different provider (rsync-to-a-second-host, for example) would
 * only need a second class implementing this same contract, never a change
 * to `Platform\Backup`'s own runner/command code.
 */
interface OffsiteBackupDestination
{
    /**
     * Uploads a single local file to the given remote key (a full path
     * within the destination, already including whatever prefix the
     * destination itself is configured with - callers do not need to know
     * or care about that prefix).
     */
    public function upload(string $localPath, string $remoteKey): void;

    /**
     * Downloads a single remote object to a local path. The parent
     * directory of `$localPath` is created if it does not already exist.
     */
    public function download(string $remoteKey, string $localPath): void;

    /**
     * True if an object exists at the given remote key. Used for
     * idempotent re-sync (skip re-uploading a file already present with the
     * same size) and for verification after upload.
     */
    public function exists(string $remoteKey): bool;

    /**
     * The remote object's size in bytes, or null if it does not exist.
     * Used for a cheap idempotency/verification check before a full
     * download+checksum round trip.
     */
    public function size(string $remoteKey): ?int;

    /**
     * Deletes a single remote object. Callers are responsible for their own
     * safety checks (see `OffsiteKeyGuard`) BEFORE calling this - the
     * destination itself does not know what "safe to delete" means for this
     * application's own backup layout.
     */
    public function delete(string $remoteKey): void;

    /**
     * Lists every remote key that starts with the given prefix. Used to
     * enumerate existing remote backups for retention/cleanup - never
     * assumes real directory semantics (S3-compatible stores have none),
     * just prefix-matched key names.
     *
     * @return array<int, string>
     */
    public function listKeysWithPrefix(string $prefix): array;
}
