<?php

/**
 * TASK-OPS-MONITORING-001 - `platform:production:monitor`.
 *
 * Two layers, deliberately kept separate:
 *
 * 1. `ProductionMonitorRunner`'s own diffing/notification-decision logic
 *    (new/changed/reminder/recovered, delivery-gated commit, exception
 *    handling) - driven against a small, real, container-bound
 *    `FakeReadinessCheck` (a genuine `ProductionReadinessCheck` subclass,
 *    not a Mockery double) that returns a CONTROLLED, fixed result set.
 *    This is what makes exact severity-change/reminder-timing/recovery
 *    scenarios reliably reproducible without fighting real config to
 *    produce one specific WARN/FAIL combination every time - the same
 *    "extend the real class for a test-only variant" pattern this
 *    project already uses elsewhere (e.g. `LocaleAwareCore extends
 *    Core`).
 * 2. A smaller set of full end-to-end tests through the REAL, unmodified
 *    `platform:production:monitor` artisan command wired to the REAL
 *    `platform:production:check`, proving the two are genuinely
 *    connected - real config toggles (matching `ProductionReadinessCheckTest.
 *    php`'s own established fixtures), real `Mail::fake()`.
 *
 * `Mail::fake()`/`Illuminate\Support\Facades\Mail` are used throughout,
 * never a real SMTP send - see `docs/architecture/production-deployment.md`
 * "Monitoring / alerting" for the one real, local-only (Mailpit) manual
 * verification performed outside the automated suite.
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Platform\Tenancy\Console\Commands\ProductionReadinessCheck;
use Platform\Tenancy\Mail\ProductionAlertMail;
use Platform\Tenancy\Services\ProductionMonitorRunner;
use Platform\Tenancy\Services\ProductionMonitorState;
use Platform\Tenancy\Support\NotificationRedactor;
use Platform\Tenancy\Support\ReadinessCheckResult;
use Platform\Tenancy\Support\ReadinessStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A real `ProductionReadinessCheck` subclass whose `collectResults()` is
 * fully test-controlled - see this file's own top docblock for why this
 * is used instead of a Mockery double. `$results` defaults to a single
 * healthy PASS row; `$throw`, when set, makes `collectResults()` throw
 * instead, for the "check exceptions" scenarios.
 */
class FakeReadinessCheck extends ProductionReadinessCheck
{
    /** @var array<int, ReadinessCheckResult> */
    public array $results = [];

    public ?Throwable $throw = null;

    public function collectResults(): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->results;
    }
}

function monitorTestStateDir(): string
{
    return sys_get_temp_dir().'/technify-monitor-test-'.bin2hex(random_bytes(6));
}

function monitorDeleteDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? monitorDeleteDir($path) : @unlink($path);
    }

    @rmdir($dir);
}

/**
 * Binds the fake readiness check + a fresh, isolated state directory, and
 * returns the real, container-resolved `ProductionMonitorRunner` under
 * test - exactly the same class `platform:production:monitor` itself
 * calls, just with its one collaborator swapped for a controllable
 * fixture (never its own diffing/decision logic, which is entirely
 * `ProductionMonitorRunner`'s own untouched code).
 */
function monitorRunnerWithFixedResults(array $results): ProductionMonitorRunner
{
    $fake = new FakeReadinessCheck;
    $fake->results = $results;

    app()->instance(ProductionReadinessCheck::class, $fake);

    return app(ProductionMonitorRunner::class);
}

function readinessResult(string $check, ReadinessStatus $status, string $detail = 'detail'): ReadinessCheckResult
{
    return new ReadinessCheckResult($check, $status, $detail);
}

beforeEach(function () {
    $this->monitorStateDir = monitorTestStateDir();
    config(['platform-monitoring.state_path' => $this->monitorStateDir]);
    app()->forgetInstance(ProductionMonitorState::class);

    // Opt-in, but with a real recipient configured by default - most
    // tests are about the DIFFING/delivery-gating logic, not the
    // disabled/missing-config posture itself (covered by dedicated tests
    // below).
    config([
        'platform-monitoring.enabled' => true,
        'platform-monitoring.recipient' => 'ops@example.test',
        'platform-monitoring.reminder_interval_minutes' => 60,
    ]);

    // Mail::fake() is called PER TEST below, not globally here - matching
    // this project's own established convention (see
    // TenantMailConfigurationTest.php) - test 7 specifically needs the
    // REAL mail manager (a genuine connection-refused failure), which
    // Mail::fake() cannot be reliably un-done from once called.
});

afterEach(function () {
    monitorDeleteDir($this->monitorStateDir);
});

