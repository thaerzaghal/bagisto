<?php

/**
 * TASK-ARCH-006 - tenant queue/background-job isolation security test matrix.
 *
 * Addresses RISK_REGISTER.md R6/R7. Real Redis queue (config(['queue.default'
 * => 'redis']) set below - NOT the 'sync' driver, which never touches a real
 * queue backend or fires JobProcessing/JobProcessed/JobFailed at all, and
 * would hide the exact class of bug this task is meant to catch), real
 * MySQL, a real Illuminate\Queue\Worker pop-and-process cycle via
 * `Artisan::call('queue:work', [..., '--once' => true])` for every
 * isolation-proof test. Nothing mocked, no fake queue.
 *
 * Mechanism under test: Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper
 * (already enabled in config/tenancy.php since TASK-ARCH-002/003, previously
 * unexercised) tags every queued job's payload with 'tenant_id' at dispatch
 * time (via Queue::createPayloadUsing()) whenever tenancy is initialized,
 * and restores/ends tenancy around each job via JobProcessing/JobProcessed/
 * JobFailed listeners it registers itself (Stancl\Tenancy\
 * TenancyServiceProvider::register() calls QueueTenancyBootstrapper::
 * __constructStatic() once per app boot - confirmed live via
 * bootstrap/cache/packages.php that this auto-discovered provider is
 * actually registered alongside our own Platform\Tenancy\Providers\
 * TenancyServiceProvider, and via config/tenancy.php that
 * QueueTenancyBootstrapper::class is in the bootstrappers list).
 *
 * IMPORTANT, real, reproduced gotcha that shaped every dispatch call below:
 * Stancl\Tenancy\Database\Concerns\TenantRun::run() is
 * `$result = $callback($this); tenancy()->end(); return $result;` - it
 * CAPTURES the closure's return value and only reverts tenancy AFTER. Since
 * Illuminate\Foundation\Bus\Dispatchable::dispatch() returns a PendingDispatch
 * that only actually calls Queue::push() in its __destruct(), writing
 * `$tenant->run(fn () => SomeJob::dispatch(...))` (an ARROW FUNCTION, whose
 * implicit return value is the PendingDispatch) lets that object escape the
 * closure and stay alive (referenced by $result) until AFTER run() has
 * already reverted tenancy - so the job gets pushed with the WRONG (already
 * central) context, silently. Reproduced live while building this file:
 * the exact same dispatch line, as an arrow function vs. as a plain
 * statement inside a block closure, produced a tenant-tagged vs. untagged
 * payload. Every dispatch call in this file therefore uses
 * `$tenant->run(function () { SomeJob::dispatch(...); })` - a bare
 * statement inside a block closure, never returned - so PendingDispatch is
 * destroyed (and the job actually pushed) while still inside the tenant
 * context. See docs/architecture/queues.md for the full writeup; this is
 * documented there as a real gotcha for any future Platform code that
 * dispatches jobs from inside a tenant->run()/tenancy()->central() closure.
 *
 * One real, reproduced gap was found and fixed this task: stancl's own
 * cleanup only fires on JobProcessed/JobFailed, neither of which Laravel
 * fires when a job throws but still has retries remaining (Illuminate\Queue\
 * Events\JobReleasedAfterException fires instead) - see
 * Platform\Tenancy\Listeners\EndTenancyAfterJobRelease for the fix, and the
 * "released for retry" test below for the live, watched-in-both-directions
 * proof (reproduced with the fix reverted, confirmed fixed with it applied -
 * same evidentiary standard as every other fix in this project).
 *
 * See docs/architecture/queues.md for the full architecture this proves.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Jobs\TenantIsolationProbeJob;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Marketing\Jobs\UpdateCreateSearchTerm;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const QUEUE_TEST_TENANT_IDS = ['tenant-queue-a', 'tenant-queue-b'];

/**
 * TEST-ONLY SCHEMA (product-owner review, 2026-08-14): queue_isolation_probes/
 * central_queue_isolation_probes hold nothing but diagnostic data (markers,
 * observed tenant ids, PIDs, object ids, a channel-code marker) - no real
 * SaaS commerce/platform data ever lives here. These were originally created
 * via permanent migrations (database/migrations/tenant/..., database/
 * migrations/...), meaning every real tenant database - and the central
 * database - would have permanently carried a diagnostic-only table forever.
 * Moved to test-only setup instead: created directly here via Schema::create()
 * when a test needs them, never through TenantProvisioner::ensureMigrated()
 * or a real `php artisan migrate` run, so production tenants and the real
 * central database never see this schema at all. Platform\Tenancy\Jobs\
 * TenantIsolationProbeJob itself is unaffected - it only ever did
 * DB::table('queue_isolation_probes')/DB::connection('mysql')
 * ->table('central_queue_isolation_probes'), agnostic to how the table
 * came to exist.
 */
function ensureCentralQueueProbeSchema(): void
{
    if (! Schema::connection('mysql')->hasTable('central_queue_isolation_probes')) {
        Schema::connection('mysql')->create('central_queue_isolation_probes', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('marker');
            $table->unsignedInteger('worker_pid')->nullable();
            $table->unsignedInteger('core_facade_object_id')->nullable();
            $table->timestamps();
        });
    }
}

function ensureTenantQueueProbeSchema(Tenant $tenant): void
{
    $tenant->run(function () {
        if (! Schema::hasTable('queue_isolation_probes')) {
            Schema::create('queue_isolation_probes', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('marker');
                $table->string('observed_tenant_id')->nullable();
                $table->unsignedInteger('observed_attempt')->default(1);
                $table->unsignedInteger('worker_pid')->nullable();
                $table->unsignedInteger('core_facade_object_id')->nullable();
                $table->string('observed_channel_code')->nullable();
                $table->timestamps();
            });
        }
    });
}

function ensureQueueTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-queue-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-queue-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-queue-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }
    ensureTenantQueueProbeSchema($tenantA);

    $tenantB = Tenant::find('tenant-queue-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-queue-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-queue-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }
    ensureTenantQueueProbeSchema($tenantB);

    return [$tenantA, $tenantB];
}

function cleanupQueueTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (QUEUE_TEST_TENANT_IDS as $id) {
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

        File::deleteDirectory(storage_path('tenant'.$id));
    }
}

/**
 * Dispatches TenantIsolationProbeJob from inside the given tenant's context
 * (or centrally, if $tenant is null) - a single, reused helper so every call
 * site in this file gets the block-closure-with-a-bare-statement shape
 * described in this file's top docblock, rather than relying on every test
 * author remembering the gotcha independently.
 */
function dispatchProbe(?\Platform\Tenancy\Models\Tenant $tenant, string $marker, ?int $failBelowAttempt = null, int $maxTries = 3): void
{
    if ($tenant) {
        $tenant->run(function () use ($marker, $failBelowAttempt, $maxTries) {
            TenantIsolationProbeJob::dispatch($marker, $failBelowAttempt, $maxTries);
        });
    } else {
        TenantIsolationProbeJob::dispatch($marker, $failBelowAttempt, $maxTries);
    }
}

/**
 * Processes exactly one job from the real redis queue via a real
 * Illuminate\Queue\Worker (same class `queue:work` itself uses in daemon
 * mode) - not sync, not a fake. Returns the Artisan command's textual
 * output (RUNNING/DONE/FAIL lines) for tests that want to assert on it.
 */
function processOneQueuedJob(): string
{
    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--once' => true,
    ]);

    return Artisan::output();
}

beforeEach(function () {
    config(['queue.default' => 'redis']);

    // TASK-ARCH-007A (R29): this file's own comment below already claimed the
    // cache here is "real redis... external, persistent state", and already
    // flushed the real redis 'cache' connection - but never actually forced
    // cache.default to 'redis', so it silently ran against the test-suite's
    // CACHE_STORE=array default instead. docs/architecture/caching.md
    // (TASK-ARCH-004) already documented, and this task re-confirmed live via
    // spl_object_id(), that CacheTenancyBootstrapper creates a brand-new
    // CacheManager on every tenancy bootstrap cycle - array's in-process
    // storage does not survive that (by design, not a bug: no cross-tenant
    // leak either way, just no cross-cycle persistence), while redis's
    // external storage does. This file's own probe tests dispatch a real job
    // through a real worker - a genuine bootstrap-cycle boundary - and then
    // read the cache back in a separate cycle, exactly the case array cannot
    // support. Forcing redis here (already the project's documented
    // production cache store, not introduced by this fix) makes the test
    // exercise the same real, persistent, external backing store this file
    // already uses for its queue - matching this file's own "real Redis
    // queue... nothing mocked, no fake queue" standard (see top docblock).
    config(['cache.default' => 'redis']);

    // The real redis queue AND cache are shared, external, persistent state
    // (unlike a DB transaction, nothing rolls this back between tests) -
    // start every test from a genuinely empty queue and cache so no other
    // test's leftover/delayed retry, and no accumulated
    // 'probe-real-attempts-*' counter (see TenantIsolationProbeJob::handle())
    // from a previous run of this file, can affect this test.
    Redis::connection('default')->flushdb();
    Redis::connection('cache')->flushdb();

    ensureCentralQueueProbeSchema();
    [$this->tenantA, $this->tenantB] = ensureQueueTestFixtures();

    DB::connection('mysql')->table('failed_jobs')->truncate();
    DB::connection('mysql')->table('central_queue_isolation_probes')->truncate();
    $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->truncate());
    $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->truncate());
});

test('a real queued job dispatched under Tenant A restores Tenant A on the worker, writes to DB A only, and ends tenancy afterward - then the identical sequence for Tenant B', function () {
    dispatchProbe($this->tenantA, 'run-1-A');
    expect(tenancy()->initialized)->toBeFalse('dispatch itself must not leave the calling process tenant-initialized');

    $output = processOneQueuedJob();
    expect($output)->toContain('DONE');
    expect(tenancy()->initialized)->toBeFalse('tenancy must be ended by the worker after a successful job, not left dangling');

    $rowA = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'run-1-A')->first());
    expect($rowA)->not->toBeNull();
    expect($rowA->observed_tenant_id)->toBe('tenant-queue-a');

    // The identical logical row/marker must never appear in Tenant B's own
    // database - real isolation, not just "the test never looked".
    $rowAInB = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'run-1-A')->first());
    expect($rowAInB)->toBeNull();

    // --- Tenant B, same proof ---
    dispatchProbe($this->tenantB, 'run-1-B');
    processOneQueuedJob();
    expect(tenancy()->initialized)->toBeFalse();

    $rowB = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'run-1-B')->first());
    expect($rowB->observed_tenant_id)->toBe('tenant-queue-b');
});

test('cache isolation survives real queue execution: Tenant A and Tenant B jobs write the identical logical key with no clearing between', function () {
    dispatchProbe($this->tenantA, 'TENANT_A');
    processOneQueuedJob();

    dispatchProbe($this->tenantB, 'TENANT_B');
    processOneQueuedJob();

    // No Cache::forget() anywhere in between - isolation must come from the
    // tag-based CacheTenancyBootstrapper (TASK-ARCH-004), not from clearing.
    expect($this->tenantA->run(fn () => Cache::get('queue-isolation')))->toBe('TENANT_A');
    expect($this->tenantB->run(fn () => Cache::get('queue-isolation')))->toBe('TENANT_B');
});

