<?php

/**
 * TASK-MVP-005. `Platform\Backup\Services\OffsiteSyncRunner` and
 * `platform:backup:sync-offsite`/`platform:backup:cleanup-offsite` against a
 * FAKE, in-memory `OffsiteBackupDestination` - real Cloudflare R2/network
 * access is a manual integration proof (task section 21: "Live R2 testing is
 * a manual integration proof... Do not make normal Platform regression
 * depend on live Cloudflare internet access"), the same accepted-exception
 * pattern this project already uses for the Stripe SDK's own official test
 * seam (`FakeStripeHttpClientForBillingTest` etc.) - R2/S3 is EXTERNAL
 * infrastructure, not this project's own database/filesystem/cache/queue.
 *
 * A real, on-disk, fabricated finalized backup directory (matching
 * `BackupRunner::run()`'s own manifest.json shape exactly) is used for every
 * test - `OffsiteSyncRunner` itself is never mocked, only the remote
 * destination it talks to.
 */

use Illuminate\Support\Facades\File;
use Platform\Backup\Contracts\OffsiteBackupDestination;
use Platform\Backup\Exceptions\OffsiteSyncFailedException;
use Platform\Backup\Services\BackupManifest;
use Platform\Backup\Services\OffsiteSyncRunner;
use Tests\TestCase;

uses(TestCase::class);

/**
 * In-memory fake - stores uploaded bytes keyed by remote key, supports the
 * exact same contract the real S3-compatible destination implements.
 * `$uploadCallCount`/`$failUploadForKey` let tests assert idempotency (no
 * re-upload when already present with matching size) and failure semantics
 * without any real network dependency.
 */
class FakeOffsiteBackupDestination implements OffsiteBackupDestination
{
    public array $objects = []; // remoteKey => raw bytes

    public array $uploadCallLog = [];

    public ?string $failUploadForKeyContaining = null;

    public ?string $corruptOnDownloadForKeyContaining = null;

    public function upload(string $localPath, string $remoteKey): void
    {
        $this->uploadCallLog[] = $remoteKey;

        if ($this->failUploadForKeyContaining !== null && str_contains($remoteKey, $this->failUploadForKeyContaining)) {
            throw new RuntimeException("Simulated upload failure for {$remoteKey}");
        }

        $this->objects[$remoteKey] = file_get_contents($localPath);
    }

    public function download(string $remoteKey, string $localPath): void
    {
        if (! isset($this->objects[$remoteKey])) {
            throw new RuntimeException("Fake: remote object not found: {$remoteKey}");
        }

        File::ensureDirectoryExists(dirname($localPath));

        $bytes = $this->objects[$remoteKey];

        if ($this->corruptOnDownloadForKeyContaining !== null && str_contains($remoteKey, $this->corruptOnDownloadForKeyContaining)) {
            $bytes .= 'CORRUPTED';
        }

        file_put_contents($localPath, $bytes);
    }

    public function exists(string $remoteKey): bool
    {
        return isset($this->objects[$remoteKey]);
    }

    public function size(string $remoteKey): ?int
    {
        return isset($this->objects[$remoteKey]) ? strlen($this->objects[$remoteKey]) : null;
    }

    public function delete(string $remoteKey): void
    {
        unset($this->objects[$remoteKey]);
    }

    public function listKeysWithPrefix(string $prefix): array
    {
        return array_values(array_filter(
            array_keys($this->objects),
            fn (string $key): bool => str_starts_with($key, $prefix)
        ));
    }
}

/**
 * Fabricates a real, on-disk finalized local backup directory matching
 * BackupRunner::run()'s own shape/manifest exactly, WITHOUT running any
 * real mysqldump/tar - small fixed byte strings stand in for the real
 * dump/archive content, real SHA-256 checksums are computed over them
 * (never fabricated), exactly like the real manifest would contain.
 */
