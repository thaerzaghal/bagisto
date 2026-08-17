<?php

declare(strict_types=1);

namespace Platform\Backup\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use Platform\Backup\Exceptions\BackupFailedException;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Throwable;

/**
 * TASK-MVP-003A. Orchestrates one full backup run: central DB dump, every
 * tenant's DB dump, every tenant's persistent files, a manifest with
 * checksums - then finalizes atomically. See `platform:backup:run`'s own
 * docblock for the full atomic success/failure contract this class
 * implements (`.in-progress` -> `.failed` or a plain, finalized timestamp
 * directory - never anything in between that could look like a success).
 *
 * TENANT DISCOVERY (task section 4): the authoritative source is the
 * central `tenants` table (`Tenant::query()`), never a hand-maintained
 * list - `Deleted` tenants are excluded outright (their physical database
 * is already confirmed dropped by `Platform\Tenancy\Services\
 * TenantLifecycle`, nothing to dump). Every other status is ATTEMPTED, not
 * assumed reachable - `tenantDatabaseAccessible()` does a real, cheap
 * connection check using the tenant's OWN generated credentials before any
 * dump is attempted, so a Pending/Provisioning/Failed tenant with no
 * physical database yet (or a partially-provisioned one) is skipped and
 * recorded in `skipped_tenants`, never a hard failure of the whole run.
 * EMERGENCY FALLBACK, if the central registry itself is ever unavailable
 * during disaster recovery: every physical tenant database can still be
 * enumerated directly via `SHOW DATABASES LIKE 'tenant%'` against the
 * central connection's OWN root/admin credentials (not this class's normal
 * path, which uses each tenant's own scoped credentials - see
 * docs/implementation/backup-and-recovery.md's runbook for the full manual
 * sequence).
 *
 * CREDENTIALS NEVER APPEAR AS A COMMAND-LINE ARGUMENT (task section 5):
 * mysqldump reads them from a per-run, mode-0600 `--defaults-extra-file`
 * temp file (deleted immediately after, in a `finally`) - `ps`/process
 * listings on the host never show a tenant's (or the central app's own)
 * database password.
 *
 * FAILURE PROPAGATION THROUGH A SHELL PIPE (task section 5): the
 * mysqldump-into-gzip command explicitly runs under `bash -c 'set -o
 * pipefail; ...'`, not the default `/bin/sh` (dash on this project's Debian
 * base image, which does not support `pipefail` at all) - without it, a
 * failing mysqldump piped into a succeeding gzip would report the
 * PIPELINE's exit code as gzip's (0), silently producing a "successful"
 * empty/partial dump. Verified by deliberately reproducing this exact
 * false-positive locally (a mysqldump command with a wrong password piped
 * into gzip, checked WITHOUT pipefail first) before writing this comment.
 */
class BackupRunner
{
    public function __construct(protected BackupPathGuard $guard)
    {
    }

    /**
     * @return array<string, mixed> the finalized manifest
     *
     * @throws BackupFailedException
     */
    public function run(): array
    {
        $timestamp = now()->format('Y-m-d_His');
        $dailyRoot = rtrim($this->guard->root(), '/').'/daily';
        $inProgressDir = "{$dailyRoot}/{$timestamp}.in-progress";

        File::ensureDirectoryExists($inProgressDir, 0750);
        File::ensureDirectoryExists("{$inProgressDir}/tenants", 0750);
        File::ensureDirectoryExists("{$inProgressDir}/tenant-files", 0750);

        $manifest = [
            'started_at' => now()->toIso8601String(),
            'app_commit' => $this->appCommit(),
            'status' => 'running',
        ];

        try {
            $manifest['central'] = $this->dumpCentral($inProgressDir);

            [$tenants, $skipped] = $this->dumpAllTenants($inProgressDir);
            $manifest['tenants'] = $tenants;
            $manifest['skipped_tenants'] = $skipped;
            $manifest['tenant_count'] = count($tenants);

            $manifest['finished_at'] = now()->toIso8601String();
            $manifest['status'] = 'success';
        } catch (Throwable $e) {
            $manifest['status'] = 'failed';
            $manifest['error'] = $e->getMessage();
            $manifest['finished_at'] = now()->toIso8601String();

            BackupManifest::write("{$inProgressDir}/manifest.json", $manifest);
            File::move($inProgressDir, "{$dailyRoot}/{$timestamp}.failed");

            throw new BackupFailedException("Backup run [{$timestamp}] failed: ".$e->getMessage(), previous: $e);
        }

        BackupManifest::write("{$inProgressDir}/manifest.json", $manifest);

        // Finalize LAST, only after every step above and the manifest write
        // itself succeeded - this rename is the one thing that turns an
        // in-progress directory into something platform:backup:cleanup's
        // retention selection (and platform:production:check's health
        // check) will ever recognize as a real, successful backup.
        $finalDir = "{$dailyRoot}/{$timestamp}";
        File::move($inProgressDir, $finalDir);

        return $manifest;
    }

