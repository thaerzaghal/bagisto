<?php

/**
 * TASK-MVP-007 production regression (found live, post-deploy). A real
 * production bug: `EnsurePublicSignupEnabled` originally called
 * `abort(404)`, throwing `NotFoundHttpException` into Laravel's real
 * exception-handler pipeline. Under real production config
 * (`APP_DEBUG=false`), `Webkul\Core\Exceptions\Handler` catches it and
 * renders a themed `shop::errors.*` Blade view, which unconditionally
 * queries the `locales` table - a table that exists only in a TENANT
 * database, never centrally. The result was a raw 500 on the central
 * `/join` route, not the intended clean 404. This is the exact same
 * failure family already found and fixed once for the sibling
 * `EnsureCentralDomain` middleware on this identical route group
 * (RISK_REGISTER.md R54) - the fix here is the same principle: return
 * the 404 response directly, with no exception thrown at all.
 *
 * WHY A REAL SUBPROCESS, NOT PEST'S IN-PROCESS HTTP TESTING: an
 * in-process `$this->get()`/`$this->post()` request never re-runs the
 * application through Laravel's real top-level exception-handler
 * bootstrap (`HandleExceptions`) the way a genuinely separate PHP
 * process does - which is why the original bug shipped past every other
 * test in this task undetected. `AdminDashboardTenancyTimingTest.php`
 * (R57) already established this exact real-subprocess technique for an
 * identical class of "only reproducible via a real process" bug - reused
 * here verbatim (plain shell-backgrounded `php artisan serve` + `curl`,
 * not `Process`/`Http` - see that file's own docblock for why).
 *
 * A PROPERLY CSRF-TOKENED POST, NOT A BARE ONE: an anonymous POST with
 * no `_token` is rejected by `VerifyCsrfToken` - part of the `platform`
 * middleware group, which runs BEFORE `EnsurePublicSignupEnabled` in the
 * route group order (`signup-routes.php`) - so a bare POST never reaches
 * this middleware at all, regardless of the fix. This test fetches a
 * real token from a real central GET first (mirroring exactly how a
 * genuine browser form submission - or Pest's own `$this->post()`
 * helper, which bypasses CSRF via `Illuminate\Foundation\Testing\
 * TestCase`'s own middleware disabling - reaches this route), so the
 * assertions below exercise the actual code path this fix changes.
 *
 * KNOWN, DISCLOSED LIMITATION: this real-subprocess environment does
 * NOT reproduce the exact production 500 for either GET or a
 * CSRF-tokened POST while disabled - both already return a clean 404
 * here even against the ORIGINAL, unfixed `abort(404)` middleware
 * (confirmed manually during this fix's own investigation). The real
 * production crash is conclusively diagnosed from `storage/logs/
 * laravel.log`'s own stack trace (an unambiguous `SQLSTATE[42S02]:
 * Base table or view not found: 1146 Table 'bagisto_central.locales'
 * doesn't exist`), not guessed - the discrepancy is a genuine,
 * unexplained difference between this local Docker test image's
 * installed Bagisto Shop package/view state and the production image's,
 * not a sign the bug was misdiagnosed. This test therefore cannot
 * itself prove "fails before / passes after" for the crash mechanism
 * the way `AdminDashboardTenancyTimingTest.php` could for R57 - it
 * instead locks in the CORRECT response shape (404, JSON, no leaked
 * detail) so a future regression back to `abort()`/`throw` is still
 * caught structurally, and the real proof of the production fix is the
 * live re-verification performed directly against `app.technify.dev`
 * after deployment (see the TASK-MVP-007 final report).
 *
 * SEPARATE, PRE-EXISTING, OUT-OF-SCOPE FINDING (not fixed here): while
 * investigating this bug, a bare (no-token) POST to `/join` was found to
 * return 500 in production for a DIFFERENT reason unrelated to this
 * middleware entirely - `VerifyCsrfToken`'s own `TokenMismatchException`
 * (419) falls through to the identical broken `shop::errors.index`
 * rendering path, and reproduces identically for OTHER, unrelated,
 * pre-existing central POST routes (`/platform/login` confirmed live).
 * This predates TASK-MVP-007, is not specific to signup, and is
 * out of scope for this narrow hotfix - see RISK_REGISTER.md's own
 * note for the full record.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

test('production regression: a real APP_DEBUG=false process returns a clean, non-leaking 404 - never 500 - for GET and a properly CSRF-tokened POST /join while disabled, with zero side effects', function () {
    $port = random_int(20000, 65000);
    $base = 'http://127.0.0.1:'.$port;
    $logFile = storage_path('logs/mvp007-serve-'.$port.'.log');
    $cookieJar = storage_path('logs/mvp007-cookies-'.$port.'.txt');
    $probeSlug = 'mvp007-realhandler-'.Str::random(6);

    $startCommand = sprintf(
        'cd %s && APP_DEBUG=false PUBLIC_SIGNUP_ENABLED=false nohup %s artisan serve --host=127.0.0.1 --port=%d < /dev/null > %s 2>&1 & echo $!',
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

        // GET - the directly reproduced production regression.
        $getBody = (string) shell_exec(sprintf(
            'curl -s -H %s %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join')
        ));
        $getStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -H %s %s',
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join')
        )));

        expect($getStatus)->toBe('404');
        expect($getBody)->toBe('{"message":"Not Found"}');
        expect($getBody)->not->toContain('SQLSTATE');
        expect($getBody)->not->toContain('locales');
        expect($getBody)->not->toContain('Stack trace');

        // A real CSRF token from a real central GET, exactly like a
        // genuine browser form submission would carry - see this file's
        // own docblock for why a bare POST cannot exercise this
        // middleware at all.
        $formPage = (string) shell_exec(sprintf(
            'curl -s -c %s -H %s %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/platform/login')
        ));
        preg_match('/name="_token" value="([^"]+)"/', $formPage, $m);
        $token = $m[1] ?? null;
        expect($token)->not->toBeNull();

        $postBody = (string) shell_exec(sprintf(
            'curl -s -b %s -H %s -X POST %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join'),
            escapeshellarg('_token='.$token),
            escapeshellarg('slug='.$probeSlug),
            escapeshellarg('owner_name=Regression Probe'),
            escapeshellarg('owner_email='.$probeSlug.'@example.test'),
            escapeshellarg('password=irrelevant-password-1')
        ));
        $postStatus = trim((string) shell_exec(sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -b %s -H %s -X POST %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s --data-urlencode %s',
            escapeshellarg($cookieJar),
            escapeshellarg('Host: localhost'),
            escapeshellarg($base.'/join'),
            escapeshellarg('_token='.$token),
            escapeshellarg('slug='.$probeSlug),
            escapeshellarg('owner_name=Regression Probe'),
            escapeshellarg('owner_email='.$probeSlug.'@example.test'),
            escapeshellarg('password=irrelevant-password-1')
        )));

        expect($postStatus)->toBe('404');
        expect($postBody)->toBe('{"message":"Not Found"}');
        expect($postBody)->not->toContain('SQLSTATE');
        expect($postBody)->not->toContain('locales');
        expect($postBody)->not->toContain('Stack trace');

        // Zero side effects - the same real central connection this
        // subprocess itself wrote to (no phpunit.xml DB override exists
        // for this project - see this file's own docblock).
        expect(Tenant::find($probeSlug))->toBeNull();
        expect(Domain::where('domain', $probeSlug.'.platform.test')->exists())->toBeFalse();
        expect(Subscription::where('tenant_id', $probeSlug)->exists())->toBeFalse();

        $provisioning = DB::connection('tenant_provisioning');
        $stillNoDatabase = $provisioning->select("SHOW DATABASES LIKE 'tenant".$probeSlug."'");
        expect($stillNoDatabase)->toBeEmpty();
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
        @unlink($cookieJar);
    }
})->group('real-process');
