<?php

/**
 * TASK-OPS-MONITORING-001A. A minimal, deliberately-blocking fixture used
 * only by tests/Feature/Platform/ProductionMonitorTimeoutTest.php to prove
 * the documented external `timeout` wrapper (see docs/architecture/
 * production-deployment.md "Monitoring / alerting" - "Execution bound")
 * genuinely terminates a nonresponsive process AND releases whatever
 * flock() it was holding, when killed inside the SAME container/process
 * tree - never merely a host-side docker client exiting.
 *
 * Mirrors `Platform\Tenancy\Console\Commands\ProductionMonitor::openLockFile()`'s
 * own `fopen(..., 'c')` + `flock(LOCK_EX|LOCK_NB)` locking exactly, without
 * needing a full Laravel bootstrap (flock() is a plain PHP/OS primitive,
 * and this project has no `symfony/process` dependency to script a real
 * child process any other way - `proc_open()`/plain PHP CLI only).
 *
 * Never invoked by any real code path - test-only, not autoloaded, not
 * registered as a command.
 */
$lockPath = $argv[1] ?? null;

if ($lockPath === null) {
    fwrite(STDERR, "usage: blocking-lock-fixture.php <lock-path>\n");
    exit(2);
}

$handle = fopen($lockPath, 'c');

if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "could not acquire lock\n");
    exit(1);
}

// Signal to the test harness that the lock is genuinely held now, before
// blocking - the harness polls for this file rather than guessing a sleep
// duration.
file_put_contents($lockPath.'.acquired', (string) getmypid());

// Simulate an internal call that never returns (a genuinely nonresponsive
// dependency - Redis, HTTP, mail, anything) - the exact failure mode
// finding 4 is about. `sleep()` here is deliberately interruptible by a
// real signal (SIGTERM/SIGKILL from the wrapping `timeout` command), which
// is precisely the property under test.
sleep(3600);