test('filesystem isolation survives real queue execution: Tenant A and Tenant B jobs write the identical logical path, physically isolated', function () {
    dispatchProbe($this->tenantA, 'TENANT_A');
    processOneQueuedJob();

    dispatchProbe($this->tenantB, 'TENANT_B');
    processOneQueuedJob();

    expect($this->tenantA->run(fn () => Storage::disk('public')->get('queue-isolation/shared.txt')))->toBe('TENANT_A');
    expect($this->tenantB->run(fn () => Storage::disk('public')->get('queue-isolation/shared.txt')))->toBe('TENANT_B');

    $physicalA = $this->tenantA->run(fn () => Storage::disk('public')->path('queue-isolation/shared.txt'));
    $physicalB = $this->tenantB->run(fn () => Storage::disk('public')->path('queue-isolation/shared.txt'));
    expect($physicalA)->not->toBe($physicalB);
    expect(file_get_contents($physicalA))->toBe('TENANT_A');
    expect(file_get_contents($physicalB))->toBe('TENANT_B');
});

test('A -> B -> A in the same worker lifecycle never leaks tenant context forward (three separate --once pops)', function () {
    // CORRECTED (product-owner review, 2026-08-14): this test calls
    // processOneQueuedJob() three separate times, each a fresh
    // Artisan::call('queue:work', ['--once' => true]) invocation. That IS
    // still the same OS process/PHP runtime as this test method - confirmed
    // by reading Illuminate\Foundation\Console\Kernel::call(), which invokes
    // the command directly in-process (no exec/proc_open/shell_exec) - but
    // it is three separate WORKER-LOOP invocations, not one continuous
    // multi-job loop, and this test captured no hard evidence (like a PID)
    // of same-process execution. The stronger, evidence-backed proof - one
    // single queue:work invocation draining a pre-queued A/B/A/central
    // sequence in one continuous loop, with getmypid() recorded per job -
    // is the next test below. This test is kept as an additional, cheaper
    // regression check; its title no longer overclaims what it proves.
    dispatchProbe($this->tenantA, 'seq-1-A');
    processOneQueuedJob();
    $afterA1 = tenancy()->initialized;

    dispatchProbe($this->tenantB, 'seq-2-B');
    processOneQueuedJob();
    $afterB = tenancy()->initialized;

    dispatchProbe($this->tenantA, 'seq-3-A');
    processOneQueuedJob();
    $afterA2 = tenancy()->initialized;

    expect($afterA1)->toBeFalse();
    expect($afterB)->toBeFalse();
    expect($afterA2)->toBeFalse();

    $seq1 = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'seq-1-A')->first());
    $seq2 = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'seq-2-B')->first());
    $seq3 = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'seq-3-A')->first());

    expect($seq1->observed_tenant_id)->toBe('tenant-queue-a');
    expect($seq2->observed_tenant_id)->toBe('tenant-queue-b');
    expect($seq3->observed_tenant_id)->toBe('tenant-queue-a');

    // seq-2-B must never have landed in Tenant A's database, and seq-1-A/
    // seq-3-A must never have landed in Tenant B's - cross-check both ways.
    expect($this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'seq-2-B')->first()))->toBeNull();
    expect($this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'seq-1-A')->orWhere('marker', 'seq-3-A')->first()))->toBeNull();
});