function makeFakeFinalizedBackup(string $root, string $timestamp): void
{
    $dir = "{$root}/daily/{$timestamp}";
    File::ensureDirectoryExists("{$dir}/tenants");
    File::ensureDirectoryExists("{$dir}/tenant-files");

    file_put_contents("{$dir}/central.sql.gz", 'FAKE-CENTRAL-DUMP-CONTENT');
    file_put_contents("{$dir}/tenants/pilot-smoke.sql.gz", 'FAKE-TENANT-DUMP-CONTENT');
    file_put_contents("{$dir}/tenant-files/pilot-smoke-app.tar.gz", 'FAKE-TENANT-FILES-CONTENT');

    $manifest = [
        'started_at' => now()->toIso8601String(),
        'status' => 'success',
        'central' => [
            'database' => 'bagisto_central',
            'file' => 'central.sql.gz',
            'size_bytes' => filesize("{$dir}/central.sql.gz"),
            'sha256' => BackupManifest::sha256("{$dir}/central.sql.gz"),
        ],
        'tenants' => [
            [
                'id' => 'pilot-smoke',
                'status' => 'ready',
                'database' => 'tenantpilot-smoke',
                'db_file' => 'tenants/pilot-smoke.sql.gz',
                'db_size_bytes' => filesize("{$dir}/tenants/pilot-smoke.sql.gz"),
                'db_sha256' => BackupManifest::sha256("{$dir}/tenants/pilot-smoke.sql.gz"),
                'files_app' => [
                    'source' => '/fake/source',
                    'file' => 'pilot-smoke-app.tar.gz',
                    'size_bytes' => filesize("{$dir}/tenant-files/pilot-smoke-app.tar.gz"),
                    'sha256' => BackupManifest::sha256("{$dir}/tenant-files/pilot-smoke-app.tar.gz"),
                ],
                'files_private' => null,
            ],
        ],
        'tenant_count' => 1,
        'skipped_tenants' => [],
        'finished_at' => now()->toIso8601String(),
    ];

    BackupManifest::write("{$dir}/manifest.json", $manifest);
}

function offsiteTestScratchRoot(): string
{
    return sys_get_temp_dir().'/offsite-sync-test-'.bin2hex(random_bytes(8));
}

beforeEach(function () {
    $this->scratchRoot = offsiteTestScratchRoot();
    config(['platform-backup.root' => $this->scratchRoot]);
    config(['platform-backup.offsite.prefix' => 'estore-backups']);

    $this->fake = new FakeOffsiteBackupDestination;
    $this->app->instance(OffsiteBackupDestination::class, $this->fake);
});

afterEach(function () {
    File::deleteDirectory($this->scratchRoot);
});

test('offsite sync disabled: reports disabled, writes a status file, never touches the destination', function () {
    config(['platform-backup.offsite.enabled' => false]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    $result = app(OffsiteSyncRunner::class)->sync('2026-08-17_181954');

    expect($result['status'])->toBe('disabled');
    expect($this->fake->uploadCallLog)->toBeEmpty();
    expect(is_file("{$this->scratchRoot}/daily/2026-08-17_181954.offsite-status.json"))->toBeTrue();
});

test('offsite sync enabled: uploads every manifest artifact and verifies each via a real checksum round trip', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    $result = app(OffsiteSyncRunner::class)->sync('2026-08-17_181954');

    expect($result['status'])->toBe('success');
    expect($result['object_count'])->toBe(4); // central + tenant db + tenant files + manifest.json
    expect($result['remote_path'])->toBe('estore-backups/daily/2026-08-17_181954');

    foreach ($result['objects'] as $object) {
        if ($object['file'] !== 'manifest.json') {
            expect($object['sha256_verified'])->not->toBeNull();
        }
    }

    expect($this->fake->exists('estore-backups/daily/2026-08-17_181954/central.sql.gz'))->toBeTrue();
    expect($this->fake->exists('estore-backups/daily/2026-08-17_181954/tenants/pilot-smoke.sql.gz'))->toBeTrue();
    expect($this->fake->exists('estore-backups/daily/2026-08-17_181954/tenant-files/pilot-smoke-app.tar.gz'))->toBeTrue();
    expect($this->fake->exists('estore-backups/daily/2026-08-17_181954/manifest.json'))->toBeTrue();
});

