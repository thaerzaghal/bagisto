<?php

declare(strict_types=1);

namespace Platform\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * TASK-ARCH-006: the smallest permanent job needed to prove tenant isolation
 * survives real, asynchronous queue execution - RISK_REGISTER.md R6/R7.
 *
 * Kept under packages/Platform/Tenancy (not tests/) because the JOB CLASS
 * itself is genuine, reusable tenancy infrastructure - a diagnostic probe
 * mechanism, not disposable test-fixture code; see docs/architecture/queues.md
 * for why this was judged the cleaner convention than a tests/-only class.
 *
 * IMPORTANT (product-owner review, 2026-08-14): the TABLES this job writes
 * to (queue_isolation_probes / central_queue_isolation_probes) are
 * deliberately NOT production schema - they hold nothing but diagnostic
 * data (markers, observed tenant ids, worker PIDs, object ids) with no real
 * SaaS/commerce value, so they are created ONLY by the test suite itself
 * (tests/Feature/Platform/TenantQueueIsolationTest.php's
 * ensureTenantQueueProbeSchema()/ensureCentralQueueProbeSchema(), via plain
 * Schema::create() calls) - never through a permanent migration, so no real
 * tenant database or the real central database ever carries this schema.
 * Consequence: dispatching this job against a database that hasn't had that
 * test-only setup run (i.e. any real, non-test environment) will throw a
 * "table not found" error - by design, not a bug. Do not dispatch this job
 * outside the Platform test suite.
 *
 * Every operation is deliberately keyed by a FIXED, shared logical
 * name/path ('queue-isolation' cache key, 'queue-isolation/shared.txt' file
 * path) rather than anything tenant-derived, so that two tenants' jobs
 * writing "the same thing" is the actual scenario under test - isolation
 * must come from the tenant context switch alone, never from the job
 * choosing a different key/path per tenant.
 */
class TenantIsolationProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Laravel reads this PUBLIC PROPERTY (via Job::maxTries(), which takes
     * priority over any --tries CLI flag on queue:work - see
     * Illuminate\Queue\Worker::markJobAsFailedIfWillExceedMaxAttempts()) to
     * decide when to stop retrying and move the job to failed_jobs. Set per
     * dispatch via the constructor below rather than a fixed class default,
     * so the same job class can prove both "fails outright" (maxTries=1)
     * and "fails then succeeds on retry" (maxTries>1) without a custom
     * retry system of our own.
     */
    public int $tries;

    /**
     * @param  string  $marker  The value written to the DB probe row, the
     *                          shared cache key, and the shared file path -
     *                          identifies which dispatch produced this
     *                          observation (e.g. "run-1-A", "run-2-B").
     * @param  int|null  $failBelowAttempt  If set, handle() throws while a
     *                          durable, cache-backed real-attempt counter
     *                          (see below) is below this number, and
     *                          succeeds once it reaches it.
     * @param  int  $maxTries  Sets $this->tries (see above).
     */
    public function __construct(
        public string $marker,
        public ?int $failBelowAttempt = null,
        int $maxTries = 3,
    ) {
        $this->tries = $maxTries;
    }

    public function handle(): void
    {
        if ($this->failBelowAttempt !== null) {
            // Deliberately NOT Job::attempts(): reproduced live while
            // building this job that Laravel's own `queue:retry` command
            // (Illuminate\Queue\Console\RetryCommand::resetAttempts())
            // resets the payload's attempts counter to 0 before re-pushing
            // it - so attempts() reports 1 again on the retried run,
            // indistinguishable from the original first attempt. A durable,
            // job-external counter (real Cache::increment(), not a value
            // this job invents in memory) is the only way to tell "this is
            // truly a later, retried execution" apart from "this is the
            // original attempt" - not a custom retry system, just an
            // observation counter for what already happened via real
            // Laravel/stancl retry mechanics.
            $realAttempt = Cache::increment('probe-real-attempts-'.$this->marker);

            if ($realAttempt < $this->failBelowAttempt) {
                throw new RuntimeException(
                    "TenantIsolationProbeJob deliberate failure for marker [{$this->marker}] on real attempt {$realAttempt} (configured to succeed at attempt {$this->failBelowAttempt})."
                );
            }
        }

        $tenantId = tenancy()->initialized
            ? tenant()->getTenantKey()
            : null;

        // Long-lived-worker evidence (product-owner review, 2026-08-14):
        // getmypid() proves multiple jobs ran under the same OS process, not
        // separate invocations. spl_object_id(core()) proves (or disproves)
        // whether Webkul\Core\Core - resolved via Laravel Facade caching,
        // which memoizes the resolved instance for the life of the PHP
        // process regardless of tenancy transitions - is the SAME instance
        // across two different tenants' jobs in one worker process. Neither
        // value is ever used as production domain data - purely test/probe
        // instrumentation, per instructions.
        $workerPid = getmypid();
        $coreFacadeObjectId = spl_object_id(core());

        // The tenant-owned probe table (created ad hoc per tenant by the test
        // suite - see this class's docblock) only exists inside tenant
        // databases the test suite has set up; the central-owned counterpart
        // only exists in the central database once the test suite creates
        // it - writing to the wrong one for the current context would be a
        // real, loud SQL error, not a silently-wrong success, which is
        // itself part of what proves the boundary (see docs/architecture/queues.md).
        if ($tenantId) {
            // Concrete data-level check for the same Core-facade-caching
            // concern: if the test has set this tenant's default channel
            // name to a tenant-specific marker string beforehand,
            // getCurrentChannel() returning a DIFFERENT tenant's marker
            // here would prove Core's memoized $currentChannel leaked
            // across the tenant switch, not just that the object id matched.
            $observedChannelCode = core()->getCurrentChannel()?->code;

            DB::table('queue_isolation_probes')->insert([
                'marker' => $this->marker,
                'observed_tenant_id' => $tenantId,
                'observed_attempt' => $this->attempts(),
                'worker_pid' => $workerPid,
                'core_facade_object_id' => $coreFacadeObjectId,
                'observed_channel_code' => $observedChannelCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::connection('mysql')->table('central_queue_isolation_probes')->insert([
                'marker' => $this->marker,
                'worker_pid' => $workerPid,
                'core_facade_object_id' => $coreFacadeObjectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::put('queue-isolation', $this->marker);

        Storage::disk('public')->put('queue-isolation/shared.txt', $this->marker);
    }
}