test('TRUE long-lived worker: ONE queue:work invocation drains a pre-queued Tenant A -> Tenant B -> Tenant A -> Central sequence in a single continuous loop, with getmypid() proving it never restarted PHP between jobs', function () {
    // This is the stronger proof requested after review of the test above:
    // all four jobs are pushed onto the real Redis queue BEFORE the worker
    // starts, then ONE single Artisan::call('queue:work', ['--stop-when-empty'
    // => true]) call drains all of them in Illuminate\Queue\Worker's own
    // internal loop (Worker::daemon()/runNextJob(), called repeatedly by
    // WorkCommand until the queue is empty) - this test regains control
    // only once, after everything has been processed, never in between.
    $testPid = getmypid();

    // Give each tenant's default channel a distinct, tenant-specific marker
    // BEFORE dispatching - the concrete data-level check (not just object
    // identity) for whether Webkul\Core\Core's memoized $currentChannel
    // (packages/Webkul/Core/src/Core.php:137-151, cached on first access,
    // resolved via Laravel's Facade-instance caching - see this file's top
    // docblock reasoning extended to Core specifically) leaks across a
    // tenant switch within one worker process.
    $this->tenantA->run(function () {
        DB::table('channels')->where('id', 1)->update(['code' => 'daemon-marker-a']);
    });
    $this->tenantB->run(function () {
        DB::table('channels')->where('id', 1)->update(['code' => 'daemon-marker-b']);
    });

    dispatchProbe($this->tenantA, 'daemon-1-A');
    dispatchProbe($this->tenantB, 'daemon-2-B');
    dispatchProbe($this->tenantA, 'daemon-3-A');
    dispatchProbe(null, 'daemon-4-central');

    expect(Redis::connection('default')->llen('queues:default'))
        ->toBe(4, 'precondition: all four jobs must be queued before the worker starts, proving the worker drains a real backlog rather than being fed one at a time');

    // Central queue infrastructure (Section 7), checked here while the
    // queue key still exists (Redis auto-deletes an empty LIST key, so this
    // check would trivially pass-by-absence if done after draining): the
    // real Redis key set for this queue is exactly the base 'default' queue
    // list plus its companion blocking-pop notification key (RedisQueue's
    // own '{queue}:notify' key, unrelated to tenancy) - confirmed via a
    // live key dump. Critically, NEITHER key name, nor any other key in
    // this Redis database, contains a tenant id - proving no per-tenant
    // queue key/namespace was created. RedisTenancyBootstrapper
    // (config/tenancy.php) remains deliberately disabled - if active, it
    // would prefix raw Redis:: key namespaces per tenant (see
    // vendor/stancl/tenancy/src/Bootstrappers/RedisTenancyBootstrapper.php),
    // which would show up here as tenant-id-bearing key names.
    $queueKeys = Redis::connection('default')->keys('*queue*');
    expect($queueKeys)->not->toBeEmpty();
    foreach ($queueKeys as $key) {
        expect($key)->not->toContain('tenant-queue-a');
        expect($key)->not->toContain('tenant-queue-b');
    }

    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--stop-when-empty' => true,
    ]);
    $output = Artisan::output();

    expect(substr_count($output, 'RUNNING'))->toBe(4);
    expect(substr_count($output, 'DONE'))->toBe(4);
    expect(Redis::connection('default')->llen('queues:default'))->toBe(0);
    expect(tenancy()->initialized)->toBeFalse('the worker must end centered/uninitialized after draining the queue, matching the central job it processed last');

    $row1 = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'daemon-1-A')->first());
    $row2 = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'daemon-2-B')->first());
    $row3 = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'daemon-3-A')->first());
    $row4 = DB::connection('mysql')->table('central_queue_isolation_probes')->where('marker', 'daemon-4-central')->first();

    expect($row1)->not->toBeNull();
    expect($row2)->not->toBeNull();
    expect($row3)->not->toBeNull();
    expect($row4)->not->toBeNull();

    // The sequence claim itself: A, then B, then A again, then central.
    expect($row1->observed_tenant_id)->toBe('tenant-queue-a');
    expect($row2->observed_tenant_id)->toBe('tenant-queue-b');
    expect($row3->observed_tenant_id)->toBe('tenant-queue-a');

    // Hard PID evidence, exactly as requested: all four jobs, AND the test
    // method itself (which pushed them and started the worker), share one
    // OS process. If queue:work had spawned a subprocess, these would differ.
    expect($row1->worker_pid)->toBe($testPid);
    expect($row2->worker_pid)->toBe($testPid);
    expect($row3->worker_pid)->toBe($testPid);
    expect($row4->worker_pid)->toBe($testPid);

    // Core-facade leak check - HYPOTHESIS TESTED AND DISPROVEN, see this
    // test's trailing comment for the full mechanism. Webkul\Core\Core is
    // resolved via Facade::getFacadeRoot(), which DOES memoize the resolved
    // instance in a process-lifetime static property with nothing in
    // packages/Webkul or packages/Platform resetting it on a tenancy
    // transition - but Illuminate\Queue\QueueServiceProvider registers a
    // $resetScope closure (calling Facade::clearResolvedInstances(), among
    // other resets) that Illuminate\Queue\Worker::daemon() - the loop this
    // --stop-when-empty test actually exercises - runs before EVERY job.
    // Confirmed live: all three tenant jobs get a genuinely fresh Core
    // instance (three different object ids), and each one's
    // getCurrentChannel() call correctly re-queries its own tenant's data.
    expect($row1->core_facade_object_id)->not->toBe($row2->core_facade_object_id);
    expect($row2->core_facade_object_id)->not->toBe($row3->core_facade_object_id);

    // Cache and filesystem isolation through the SAME daemon run (Section 3's
    // required sequence: each job's Cache/Storage writes, inspected after
    // the fact). Job 3 (daemon-3-A) was the last write to Tenant A's cache
    // key/file path, so Tenant A's final state reflects it; Tenant B's
    // reflects its own single job (daemon-2-B) since nothing overwrote it.
    expect($this->tenantA->run(fn () => Cache::get('queue-isolation')))->toBe('daemon-3-A');
    expect($this->tenantB->run(fn () => Cache::get('queue-isolation')))->toBe('daemon-2-B');
    expect($this->tenantA->run(fn () => Storage::disk('public')->get('queue-isolation/shared.txt')))->toBe('daemon-3-A');
    expect($this->tenantB->run(fn () => Storage::disk('public')->get('queue-isolation/shared.txt')))->toBe('daemon-2-B');

    // Concrete data-level confirmation of the same result: each job sees
    // only its own tenant's channel marker, never another tenant's.
    expect($row1->observed_channel_code)->toBe('daemon-marker-a');
    expect($row2->observed_channel_code)->toBe('daemon-marker-b');
    expect($row3->observed_channel_code)->toBe('daemon-marker-a');

    // Restore the shared channel fixture's code so other tests/files reusing
    // tenant-a/tenant-b-style default-channel assumptions aren't affected -
    // tenant-queue-a/b are this file's own dedicated fixtures, but hygiene
    // matters regardless.
    $this->tenantA->run(function () {
        DB::table('channels')->where('id', 1)->update(['code' => 'default']);
    });
    $this->tenantB->run(function () {
        DB::table('channels')->where('id', 1)->update(['code' => 'default']);
    });
});