// ---------------------------------------------------------------------
// 1. Healthy baseline / INFO-only.
// ---------------------------------------------------------------------

test('1. a fully healthy (PASS/INFO-only) result set sends no notification and is not treated as an incident', function () {
    Mail::fake();

    $runner = monitorRunnerWithFixedResults([
        readinessResult('APP_DEBUG', ReadinessStatus::Pass),
        readinessResult('Stripe', ReadinessStatus::Info),
    ]);

    $outcome = $runner->run();

    expect($outcome->hasIncidents())->toBeFalse();
    expect($outcome->notifications)->toBe([]);
    expect($outcome->notificationsSent)->toBeFalse();
    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------
// 2. Initial FAIL / initial WARN - including WARN with readiness exit 0.
// ---------------------------------------------------------------------

test('2. an initial FAIL is a genuinely new incident and is notified', function () {
    Mail::fake();

    $runner = monitorRunnerWithFixedResults([
        readinessResult('APP_DEBUG', ReadinessStatus::Fail, 'true - MUST be false in production.'),
    ]);

    $outcome = $runner->run();

    expect($outcome->notifications)->toHaveCount(1);
    expect($outcome->notifications[0]['reason'])->toBe('new');
    expect($outcome->notifications[0]['status'])->toBe('fail');
    expect($outcome->notificationsSent)->toBeTrue();

    Mail::assertSent(ProductionAlertMail::class, fn (ProductionAlertMail $mail) => $mail->hasTo('ops@example.test')
        && $mail->notifications[0]['check'] === 'APP_DEBUG'
    );
});

test('2b. an initial WARN is notified even though platform:production:check itself would exit 0 for a WARN-only result', function () {
    Mail::fake();

    // ProductionReadinessCheckTest.php tests 6/9/10 (unchanged, still
    // passing - see this task's own regression run) already establish,
    // against the real command, that a WARN-only result exits SUCCESS
    // (e.g. "10. warns (does not fail) when Turnstile signup abuse
    // protection is disabled" -> `assertSuccessful()`). This test does
    // not re-derive that fact against a fully-reproduced production-shaped
    // config - it takes it as given and focuses on the one thing that
    // fact actually matters for here.
    //
    // The monitor must inspect STATUSES, not the readiness command's exit
    // code, which it never even sees - collectResults() is the only thing
    // consumed. Proven against a controlled fixed WARN result.
    $outcome = monitorRunnerWithFixedResults([
        readinessResult('TRUSTED_PROXIES', ReadinessStatus::Warn, 'unset - trusting ALL proxies.'),
    ])->run();

    expect($outcome->notifications)->toHaveCount(1);
    expect($outcome->notifications[0]['reason'])->toBe('new');
    expect($outcome->notifications[0]['status'])->toBe('warn');
    Mail::assertSent(ProductionAlertMail::class);
});

// ---------------------------------------------------------------------
// 3. Duplicate suppression despite changing detail text.
// ---------------------------------------------------------------------

test('3. an unchanged WARN is suppressed on the next run even though its detail text (e.g. an age/timestamp) changed', function () {
    Mail::fake();

    $runner1 = monitorRunnerWithFixedResults([
        readinessResult('Backups', ReadinessStatus::Warn, 'newest successful backup is from [2026-08-01_030000], 3h ago.'),
    ]);
    $runner1->run();
    Mail::assertSent(ProductionAlertMail::class, 1);

    // Same check, same status, DIFFERENT detail text (a realistic
    // "hours ago" drift) - must not fingerprint on this.
    $runner2 = monitorRunnerWithFixedResults([
        readinessResult('Backups', ReadinessStatus::Warn, 'newest successful backup is from [2026-08-01_030000], 4h ago.'),
    ]);
    $outcome2 = $runner2->run();

    expect($outcome2->notifications)->toBe([]);
    Mail::assertSent(ProductionAlertMail::class, 1); // still exactly one, total
});

// ---------------------------------------------------------------------
// 4. Severity changes, reminder timing, recovery, recurrence.
// ---------------------------------------------------------------------

test('4. a severity change (WARN -> FAIL) is notified immediately, never suppressed by the reminder interval', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Warn)])->run();
    Mail::assertSent(ProductionAlertMail::class, 1);

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Fail)])->run();

    expect($outcome->notifications)->toHaveCount(1);
    expect($outcome->notifications[0]['reason'])->toBe('changed');
    Mail::assertSent(ProductionAlertMail::class, 2);
});