    protected function dumpCentral(string $dir): array
    {
        $conn = (array) config('database.connections.mysql');
        $destFile = "{$dir}/central.sql.gz";

        $this->mysqldumpToGzip(
            (string) $conn['host'],
            (string) $conn['port'],
            (string) $conn['database'],
            (string) $conn['username'],
            $conn['password'] === null ? null : (string) $conn['password'],
            $destFile
        );

        return [
            'database' => $conn['database'],
            'file' => 'central.sql.gz',
            'size_bytes' => filesize($destFile),
            'sha256' => BackupManifest::sha256($destFile),
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, string>>}
     */
    protected function dumpAllTenants(string $dir): array
    {
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port');
        $suffixBase = (string) config('tenancy.filesystem.suffix_base');

        $results = [];
        $skipped = [];

        foreach (Tenant::query()->where('status', '!=', TenantStatus::Deleted->value)->get() as $tenant) {
            $dbName = $tenant->database()->getName();
            $username = $tenant->database()->getUsername();
            $password = $tenant->database()->getPassword();

            if ($dbName === null || $username === null || ! $this->tenantDatabaseAccessible($host, $port, $dbName, $username, $password)) {
                $skipped[] = [
                    'id' => $tenant->getTenantKey(),
                    'status' => $tenant->status?->value ?? 'unknown',
                    'reason' => 'no accessible physical database (not yet provisioned, or provisioning failed before the database was created)',
                ];

                continue;
            }

            $dbDestFile = "{$dir}/tenants/{$tenant->getTenantKey()}.sql.gz";
            $this->mysqldumpToGzip($host, $port, $dbName, $username, $password, $dbDestFile);

            $appArchive = $this->tarDirectory(
                storage_path($suffixBase.$tenant->getTenantKey().'/app'),
                "{$dir}/tenant-files/{$tenant->getTenantKey()}-app.tar.gz"
            );

            $privateArchive = $this->tarDirectory(
                storage_path('app/private/'.$suffixBase.$tenant->getTenantKey()),
                "{$dir}/tenant-files/{$tenant->getTenantKey()}-private.tar.gz"
            );

            $results[] = [
                'id' => $tenant->getTenantKey(),
                'status' => $tenant->status?->value ?? 'unknown',
                'database' => $dbName,
                'db_file' => "tenants/{$tenant->getTenantKey()}.sql.gz",
                'db_size_bytes' => filesize($dbDestFile),
                'db_sha256' => BackupManifest::sha256($dbDestFile),
                'files_app' => $appArchive,
                'files_private' => $privateArchive,
            ];
        }

        return [$results, $skipped];
    }

    /**
     * A real, cheap connection check using the tenant's OWN scoped
     * credentials - not `SHOW DATABASES` against the central app user
     * (which, by design - docs/architecture/production-deployment.md
     * section F - has no privilege on any tenant database at all, so that
     * query would never show a tenant DB regardless of whether it exists).
     * Confirms both "does the database exist" and "can we actually dump it
     * with these exact credentials" in one step.
     */
    protected function tenantDatabaseAccessible(string $host, string $port, string $dbName, string $username, ?string $password): bool
    {
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                $username,
                $password ?? '',
                [PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    protected function mysqldumpToGzip(string $host, string $port, string $database, string $username, ?string $password, string $destFile): void
    {
        $optionFile = tempnam(sys_get_temp_dir(), 'platform-backup-');
        $contents = "[client]\nuser={$username}\n";

        if ($password !== null && $password !== '') {
            $contents .= "password={$password}\n";
        }

        file_put_contents($optionFile, $contents);
        chmod($optionFile, 0600);

        try {
            $binary = (string) config('platform-backup.mysqldump_binary');

            // --skip-ssl (task section: real pilot-server verification,
            // TASK-MVP-003A): verified empirically against the real pilot
            // server - the production image's `default-mysql-client`
            // package resolves to the MariaDB client (11.8.6, Debian
            // trixie), whose CLI tools default `ssl-verify-server-cert=ON`
            // and reject MySQL 8's auto-generated self-signed server
            // certificate outright ("TLS/SSL error: self-signed certificate
            // in certificate chain") even in the client's nominally
            // encryption-optional mode - unlike this project's own PDO
            // connection (used elsewhere, e.g. tenantDatabaseAccessible()
            // below), which does not enable SSL unless explicitly
            // configured and already connects to this same MySQL host
            // without incident. The dump traverses only the trusted
            // internal Docker bridge network (container-to-container, this
            // host's own `internal` compose network - never the public
            // internet), so disabling verification here does not weaken
            // this project's actual threat model.
            $dumpCommand = sprintf(
                '%s --defaults-extra-file=%s --host=%s --port=%s --single-transaction --routines --triggers --events --default-character-set=utf8mb4 --no-tablespaces --skip-ssl %s | gzip -9 > %s',
                escapeshellcmd($binary),
                escapeshellarg($optionFile),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($database),
                escapeshellarg($destFile)
            );

            // bash, not /bin/sh - see this class's own docblock ("FAILURE
            // PROPAGATION THROUGH A SHELL PIPE") for why pipefail is
            // mandatory here and why dash (this image's /bin/sh) can't
            // provide it.
            $result = Process::timeout(600)->run(['bash', '-c', 'set -o pipefail; '.$dumpCommand]);

            if (! $result->successful()) {
                @unlink($destFile);

                throw new BackupFailedException("mysqldump failed for database [{$database}]: ".$result->errorOutput());
            }

            // task section 15: dumps contain real customer/order PII - never
            // world-readable. gzip's own default mode (governed by the
            // process umask, typically 644) is NOT sufficient on its own.
            @chmod($destFile, 0640);
        } finally {
            @unlink($optionFile);
        }
    }

    protected function tarDirectory(string $sourceDir, string $destFile): ?array
    {
        if (! is_dir($sourceDir)) {
            return null;
        }

        $binary = (string) config('platform-backup.tar_binary');
        $parent = dirname($sourceDir);
        $base = basename($sourceDir);

        $result = Process::timeout(600)->run([$binary, '-czf', $destFile, '-C', $parent, $base]);

        if (! $result->successful()) {
            @unlink($destFile);

            throw new BackupFailedException("tar failed for [{$sourceDir}]: ".$result->errorOutput());
        }

        @chmod($destFile, 0640); // task section 15 - never world-readable, see mysqldumpToGzip()'s identical note

        return [
            'source' => $sourceDir,
            'file' => basename($destFile),
            'size_bytes' => filesize($destFile),
            'sha256' => BackupManifest::sha256($destFile),
        ];
    }

    /**
     * Best-effort only (task section 8, "if practical") - the deployed
     * image deliberately excludes .git (.dockerignore, smaller/faster
     * builds), so there is no git metadata to read INSIDE the running
     * container. A future deploy step could write this file; until then,
     * the manifest simply omits a commit value rather than guessing one.
     */
    protected function appCommit(): ?string
    {
        $marker = base_path('APP_COMMIT');

        return is_file($marker) ? trim((string) file_get_contents($marker)) : null;
    }
}