test('TRUE long-lived worker: a Tenant A job that releases for retry does not poison the very next Tenant B job in the SAME worker (R26, re-verified under the daemon loop, not separate --once calls)', function () {
    // R26's original reproduction/fix (see Platform\Tenancy\Listeners\
    // EndTenancyAfterJobRelease) was verified with a single --once call and
    // an after-the-fact tenancy()->initialized check from the TEST process.
    // Re-verified here under the TRUE daemon loop (--stop-when-empty), and
    // re-confirmed by TEMPORARILY disabling the fix and re-running (not
    // committed that way - restored immediately after): with the fix
    // removed, tenancy()->initialized stayed true after the whole run.
    // IMPORTANT PRECISION, found during that re-verification: even WITHOUT
    // the fix, Tenant B's job still correctly ran (and wrote to) tenant B's
    // own database - Stancl\Tenancy\Tenancy::initialize() unconditionally
    // calls end() before switching to a different tenant, so the NEXT job
    // is never actually misattributed. What R26 actually fixes is narrower
    // and still real: tenancy()->initialized/tenant() staying incorrectly
    // "on" (pointing at whichever tenant most recently ran) for longer than
    // it should - a dangling-process-state bug, not a data-misattribution
    // bug. Framed accurately in RISK_REGISTER.md/docs/architecture/queues.md.
    dispatchProbe($this->tenantA, 'release-then-b-A', failBelowAttempt: 2, maxTries: 3);
    dispatchProbe($this->tenantB, 'release-then-b-B');

    $testPid = getmypid();

    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--stop-when-empty' => true,
    ]);
    $output = Artisan::output();

    // A always releases (FAIL) at least once; B always succeeds (DONE)
    // within this same continuous loop - the two claims this test actually
    // needs. NOT asserted as an exact DONE count: with no backoff
    // configured, Tenant A's released job OFTEN becomes available again
    // fast enough for this same --stop-when-empty loop to also pick it up
    // and succeed (observed during development) - but Redis' delayed-job
    // availability is timestamp-based, sub-second timing that is not
    // deterministically guaranteed to land inside one worker invocation,
    // especially under real system load (this flaked once during a full
    // 45-test suite run under load - a real timing race in the TEST, not a
    // product bug). Handled below by explicitly finishing A's retry with a
    // follow-up processOneQueuedJob() call if the daemon loop didn't
    // already catch it, rather than asserting on timing.
    expect($output)->toContain('FAIL');
    expect($output)->toContain('DONE');

    expect(tenancy()->initialized)->toBeFalse();

    // Tenant B's job ran correctly as tenant B (true with or without R26's
    // fix, per the precision note above - Tenancy::initialize() itself
    // handles switching away from a stale tenant safely; this assertion
    // documents that correctness, not the specific bug R26 fixes).
    $bRow = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'release-then-b-B')->first());
    expect($bRow)->not->toBeNull();
    expect($bRow->observed_tenant_id)->toBe('tenant-queue-b');
    expect($bRow->worker_pid)->toBe($testPid);

    // Tenant A's job: if the same continuous loop didn't already catch its
    // natural retry (timing-dependent, see above), finish it explicitly -
    // still the same OS process/PID either way, since nothing in this test
    // exits between the daemon call and this fallback.
    $aRow = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'release-then-b-A')->first());
    if (! $aRow) {
        processOneQueuedJob();
        $aRow = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'release-then-b-A')->first());
    }

    // Once it DID succeed, it correctly restored TENANT A - not central,
    // not tenant B, despite having been "poisoned" by a release in between.
    expect($aRow)->not->toBeNull();
    expect($aRow->observed_tenant_id)->toBe('tenant-queue-a');
    expect($aRow->worker_pid)->toBe($testPid);
});

test('R27b assumptions verified: queue:retry itself performs no tenant DB/cache/filesystem writes, and its dangling tenancy state does not corrupt a subsequent Tenant B job processed by a real daemon afterward', function () {
    // R27b (docs/architecture/queues.md, RISK_REGISTER.md) was judged low
    // real-world impact on the assumption that `queue:retry` normally runs
    // as its own short-lived CLI process, separate from the `queue:work`
    // daemon - so its dangling tenancy dies with that process. This test
    // verifies the two assumptions that ARE testable from inside one PHP
    // process (process separation itself is a deployment-topology fact,
    // not something a unit/feature test can observe from within a single
    // process - documented, not asserted here).
    dispatchProbe($this->tenantA, 'r27b-fail', failBelowAttempt: 2, maxTries: 1);
    processOneQueuedJob();

    $failedRow = DB::connection('mysql')->table('failed_jobs')->latest('failed_at')->first();
    expect($failedRow)->not->toBeNull();

    // Assumption 1: queue:retry itself writes nothing to any tenant's DB/
    // cache/filesystem - it only moves a payload from failed_jobs back onto
    // the queue (Illuminate\Queue\Console\RetryCommand::retryJob() calls
    // pushRaw() and the failer's forget(), nothing else). Confirmed: no
    // probe row exists for this marker in Tenant A's DB immediately after
    // queue:retry, before any worker has processed the re-queued job.
    Artisan::call('queue:retry', ['id' => [$failedRow->uuid]]);
    expect(tenancy()->initialized)->toBeTrue('precondition: reproduces R27b\'s dangling state, so the next check is meaningful');
    $prematureRow = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'r27b-fail')->first());
    expect($prematureRow)->toBeNull('queue:retry must not have written anything itself - only re-queued the payload');

    // Assumption 2: with tenancy left dangling (as Tenant A) by queue:retry,
    // queue a fresh Tenant B job and drain BOTH (the re-queued A retry, then
    // B) via one real daemon loop - Tenant B's job must still write to
    // Tenant B's own database, not Tenant A's.
    dispatchProbe($this->tenantB, 'r27b-after-retry-B');

    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => 'default',
        '--stop-when-empty' => true,
    ]);

    // DEEPER FINDING than originally scoped (found while writing this
    // verification, not assumed): tenancy does NOT end up central here -
    // it ends up back on TENANT A. Full mechanism: queue:retry's
    // JobRetryRequested handler leaves tenancy initialized as A (already
    // known, R27b). When the daemon then pops the retried A job, stancl's
    // QueueTenancyBootstrapper captures $previousTenant = tenant() = A
    // (already dangling) BEFORE re-initializing - since the job's own
    // tenant IS A, JobProcessed's revertToPreviousState() sees "previous
    // tenant == current tenant" and treats it as a nested-dispatch no-op,
    // leaving tenancy on A instead of ending it. When B's job pops next,
    // $previousTenant is captured as that same stale A, tenancy correctly
    // switches to B for the job itself (Tenancy::initialize() is robust to
    // that), B's JobProcessed then sees "previous tenant (A) != current (B)"
    // and takes the "revert back to the previous tenant" branch - reverting
    // to A, not central. Data isolation still holds throughout (verified
    // below) - only the final process-level tenancy state is wrong, and
    // only reachable at all if queue:retry and queue:work share a process,
    // which is the same precondition R27b already documents as not
    // matching this app's real deployment topology (separate CLI process
    // vs. daemon). Recorded here with the fuller mechanism traced, not
    // silently smoothed over.
    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant()->getTenantKey())->toBe('tenant-queue-a');

    $bRow = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'r27b-after-retry-B')->first());
    expect($bRow)->not->toBeNull();
    expect($bRow->observed_tenant_id)->toBe('tenant-queue-b', 'the actual job execution and data write are correct regardless of the end-of-run process-state anomaly above');

    $leakedIntoA = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'r27b-after-retry-B')->first());
    expect($leakedIntoA)->toBeNull();
});

