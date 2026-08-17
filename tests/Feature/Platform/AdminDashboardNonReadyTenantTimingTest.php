<?php

/**
 * TASK-MVP-003B (RISK_REGISTER.md R58). The permanent, real-subprocess
 * regression test for R58 - the follow-up to R57's own
 * `AdminDashboardTenancyTimingTest.php` (kept, unchanged, as the Ready-
 * tenant regression proof; this file complements it for non-Ready
 * tenants).
 *
 * WHY REAL SUBPROCESS, NOT PEST: confirmed empirically (not assumed, and
 * not merely inherited from R57's own precedent) that Pest's in-process
 * `$this->get()` does NOT reliably exercise `Platform\Tenancy\Providers\
 * TenancyServiceProvider`'s early `RouteMatched` listener against an
 * eager-crash-prone route at all - see `TenantEarlyRejectionTest.php`'s
 * own docblock for the specific reproduction.
 *
 * WHY EACH SCENARIO GETS ITS OWN `php artisan serve` PROCESS, NOT ONE
 * SHARED ACROSS ALL THREE (RISK_REGISTER.md R61): found live, during this
 * test's own development, that `Illuminate\Foundation\Http\
 * Kernel::terminate()` UNCONDITIONALLY re-runs `gatherRouteMiddleware()`
 * (to discover any `TerminableMiddleware`) - completely independently of
 * this task's own `RouteMatched`-level fix, AFTER the real response has
 * already been sent to the client - which re-triggers the identical
 * eager-controller-construction crash for a non-ready tenant (tenancy
 * correctly never initialized, exactly as designed, so this SEPARATE
 * eager probe still hits central). For `php artisan serve` specifically
 * (single-threaded, no per-request process isolation), an uncaught
 * exception at that point was observed to kill the ENTIRE server
 * process, taking down every subsequent request in the same test - not
 * merely a log entry. Confirmed via direct reproduction: a shared-process
 * version of this test failed a SECOND real request with a connection-
 * level failure ('000'), immediately after a FIRST, correctly-423
 * request to the same tenant succeeded. This is a distinct, separately
 * recorded finding (R61) - NOT something R58's own fix could or should
 * address (it lives entirely in Laravel's OWN unconditional termination-
 * phase middleware discovery, not in anything this task's RouteMatched
 * listener touches) - worked around here, not fixed, by giving each
 * scenario its own disposable server process so one scenario's
 * termination-phase crash cannot affect another's.
 *
 * Regression-proven both ways for the actual R58 fix under test: fails
 * (raw 500/connection failure on the FIRST request to a non-ready
 * tenant's eager-crash route) against the pre-R58 code, passes (423/503)
 * with the fix applied - verified via `git stash` during this task's own
 * development.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const R58_TIMING_READY_ID = 'tenant-r58-timing-ready';
const R58_TIMING_SUSPENDED_ID = 'tenant-r58-timing-suspended';
const R58_TIMING_PENDING_ID = 'tenant-r58-timing-pending';

function cleanupR58TimingTenant(string $id): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    $row = $central->table('tenants')->where('id', $id)->first();

    if ($row) {
        $data = json_decode($row->data ?? '{}', true) ?: [];

        if (! empty($data['tenancy_db_username'])) {
            $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
        }
        if (! empty($data['tenancy_db_name'])) {
            $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
        }
    }

    $central->table('domains')->where('tenant_id', $id)->delete();
    $central->table('tenants')->where('id', $id)->delete();
}

/**
 * Starts its own disposable `php artisan serve` process, runs $callback
 * against it, then always tears the process down - see this file's own
 * docblock ("WHY EACH SCENARIO GETS ITS OWN...") for why this is per-
 * scenario rather than shared.
 */
function withFreshServeProcess(callable $callback): mixed
{
    $port = random_int(20000, 65000);
    $base = 'http://127.0.0.1:'.$port;
    $logFile = storage_path('logs/r58-timing-serve-'.$port.'.log');

    $startCommand = sprintf(
        'cd %s && nohup %s artisan serve --host=127.0.0.1 --port=%d < /dev/null > %s 2>&1 & echo $!',
        escapeshellarg(base_path()),
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($logFile)
    );
    $pid = trim((string) shell_exec($startCommand));

    try {
        $ready = false;
        for ($i = 0; $i < 60; $i++) {
            $code = trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" --max-time 15 '.escapeshellarg($base.'/up')));
            if ($code === '200') { $ready = true; break; }
            usleep(500_000);
        }

        if (! $ready) {
            dump('serve_log: '.(is_file($logFile) ? file_get_contents($logFile) : '(no log file)'));
        }
        expect($ready)->toBeTrue('php artisan serve did not become ready in time.');

        return $callback($base);
    } finally {
        if ($pid !== '') {
            shell_exec('kill -9 '.escapeshellarg($pid).' 2>/dev/null');
        }
        shell_exec(sprintf(
            "for p in \$(ls /proc/*/cmdline 2>/dev/null | xargs -n1 dirname 2>/dev/null | xargs -n1 basename 2>/dev/null); do ".
            "if [ -r /proc/\$p/cmdline ]; then cmd=\$(tr '\\0' ' ' < /proc/\$p/cmdline 2>/dev/null); ".
            "case \"\$cmd\" in *%d*) kill -9 \$p 2>/dev/null ;; esac; fi; done",
            $port
        ));
        @unlink($logFile);
    }
}

