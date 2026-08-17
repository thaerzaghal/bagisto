<?php

/**
 * TASK-MVP-003A. Pure unit test - deliberately no `uses()` binding at all
 * (no database, no app boot; `Platform\Backup\Services\BackupPathGuard` has
 * zero Laravel/framework dependency, only `realpath()`/string comparison).
 * Real, on-disk temp directories are used (not mocked) specifically because
 * `realpath()`'s symlink-resolution behavior - the actual safety mechanism
 * under test - cannot be faithfully exercised against a fake/in-memory path.
 */

use Platform\Backup\Services\BackupPathGuard;

function makeBackupGuardScratchDir(): string
{
    $dir = sys_get_temp_dir().'/backup-guard-test-'.bin2hex(random_bytes(8));
    mkdir($dir.'/root/inside', 0700, true);
    mkdir($dir.'/outside', 0700, true);

    return $dir;
}

function cleanupBackupGuardScratchDir(string $dir): void
{
    if (is_dir($dir.'/root/inside')) {
        rmdir($dir.'/root/inside');
    }
    if (is_dir($dir.'/root')) {
        rmdir($dir.'/root');
    }
    if (is_dir($dir.'/outside')) {
        rmdir($dir.'/outside');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

test('a path inside the configured root is allowed', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root');

    expect($guard->isInsideRoot($scratch.'/root/inside'))->toBeTrue();
    expect($guard->isInsideRoot($scratch.'/root'))->toBeTrue();

    cleanupBackupGuardScratchDir($scratch);
});

test('a sibling path outside the configured root is rejected', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root');

    expect($guard->isInsideRoot($scratch.'/outside'))->toBeFalse();

    cleanupBackupGuardScratchDir($scratch);
});

test('a ../ traversal that resolves outside the root is rejected, not just string-prefix-matched', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root');

    // Naive string-prefix checks are exactly what this class exists to
    // avoid - 'root/inside/../../outside' textually contains 'root/' but
    // realpath()-resolves to $scratch/outside, genuinely outside root.
    expect($guard->isInsideRoot($scratch.'/root/inside/../../outside'))->toBeFalse();

    cleanupBackupGuardScratchDir($scratch);
});

test('a nonexistent path is never treated as inside the root - fails closed', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root');

    expect($guard->isInsideRoot($scratch.'/root/does-not-exist'))->toBeFalse();

    cleanupBackupGuardScratchDir($scratch);
});

test('assertInsideRoot throws for an outside path and does not throw for an inside one', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root');

    expect(fn () => $guard->assertInsideRoot($scratch.'/outside'))
        ->toThrow(RuntimeException::class);

    $guard->assertInsideRoot($scratch.'/root/inside');
    expect(true)->toBeTrue(); // reaching here means no exception was thrown

    cleanupBackupGuardScratchDir($scratch);
});

test('an unresolvable configured root (nonexistent) rejects everything - fails closed, never open', function () {
    $scratch = makeBackupGuardScratchDir();
    $guard = new BackupPathGuard($scratch.'/root-that-does-not-exist');

    expect($guard->isInsideRoot($scratch.'/root/inside'))->toBeFalse();

    cleanupBackupGuardScratchDir($scratch);
});