test('5. a still-unresolved incident is re-notified once the reminder interval elapses, and not before', function () {
    Mail::fake();

    config(['platform-monitoring.reminder_interval_minutes' => 60]);

    monitorRunnerWithFixedResults([readinessResult('DB_PROVISION_USERNAME', ReadinessStatus::Fail)])->run();
    Mail::assertSent(ProductionAlertMail::class, 1);

    // 30 minutes later - still inside the 60-minute reminder window.
    CarbonImmutable::setTestNow(now()->addMinutes(30));
    $tooSoon = monitorRunnerWithFixedResults([readinessResult('DB_PROVISION_USERNAME', ReadinessStatus::Fail)])->run();
    expect($tooSoon->notifications)->toBe([]);
    Mail::assertSent(ProductionAlertMail::class, 1);

    // 61 minutes after the ORIGINAL notification (31 more) - reminder due.
    CarbonImmutable::setTestNow(now()->addMinutes(31));
    $due = monitorRunnerWithFixedResults([readinessResult('DB_PROVISION_USERNAME', ReadinessStatus::Fail)])->run();
    expect($due->notifications)->toHaveCount(1);
    expect($due->notifications[0]['reason'])->toBe('reminder');
    Mail::assertSent(ProductionAlertMail::class, 2);

    CarbonImmutable::setTestNow();
});

test('6. a resolved incident sends exactly one RECOVERED notice, then stays quiet, and a later re-occurrence is treated as new again', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Fail, 'unreachable')])->run();
    Mail::assertSent(ProductionAlertMail::class, 1);

    $recoveredOutcome = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Pass, 'reachable')])->run();
    expect($recoveredOutcome->notifications)->toHaveCount(1);
    expect($recoveredOutcome->notifications[0]['reason'])->toBe('recovered');
    Mail::assertSent(ProductionAlertMail::class, 2);

    // Still healthy - no further mail.
    $stillHealthy = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Pass)])->run();
    expect($stillHealthy->notifications)->toBe([]);
    Mail::assertSent(ProductionAlertMail::class, 2);

    // Breaks again - a fresh incident, reason 'new', not suppressed as if
    // it were a continuation of the old one.
    $again = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Fail)])->run();
    expect($again->notifications)->toHaveCount(1);
    expect($again->notifications[0]['reason'])->toBe('new');
    Mail::assertSent(ProductionAlertMail::class, 3);
});

