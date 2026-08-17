<?php

/**
 * TASK-MVP-004B (RISK_REGISTER.md R57). A permanent regression test for a
 * real production bug: Laravel's own controller-middleware-gathering step
 * (`Illuminate\Routing\Route::controllerMiddleware()`, invoked by
 * `Router::gatherRouteMiddleware()` - BEFORE any route middleware, including
 * tenancy initialization, ever runs) eagerly, wastefully instantiates the
 * matched route's controller purely to inspect its declared middleware. For
 * `Webkul\Admin\Http\Controllers\DashboardController` (which constructor-
 * injects `Webkul\Admin\Helpers\Dashboard`, which constructor-injects
 * `Webkul\Admin\Helpers\Reporting\{Sale,Product,Customer}`, each of whose
 * OWN constructor unconditionally calls `Webkul\Core\Core::getAllChannels()`),
 * that eager, throwaway construction runs a real `channels` query while the
 * database connection is still central - raw 500ing with `SQLSTATE[42S02]:
 * Table 'bagisto_central.channels' doesn't exist` on a Ready tenant's very
 * first real Admin dashboard visit. `Webkul\Admin\Http\Controllers\
 * Reporting\Controller` shares the same root cause (confirmed during the
 * R57 investigation, not fixed separately - `Platform\Tenancy\Providers\
 * TenancyServiceProvider::preInitializeTenancyOnRouteMatch()` protects every
 * route this way, not just the dashboard).
 *
 * WHY A REAL SUBPROCESS, NOT PEST'S IN-PROCESS HTTP TESTING: confirmed
 * empirically during the original investigation that `$this->get()`/
 * `$this->post()` do NOT reproduce this bug at all, regardless of how many
 * simulated "requests" are made within one test - Pest/Laravel's testing
 * kernel does not rebuild the full application container per call the way a
 * genuinely separate PHP-FPM-style process does, which happens to mask the
 * exact registration-order-sensitive failure this bug depends on.
 *
 * WHY PLAIN `exec()`/`curl`, NOT `Illuminate\Support\Facades\Process`/
 * `Http`: also tried and abandoned during this test's own development -
 * `Process::start()` combined with the `Http` client facade produced
 * unreliable, hard-to-diagnose results in the actual CI-shaped environment
 * this suite runs in (a slow bind-mounted filesystem where `php artisan
 * serve` re-bootstraps the full application, uncached, on every single
 * request - confirmed to take several real seconds per request even for
 * the minimal `/up` route). Plain shell backgrounding (`... > logfile 2>&1
 * & echo $!`) plus `curl` is the exact technique already proven reliable,
 * repeatedly, for reproducing and then verifying the fix for this bug in
 * the first place - reused here rather than a fresh abstraction layer with
 * its own unproven failure modes.
 *
 * This is deliberately NOT a test that merely asserts the `RouteMatched`
 * listener is registered; it exercises the actual failure mode end to end
 * and is regression-proven (see R57's own RISK_REGISTER.md entry) to fail
 * without the Platform fix and pass with it.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const R57_TEST_TENANT_ID = 'tenant-r57-regression';

function cleanupR57Tenant(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    $row = $central->table('tenants')->where('id', R57_TEST_TENANT_ID)->first();

    if ($row) {
        $data = json_decode($row->data ?? '{}', true) ?: [];

        if (! empty($data['tenancy_db_username'])) {
            $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
        }

        if (! empty($data['tenancy_db_name'])) {
            $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
        }
    }

    $central->table('domains')->where('tenant_id', R57_TEST_TENANT_ID)->delete();
    $central->table('tenants')->where('id', R57_TEST_TENANT_ID)->delete();
}

beforeEach(function () {
    cleanupR57Tenant();

    $this->tenant = Tenant::create([
        'id' => R57_TEST_TENANT_ID,
        'status' => TenantStatus::Pending,
        'owner_name' => 'R57 Regression Owner',
        'owner_email' => 'r57-regression@example.test',
    ]);
    $this->tenant->domains()->create(['domain' => R57_TEST_TENANT_ID.'.localhost']);

    app(TenantProvisioner::class)->provision($this->tenant, [
        'name' => 'R57 Regression Owner',
        'email' => 'r57-regression@example.test',
        'password' => 'r57-regression-password-1',
    ]);
    $this->tenant->refresh();
    expect($this->tenant->status)->toBe(TenantStatus::Ready);
});

afterEach(fn () => cleanupR57Tenant());

test('R57: a Ready tenant Admin dashboard does not 500 on the very first real-process request', function () {
    // A random, not a fixed, port - a leaked process from an earlier
    // interrupted run (this environment does not reliably let us kill the
    // grandchild `php -S ...` process `artisan serve` itself spawns) can
    // then never collide with THIS run.
    $port = random_int(20000, 65000);
    $base = 'http://127.0.0.1:'.$port;
    $logFile = storage_path('logs/r57-serve-'.$port.'.log');
    $host = R57_TEST_TENANT_ID.'.localhost';
    $cookieJar = storage_path('logs/r57-cookies-'.$port.'.txt');

    $startCommand = sprintf(
        'cd %s && nohup %s artisan serve --host=127.0.0.1 --port=%d < /dev/null > %s 2>&1 & echo $!',
        escapeshellarg(base_path()),
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($logFile)
    );
    $pid = trim((string) shell_exec($startCommand));

    try {
        // Same reasoning as this file's own docblock: `php artisan serve`
        // re-bootstraps the full application from scratch on every single
        // request on this environment's bind-mounted filesystem - measured
        // several real seconds even for the minimal `/up` route. A short
        // per-attempt timeout does not mean "not ready" - it means the
        // request was aborted mid-flight, which is what previously made
        // this loop never succeed despite the server being genuinely up.
        $ready = false;

        for ($i = 0; $i < 60; $i++) {
            $code = trim((string) shell_exec(
                'curl -s -o /dev/null -w "%{http_code}" --max-time 15 '.escapeshellarg($base.'/up')
            ));

            if ($code === '200') {
                $ready = true;
                break;
            }

            usleep(500_000);
        }

        if (! $ready) {
            dump('serve_log: '.(is_file($logFile) ? file_get_contents($logFile) : '(no log file)'));
        }

        expect($ready)->toBeTrue('php artisan serve did not become ready in time.');

        $loginPage = shell_exec(sprintf(
            'curl -s -c %s --max-time 30 -H %s %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: '.$host),
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
            escapeshellarg('Host: '.$host),
            escapeshellarg($base.'/admin/login'),
            escapeshellarg('_token='.$token),
            escapeshellarg('email=r57-regression@example.test'),
            escapeshellarg('password=r57-regression-password-1')
        )));
        expect($loginStatus)->toBe('302');

        $dashboardStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -b %s --max-time 30 -H %s %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: '.$host),
            escapeshellarg($base.'/admin/dashboard')
        )));

        // THE regression assertion - this raw-500'd before the fix
        // (SQLSTATE[42S02]: Table 'bagisto_central.channels' doesn't
        // exist), on the tenant's very first real-process dashboard
        // request.
        expect($dashboardStatus)->toBe('200');
    } finally {
        if ($pid !== '') {
            shell_exec('kill -9 '.escapeshellarg($pid).' 2>/dev/null');
        }
        // The actual listening process is a grandchild of the shell that
        // launched it (see this file's own docblock) - find and kill
        // whatever is still bound to this run's own random port too, so a
        // slow/stuck attempt never leaks past this test.
        shell_exec(sprintf(
            "for p in \$(ls /proc/*/cmdline 2>/dev/null | xargs -n1 dirname 2>/dev/null | xargs -n1 basename 2>/dev/null); do ".
            "if [ -r /proc/\$p/cmdline ]; then cmd=\$(tr '\\0' ' ' < /proc/\$p/cmdline 2>/dev/null); ".
            "case \"\$cmd\" in *%d*) kill -9 \$p 2>/dev/null ;; esac; fi; done",
            $port
        ));
        @unlink($logFile);
        @unlink($cookieJar);
    }
})->group('real-process');
