<?php

declare(strict_types=1);

namespace Platform\Backup\Exceptions;

use RuntimeException;

/**
 * TASK-MVP-005. Thrown by `Platform\Backup\Services\OffsiteSyncRunner` when
 * any step of syncing/verifying a finalized local backup to the offsite
 * destination fails - upload, size mismatch, or checksum mismatch during
 * verification. `platform:backup:sync-offsite` catches this, records
 * `status: "failed"` in the local offsite-status sibling file (never
 * touches the already-finalized local backup directory itself), and exits
 * non-zero - the local backup itself is never affected by an offsite
 * failure (task section 9: "Do NOT destroy a valid local backup because the
 * network/provider is unavailable").
 */
class OffsiteSyncFailedException extends RuntimeException {}