/**
 * HYPOTHESIS TESTED AND DISPROVEN (product-owner review, 2026-08-14) - a
 * real investigation, not a speculative fix, and reported here per this
 * task's instruction to document either outcome honestly.
 *
 * Going in, there was real reason to suspect a leak: Webkul\Core\Core
 * (resolved via the `core()` helper / `Core` facade) memoizes
 * $currentChannel/$currentCurrency/$currentLocale on first access
 * (packages/Webkul/Core/src/Core.php) and nothing in packages/Webkul or
 * packages/Platform explicitly resets those properties on a tenancy
 * transition. Standard Laravel Facade caching (Facade::$cached = true,
 * Facade::resolveFacadeInstance()) would normally keep the SAME resolved
 * Core instance alive for the life of the PHP process.
 *
 * The test above found this does NOT actually happen in the realistic
 * production topology: Illuminate\Queue\QueueServiceProvider registers a
 * $resetScope closure - which calls Facade::clearResolvedInstances(), among
 * other resets (log context, per-connection query-duration counters,
 * scoped container instances) - and Illuminate\Queue\Worker::daemon() (the
 * loop `queue:work` actually runs in production, and what
 * `--stop-when-empty` exercises here) calls that closure before EVERY
 * single job, unconditionally, regardless of tenant. Confirmed live: three
 * consecutive jobs in one real worker process each got a fresh, distinct
 * Core instance (three different spl_object_id() values) and each
 * correctly read only its own tenant's channel data.
 *
 * One real caveat, not a leak: Illuminate\Queue\Worker::runNextJob()
 * (what `--once` calls - see WorkCommand.php: `{$this->option('once') ?
 * 'runNextJob' : 'daemon'}`) does NOT call $resetScope. This app never
 * runs `--once` in production (it exists for this test suite's own
 * per-job introspection convenience, and for manual debugging) - a real
 * `queue:work` deployment always runs in daemon mode. Noted for
 * completeness, not treated as a finding requiring action.
 */
test('a job dispatched with no tenant context active remains central and does not inherit the previously processed tenant', function () {
    // Process a Tenant A job first, in the SAME worker lifecycle, so there
    // genuinely IS a "previous tenant" for the central job to not inherit.
    dispatchProbe($this->tenantA, 'pre-central-A');
    processOneQueuedJob();
    expect(tenancy()->initialized)->toBeFalse();

    // Dispatched with NO active tenant() context at all.
    dispatchProbe(null, 'central-job');
    $output = processOneQueuedJob();

    expect($output)->toContain('DONE');
    expect(tenancy()->initialized)->toBeFalse('a central job must never leave the worker tenant-initialized');

    $centralRow = DB::connection('mysql')->table('central_queue_isolation_probes')->where('marker', 'central-job')->first();
    expect($centralRow)->not->toBeNull('the central job must be able to write to real central infrastructure');

    // It must not have written into Tenant A's database either.
    $leakedIntoA = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'central-job')->first());
    expect($leakedIntoA)->toBeNull();
});

test('the raw queued payload for a tenant job contains only the trusted tenant identifier, never secrets, and a central job carries no tenant metadata at all', function () {
    dispatchProbe($this->tenantA, 'payload-check-A');

    $raw = Redis::connection('default')->lrange('queues:default', 0, -1);
    expect($raw)->toHaveCount(1);

    $decoded = json_decode($raw[0], true);
    expect($decoded['tenant_id'])->toBe('tenant-queue-a');

    // Whole raw JSON payload (including the serialized job 'data' blob) must
    // not contain the tenant's DB password, the elevated provisioning
    // credentials, or anything that looks like a raw Host header - only the
    // opaque tenant id, which is meaningless without a central 'tenants'
    // lookup (exactly the same trust boundary InitializeTenancyByDomain
    // already relies on for HTTP requests).
    $tenantDbPassword = $this->tenantA->run(fn () => config('database.connections.tenant.password'));
    expect($tenantDbPassword)->not->toBeEmpty('precondition: the tenant actually has a real scoped DB password to check for');
    expect($raw[0])->not->toContain($tenantDbPassword);
    expect($raw[0])->not->toContain(config('database.connections.tenant_provisioning.password'));
    expect($raw[0])->not->toContain('tenant-queue-a.localhost');

    processOneQueuedJob();

    // Central job: payload must carry no tenant_id key at all (not even null).
    dispatchProbe(null, 'payload-check-central');
    $rawCentral = Redis::connection('default')->lrange('queues:default', 0, -1);
    $decodedCentral = json_decode($rawCentral[0], true);
    expect(array_key_exists('tenant_id', $decodedCentral))->toBeFalse();
    processOneQueuedJob();
});