test('syncing the same finalized backup twice is idempotent - the second run does not re-upload', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    app(OffsiteSyncRunner::class)->sync('2026-08-17_181954');
    $firstUploadCount = count($this->fake->uploadCallLog);
    expect($firstUploadCount)->toBe(4);

    app(OffsiteSyncRunner::class)->sync('2026-08-17_181954');

    // No new uploads - every object already exists remotely with a matching size.
    expect(count($this->fake->uploadCallLog))->toBe($firstUploadCount);
});

test('a partial prior failure can be retried and only re-uploads what is actually missing', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    // Simulate the tenant DB file having already been uploaded by a prior,
    // partially-successful attempt (e.g. central.sql.gz uploaded, then the
    // process was killed) - only the missing objects should upload now.
    $this->fake->objects['estore-backups/daily/2026-08-17_181954/tenants/pilot-smoke.sql.gz'] =
        file_get_contents("{$this->scratchRoot}/daily/2026-08-17_181954/tenants/pilot-smoke.sql.gz");

    $result = app(OffsiteSyncRunner::class)->sync('2026-08-17_181954');

    expect($result['status'])->toBe('success');
    expect($this->fake->uploadCallLog)->not->toContain('estore-backups/daily/2026-08-17_181954/tenants/pilot-smoke.sql.gz');
    expect($this->fake->uploadCallLog)->toContain('estore-backups/daily/2026-08-17_181954/central.sql.gz');
});

test('an upload failure is reported as FAILED, the local backup is untouched, and the status file records the failure', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    $this->fake->failUploadForKeyContaining = 'central.sql.gz';

    expect(fn () => app(OffsiteSyncRunner::class)->sync('2026-08-17_181954'))
        ->toThrow(OffsiteSyncFailedException::class);

    // The local, already-finalized backup directory is completely untouched.
    expect(is_file("{$this->scratchRoot}/daily/2026-08-17_181954/central.sql.gz"))->toBeTrue();
    expect(is_file("{$this->scratchRoot}/daily/2026-08-17_181954/manifest.json"))->toBeTrue();

    $status = json_decode(file_get_contents("{$this->scratchRoot}/daily/2026-08-17_181954.offsite-status.json"), true);
    expect($status['status'])->toBe('failed');
    expect($status['error'])->toContain('central.sql.gz');
});

test('a checksum mismatch on verification is treated as a failure, not a silent success', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    $this->fake->corruptOnDownloadForKeyContaining = 'central.sql.gz';

    expect(fn () => app(OffsiteSyncRunner::class)->sync('2026-08-17_181954'))
        ->toThrow(OffsiteSyncFailedException::class, 'Checksum mismatch');

    $status = json_decode(file_get_contents("{$this->scratchRoot}/daily/2026-08-17_181954.offsite-status.json"), true);
    expect($status['status'])->toBe('failed');
});

test('syncing a non-existent local backup fails clearly rather than silently no-op-ing', function () {
    config(['platform-backup.offsite.enabled' => true]);

    expect(fn () => app(OffsiteSyncRunner::class)->sync('2099-01-01_000000'))
        ->toThrow(OffsiteSyncFailedException::class, 'No manifest found');
});

test('syncing a backup whose own manifest is not status=success is refused', function () {
    config(['platform-backup.offsite.enabled' => true]);

    // A directory at the finalized-name path whose OWN manifest records a
    // failure - defense in depth beyond BackupRunner's own .failed-suffix
    // convention, in case a manifest is ever inspected/copied manually.
    File::ensureDirectoryExists("{$this->scratchRoot}/daily/2026-08-17_190000");
    BackupManifest::write("{$this->scratchRoot}/daily/2026-08-17_190000/manifest.json", ['status' => 'failed', 'error' => 'simulated']);

    expect(fn () => app(OffsiteSyncRunner::class)->sync('2026-08-17_190000'))
        ->toThrow(OffsiteSyncFailedException::class, 'not a successful');
});

