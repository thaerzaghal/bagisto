<?php

declare(strict_types=1);

namespace Platform\Tenancy\Listeners;

use Illuminate\Queue\Events\JobReleasedAfterException;

/**
 * TASK-ARCH-006 (RISK_REGISTER.md R6/R7): fixes a real, reproduced
 * long-lived-worker tenancy leak in Stancl\Tenancy\Bootstrappers\
 * QueueTenancyBootstrapper.
 *
 * That bootstrapper's own job listener (vendor/stancl/tenancy/src/
 * Bootstrappers/QueueTenancyBootstrapper.php::setUpJobListener()) only
 * reverts tenancy on Illuminate\Queue\Events\JobProcessed and JobFailed.
 * Laravel's own Illuminate\Queue\Worker::process()/handleJobException()
 * fires NEITHER of those two events when a job throws but still has
 * retries remaining (Worker::process() only calls raiseAfterJobEvent(),
 * which fires JobProcessed, on the SUCCESS path inside its try block; a
 * caught exception jumps straight to handleJobException(), which - unless
 * the job has now exhausted its max attempts, in which case it fires
 * JobFailed via failJob() - just releases the job back onto the queue and
 * fires Illuminate\Queue\Events\JobReleasedAfterException instead).
 *
 * Reproduced live during TASK-ARCH-006: dispatch a tenant-tagged job with
 * tries() > 1 that throws, process it once via a real `queue:work --once`
 * worker - tenancy()->initialized remains true, still pointing at the
 * failed job's tenant, even after the worker call returns. On a real
 * long-running worker (daemon mode), any code that runs before the NEXT
 * job is popped (or if the queue happens to sit empty for a while) would
 * observe a stale tenant context that does not correspond to anything
 * currently executing.
 *
 * Fix: an additional Platform-side listener on the one event stancl's own
 * bootstrapper doesn't handle. Deliberately NOT a custom retry system and
 * NOT a change to the job/dispatch/retry mechanism itself - it only ends
 * tenancy (Stancl\Tenancy\Tenancy::end(), the exact same package-provided
 * method QueueTenancyBootstrapper itself calls) after a release, mirroring
 * what JobFailed's handler already does for the more common "no previous
 * tenant" case. The narrower nested-dispatchSync-with-a-different-previous-
 * tenant edge case QueueTenancyBootstrapper::revertToPreviousState() also
 * handles is not replicated here (no real Bagisto code found in the
 * TASK-ARCH-006 audit dispatches from within an active tenant context via
 * dispatchSync while a DIFFERENT tenant was already initialized before
 * that) - if that gap matters later, it belongs to a dedicated worker/
 * Octane-hardening task, not a silent guess added here.
 */
class EndTenancyAfterJobRelease
{
    public function handle(JobReleasedAfterException $event): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
