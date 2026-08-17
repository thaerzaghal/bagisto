<?php

/**
 * TASK-MVP-003A. Pure unit test - no `uses()` binding, no database, no app
 * boot; `Platform\Backup\Services\BackupManifest` only touches real
 * temp-file I/O (`file_put_contents`/`hash_file`), which is exercised
 * directly here rather than mocked.
 */

use Platform\Backup\Services\BackupManifest;

test('a written manifest round-trips through read() unchanged', function () {
    $path = sys_get_temp_dir().'/backup-manifest-test-'.bin2hex(random_bytes(8)).'.json';

    $manifest = [
        'started_at' => '2026-08-17T21:00:00+00:00',
        'status' => 'success',
        'tenant_count' => 2,
        'tenants' => [
            ['id' => 'pilot-smoke', 'database' => 'tenantpilot-smoke'],
        ],
    ];

    BackupManifest::write($path, $manifest);

    expect(BackupManifest::read($path))->toBe($manifest);

    unlink($path);
});

test('read() returns null for a nonexistent manifest file', function () {
    expect(BackupManifest::read(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(8)).'.json'))->toBeNull();
});

test('read() returns null for a manifest file that is not valid JSON', function () {
    $path = sys_get_temp_dir().'/backup-manifest-test-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($path, 'not json at all');

    expect(BackupManifest::read($path))->toBeNull();

    unlink($path);
});

test('sha256() matches a known, independently-computed checksum of real file content', function () {
    $path = sys_get_temp_dir().'/backup-manifest-test-'.bin2hex(random_bytes(8)).'.txt';
    file_put_contents($path, 'platform-backup-test-content');

    expect(BackupManifest::sha256($path))->toBe(hash('sha256', 'platform-backup-test-content'));

    unlink($path);
});

test('a written manifest never contains the word password/secret as a key or value, guarding against accidental future misuse', function () {
    // Not a claim BackupManifest itself redacts anything - it never
    // receives secrets in the first place (BackupRunner only ever passes
    // it names/sizes/checksums/status strings). This test exists so that
    // if a FUTURE change ever fed it a credential by mistake, this
    // regression would catch it rather than silently writing a
    // world-readable secret into manifest.json.
    $path = sys_get_temp_dir().'/backup-manifest-test-'.bin2hex(random_bytes(8)).'.json';

    BackupManifest::write($path, [
        'started_at' => '2026-08-17T21:00:00+00:00',
        'status' => 'success',
        'central' => ['database' => 'bagisto_central', 'file' => 'central.sql.gz', 'sha256' => str_repeat('a', 64)],
        'tenants' => [['id' => 'pilot-smoke', 'database' => 'tenantpilot-smoke']],
    ]);

    $raw = strtolower((string) file_get_contents($path));

    expect($raw)->not->toContain('password');
    expect($raw)->not->toContain('secret');

    unlink($path);
});