test('6b. a truly first-ever run against an already-healthy environment sends nothing - no false "recovered" notice', function () {
    Mail::fake();

    // No prior state file exists at all (this test's isolated, fresh
    // $this->monitorStateDir has never been written to) - task
    // instruction: "an initial healthy run must not generate a false
    // recovery notification."
    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();

    expect($outcome->notifications)->toBe([]);
    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------
// 5. Delivery failure -> retry without falsely marking delivery.
// ---------------------------------------------------------------------

test('7. a mail delivery failure leaves the incident pending, retried (and eventually delivered) on the next run', function () {
    // Mail::fake() never really "fails" on its own - force a real
    // transport-level exception the same way this project's own existing
    // mail-failure test does (a real connection-refused SMTP target),
    // rather than mocking the exception path.
    Mail::swap(app('mail.manager'));
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $outcome1 = monitorRunnerWithFixedResults([readinessResult('Mail (central SMTP fallback)', ReadinessStatus::Warn)])->run();

    expect($outcome1->notifications)->toHaveCount(1);
    expect($outcome1->notificationsSent)->toBeFalse();
    expect($outcome1->deliverySkippedReason)->not->toBeNull();

    // Same check, same status, still pending - the NEXT run must retry
    // the SAME notification (reason still 'new', not silently dropped),
    // not require the underlying check itself to change again.
    $outcome2 = monitorRunnerWithFixedResults([readinessResult('Mail (central SMTP fallback)', ReadinessStatus::Warn)])->run();
    expect($outcome2->notifications)->toHaveCount(1);
    expect($outcome2->notifications[0]['reason'])->toBe('new');
    expect($outcome2->notificationsSent)->toBeFalse();

    // Now let it succeed (Mail::fake() again = "the network is healthy
    // now") - the pending incident is finally delivered.
    Mail::fake();
    $outcome3 = monitorRunnerWithFixedResults([readinessResult('Mail (central SMTP fallback)', ReadinessStatus::Warn)])->run();
    expect($outcome3->notificationsSent)->toBeTrue();
    Mail::assertSent(ProductionAlertMail::class, 1);

    // And now it correctly suppresses again.
    $outcome4 = monitorRunnerWithFixedResults([readinessResult('Mail (central SMTP fallback)', ReadinessStatus::Warn)])->run();
    expect($outcome4->notifications)->toBe([]);
});

// ---------------------------------------------------------------------
// 6. Check exceptions / repeated command invocations.
// ---------------------------------------------------------------------

test('8. an exception thrown by collectResults() is treated as a monitor failure - alerted, not a silent healthy run', function () {
    Mail::fake();

    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException('a real, unexpected failure inside a check');
    app()->instance(ProductionReadinessCheck::class, $fake);

    $runner = app(ProductionMonitorRunner::class);
    $outcome = $runner->run();

    expect($outcome->monitorException)->not->toBeNull();
    expect($outcome->results)->toHaveCount(1);
    expect($outcome->results[0]->check)->toBe('Production monitor');
    expect($outcome->results[0]->status)->toBe(ReadinessStatus::Fail);
    // The raw exception message/stack trace must never reach the sent
    // notification content (task instruction: no raw exception dumps).
    expect($outcome->results[0]->detail)->toContain('RuntimeException');
    expect($outcome->notificationsSent)->toBeTrue();

    Mail::assertSent(ProductionAlertMail::class, function (ProductionAlertMail $mail) {
        $body = json_encode($mail->notifications);

        return ! str_contains($body, '.php:')  // no stack-trace-shaped frame reference
            && str_contains($body, 'RuntimeException');
    });
});

test('9. the artisan command itself exits non-zero when collectResults() throws', function () {
    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException('boom');
    app()->instance(ProductionReadinessCheck::class, $fake);

    $exitCode = Artisan::call('platform:production:monitor');

    expect($exitCode)->not->toBe(0);
});

test('10. repeated invocations never retain stale counters/state - three consecutive real platform:production:check runs report an identical, non-accumulating failure/warning count', function () {
    config(['app.debug' => true]);

    $summary = function (): array {
        Artisan::call('platform:production:check');
        preg_match('/(\d+) check\(s\) failed, (\d+) warning\(s\)\./', Artisan::output(), $matches);

        return $matches;
    };

    $first = $summary();
    $second = $summary();
    $third = $summary();

    expect($first)->not->toBe([]);
    // The exact same real command, run three times in the SAME PHP
    // process with nothing else changing, must report the exact same
    // failure/warning count every time - never 1, then 2, then 3 from a
    // leaked/accumulated prior-instance counter (the specific class of
    // bug ProductionReadinessCheck::collectResults()'s own docblock
    // documents this refactor as closing).
    expect([$first[1], $first[2]])->toBe([$second[1], $second[2]]);
    expect([$second[1], $second[2]])->toBe([$third[1], $third[2]]);
});

// ---------------------------------------------------------------------
// 7. Missing enabled configuration / disabled-by-default.
// ---------------------------------------------------------------------

test('11. alerting is disabled by default - config/platform-monitoring.php\'s own shipped default sends nothing', function () {
    Mail::fake();

    // A fresh config() call bypassing this file's own beforeEach() override,
    // to prove the SHIPPED default (no MONITOR_ALERT_ENABLED set) is
    // genuinely false, not merely false because this test file set it so.
    config(['platform-monitoring.enabled' => (bool) env('MONITOR_ALERT_ENABLED', false)]);

    expect(config('platform-monitoring.enabled'))->toBeFalse();

    $outcome = monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Fail)])->run();

    expect($outcome->notifications)->toHaveCount(1); // still tracked/would-notify
    expect($outcome->notificationsSent)->toBeFalse();
    expect($outcome->deliverySkippedReason)->toContain('disabled');
    Mail::assertNothingSent();
});

test('12. enabled with no recipient configured fails visibly - never silently claims delivery', function () {
    Mail::fake();

    config(['platform-monitoring.enabled' => true, 'platform-monitoring.recipient' => '']);

    $outcome = monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Fail)])->run();

    expect($outcome->notificationsSent)->toBeFalse();
    expect($outcome->deliverySkippedReason)->toContain('MONITOR_ALERT_RECIPIENT');
    Mail::assertNothingSent();

    $exitCode = Artisan::call('platform:production:monitor');
    expect($exitCode)->not->toBe(0);
});

// ---------------------------------------------------------------------
// 8. Overlap protection / durable state.
// ---------------------------------------------------------------------