test('a Tenant A job that fails outright is recorded centrally with its tenant identity preserved, tenancy is cleaned up, and Tenant B is unaffected afterward', function () {
    dispatchProbe($this->tenantA, 'will-fail', failBelowAttempt: 999, maxTries: 1);

    $output = processOneQueuedJob();
    expect($output)->toContain('FAIL');
    expect(tenancy()->initialized)->toBeFalse('a definitively failed job must not leave the worker tenant-initialized');

    // failed_jobs is central Laravel infrastructure - see the "queue
    // infrastructure remains central" test for the schema-level proof; here
    // we only need to confirm the row for THIS failure landed there with
    // the tenant identity preserved (needed for `queue:retry` to work).
    $failedRow = DB::connection('mysql')->table('failed_jobs')->latest('failed_at')->first();
    expect($failedRow)->not->toBeNull();
    expect($failedRow->payload)->toContain('tenant-queue-a');

    // The failed job must not have written anything to Tenant A's DB.
    $probeRow = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'will-fail')->first());
    expect($probeRow)->toBeNull();

    // Tenant B must work completely normally afterward - a failed Tenant A
    // job must not poison the worker for subsequent jobs.
    dispatchProbe($this->tenantB, 'after-failure-B');
    processOneQueuedJob();
    $rowB = $this->tenantB->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'after-failure-B')->first());
    expect($rowB->observed_tenant_id)->toBe('tenant-queue-b');
});

test('a Tenant A job that fails but is released for retry (not yet exhausted) does not leave the worker tenant-initialized', function () {
    // This is the real, reproduced gap fixed by Platform\Tenancy\Listeners\
    // EndTenancyAfterJobRelease - see that class's docblock. Stancl's own
    // QueueTenancyBootstrapper only reverts tenancy on JobProcessed/
    // JobFailed; Laravel fires neither when a job throws with retries still
    // remaining (Illuminate\Queue\Events\JobReleasedAfterException fires
    // instead). maxTries: 3, failBelowAttempt: 2 - attempt 1 always throws
    // and is released (2 tries remaining), never reaching failed_jobs.
    dispatchProbe($this->tenantA, 'will-retry', failBelowAttempt: 2, maxTries: 3);

    $output = processOneQueuedJob();
    expect($output)->toContain('FAIL');

    expect(DB::connection('mysql')->table('failed_jobs')->count())
        ->toBe(0, 'attempt 1 of 3 must be released for retry, not moved to failed_jobs yet');

    expect(tenancy()->initialized)
        ->toBeFalse('a released-for-retry failure must not leave the worker stuck on the failed job\'s tenant - see EndTenancyAfterJobRelease');
});

