<?php

declare(strict_types=1);

namespace Platform\Backup\Exceptions;

use RuntimeException;

/**
 * TASK-MVP-003A. Thrown by any step of `Platform\Backup\Services\
 * BackupRunner::run()` - central dump, a tenant dump, or a tenant file
 * archive. `BackupRunner` catches this (and any other `Throwable`) at the
 * top level, records it in the manifest, renames the in-progress directory
 * to `.failed` (never leaves a directory that looks like a finalized
 * success), and re-throws so `platform:backup:run`'s own exit code is
 * non-zero - see that command's docblock for the full atomic
 * success/failure contract.
 */
class BackupFailedException extends RuntimeException
{
}