test('13. a second invocation skips cleanly (exit 0, no mail) while the lock is already held', function () {
    Mail::fake();

    $state = app(ProductionMonitorState::class);
    $state->ensureDirectoryExists();

    $handle = fopen($state->lockFilePath(), 'c');
    flock($handle, LOCK_EX);

    try {
        $exitCode = Artisan::call('platform:production:monitor');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Skipped');
        Mail::assertNothingSent();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

test('14. state persists to a real file on disk and survives across separate runner instances (simulating separate cron invocations)', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Fail)])->run();

    $state = app(ProductionMonitorState::class);
    expect(is_file($state->stateFilePath()))->toBeTrue();

    $stored = $state->read();
    expect($stored['checks']['CACHE_STORE']['status'])->toBe('fail');
    expect($stored['checks']['CACHE_STORE']['last_notified_status'])->toBe('fail');

    // A brand-new ProductionMonitorState instance pointed at the SAME
    // directory (simulating a fresh PHP process/container recreation,
    // not an in-memory carryover) reads the identical, correct state.
    $reopened = new ProductionMonitorState($this->monitorStateDir);
    expect($reopened->read())->toBe($stored);
});

test('15. a corrupt/unreadable state file is treated as a fresh baseline, not a monitor crash', function () {
    Mail::fake();

    $state = app(ProductionMonitorState::class);
    $state->ensureDirectoryExists();
    file_put_contents($state->stateFilePath(), '{not valid json');

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Fail)])->run();

    expect($outcome->monitorException)->toBeNull();
    expect($outcome->notifications)->toHaveCount(1);
    expect($outcome->notifications[0]['reason'])->toBe('new');
});

// ---------------------------------------------------------------------
// 9. Central-only notification routing / no tenant-data mutation.
// ---------------------------------------------------------------------

test('16. the notification is sent through the plain central smtp mailer, never Bagisto\'s per-tenant dynamic transport, and never initializes tenant context', function () {
    Mail::fake();

    expect(tenancy()->initialized)->toBeFalse();

    monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Fail)])->run();

    expect(tenancy()->initialized)->toBeFalse();

    Mail::assertSent(ProductionAlertMail::class, function (ProductionAlertMail $mail) {
        return $mail->mailer === 'smtp';
    });
});