test('retrying a failed Tenant A job (via the real queue:retry mechanism) restores the original tenant, not central and not another tenant', function () {
    // attempt 1 throws (failBelowAttempt: 2), attempt 2 succeeds - maxTries:
    // 1 so the FIRST attempt is moved straight to failed_jobs (a clean,
    // deterministic starting point for `queue:retry`, rather than relying
    // on natural backoff timing to make a 2nd automatic attempt available).
    dispatchProbe($this->tenantA, 'will-retry-success', failBelowAttempt: 2, maxTries: 1);
    processOneQueuedJob();
    expect(tenancy()->initialized)->toBeFalse();

    $failedRow = DB::connection('mysql')->table('failed_jobs')->latest('failed_at')->first();
    expect($failedRow)->not->toBeNull();

    // Real Laravel retry command: re-pushes the ORIGINAL serialized payload
    // (tenant_id included) back onto the real queue - not a custom
    // re-dispatch of our own.
    //
    // REAL, REPRODUCED FINDING (documented, not silently patched around -
    // see docs/architecture/queues.md and RISK_REGISTER.md): Illuminate\
    // Queue\Console\RetryCommand::handle() fires Illuminate\Queue\Events\
    // JobRetryRequested for each job BEFORE re-pushing it - and
    // QueueTenancyBootstrapper::setUpJobListener() responds to that event by
    // calling tenancy()->initialize() (confirmed by reading its source),
    // presumably so retryJob()'s later getQueueableOptions()/retryUntil()
    // calls on the job instance run tenant-scoped. There is no matching
    // "after retry requested" event, so tenancy stays initialized once
    // queue:retry returns - reproduced live: tenancy()->initialized is
    // already true, as tenant-queue-a, immediately after this Artisan::call,
    // before the line below even runs. This further means the immediately
    // NEXT JobProcessing/JobProcessed cycle sees a stale $previousTenant
    // equal to the job's own tenant, which QueueTenancyBootstrapper::
    // revertToPreviousState() treats as the "nested dispatchNow, same
    // tenant" case and deliberately does NOT revert - so tenancy remains
    // initialized even after the retried job successfully completes.
    //
    // Judged NOT to need a Platform-side fix here (unlike
    // EndTenancyAfterJobRelease, which fixes a gap reachable from inside a
    // real, long-running `queue:work` daemon): `queue:retry` is normally
    // invoked as its own one-off `php artisan queue:retry` CLI process,
    // entirely separate from the worker daemon - that process exits
    // immediately afterward, taking this dangling in-memory tenancy state
    // with it. The leak is only observable here because this test calls
    // queue:retry and queue:work in-process, in the same PHP runtime, via
    // Artisan::call() - a testing-environment artifact, not a realistic
    // production topology. Flagged as a known, real package-level nuance to
    // be aware of if `queue:retry` is ever invoked programmatically from
    // inside a long-running process.
    Artisan::call('queue:retry', ['id' => [$failedRow->uuid]]);

    $output = processOneQueuedJob();
    expect($output)->toContain('DONE');

    $row = $this->tenantA->run(fn () => DB::table('queue_isolation_probes')->where('marker', 'will-retry-success')->first());
    expect($row)->not->toBeNull();
    expect($row->observed_tenant_id)->toBe('tenant-queue-a', 'retry must restore the ORIGINAL tenant, not central and not another tenant');
    // Not asserting observed_attempt here: RetryCommand::resetAttempts()
    // resets the job payload's attempts counter to 0 before re-pushing it,
    // so Job::attempts() legitimately reports 1 again on the retried run -
    // see TenantIsolationProbeJob::handle()'s docblock for why the pass/
    // fail decision itself uses a separate, durable cache counter instead.

    expect(DB::connection('mysql')->table('failed_jobs')->count())->toBe(0);

    // Explicit, documented cleanup for the JobRetryRequested quirk above -
    // not "inventing a workaround" inside the job/mechanism itself, just
    // this test tidying up the state IT is responsible for before the next
    // test's beforeEach runs.
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

test('a job whose tenant was deleted before processing fails safely - it never runs centrally, never runs under another tenant, and never uses stale credentials', function () {
    $doomed = Tenant::create(['id' => 'tenant-queue-doomed', 'status' => TenantStatus::Pending]);
    $doomed->domains()->create(['domain' => 'tenant-queue-doomed.localhost']);
    app(TenantProvisioner::class)->provision($doomed);
    ensureTenantQueueProbeSchema($doomed);

    dispatchProbe($doomed, 'doomed-job', maxTries: 1);

    // Simulate the tenant disappearing between dispatch and processing -
    // the central row is gone, exactly like a real deletion. The tenant's
    // physical database is deliberately left in place for this test (a real
    // delete would drop it too, but the central row's absence alone is
    // already enough to make Stancl\Tenancy\Tenancy::find() return null,
    // which is the actual mechanism under test here - see
    // vendor/stancl/tenancy/src/Tenancy.php::initialize()).
    $data = json_decode(DB::connection('mysql')->table('tenants')->where('id', 'tenant-queue-doomed')->value('data') ?? '{}', true) ?: [];
    DB::connection('mysql')->table('domains')->where('tenant_id', 'tenant-queue-doomed')->delete();
    DB::connection('mysql')->table('tenants')->where('id', 'tenant-queue-doomed')->delete();

    $output = processOneQueuedJob();

    // Real, observed behavior: Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedById
    // is thrown from inside the JobProcessing listener itself
    // (QueueTenancyBootstrapper::initializeTenancyForQueue()), which runs
    // inside Illuminate\Queue\Worker::process()'s own try/catch (via
    // raiseBeforeJobEvent()) - so it is handled exactly like any other job
    // exception (maxTries: 1 here means it goes straight to failed_jobs),
    // not a worker crash and not a silent central/wrong-tenant fallback.
    expect($output)->toContain('FAIL');
    expect(tenancy()->initialized)->toBeFalse();

    $failedRow = DB::connection('mysql')->table('failed_jobs')->latest('failed_at')->first();
    expect($failedRow)->not->toBeNull();
    expect($failedRow->exception)->toContain('TenantCouldNotBeIdentifiedById');

    // Central infrastructure must show no trace of the job having run
    // centrally.
    expect(DB::connection('mysql')->table('central_queue_isolation_probes')->where('marker', 'doomed-job')->first())->toBeNull();

    // Cleanup: drop the orphaned physical tenant database/user directly
    // (the central row is already gone, so the normal cleanup helper can't
    // look it up by id).
    if (! empty($data['tenancy_db_username'])) {
        DB::connection('tenant_provisioning')->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
    }
    if (! empty($data['tenancy_db_name'])) {
        DB::connection('tenant_provisioning')->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    File::deleteDirectory(storage_path('tenanttenant-queue-doomed'));
});

test('queue infrastructure (jobs/failed_jobs/job_batches) exists only in the central database, never inside a tenant database', function () {
    expect(DB::connection('mysql')->getSchemaBuilder()->hasTable('failed_jobs'))->toBeTrue();
    expect(DB::connection('mysql')->getSchemaBuilder()->hasTable('job_batches'))->toBeTrue();
    expect(DB::connection('mysql')->getSchemaBuilder()->hasTable('jobs'))->toBeTrue();

    $this->tenantA->run(function () {
        expect(Schema::hasTable('failed_jobs'))->toBeFalse();
        expect(Schema::hasTable('job_batches'))->toBeFalse();
        expect(Schema::hasTable('jobs'))->toBeFalse();

        // The tenant DOES have its own queue_isolation_probes table (tenant-
        // owned data touched by the job, created by this file's own
        // ensureTenantQueueProbeSchema() - a test-only table, never a real
        // migration, see docs/architecture/queues.md "Queue probe tables
        // are test-only infrastructure") - confirming the deliberate
        // boundary is "infrastructure central, data touched BY the job
        // tenant-owned", not "everything queue-related is central".
        expect(Schema::hasTable('queue_isolation_probes'))->toBeTrue();
    });
});

test('a real Bagisto job (Webkul\Marketing\Jobs\UpdateCreateSearchTerm) dispatched under Tenant A writes only to Tenant A\'s search_terms table', function () {
    $this->tenantA->run(function () {
        $channelId = DB::table('channels')->value('id');

        UpdateCreateSearchTerm::dispatch([
            'term' => 'queue-isolation-probe-term',
            'channel_id' => $channelId,
            'locale' => 'en',
            'results' => 3,
        ]);
    });

    $output = processOneQueuedJob();
    expect($output)->toContain('DONE');

    $rowA = $this->tenantA->run(fn () => DB::table('search_terms')->where('term', 'queue-isolation-probe-term')->first());
    expect($rowA)->not->toBeNull();
    expect((int) $rowA->results)->toBe(3);

    $rowB = $this->tenantB->run(fn () => DB::table('search_terms')->where('term', 'queue-isolation-probe-term')->first());
    expect($rowB)->toBeNull();
});

afterAll(function () {
    cleanupQueueTestTenants();
});
