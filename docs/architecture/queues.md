# Queue / Job Isolation

## Current state (verified)

Default queue connection: `database` (config default), overridden to `sync` in `.env.example` (meaning local/dev runs jobs inline — the async path is not exercised by default, worth remembering when this risk feels "not observed" during casual local testing). Eight `ShouldQueue` job classes identified across `Webkul\CatalogRule`, `Webkul\DataTransfer\Jobs\Import\*`, `Webkul\Marketing`, `Webkul\Product\Jobs\ElasticSearch\*`, `Webkul\Product\Jobs\{UpdateCreateInventoryIndex,UpdateCreatePriceIndex}`, `Webkul\Sitemap`.

None of these jobs contain explicit `DB::connection()` calls — they resolve repositories and `core()` state inside `handle()`, at execution time, **on the worker process**, which by default has no idea which tenant a given job belongs to. This is a correctness risk, not just an isolation one: without tenant context, a worker could execute a queued reindex/import job against whatever connection happens to be currently configured — potentially the wrong tenant entirely, or none.

Separately: `config/queue.php`'s batching/failed-jobs configuration hardcodes `env('DB_CONNECTION', 'mysql')` in two places (line-referenced in [RISK_REGISTER.md](../../RISK_REGISTER.md) R7) rather than reading `config('database.default')` — worth an explicit design decision on whether failed-job/batch bookkeeping should live centrally (arguably more useful for platform-wide ops visibility) or per-tenant (more consistent with everything else, but scatters failure visibility across N databases).

## Isolation strategy

`stancl/tenancy`'s `QueueTenancyBootstrapper` tags dispatched jobs with the initiating tenant's identity in the job payload, and re-initializes tenancy (including the DB connection swap) on the worker **before** `handle()` runs. This must be explicitly enabled and configured — it is not automatic just by having `stancl/tenancy` installed — and every one of the eight job classes above must be covered by a test that dispatches the job from within tenant A's context and asserts it executes against tenant A's database when picked up by a worker, not whatever tenant happened to run last on that worker process.

## Failed jobs / batching table location — design decision for Phase 14

Recommend: **central**, for platform-wide operational visibility (a platform admin should be able to see all failed jobs across all tenants in one place for support/ops purposes), with a `tenant_id` column added to disambiguate. This is one of the few places a `tenant_id` column is actually justified (see [database-per-tenant.md](database-per-tenant.md)'s rationale for why Bagisto's own commerce tables don't need one) — because failed-job bookkeeping is inherently a platform-operations concern, not tenant commerce data, and centralizing it avoids having to query N tenant databases to answer "what's broken right now."

## Scheduled commands

Any Bagisto artisan command intended to run per-tenant (e.g. a reindex, a report) must be wrapped by our own scheduling code that iterates tenants and runs the command within each tenant's initialized context (`stancl/tenancy` provides a `tenants:run` style command for exactly this) — a bare `php artisan schedule:run` entry for a Bagisto command would otherwise execute once, against whatever the default/central connection is, which is almost never the intended behavior for tenant-scoped maintenance tasks.