test('17. --dry-run computes and reports the same decision without sending mail or persisting any state change', function () {
    Mail::fake();

    $exitCode = Artisan::call('platform:production:monitor', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('--dry-run');
    Mail::assertNothingSent();

    $state = app(ProductionMonitorState::class);
    expect(is_file($state->stateFilePath()))->toBeFalse();
});

test('18. the sent notification never contains a secret value, matching platform:production:check\'s own long-standing guarantee', function () {
    Mail::fake();

    config(['platform-billing.stripe.secret' => 'sk_test_super_secret_value_12345']);

    $outcome = monitorRunnerWithFixedResults([
        readinessResult('DB_PROVISION_USERNAME', ReadinessStatus::Fail, 'is [root] - production MUST use a dedicated user.'),
    ])->run();

    $encoded = json_encode($outcome->notifications);
    expect($encoded)->not->toContain('sk_test_super_secret_value_12345');

    Mail::assertSent(ProductionAlertMail::class, fn (ProductionAlertMail $mail) => ! str_contains(json_encode($mail->notifications), 'sk_test_super_secret_value_12345')
    );
});

// =======================================================================
// TASK-OPS-MONITORING-001A - review fixes.
// =======================================================================

// -----------------------------------------------------------------------
// Finding 1: secret disclosure at the notification/state boundary.
// -----------------------------------------------------------------------

test('19. describe() never includes an exception message, for any exception, including one containing a synthetic secret - the shared mechanism behind BOTH the collectResults() and delivery exception paths', function () {
    $secret = 'Bearer SYNTHETIC_TOKEN_'.Str::random(24);
    $runner = app(ProductionMonitorRunner::class);
    $method = new ReflectionMethod($runner, 'describe');
    $method->setAccessible(true);

    $described = $method->invoke($runner, new RuntimeException("auth failed: {$secret}"));

    expect($described)->not->toContain($secret);
    expect($described)->not->toContain('auth failed');
    expect($described)->toContain('RuntimeException');
});

test('20. a synthetic secret in a collectResults() exception never reaches the email, persisted state, or console output', function () {
    Mail::fake();

    $secret = 'sk_live_SYNTHETIC_'.Str::random(20);
    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException("connection failed: password={$secret}");
    app()->instance(ProductionReadinessCheck::class, $fake);

    Artisan::call('platform:production:monitor');
    $consoleOutput = Artisan::output();

    expect($consoleOutput)->not->toContain($secret);

    $state = app(ProductionMonitorState::class)->read();
    expect(json_encode($state))->not->toContain($secret);

    Mail::assertSent(ProductionAlertMail::class, fn (ProductionAlertMail $mail) => ! str_contains(json_encode($mail->notifications), $secret)
    );
});

test('21. a synthetic secret in a real delivery exception never reaches persisted state or console output', function () {
    // A REAL, unmocked transport failure (matching this project's own
    // established "real connection-refused SMTP target" convention) -
    // proves describe()'s class-only rule holds for a genuine caught
    // exception on the delivery path specifically, not just in isolation.
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $secret = 'SYNTHETIC_DELIVERY_SECRET_'.Str::random(16);
    // Simulate a check whose OWN detail happens to carry the same secret
    // shape a real exception-message-embedding check (checkRedis()) could
    // produce - proves the delivery-failure path doesn't reintroduce it
    // even when the underlying incident detail already contains one.
    $outcome = monitorRunnerWithFixedResults([
        readinessResult('Redis', ReadinessStatus::Fail, "unreachable: token={$secret}"),
    ])->run();

    expect($outcome->notificationsSent)->toBeFalse();
    expect($outcome->deliverySkippedReason)->not->toContain($secret);

    $state = app(ProductionMonitorState::class)->read();
    expect(json_encode($state))->not->toContain($secret);
});

test('22. a secret embedded in a check\'s own detail text (mirroring checkRedis()/checkThemeStaticAssets()\'s real exception-message-embedding pattern) is redacted before reaching the notification', function () {
    Mail::fake();

    $secret = 'SYNTHETIC_hunter2_'.Str::random(10);
    $outcome = monitorRunnerWithFixedResults([
        readinessResult('Redis', ReadinessStatus::Fail, "unreachable: connection refused (password={$secret})"),
    ])->run();

    expect($outcome->notifications)->toHaveCount(1);
    expect($outcome->notifications[0]['detail'])->not->toContain($secret);
    expect($outcome->notifications[0]['detail'])->toContain('[REDACTED]');

    Mail::assertSent(ProductionAlertMail::class, fn (ProductionAlertMail $mail) => ! str_contains(json_encode($mail->notifications), $secret)
    );
});

test('23. NotificationRedactor redacts common credential shapes without mangling ordinary detail text', function () {
    expect(NotificationRedactor::redact('password=hunter2 rest of message'))
        ->toContain('password=[REDACTED]')
        ->not->toContain('hunter2');

    expect(NotificationRedactor::redact('redis://user:hunter2@127.0.0.1:6379'))
        ->not->toContain('hunter2');

    expect(NotificationRedactor::redact('Authorization: Bearer abcdefghij0123456789'))
        ->not->toContain('abcdefghij0123456789');

    $benign = 'unset - trusting ALL proxies ("*"). Acceptable for local development only.';
    expect(NotificationRedactor::redact($benign))->toBe($benign);
});

// -----------------------------------------------------------------------
// Finding 2: configuration validation.
// -----------------------------------------------------------------------

test('24. config/platform-monitoring.php treats an unset MONITOR_STATE_PATH as the documented BACKUP_ROOT/monitoring default', function () {
    putenv('MONITOR_STATE_PATH');
    putenv('BACKUP_ROOT=/tmp/technify-backup-root-test');

    $resolved = (require base_path('config/platform-monitoring.php'))['state_path'];

    expect($resolved)->toBe('/tmp/technify-backup-root-test/monitoring');

    putenv('BACKUP_ROOT');
});

test('25. config/platform-monitoring.php treats a BLANK MONITOR_STATE_PATH (present, empty) exactly like unset - the .env.example regression', function () {
    // Matches .env.example's own shipped line verbatim: present, blank,
    // never commented out.
    putenv('MONITOR_STATE_PATH=');
    putenv('BACKUP_ROOT=/tmp/technify-backup-root-test');

    $resolved = (require base_path('config/platform-monitoring.php'))['state_path'];

    expect($resolved)->toBe('/tmp/technify-backup-root-test/monitoring');
    expect($resolved)->not->toBe('');

    putenv('MONITOR_STATE_PATH');
    putenv('BACKUP_ROOT');
});

test('26. config/platform-monitoring.php uses an explicit, valid MONITOR_STATE_PATH verbatim', function () {
    putenv('MONITOR_STATE_PATH=/tmp/technify-explicit-monitor-state');

    $resolved = (require base_path('config/platform-monitoring.php'))['state_path'];

    expect($resolved)->toBe('/tmp/technify-explicit-monitor-state');

    putenv('MONITOR_STATE_PATH');
});

test('27. ProductionMonitorState rejects an empty, root-only, or relative path before any filesystem operation', function () {
    expect(fn () => (new ProductionMonitorState('   '))->read())->toThrow(RuntimeException::class);
    expect(fn () => (new ProductionMonitorState('/'))->ensureDirectoryExists())->toThrow(RuntimeException::class);
    expect(fn () => (new ProductionMonitorState('///'))->ensureDirectoryExists())->toThrow(RuntimeException::class);
    expect(fn () => (new ProductionMonitorState('relative/path'))->read())->toThrow(RuntimeException::class);
});

test('28. an unsafe resolved state path surfaces as a real, visible monitor failure through the runner - never a crash, never silently ignored', function () {
    config(['platform-monitoring.state_path' => '']);
    app()->forgetInstance(ProductionMonitorState::class);

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();

    expect($outcome->monitorException)->not->toBeNull();
    expect($outcome->results[0]->check)->toBe('Production monitor');
    expect($outcome->results[0]->status)->toBe(ReadinessStatus::Fail);
});

test('29. enabled monitoring with a missing recipient is surfaced as a configuration problem even when every check is currently healthy', function () {
    config(['platform-monitoring.enabled' => true, 'platform-monitoring.recipient' => '']);

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();

    expect($outcome->hasIncidents())->toBeFalse();
    expect($outcome->notifications)->toBe([]);
    expect($outcome->configurationProblem)->not->toBeNull();
    expect($outcome->configurationProblem)->toContain('MONITOR_ALERT_RECIPIENT');

    $exitCode = Artisan::call('platform:production:monitor');
    expect($exitCode)->not->toBe(0);
});

test('30. enabled monitoring with a syntactically invalid recipient is a configuration problem, validated locally with no external call', function () {
    config(['platform-monitoring.enabled' => true, 'platform-monitoring.recipient' => 'not-an-email']);

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();

    expect($outcome->configurationProblem)->toContain('does not look like a valid email');
});

test('31. disabled monitoring never reports a configuration problem, regardless of the recipient value', function () {
    config(['platform-monitoring.enabled' => false, 'platform-monitoring.recipient' => '']);

    $outcome = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Fail)])->run();

    expect($outcome->configurationProblem)->toBeNull();
});

