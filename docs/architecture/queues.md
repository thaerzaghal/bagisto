# Queue / Background Job Isolation

**Status: RESOLVED (TASK-ARCH-006, 2026-08-14; strengthened in a product-owner review round the same day).** Addresses RISK_REGISTER.md R6/R7. Proven with real, asynchronous execution against a real Redis queue and a real `Illuminate\Queue\Worker` pop/process cycle — not the `sync` driver, which never touches a real queue backend or fires the events this task's whole mechanism depends on. The review round added a TRUE single-invocation, multi-job daemon-worker proof with hard PID evidence (see "TRUE long-lived worker verification" below) — the original A→B→A proof used three separate `--once` invocations, which is mechanistically the same OS process (verified, not assumed) but a weaker proof than one continuous worker loop.

## Queue architecture before this task

`QUEUE_CONNECTION=sync` (still the app's default — see "Queue backend used" below for why that wasn't changed). `Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper` was already listed in `config('tenancy.bootstrappers')` since TASK-ARCH-002/003, but had never been exercised: nothing in the test suite dispatched a real queued job through a real backend, so whether tenant identity actually survived a worker pop-and-process cycle was unverified.

## Bagisto queue/job audit findings

Full-repo audit of `packages/Webkul` found 20 `ShouldQueue` job classes and 12 real `Job::dispatch()` call sites (listed in full in the RISK_REGISTER.md R6 evidence). Notable patterns:

- **Simple, single-job dispatches**: `ProcessSitemap`, `UpdateCreateInventoryIndexJob`, `UpdateCreateSearchTermJob`, `UpdateCreateCatalogRuleIndexJob`, `DeleteCatalogRuleIndexJob`, ElasticSearch index jobs — all dispatched as bare statements from controllers/listeners already running inside a tenant-initialized HTTP request. None dispatch from inside a `tenant->run()`/`tenancy()->central()` closure, so the arrow-function/`PendingDispatch` gotcha documented below does not affect any real Bagisto code found in this audit.
- **Chained/batched jobs**: `Webkul\DataTransfer`'s import pipeline (`AbstractImporter`, `Concerns\ValidatesInChunks`, `Concerns\DownloadsImages`) uses `Bus::batch()`/`Bus::chain()` extensively — touches the central `job_batches` table (see "Central vs. tenant queue infrastructure"). Not exercised end-to-end in this task (a full CSV-import integration test needs substantial unrelated setup); the underlying mechanism (`Bus::batch`/`Bus::chain` ultimately dispatch through the same `Queue::push()`/payload-hook pipeline every other job uses) is covered by the general proof, not independently re-verified for this specific package.
- **`Webkul\Admin\Mail\Mailable`/`Webkul\Marketing\Mail\Mailable`/`Webkul\Shop\Mail\Mailable`**: implement `ShouldQueue` — queued mail goes through the identical `Queue::push()`/payload-tagging pipeline as jobs. Not independently tested (no real mail transport in this environment); the mechanism is the same one proven for jobs.
- No queue middleware, no custom queue connections, no per-tenant queue names anywhere in `packages/Webkul`.

## stancl queue tenancy mechanism (read from the installed package source, not documentation)

`Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper`:

1. **Payload tagging** (`setUpPayloadGenerator()`, called from the bootstrapper's constructor, itself resolved fresh every time tenancy bootstraps): hooks `Illuminate\Queue\Queue::createPayloadUsing()` — a **static**, driver-agnostic hook Laravel's `Queue::createPayload()` calls for every job pushed on any connection. If `tenancy()->initialized` at push time (and the target connection isn't marked `'central' => true` in `config('queue.connections.{name}.central')`), it adds `'tenant_id' => tenant()->getTenantKey()` to the raw payload. Confirmed live via raw Redis payload inspection (see "Tenant payload format/security" below).
2. **Restoring tenancy before a job runs**: `Illuminate\Queue\Events\JobProcessing` → reads `$event->job->payload()['tenant_id']`. If present, `tenancy()->initialize(tenancy()->find($tenantId))`. If absent, ensures tenancy is **not** initialized (`tenancy()->end()` if it was) — this is the built-in "central jobs don't inherit the previous tenant" protection, needing no code from this task.
3. **Ending tenancy after a job runs**: `JobProcessed`/`JobFailed` → reverts to whatever tenant (or none) was active *before* this job's `JobProcessing` ran.
4. `QueueTenancyBootstrapper::__constructStatic()` (which registers the listeners above) is called once per app boot by **`Stancl\Tenancy\TenancyServiceProvider::register()`** — the package's *own* auto-discovered provider (confirmed present and active via `bootstrap/cache/packages.php`), not `Platform\Tenancy\Providers\TenancyServiceProvider`. Both providers are registered side by side; ours never needed to duplicate this wiring.

This is the package-supported mechanism, used as-is — no parallel custom queue-tenancy framework was built.

**Two real gaps were found in this mechanism, one fixed, one documented:**

### Gap 1 (fixed): tenancy leaks after a released-for-retry failure

`QueueTenancyBootstrapper`'s cleanup only listens to `JobProcessed`/`JobFailed`. `Illuminate\Queue\Worker::process()`/`handleJobException()` fires **neither** when a job throws but still has retries remaining — it fires `Illuminate\Queue\Events\JobReleasedAfterException` instead (confirmed by reading `Worker.php`: `raiseAfterJobEvent()`, which fires `JobProcessed`, only runs on the success path inside the `try` block; a caught exception jumps straight to `handleJobException()`, which calls `failJob()` — firing `JobFailed` — only if retries are exhausted, otherwise just `release()`s the job and fires `JobReleasedAfterException`).

**Reproduced live**: dispatched a tenant-tagged job with `tries > 1` that throws, processed it once via a real `queue:work --once` worker — `tenancy()->initialized` remained `true`, still pointing at the failed job's tenant, after the worker call returned.

**Fix**: `Platform\Tenancy\Listeners\EndTenancyAfterJobRelease`, registered on `Illuminate\Queue\Events\JobReleasedAfterException` in `Platform\Tenancy\Providers\TenancyServiceProvider::events()`. Ends tenancy (`tenancy()->end()`, the exact same package method `QueueTenancyBootstrapper` itself calls) if still initialized. Not a custom retry system — it doesn't touch retry scheduling, backoff, or job state, only mirrors the cleanup `JobFailed`'s handler already does for the common case. Verified fixed by watching the exact same reproduction pass after the fix (same evidentiary standard used throughout this project). Zero `packages/Webkul` changes.

### Gap 2 (documented, not fixed): `queue:retry` leaves tenancy dangling, low real-world impact — fuller mechanism traced in the review round

`Illuminate\Queue\Console\RetryCommand::handle()` fires `Illuminate\Queue\Events\JobRetryRequested` for each job **before** re-pushing it. `QueueTenancyBootstrapper` responds to that event by calling `tenancy()->initialize()` too (presumably so the job instance's `retryUntil()`/queueable-options methods run tenant-scoped) — but there is no corresponding "after retry requested" event, so tenancy stays initialized once the command returns.

**Compounding effect, traced fully in the product-owner review round** (`TenantQueueIsolationTest.php`, "R27b assumptions verified..."): the dangling state doesn't just persist — it can propagate through a *second*, unrelated job. Sequence observed: `queue:retry` leaves tenancy on Tenant A → a real daemon (`--stop-when-empty`) then processes the re-queued A job (succeeds; `JobProcessed`'s `revertToPreviousState()` sees `$previousTenant` (A, already dangling) `=== tenant()` (A), treats it as a nested-dispatch no-op, and does **not** revert) → the daemon then pops an unrelated Tenant B job (`JobProcessing` correctly switches to B — `Tenancy::initialize()` itself is robust to this) → B's job runs and writes correctly to B's own database → but B's `JobProcessed` sees `$previousTenant` (still stale A) `!==` `tenant()` (B) and takes the "revert back to the previous tenant" branch, landing tenancy back on **A**, not central. **Verified: data isolation held perfectly throughout this entire chain** — B's job wrote only to B's own database, never A's — only the final process-level `tenancy()->initialized`/`tenant()` state ends up wrong (stuck on A, not properly ended).

**Judged not to need a Platform-side fix, for the same reason as before, now confirmed to be the actual precondition for any of this to matter**: `queue:retry` is normally invoked as its own one-off `php artisan queue:retry` CLI process, entirely separate from the `queue:work` daemon process — that process exits immediately afterward, and none of its in-memory state (dangling or otherwise) can reach a separate daemon process, which starts fresh with `tenancy()->initialized === false`. The entire chain above — including the second-order "reverts to the wrong tenant after an unrelated job" effect — is only reachable when `queue:retry` and `queue:work` share one PHP process, which this test deliberately constructs via `Artisan::call()` for introspection purposes and which does not match this app's real deployment topology. Documented here with the fuller mechanism traced (not just asserted), in case `queue:retry` is ever invoked programmatically from inside a long-running process in this app.

## A real, reproduced PHP gotcha that shaped every test in this task

`Stancl\Tenancy\Database\Concerns\TenantRun::run()` is:
```php
public function run(callable $callback) {
    $originalTenant = tenant();
    tenancy()->initialize($this);
    $result = $callback($this);
    tenancy()->initialize($originalTenant) ?? tenancy()->end();
    return $result;
}
```
It **captures** the closure's return value and only reverts tenancy *after*. `Illuminate\Foundation\Bus\Dispatchable::dispatch()` returns a `PendingDispatch` that only actually calls `Queue::push()` in its `__destruct()`. Writing `$tenant->run(fn () => SomeJob::dispatch(...))` — an **arrow function**, whose implicit return value is that `PendingDispatch` — lets the object escape the closure and stay alive (referenced by `$result`) until *after* `run()` has already reverted tenancy, so the job gets pushed with the wrong (already-central) tenant context, silently, no error.

**Reproduced live** while building this task's tests: the identical dispatch line, written as an arrow function vs. as a bare statement inside a block closure, produced an untagged vs. correctly tenant-tagged payload. Every dispatch in `TenantQueueIsolationTest.php` therefore uses `$tenant->run(function () { SomeJob::dispatch(...); })` — a bare statement, never returned. No real Bagisto code in the audit uses this pattern (Bagisto never calls `tenant->run()` itself), but any **future** Platform code that dispatches jobs from inside a `tenant->run()`/`tenancy()->central()` closure must use this shape, not an arrow function.

## TRUE long-lived worker verification (product-owner review round)

The original A→B→A proof called `Artisan::call('queue:work', ['--once' => true])` three separate times from the test method. Asked to verify this was genuinely one OS process, not three: `Illuminate\Foundation\Console\Kernel::call()` invokes `$this->getArtisan()->call($command, $parameters, $outputBuffer)` directly — no `exec()`/`proc_open()`/`shell_exec()` anywhere in the call chain — so each of those three calls **did** run in the same PHP process as the test. That reasoning was correct, but the test captured no hard evidence of it, and three separate `--once` invocations is a materially weaker proof than one continuous multi-job worker loop, which is how `queue:work` actually runs in production. A stronger test was added rather than just asserting the reasoning was fine.

### The stronger proof

`TenantQueueIsolationTest.php`, "TRUE long-lived worker: ONE queue:work invocation drains a pre-queued Tenant A → Tenant B → Tenant A → Central sequence...": all four jobs are pushed onto the real Redis queue **before** the worker starts; then **one** `Artisan::call('queue:work', ['--stop-when-empty' => true])` call drains all four via `Illuminate\Queue\Worker::daemon()`'s own internal loop — the test regains control only once, after everything has processed. Each job records `getmypid()` into its probe row. Result: all four jobs' `worker_pid`, and the test method's own `getmypid()`, are identical — concrete, inspectable proof of one OS process, not an inference from reading framework source.

### Webkul\Core\Core facade-caching hypothesis: tested and disproven

Going in, there was real reason to suspect a leak: `Webkul\Core\Core` (resolved via the `core()` helper / `Core` facade) memoizes `$currentChannel`/`$currentCurrency`/`$currentLocale` on first access (`packages/Webkul/Core/src/Core.php`), and nothing in `packages/Webkul` or `packages/Platform` explicitly resets those properties on a tenancy transition. Standard Laravel Facade caching (`Facade::$cached = true`) would normally keep the same resolved instance alive for the life of the process.

**This does not actually happen**, confirmed by tracing the mechanism, not just observing the test pass: `Illuminate\Queue\QueueServiceProvider` registers a `$resetScope` closure — which calls `Facade::clearResolvedInstances()`, among other resets (log context, per-connection query-duration counters, scoped container instances, `memory_reset_peak_usage()`) — and `Illuminate\Queue\Worker::daemon()` (the loop `queue:work` runs in production, and what `--stop-when-empty` exercises in the test above) calls that closure **before every single job**, unconditionally. Verified live: three consecutive jobs (A, B, A) in the one real worker process from the test above each got a distinct `spl_object_id(core())`, and each correctly read only its own tenant's channel data (each tenant's default channel `code` was set to a distinct marker beforehand — Tenant B's job correctly observed Tenant B's marker, not Tenant A's).

**One real, narrower caveat, not a leak**: `Illuminate\Queue\Worker::runNextJob()` — what `--once` calls (`WorkCommand.php`: `{$this->option('once') ? 'runNextJob' : 'daemon'}`) — does **not** call `$resetScope`. This app never runs `--once` in production (a real `queue:work` deployment always runs in daemon mode; `--once` exists only for this test suite's own per-job introspection and manual debugging), so this is noted for completeness, not treated as an actionable finding.

### R26 re-verified under the true daemon loop, with a precision correction

`TenantQueueIsolationTest.php`, "TRUE long-lived worker: a Tenant A job that releases for retry does not poison the very next Tenant B job...": queues a releasing Tenant A job immediately followed by a normal Tenant B job, drains both in one continuous `--stop-when-empty` loop. Re-confirmed the fix matters here too by temporarily disabling `EndTenancyAfterJobRelease` and re-running (not committed that way — restored immediately): without the fix, `tenancy()->initialized` stayed `true` after the run.

**Precision correction found during that re-verification**: even *without* the fix, Tenant B's job still correctly wrote to Tenant B's own database — `Stancl\Tenancy\Tenancy::initialize()` unconditionally calls `end()` before switching to a different tenant, so the *next* job is never actually misattributed. What R26 fixes is narrower than "the next job could run under the wrong tenant": it's that `tenancy()->initialized`/`tenant()` stays incorrectly "on" (pointing at whichever tenant most recently ran) for longer than it should between jobs — a dangling-process-state bug, not a data-misattribution bug. Still worth fixing (idle-window code, health checks, or logging between jobs could observe the stale state), just described accurately here rather than overstated.

## Queue backend used and why

**Redis**, for the real end-to-end proof (`config(['queue.default' => 'redis'])`, set inside the test suite — the app's own `.env`/`.env.example` default (`QUEUE_CONNECTION=sync`) was deliberately **not** changed; see below). Reasons:

- Already available and already proven in this environment (the same `bagisto-redis-1` container used for `CACHE_STORE=redis` verification in TASK-ARCH-004).
- Avoids a real, found ambiguity in the `'database'` queue driver specifically: `config('queue.connections.database.connection')` defaults to `null`, meaning Laravel resolves it against `config('database.default')` — which, under this app's tenancy model, is dynamically swapped between `'mysql'` (central) and `'tenant'` by `Stancl\Tenancy\Database\DatabaseManager::setDefaultConnection()`. A one-off empirical check (not the full test matrix) found this resolves safely to the central connection in this app's actual resolution order — but the exact mechanism wasn't fully traced, so `DB_QUEUE_CONNECTION=mysql` was pinned explicitly in `.env`/`.env.example` anyway (see below), removing the ambiguity rather than relying on unexplained-but-lucky timing.
- `sync` was explicitly avoided as the *primary proof mechanism* per this task's own instruction: it never touches a real queue backend and never fires `JobProcessing`/`JobProcessed`/`JobFailed`/`JobReleasedAfterException` at all (`SyncQueue::push()` calls the job's handler inline, in-process) — it would have hidden every finding in this document, including both real gaps above.

**Why the app's actual `QUEUE_CONNECTION` default was left as `sync`**: changing the production queue driver is a deployment decision outside this task's explicit scope (no instruction asked for it, and Redis/database queue both carry their own operational requirements — a running worker process, monitoring, `queue:restart` on deploy — that are genuinely out of scope here, see "Queue restart / deployment" below). `DB_QUEUE_CONNECTION=mysql` was still added defensively (cheap, safe, closes a real ambiguity) even though it currently has no effect under `sync`.

## Central vs. tenant queue infrastructure

Verified directly via schema inspection (`tests/Feature/Platform/TenantQueueIsolationTest.php`, "queue infrastructure... exists only in the central database"):

| Table | Location | Why |
|---|---|---|
| `jobs`, `failed_jobs`, `job_batches` | **Central only** — root `database/migrations/`, never migrated into any tenant DB (same `Migrator::paths()` mechanism proven in R17: `TenantProvisioner::ensureMigrated()` uses `app('migrator')->paths()`, which structurally never includes the root migrations directory) | Queue payloads, failure records, and batch metadata are platform/infrastructure concerns, not any one tenant's commerce data |
| `queue_isolation_probes` | **Tenant-owned, TEST-ONLY** — created ad hoc per tenant by the test suite (`ensureTenantQueueProbeSchema()` in `TenantQueueIsolationTest.php`), never via a permanent migration, one physically separate table per tenant *test* database | Illustrates the "data touched by a job is tenant-owned" boundary for the test matrix. Originally shipped as a real migration (`database/migrations/tenant/...`) — moved to test-only setup in a product-owner review round once it was clear the table holds nothing but diagnostic data (markers, PIDs, object ids) with no real SaaS value, so no production tenant database should carry it forever. See "Queue probe tables are test-only infrastructure" below. |
| `central_queue_isolation_probes` | **Central, TEST-ONLY** — created ad hoc by the test suite (`ensureCentralQueueProbeSchema()`), never via a permanent migration | Same reasoning as above, for the central-job case (proving central jobs aren't just failing closed, without permanently adding diagnostic schema to the real central database) |

`config('queue.batching.database')`/`config('queue.failed.database')` both resolve `env('DB_CONNECTION', 'mysql')` — a **literal string**, evaluated once from `.env`, never affected by the tenant connection swap (which adds a separate `'tenant'`-named connection rather than mutating `'mysql'` in place — confirmed by reading `Stancl\Tenancy\Database\DatabaseManager::createTenantConnection()`). Verified live: a tenant-tagged job's failure record landed in the central `failed_jobs` table with the tenant identity preserved in its payload, regardless of which tenant was active when the failure occurred.

## Tenant payload format/security

Raw Redis payload for a tenant-dispatched job (inspected directly via `Redis::connection('default')->lrange(...)`, not through any Laravel abstraction that might mask the real wire format):

```json
{
  "uuid": "...", "displayName": "Platform\\Tenancy\\Jobs\\TenantIsolationProbeJob",
  "job": "Illuminate\\Queue\\CallQueuedHandler@call",
  "maxTries": 3, "data": { "commandName": "...", "command": "O:...", "batchId": null },
  "createdAt": 1786669122,
  "tenant_id": "tenant-queue-a",
  "id": "...", "attempts": 0, "delay": null
}
```

Verified (`TenantQueueIsolationTest.php`, "the raw queued payload..."): the only tenancy-related field is `tenant_id` — the trusted `Tenant` model's own key (a UUID, `Stancl\Tenancy\UUIDGenerator`), meaningless without a central `tenants` table lookup — exactly the same trust boundary `InitializeTenancyByDomain` already relies on for HTTP requests. Confirmed absent from the raw payload: the tenant's scoped database password, the elevated `tenant_provisioning` connection's password, and the tenant's domain string. A central job's payload carries **no `tenant_id` key at all** (not even `null`) — confirmed via `array_key_exists()`, not just an empty-string check.

## Platform test job

`Platform\Tenancy\Jobs\TenantIsolationProbeJob` — kept under `packages/Platform/Tenancy` rather than `tests/`, deliberately: the JOB CLASS itself is genuine, reusable tenancy infrastructure (could plausibly back a future health-check/diagnostic endpoint), not disposable test code, matching how `TenantProvisioner`/the tenancy listeners already live in this package. On `handle()`: writes to the tenant-owned `queue_isolation_probes` table (or the central counterpart when no tenant is active), writes a fixed cache key (`queue-isolation`) and a fixed file path (`queue-isolation/shared.txt`) — deliberately fixed/shared logical names, not tenant-derived, so that two tenants' jobs writing "the same thing" is the actual scenario under test. Also records `worker_pid` (`getmypid()`) and `core_facade_object_id`/`observed_channel_code` (the long-lived-worker evidence added in the product-owner review round — see "TRUE long-lived worker verification" above) — test/probe instrumentation only, never used as production domain data. Its backing tables are test-only (see below) — dispatching this job against a database without that test-only setup throws a "table not found" error by design.

### Queue probe tables are test-only infrastructure, not production schema

`queue_isolation_probes`/`central_queue_isolation_probes` were originally shipped as permanent migrations (`database/migrations/tenant/2026_08_14_000001...`/`000003...`, `database/migrations/2026_08_14_000002...`/`000004...`), meaning every real tenant database — and the real central database — would have permanently carried a diagnostic-only table (markers, observed tenant ids, worker PIDs, `spl_object_id()` values, a channel-code marker) forever, with zero production/SaaS value.

**Corrected in a product-owner review round**: those four migration files were deleted. The two tables are now created directly by the test suite (`tests/Feature/Platform/TenantQueueIsolationTest.php`'s `ensureTenantQueueProbeSchema()`/`ensureCentralQueueProbeSchema()`, plain `Schema::create()` calls, idempotent via `Schema::hasTable()` guards) — never through `TenantProvisioner::ensureMigrated()` and never through a real `php artisan migrate` run. `TenantIsolationProbeJob` itself needed no change: it only ever did `DB::table('queue_isolation_probes')`/`DB::connection('mysql')->table('central_queue_isolation_probes')`, agnostic to how the table came to exist. A real production tenant, provisioned via the real `TenantProvisioner`, never sees this schema at all.

## Real Bagisto job proof

`Webkul\Marketing\Jobs\UpdateCreateSearchTerm` was chosen after auditing all 20 `ShouldQueue` classes: the simplest real job with minimal setup (one array of scalars: `term`, `channel_id`, `locale`, `results`; no complex model graph). Dispatched under Tenant A via the real queue, processed by a real worker, confirmed to write only to Tenant A's own `search_terms` table (Tenant B's table checked and confirmed empty for the same term). `DataTransfer`'s `Bus::batch()`/`Bus::chain()` import pipeline was also audited but not independently exercised end-to-end — see "Bagisto queue/job audit findings" above for why.

## Long-lived worker safety

Explicitly checked for additional cross-tenant state risk beyond the two documented gaps: `Webkul\Core\Core`'s per-process memoized channel/currency/locale state (flagged as a real hypothesis, R8's underlying concern made concrete for the queue-worker case) was tested directly, not assumed — see "TRUE long-lived worker verification" above. Result: **no leak**, because `Illuminate\Queue\Worker::daemon()` already calls `Facade::clearResolvedInstances()` before every job via Laravel's own built-in `$resetScope` mechanism. `SystemConfig` was also audited and found to hold no equivalent memoized instance state. This narrows R8's live risk specifically for `queue:work` daemons (still applicable to Octane, which does not use `Worker::daemon()`'s reset mechanism and remains un-audited per the existing R8 guidance — Octane was not enabled, per instructions).

## Queue restart / deployment (documented only, not implemented)

Out of scope to implement. For the record: Laravel workers cache the booted application state in memory per the `queue:work` process's lifetime, so code/config deploys require `php artisan queue:restart` (or a process manager cycling workers) to take effect — standard Laravel operational guidance, unrelated to anything tenancy-specific found in this task. No Horizon/Supervisor requirement was identified or added; a single `queue:work redis` process is sufficient for what this task proves. Production supervision strategy is a deployment decision, deferred.

## Suspended tenant (documented, not addressed — no security-critical behavior found)

`Stancl\Tenancy\Tenancy::initialize()` (the method `QueueTenancyBootstrapper`'s `JobProcessing` handler calls) performs **no status check at all** — confirmed by reading its source: it only checks whether `find($tenantId)` returned a row, not that row's `status`. A queued job for a `TenantStatus::Suspended` tenant would initialize and run normally against that tenant's live, still-isolated database. This is **not** a data-isolation break (the suspended tenant's data remains exactly as isolated as any other tenant's) — it is a lifecycle-policy gap: nothing currently stops a suspended tenant's background work from continuing. Explicitly not addressed here, per this task's own scope instruction not to expand into lifecycle enforcement — flagged for the dedicated tenant-lifecycle-hardening task.

## Missing/deleted tenant

`Tenancy::find($id)` returns `null` for a deleted tenant; `initialize(null)` throws `Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedById`. Reproduced live: this throws from *inside* the `JobProcessing` listener itself, which runs inside `Illuminate\Queue\Worker::process()`'s own `try`/`catch` (via `raiseBeforeJobEvent()`) — so it is handled exactly like any other job exception (moved to `failed_jobs` once retries are exhausted), **not** a worker crash, and **not** a silent fallback to central or another tenant. Verified: the job never wrote to central infrastructure, the failure record's `exception` column contains `TenantCouldNotBeIdentifiedById`, and tenancy was left correctly uninitialized afterward.

## Queue names / channels

No per-tenant physical queues were introduced. A single shared `default` queue is used; each payload securely carries trusted tenant identity (see "Tenant payload format/security"), and the worker correctly initializes/cleans tenancy per job — satisfying isolation without the operational complexity of per-tenant queue provisioning. Revisit only if a demonstrated need (e.g. per-tenant rate limiting/prioritization) arises.
