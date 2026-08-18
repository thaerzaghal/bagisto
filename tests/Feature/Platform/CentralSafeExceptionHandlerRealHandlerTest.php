<?php

/**
 * TASK-MVP-008 (RISK_REGISTER.md R68). The one case
 * `CentralSafeExceptionHandlerTest.php` cannot cover in-process: a missing/
 * invalid CSRF token. `Illuminate\Foundation\Http\Middleware\
 * VerifyCsrfToken::handle()` unconditionally bypasses verification whenever
 * `$this->runningUnitTests()` is true (`APP_ENV=testing`) - there is no way
 * to make an in-process Pest request ever throw `TokenMismatchException` at
 * all, regardless of any other setup. A real subprocess, with its own real
 * `APP_ENV`/`APP_DEBUG` process environment, is the only way to exercise the
 * genuine CSRF failure path - the same established technique already used
 * for R57 (`AdminDashboardTenancyTimingTest.php`) and R67
 * (`EnsurePublicSignupEnabledRealHandlerTest.php`).
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

const CSEHR_TENANT_ID = 'cseh-real-ready';

function cleanupCsehRealTenant(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    $row = $central->table('tenants')->where('id', CSEHR_TENANT_ID)->first();

    if ($row) {
        $data = json_decode($row->data ?? '{}', true) ?: [];
        $dbUsername = $data['tenancy_db_username'] ?? null;
        $dbName = $data['tenancy_db_name'] ?? null;

        if ($dbUsername) {
            $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $dbUsername).'`');
        }

        if ($dbName) {
            $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $dbName).'`');
        }
    }

    $central->table('domains')->where('tenant_id', CSEHR_TENANT_ID)->delete();
    $central->table('tenants')->where('id', CSEHR_TENANT_ID)->delete();
}

beforeEach(fn () => cleanupCsehRealTenant());
afterEach(fn () => cleanupCsehRealTenant());

test('production regression: a real APP_DEBUG=false process returns a clean 419 - never 500 - for missing-CSRF central POSTs, while a real tenant remains completely unaffected', function () {
    $tenant = Tenant::create(['id' => CSEHR_TENANT_ID, 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => CSEHR_TENANT_ID.'.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    $port = random_int(20000, 65000);
    $base = 'http://127.0.0.1:'.$port;
    $logFile = storage_path('logs/cseh-serve-'.$port.'.log');

    $startCommand = sprintf(
        'cd %s && APP_DEBUG=false PUBLIC_SIGNUP_ENABLED=true nohup %s artisan serve --host=127.0.0.1 --port=%d < /dev/null > %s 2>&1 & echo $!',
        escapeshellarg(base_path()),
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($logFile)
    );
    $pid = trim((string) shell_exec($startCommand));

    try {
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

        // THE regression assertion - Platform Admin login, no CSRF token.
        $loginBody = (string) shell_exec(sprintf(
            'curl -s -H %s -X POST %s --data-urlencode %s --data-urlencode %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/platform/login'),
            escapeshellarg('email=nobody@example.test'),
            escapeshellarg('password=irrelevant')
        ));
        $loginStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -H %s -X POST %s --data-urlencode %s --data-urlencode %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/platform/login'),
            escapeshellarg('email=nobody@example.test'),
            escapeshellarg('password=irrelevant')
        )));

        expect($loginStatus)->toBe('419');
        expect($loginBody)->not->toContain('SQLSTATE');
        expect($loginBody)->not->toContain('locales');
        expect($loginBody)->not->toContain('Stack trace');

        // THE regression assertion - /join, no CSRF token (the original
        // R68 discovery route).
        $joinBody = (string) shell_exec(sprintf(
            'curl -s -H %s -X POST %s --data-urlencode %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join'),
            escapeshellarg('slug=cseh-real-probe')
        ));
        $joinStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -H %s -X POST %s --data-urlencode %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join'),
            escapeshellarg('slug=cseh-real-probe')
        )));

        expect($joinStatus)->toBe('419');
        expect($joinBody)->not->toContain('SQLSTATE');
        expect($joinBody)->not->toContain('locales');

        // Zero side effects from the /join probe despite the earlier 500
        // this exact shape used to produce.
        expect(Tenant::find('cseh-real-probe'))->toBeNull();

        // A REAL tenant, through the SAME real subprocess, completely
        // unaffected - proves delegation to Webkul's own Handler still
        // works correctly under a genuine subprocess boundary too, not
        // just in-process.
        $tenantStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -H %s %s',
            escapeshellarg('Host: '.CSEHR_TENANT_ID.'.platform.test'),
            escapeshellarg($base.'/')
        )));
        expect($tenantStatus)->toBe('200');
    } finally {
        if ($pid !== '') {
            shell_exec('kill -9 '.escapeshellarg($pid).' 2>/dev/null');
        }
        shell_exec(sprintf(
            'for p in $(ls /proc/*/cmdline 2>/dev/null | xargs -n1 dirname 2>/dev/null | xargs -n1 basename 2>/dev/null); do '.
            "if [ -r /proc/\$p/cmdline ]; then cmd=\$(tr '\\0' ' ' < /proc/\$p/cmdline 2>/dev/null); ".
            'case "$cmd" in *%d*) kill -9 $p 2>/dev/null ;; esac; fi; done',
            $port
        ));
        @unlink($logFile);
    }
})->group('real-process');