test('platform:backup:sync-offsite defaults to the newest finalized local backup when none is specified', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-16_030000');
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_030000');

    $this->artisan('platform:backup:sync-offsite')->assertSuccessful();

    expect($this->fake->exists('estore-backups/daily/2026-08-17_030000/central.sql.gz'))->toBeTrue();
    expect($this->fake->exists('estore-backups/daily/2026-08-16_030000/central.sql.gz'))->toBeFalse();
});

test('platform:backup:sync-offsite exits non-zero on a real failure, for cron detection', function () {
    config(['platform-backup.offsite.enabled' => true]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');
    $this->fake->failUploadForKeyContaining = 'central.sql.gz';

    $this->artisan('platform:backup:sync-offsite')->assertFailed();
});

test('platform:backup:sync-offsite reports DISABLED and still exits successfully', function () {
    config(['platform-backup.offsite.enabled' => false]);
    makeFakeFinalizedBackup($this->scratchRoot, '2026-08-17_181954');

    $this->artisan('platform:backup:sync-offsite')->assertSuccessful();
});

test('platform:backup:cleanup-offsite deletes offsite backups older than the offsite retention window, keeps the newest', function () {
    config(['platform-backup.offsite.enabled' => true]);
    config(['platform-backup.offsite.retention_days' => 7]);

    // Directly populate the fake destination - this command works purely
    // off remote listing, no local directories needed for it to function.
    // 2026-08-01 is 16 days old (delete), 2026-08-15 is 2 days old (keep,
    // within the 7-day window), 2026-08-17 is today/newest (always keep).
    foreach (['2026-08-01_030000', '2026-08-15_030000', '2026-08-17_030000'] as $timestamp) {
        $this->fake->objects["estore-backups/daily/{$timestamp}/central.sql.gz"] = 'x';
        $this->fake->objects["estore-backups/daily/{$timestamp}/manifest.json"] = 'x';
    }

    $this->travelTo(new DateTime('2026-08-17 12:00:00'));

    $this->artisan('platform:backup:cleanup-offsite')->assertSuccessful();

    expect($this->fake->listKeysWithPrefix('estore-backups/daily/2026-08-01_030000'))->toBeEmpty();
    expect($this->fake->listKeysWithPrefix('estore-backups/daily/2026-08-15_030000'))->not->toBeEmpty();
    expect($this->fake->listKeysWithPrefix('estore-backups/daily/2026-08-17_030000'))->not->toBeEmpty();

    $this->travelBack();
});

test('platform:backup:cleanup-offsite --dry-run deletes nothing', function () {
    config(['platform-backup.offsite.enabled' => true]);
    config(['platform-backup.offsite.retention_days' => 7]);

    $this->fake->objects['estore-backups/daily/2026-08-01_030000/central.sql.gz'] = 'x';
    $this->fake->objects['estore-backups/daily/2026-08-17_030000/central.sql.gz'] = 'x';

    $this->travelTo(new DateTime('2026-08-17 12:00:00'));

    $this->artisan('platform:backup:cleanup-offsite', ['--dry-run' => true])->assertSuccessful();

    expect($this->fake->listKeysWithPrefix('estore-backups/daily/2026-08-01_030000'))->not->toBeEmpty();

    $this->travelBack();
});

test('platform:backup:cleanup-offsite disabled: no-op, never lists or deletes anything', function () {
    config(['platform-backup.offsite.enabled' => false]);
    $this->fake->objects['estore-backups/daily/2026-08-01_030000/central.sql.gz'] = 'x';

    $this->artisan('platform:backup:cleanup-offsite')->assertSuccessful();

    expect($this->fake->exists('estore-backups/daily/2026-08-01_030000/central.sql.gz'))->toBeTrue();
});
