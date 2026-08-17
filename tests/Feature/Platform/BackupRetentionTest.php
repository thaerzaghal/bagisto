<?php

/**
 * TASK-MVP-003A. Pure unit test - no `uses()` binding, no database, no app
 * boot; `Platform\Backup\Services\BackupRetention` takes plain strings and
 * a `DateTimeImmutable` and returns plain strings. Backup directory names
 * are fabricated directly (`Y-m-d_His`, matching `BackupRunner::run()`'s
 * own format) - no real directories/backups needed to prove the selection
 * MATH is correct.
 */

use Platform\Backup\Services\BackupRetention;

test('backups older than the retention window are selected for deletion', function () {
    $retention = new BackupRetention(retentionDays: 7);
    $now = new DateTimeImmutable('2026-08-17 12:00:00');

    $names = [
        '2026-08-01_030000', // 16 days old - delete
        '2026-08-09_030000', // 8 days old - delete
        '2026-08-11_030000', // 6 days old - keep
        '2026-08-17_030000', // today, newest - keep
    ];

    $deleted = $retention->selectForDeletion($names, $now);

    expect($deleted)->toBe(['2026-08-01_030000', '2026-08-09_030000']);
});

test('the single newest backup is NEVER selected for deletion, even if its own timestamp is older than the cutoff', function () {
    $retention = new BackupRetention(retentionDays: 7);
    $now = new DateTimeImmutable('2026-08-17 12:00:00');

    // Simulates a stalled backup schedule - the only backup that exists is
    // already stale, but deleting it would leave zero backups. Must never
    // happen regardless of how old it is.
    $names = ['2026-08-01_030000'];

    expect($retention->selectForDeletion($names, $now))->toBe([]);
});

test('with only two backups, the older one is deleted only once it crosses the retention window, never before', function () {
    $retention = new BackupRetention(retentionDays: 7);
    $now = new DateTimeImmutable('2026-08-17 12:00:00');

    $namesNotYetStale = ['2026-08-12_030000', '2026-08-17_030000'];
    expect($retention->selectForDeletion($namesNotYetStale, $now))->toBe([]);

    $namesStale = ['2026-08-01_030000', '2026-08-17_030000'];
    expect($retention->selectForDeletion($namesStale, $now))->toBe(['2026-08-01_030000']);
});

test('retentionDays=0 still never deletes the newest backup', function () {
    $retention = new BackupRetention(retentionDays: 0);
    $now = new DateTimeImmutable('2026-08-17 12:00:00');

    $names = ['2026-08-16_030000', '2026-08-17_030000'];

    // Only the newest is ever protected - with retentionDays=0 the cutoff
    // is "now", so the OLDER one is deletable even though barely a day old.
    // This is deliberate (an operator who sets 0 is asking for aggressive
    // cleanup) - the invariant this test actually protects is narrower:
    // the newest one specifically is never in the result.
    expect($retention->selectForDeletion($names, $now))->not->toContain('2026-08-17_030000');
});

test('a single backup name never selects itself for deletion', function () {
    $retention = new BackupRetention(retentionDays: 1);
    $now = new DateTimeImmutable('2030-01-01 00:00:00'); // far future, would otherwise be "stale"

    expect($retention->selectForDeletion(['2026-08-01_030000'], $now))->toBe([]);
});

test('an empty backup list selects nothing', function () {
    $retention = new BackupRetention(retentionDays: 7);

    expect($retention->selectForDeletion([], new DateTimeImmutable()))->toBe([]);
});

test('duplicate names are de-duplicated before selection', function () {
    $retention = new BackupRetention(retentionDays: 7);
    $now = new DateTimeImmutable('2026-08-17 12:00:00');

    $names = ['2026-08-01_030000', '2026-08-01_030000', '2026-08-17_030000'];

    expect($retention->selectForDeletion($names, $now))->toBe(['2026-08-01_030000']);
});