function curlStatus(string $base, string $host, string $path): string
{
    return trim((string) shell_exec(sprintf(
        'curl -s -o /dev/null -w "%%{http_code}" --max-time 30 -H %s %s',
        escapeshellarg('Host: '.$host),
        escapeshellarg($base.$path)
    )));
}

beforeEach(function () {
    cleanupR58TimingTenant(R58_TIMING_READY_ID);
    cleanupR58TimingTenant(R58_TIMING_SUSPENDED_ID);
    cleanupR58TimingTenant(R58_TIMING_PENDING_ID);

    $ready = Tenant::create(['id' => R58_TIMING_READY_ID, 'status' => TenantStatus::Pending]);
    $ready->domains()->create(['domain' => R58_TIMING_READY_ID.'.localhost']);
    app(TenantProvisioner::class)->provision($ready, [
        'name' => 'R58 Timing Ready Owner',
        'email' => 'r58-timing-ready@example.test',
        'password' => 'r58-timing-password-1',
    ]);
    expect($ready->fresh()->status)->toBe(TenantStatus::Ready);

    $suspended = Tenant::create(['id' => R58_TIMING_SUSPENDED_ID, 'status' => TenantStatus::Pending]);
    $suspended->domains()->create(['domain' => R58_TIMING_SUSPENDED_ID.'.localhost']);
    app(TenantProvisioner::class)->provision($suspended, [
        'name' => 'R58 Timing Suspended Owner',
        'email' => 'r58-timing-suspended@example.test',
        'password' => 'r58-timing-password-2',
    ]);
    $suspended->forceFill(['status' => TenantStatus::Suspended])->save();

    // Pending tenant - never provisioned at all, the strongest proof of
    // "no tenant DB access" (there is no database to accidentally touch).
    $pending = Tenant::create(['id' => R58_TIMING_PENDING_ID, 'status' => TenantStatus::Pending]);
    $pending->domains()->create(['domain' => R58_TIMING_PENDING_ID.'.localhost']);
});

afterEach(function () {
    cleanupR58TimingTenant(R58_TIMING_READY_ID);
    cleanupR58TimingTenant(R58_TIMING_SUSPENDED_ID);
    cleanupR58TimingTenant(R58_TIMING_PENDING_ID);
});

test('R57 regression: Ready tenant dashboard/reports still 200 under real HTTP', function () {
    withFreshServeProcess(function (string $base) {
        $cookieJar = storage_path('logs/r58-timing-cookies-ready.txt');

        $loginPage = shell_exec(sprintf(
            'curl -s -c %s --max-time 30 -H %s %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: '.R58_TIMING_READY_ID.'.localhost'),
            escapeshellarg($base.'/admin/login')
        ));
        preg_match('/name="_token" value="([^"]+)"/', (string) $loginPage, $matches);
        $token = $matches[1] ?? null;
        expect($token)->not->toBeNull();

        $loginStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -b %s -c %s --max-time 30 -H %s -X POST %s '.
            '--data-urlencode %s --data-urlencode %s --data-urlencode %s',
            escapeshellarg($cookieJar),
            escapeshellarg($cookieJar),
            escapeshellarg('Host: '.R58_TIMING_READY_ID.'.localhost'),
            escapeshellarg($base.'/admin/login'),
            escapeshellarg('_token='.$token),
            escapeshellarg('email=r58-timing-ready@example.test'),
            escapeshellarg('password=r58-timing-password-1')
        )));
        expect($loginStatus)->toBe('302');

        $dashboardStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -b %s --max-time 30 -H %s %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: '.R58_TIMING_READY_ID.'.localhost'),
            escapeshellarg($base.'/admin/dashboard')
        )));
        expect($dashboardStatus)->toBe('200');

        @unlink($cookieJar);
    });
})->group('real-process');

test('R58 fix: Suspended tenant dashboard/reports return 423 under real HTTP, not a raw crash', function () {
    withFreshServeProcess(function (string $base) {
        expect(curlStatus($base, R58_TIMING_SUSPENDED_ID.'.localhost', '/admin/dashboard'))->toBe('423');
    });

    // A fresh process for the second route too - see this file's own
    // docblock (R61) for why the FIRST request's own termination-phase
    // side effect cannot be allowed to risk the second assertion.
    withFreshServeProcess(function (string $base) {
        expect(curlStatus($base, R58_TIMING_SUSPENDED_ID.'.localhost', '/admin/reporting/sales'))->toBe('423');
    });
})->group('real-process');

test('R58 fix: Pending (never-provisioned) tenant dashboard/reports return 503 under real HTTP, not a raw crash', function () {
    withFreshServeProcess(function (string $base) {
        expect(curlStatus($base, R58_TIMING_PENDING_ID.'.localhost', '/admin/dashboard'))->toBe('503');
    });

    withFreshServeProcess(function (string $base) {
        expect(curlStatus($base, R58_TIMING_PENDING_ID.'.localhost', '/admin/reporting/sales'))->toBe('503');
    });
})->group('real-process');
