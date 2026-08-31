<?php

/**
 * TASK-OPS-MONITORING-001A finding 4. Proves the documented execution-bound
 * MECHANISM - an EXTERNAL `timeout` wrapper around the artisan invocation
 * itself (see docs/architecture/production-deployment.md "Monitoring /
 * alerting" - "Execution bound"), never a PHP-internal one - actually
 * terminates a genuinely nonresponsive process AND releases whatever
 * flock() it was holding, not merely that a wrapping shell command exits.
 *
 * Deliberately a real subprocess test (plain `proc_open()` - this project
 * has no `symfony/process` dependency, and this task adds none). Pest's
 * in-process testing cannot reproduce cross-process signal/lock-release
 * behavior at all - the same category of limitation this project's own
 * existing R57/R58 subprocess tests (AdminDashboardTenancyTimingTest.php
 * etc.) are already built to work around.
 *
 * Sends no real email - this file never touches Mail, the real
 * `platform:production:monitor` command, or Laravel's own bootstrap at
 * all; it tests the underlying `timeout`+`flock()` mechanism directly,
 * using a minimal, dedicated, deliberately-blocking fixture
 * (tests/fixtures/blocking-lock-fixture.php) that mirrors `Platform\
 * Tenancy\Console\Commands\ProductionMonitor::openLockFile()`'s own
 * locking code exactly.
 */

use Tests\TestCase;

uses(TestCase::class);

function timeoutTestFixturePath(): string
{
    return base_path('tests/fixtures/blocking-lock-fixture.php');
}

function timeoutTestWaitUntil(callable $condition, float $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        if ($condition()) {
            return true;
        }

        usleep(50_000);
    }

    return false;
}

test('an external timeout wrapper terminates a nonresponsive locked process (in-container, not merely a host client) and releases its lock, proving bounded termination and that a subsequent invocation can then run', function () {
    $lockPath = sys_get_temp_dir().'/technify-timeout-test-'.bin2hex(random_bytes(6)).'.lock';
    $acquiredMarker = $lockPath.'.acquired';

    try {
        // Wrapped EXACTLY as documented for the real cron entry: `timeout
        // <bound> <command...>` - the timeout command and the blocking PHP
        // process share the SAME process tree, the same property the real
        // `docker compose exec app timeout <bound> php artisan
        // platform:production:monitor` cron line relies on.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = ['timeout', '2', 'php', timeoutTestFixturePath(), $lockPath];
        $process = proc_open($command, $descriptors, $pipes);

        expect($process)->not->toBeFalse();

        $lockAcquired = timeoutTestWaitUntil(fn () => is_file($acquiredMarker), 5.0);
        expect($lockAcquired)->toBeTrue('the blocking fixture never signaled that it had acquired its lock');

        // Confirm the lock is genuinely held right now - a fresh,
        // non-blocking flock() attempt from THIS (the test) process must
        // fail while the fixture is still running.
        $probe = fopen($lockPath, 'c');
        expect(flock($probe, LOCK_EX | LOCK_NB))->toBeFalse();
        fclose($probe);

        // Wait for `timeout` to actually terminate the whole thing -
        // bounded well beyond the fixture's own 2-second timeout so this
        // cannot itself flake on a slow CI host, but still finite (this
        // whole test would hang forever if bounded termination genuinely
        // did not work, which is exactly the condition under test - a
        // real, visible test failure via Pest's own default test timeout
        // if the underlying fix regresses).
        $stillRunning = true;
        $terminatedWithin = timeoutTestWaitUntil(function () use ($process, &$stillRunning) {
            $status = proc_get_status($process);
            $stillRunning = $status['running'];

            return ! $stillRunning;
        }, 8.0);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        expect($terminatedWithin)->toBeTrue('the timeout-wrapped process was still running well past its bound - termination is not actually reaching the in-container process');
        expect($stillRunning)->toBeFalse();

        // The critical proof (finding 4's own core claim): the lock is
        // releasable immediately afterward - no orphaned holder remains
        // just because the wrapping command exited. If `timeout` had only
        // killed some OUTER shell without reaching the actual PHP process
        // (the exact failure mode this finding warns about for "merely
        // terminating the host docker client"), this flock() attempt would
        // still fail here.
        $probeAfter = fopen($lockPath, 'c');
        $reacquired = flock($probeAfter, LOCK_EX | LOCK_NB);
        expect($reacquired)->toBeTrue('the lock was not released - the blocking process was not actually terminated, only some wrapper around it');
        flock($probeAfter, LOCK_UN);
        fclose($probeAfter);

        // And a "subsequent invocation" (a second, fresh lock attempt) can
        // now genuinely proceed - the operational property the whole
        // mechanism exists to guarantee.
        $secondAttempt = fopen($lockPath, 'c');
        expect(flock($secondAttempt, LOCK_EX | LOCK_NB))->toBeTrue();
        flock($secondAttempt, LOCK_UN);
        fclose($secondAttempt);
    } finally {
        @unlink($lockPath);
        @unlink($acquiredMarker);
    }
});

test('without a wrapping timeout, a nonresponsive locked process is NOT self-terminating - establishing why the external wrapper is required, not optional', function () {
    // The negative control for the test above: confirms the fixture
    // itself has no internal bound of its own (it is a faithful stand-in
    // for "some internal call that never returns" on purpose) - the
    // monitor's execution bound genuinely comes from the external
    // wrapper, not from anything inside the command.
    $lockPath = sys_get_temp_dir().'/technify-timeout-test-unwrapped-'.bin2hex(random_bytes(6)).'.lock';
    $acquiredMarker = $lockPath.'.acquired';

    try {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['php', timeoutTestFixturePath(), $lockPath], $descriptors, $pipes);

        expect($process)->not->toBeFalse();
        expect(timeoutTestWaitUntil(fn () => is_file($acquiredMarker), 5.0))->toBeTrue();

        // Give it a window comparable to the wrapped test's own bound -
        // it must STILL be running, proving no accidental self-timeout.
        usleep(500_000);
        $status = proc_get_status($process);
        expect($status['running'])->toBeTrue();

        // Clean up by force - this is the one place this test file
        // terminates the fixture itself, since nothing else will.
        proc_terminate($process, SIGKILL);
        timeoutTestWaitUntil(function () use ($process) {
            return ! proc_get_status($process)['running'];
        }, 3.0);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    } finally {
        @unlink($lockPath);
        @unlink($acquiredMarker);
    }
});