// -----------------------------------------------------------------------
// Finding 3: preserve incident history through monitor failures.
// -----------------------------------------------------------------------

test('32. known notified incident -> collection exception -> same incident (bookkeeping preserved, never reset, never re-notified)', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Fail)])->run();
    Mail::assertSent(ProductionAlertMail::class, 1);

    $stateBefore = app(ProductionMonitorState::class)->read()['checks']['APP_DEBUG'];

    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException('collection exploded');
    app()->instance(ProductionReadinessCheck::class, $fake);
    $duringFailure = app(ProductionMonitorRunner::class)->run();

    expect($duringFailure->monitorException)->not->toBeNull();
    expect(collect($duringFailure->notifications)->pluck('check')->all())->not->toContain('APP_DEBUG');

    $stateAfter = app(ProductionMonitorState::class)->read()['checks']['APP_DEBUG'];
    expect($stateAfter)->toBe($stateBefore);

    // Still exactly one email total - APP_DEBUG was never re-notified.
    Mail::assertSent(ProductionAlertMail::class, 2); // the original + this run's "Production monitor" new-incident mail
});

test('33. known notified incident -> collection exception -> healthy (recovers correctly once collection resumes)', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Fail)])->run();

    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException('collection exploded');
    app()->instance(ProductionReadinessCheck::class, $fake);
    app(ProductionMonitorRunner::class)->run();

    $recovered = monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Pass)])->run();

    $appDebugNotification = collect($recovered->notifications)->firstWhere('check', 'APP_DEBUG');
    expect($appDebugNotification)->not->toBeNull();
    expect($appDebugNotification['reason'])->toBe('recovered');
});

test('34. collection exception -> successful collection -> exactly one "Production monitor" recovery notice, then quiet', function () {
    Mail::fake();

    $fake = new FakeReadinessCheck;
    $fake->throw = new RuntimeException('collection exploded');
    app()->instance(ProductionReadinessCheck::class, $fake);
    $failing = app(ProductionMonitorRunner::class)->run();
    expect(collect($failing->notifications)->pluck('reason')->all())->toBe(['new']);
    Mail::assertSent(ProductionAlertMail::class, 1);

    $recovering = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();
    $monitorNotification = collect($recovering->notifications)->firstWhere('check', 'Production monitor');
    expect($monitorNotification)->not->toBeNull();
    expect($monitorNotification['reason'])->toBe('recovered');
    Mail::assertSent(ProductionAlertMail::class, 2);

    $stillHealthy = monitorRunnerWithFixedResults([readinessResult('CACHE_STORE', ReadinessStatus::Pass)])->run();
    expect($stillHealthy->notifications)->toBe([]);
    Mail::assertSent(ProductionAlertMail::class, 2);
});

