<?php

/**
 * TASK-MVP-005. Pure unit test - no `uses()` binding, no database, no app
 * boot; `Platform\Backup\Services\OffsiteKeyGuard` only does string-shape
 * matching against a configured prefix, mirroring `BackupPathGuardTest.php`'s
 * own pure-unit style for the equivalent LOCAL safety check.
 */

use Platform\Backup\Services\OffsiteKeyGuard;

test('a key exactly matching <prefix>/daily/<timestamp> is safe', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('estore-backups/daily/2026-08-17_181954'))->toBeTrue();
});

test('a real object key underneath <prefix>/daily/<timestamp>/ is safe', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('estore-backups/daily/2026-08-17_181954/central.sql.gz'))->toBeTrue();
    expect($guard->isSafeBackupKey('estore-backups/daily/2026-08-17_181954/tenants/pilot-smoke.sql.gz'))->toBeTrue();
});

test('a key outside the configured prefix is rejected', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('some-other-prefix/daily/2026-08-17_181954/central.sql.gz'))->toBeFalse();
});

test('the bare prefix itself, or prefix/daily with no timestamp, is rejected - never "delete everything"', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('estore-backups'))->toBeFalse();
    expect($guard->isSafeBackupKey('estore-backups/daily'))->toBeFalse();
    expect($guard->isSafeBackupKey('estore-backups/daily/'))->toBeFalse();
});

test('a malformed timestamp segment is rejected, not loosely pattern-matched', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('estore-backups/daily/not-a-timestamp/central.sql.gz'))->toBeFalse();
    expect($guard->isSafeBackupKey('estore-backups/daily/2026-08-17/central.sql.gz'))->toBeFalse();
});

test('a completely unrelated bucket object (no relation to this prefix at all) is rejected', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->isSafeBackupKey('some-unrelated-customer-upload.jpg'))->toBeFalse();
    expect($guard->isSafeBackupKey('other-app/config/settings.json'))->toBeFalse();
});

test('assertSafeBackupKey throws for an unsafe key and does not throw for a safe one', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect(fn () => $guard->assertSafeBackupKey('estore-backups/daily'))
        ->toThrow(RuntimeException::class);

    $guard->assertSafeBackupKey('estore-backups/daily/2026-08-17_181954/manifest.json');
    expect(true)->toBeTrue(); // reaching here means no exception was thrown
});

test('timestampFromKey extracts the timestamp from a safe key and returns null for an unsafe one', function () {
    $guard = new OffsiteKeyGuard('estore-backups');

    expect($guard->timestampFromKey('estore-backups/daily/2026-08-17_181954'))->toBe('2026-08-17_181954');
    expect($guard->timestampFromKey('estore-backups/daily/2026-08-17_181954/central.sql.gz'))->toBe('2026-08-17_181954');
    expect($guard->timestampFromKey('some-other-prefix/daily/2026-08-17_181954'))->toBeNull();
    expect($guard->timestampFromKey('estore-backups/daily'))->toBeNull();
});

test('a prefix with leading/trailing slashes in config is normalized consistently', function () {
    $guard = new OffsiteKeyGuard('/estore-backups/');

    expect($guard->isSafeBackupKey('estore-backups/daily/2026-08-17_181954/central.sql.gz'))->toBeTrue();
});