test('35. a recovery notification that fails to deliver is retried, never lost or reclassified as brand-new', function () {
    // Seed a PRIOR, already-successfully-notified FAIL incident directly
    // (rather than sending a real email just to arrive at this starting
    // point) - this test's own subject is specifically the RECOVERY
    // delivery-failure/retry behavior, not the initial notification.
    app(ProductionMonitorState::class)->write([
        'checks' => [
            'Redis' => [
                'status' => 'fail',
                'since' => CarbonImmutable::now()->subHour()->toIso8601String(),
                'last_notified_status' => 'fail',
                'last_notified_at' => CarbonImmutable::now()->subHour()->toIso8601String(),
            ],
        ],
        'meta' => [],
    ]);

    // Deliberately UNFAKED from here on (matching test 7's own established
    // pattern) - Mail::fake() never really "fails," so this needs a
    // genuine, real transport-level exception (a real connection-refused
    // SMTP target), not a mock.
    Mail::swap(app('mail.manager'));
    config(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $failedRecovery = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Pass)])->run();
    expect($failedRecovery->notifications)->toHaveCount(1);
    expect($failedRecovery->notifications[0]['reason'])->toBe('recovered');
    expect($failedRecovery->notificationsSent)->toBeFalse();

    $retried = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Pass)])->run();
    expect($retried->notifications)->toHaveCount(1);
    expect($retried->notifications[0]['reason'])->toBe('recovered');

    Mail::fake();
    $delivered = monitorRunnerWithFixedResults([readinessResult('Redis', ReadinessStatus::Pass)])->run();
    expect($delivered->notificationsSent)->toBeTrue();
    Mail::assertSent(ProductionAlertMail::class, 1);
});

test('36. an empty result set from collectResults() is rejected as a monitor failure, not a healthy empty run, and does not erase prior state', function () {
    Mail::fake();

    monitorRunnerWithFixedResults([readinessResult('APP_DEBUG', ReadinessStatus::Fail)])->run();

    $fake = new FakeReadinessCheck;
    $fake->results = []; // no throw - a genuinely empty, invalid result set
    app()->instance(ProductionReadinessCheck::class, $fake);
    $outcome = app(ProductionMonitorRunner::class)->run();

    expect($outcome->monitorException)->not->toBeNull();
    expect($outcome->results)->toHaveCount(1);
    expect($outcome->results[0]->check)->toBe('Production monitor');

    $state = app(ProductionMonitorState::class)->read()['checks'];
    expect($state)->toHaveKey('APP_DEBUG');
    expect($state['APP_DEBUG']['last_notified_status'])->toBe('fail');
});

test('37. a duplicate-labeled result set from collectResults() is rejected as a monitor failure', function () {
    $fake = new FakeReadinessCheck;
    $fake->results = [readinessResult('X', ReadinessStatus::Pass), readinessResult('X', ReadinessStatus::Fail)];
    app()->instance(ProductionReadinessCheck::class, $fake);
    $outcome = app(ProductionMonitorRunner::class)->run();

    expect($outcome->monitorException)->not->toBeNull();
});

// -----------------------------------------------------------------------
// Finding 5: --test-notification.
// -----------------------------------------------------------------------

test('38. --test-notification sends one clearly-labeled email using the configured recipient/central smtp, without running any readiness check or touching incident state', function () {
    Mail::fake();
    $fake = new FakeReadinessCheck;
    // Deliberately wired so that IF this code path mistakenly ran the
    // real readiness pipeline, this test would fail loudly rather than
    // silently passing.
    $fake->throw = new RuntimeException('the readiness pipeline must never run for --test-notification');
    app()->instance(ProductionReadinessCheck::class, $fake);

    $exitCode = Artisan::call('platform:production:monitor', ['--test-notification' => true]);

    expect($exitCode)->toBe(0);
    Mail::assertSent(ProductionAlertMail::class, function (ProductionAlertMail $mail) {
        return $mail->hasTo('ops@example.test')
            && count($mail->notifications) === 1
            && $mail->notifications[0]['reason'] === 'test';
    });

    $state = app(ProductionMonitorState::class);
    expect(is_file($state->stateFilePath()))->toBeFalse();
});

test('39. --test-notification respects opt-in requirements - disabled or missing recipient fails visibly, sends nothing', function () {
    Mail::fake();
    config(['platform-monitoring.enabled' => false]);

    $exitCode = Artisan::call('platform:production:monitor', ['--test-notification' => true]);

    expect($exitCode)->not->toBe(0);
    Mail::assertNothingSent();
});
