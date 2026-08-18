# Platform CI & Testing (TASK-ARCH-017, revised TASK-ARCH-017A, TASK-MVP-011)

Real implementation record - not a Phase 0 speculative sketch. See
[testing-strategy.md](testing-strategy.md) for the original Phase 0 plan;
this document describes what actually exists.

## Local-first testing is the official policy (TASK-MVP-011)

**Every workflow under `.github/workflows/` is `workflow_dispatch`-only -
none of them run automatically on `push` or `pull_request` any more.**
GitHub is source/history storage for this project; local execution is the
actual verification loop, and production is verified only through safe,
non-destructive checks (`platform:production:check`, real but narrow HTTP
smoke, backup/R2/SMTP health - see "Three local test levels" below). This
was a deliberate product decision (DECISION_LOG.md), not an oversight -
GitHub Free-tier Actions minutes are limited, this fork already had known
upstream CI incompatibilities (see "Upstream Bagisto CI incompatibility"
below), and heavy Playwright sharding (10 shards x 2 suites) on every single
push was never buying anything a local run doesn't already prove faster.
Every workflow file is kept, unchanged apart from its trigger stanza, and
remains runnable on demand from the Actions tab ("Run workflow") whenever a
deliberate milestone check or an actual Docker image publish is wanted.

## Official local development flow

```
AI/developer
    -> focused local tests (Level 1)
    -> Pest / Pint / Playwright as relevant
    -> commit
    -> push
    -> NO automatic GitHub Actions run
```

Production is never used as a test runner - never the full Pest suite,
never the full Playwright suite, never a destructive/CI-style provisioning
test. Production verification stays limited to `platform:production:check`,
safe HTTP smoke checks, the exact real flow under verification, and
backup/R2/SMTP health (Level 3 below).

## Local developer commands

**Platform Pest - full suite** (default config: `CACHE_STORE=array`,
`QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array` - matching every other Pest
suite in this repository):
```bash
vendor/bin/pest tests/Feature/Platform
# (equivalently: vendor/bin/pest --testsuite="Platform Feature Test")
```

**Platform Pest - one focused file** (the normal day-to-day command while
implementing/fixing something specific):
```bash
vendor/bin/pest tests/Feature/Platform/<File>.php
```

**Platform Pest - production-config smoke lane.** `-c phpunit.smoke.xml` is
mandatory - **do NOT replace it with a bare `tests/Feature/PlatformSmoke`
directory invocation**, which would silently run under `phpunit.xml`'s
array/sync/array test defaults instead of the real production-intended
values this lane exists to prove (see that file's own docblock, and the
R29/R33 masking mechanism this exact mistake would reproduce):
```bash
vendor/bin/pest -c phpunit.smoke.xml
```

**Upstream Webkul Pest suites** (the suites Lane A's workflow used to run
automatically - documented here exactly as currently known, not as a
recommended everyday command; see the known incompatibility note below
before relying on this locally):
```bash
vendor/bin/pest --parallel --colors=always \
  --testsuite="Admin Feature Test,Core Unit Test,Customer Unit Test,DataGrid Unit Test,EUWithdrawal Feature Test,Installer Feature Test,PayGlocal Unit Test,PayGlocal Feature Test,PayU Unit Test,PayU Feature Test,Razorpay Unit Test,Razorpay Feature Test,Shop Feature Test,Stripe Unit Test,Stripe Feature Test"
```
This requires `php artisan bagisto:install --no-interaction` first, exactly
as `pest_tests.yml` does it - **and that step has a known, pre-existing,
not-fixed-here incompatibility with this fork** (`bagisto:install`'s
internal `migrate` step is rejected by
`Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema`, R30 -
see "Upstream Bagisto CI incompatibility" below for the full mechanism).
Confirm your own local database is genuinely disposable before attempting
this - never point it at `bagisto_central`.

**Pint - whole repository** (matches what `pint_tests.yml` itself runs):
```bash
vendor/bin/pint --test
```

**Pint - day-to-day Platform scope:**
```bash
vendor/bin/pint --test packages/Platform
```

**Admin Playwright** (from `admin_playwright_tests.yml`'s own steps -
requires a running `php artisan serve` at the given `BASE_URL` first;
10-shard splitting is a CI-only optimization, not needed on one machine -
prefer a focused `--grep`/single spec file during normal task development,
and reserve the full suite for when Admin UI materially changed):
```bash
cd packages/Webkul/Admin
npm install
npx playwright install --with-deps chromium   # first time only
BASE_URL=http://127.0.0.1:8000 npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

**Shop Playwright** (identical shape, `packages/Webkul/Shop`):
```bash
cd packages/Webkul/Shop
npm install
npx playwright install --with-deps chromium   # first time only
BASE_URL=http://127.0.0.1:8000 npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

**Translation check** (exactly what `translation_tests.yml` runs):
```bash
php artisan bagisto:translations:check
```

**Production readiness** (Level 3 only - never a substitute for the above
during development):
```bash
php artisan platform:production:check
```

## Three local test levels

**Level 1 - task focused** (normal development): the Pest file(s) you
touched plus any directly-related regression file, Pint on the touched
file/package, and a focused Playwright spec only if the change is UI-facing.

**Level 2 - milestone regression** (before a significant push/deploy): the
broader `tests/Feature/Platform` suite, a broader/whole-repo Pint pass, the
relevant Admin/Shop Playwright suite if UI changed, and the
production-config smoke lane when the change touches session/cache/queue
behavior.

**Level 3 - production verification** (after deployment only):
`platform:production:check`, safe real HTTP smoke of the exact flow under
verification, and backup/R2/mail health. Never the full Pest suite, never
the full Playwright suite, never a destructive CI-style test, against
production.

## Known local/test-environment caveats (not fixed here)

- **R49** - long-running real-worker/queue-draining test flakiness tied to
  total single-process suite DURATION (19+ minutes), not to any specific
  test's logic. See RISK_REGISTER.md.
- **`AdminDashboardNonReadyTenantTimingTest.php`** uses real-subprocess
  `php artisan serve` processes and cleans up its own disposable tenant
  databases in `beforeEach`/`afterEach` (`DROP DATABASE IF EXISTS`) - if the
  local server process or Docker container is killed abruptly mid-run
  (matching R61's own documented "`Kernel::terminate()` can kill a
  single-threaded `serve` process" mechanism), that cleanup may not
  complete, potentially leaving an orphaned tenant schema behind locally.
  Re-run `cleanupR58TimingTenant()`'s own logic (or just drop the
  `tenant-r58-timing-*` databases manually) if this is ever suspected.
- **`bagisto:install` vs. `PreventCentralMigrationOfTenantSchema` (R30)** -
  see "Upstream Bagisto CI incompatibility" below.
- Upstream Bagisto's own Pest/Playwright CI assumptions (installer-driven
  bootstrap, no tenancy) do not necessarily hold for this multi-tenant
  fork - treat Lane A/Playwright local runs as informational, not as a
  required gate, until/unless that incompatibility is deliberately
  addressed in its own task.

## CI lanes (workflow inventory - all now manual-only, TASK-MVP-011)

Three lanes across two workflow files, all additive - no pre-existing
Bagisto/Webkul CI coverage was removed, only its automatic trigger:

| Lane | Workflow | Job | Trigger | What it proves |
|---|---|---|---|---|
| A. Existing Bagisto tests | `.github/workflows/pest_tests.yml` | `pest_tests` | manual (`workflow_dispatch`) | Unmodified upstream Webkul package suites (Admin/Core/Customer/DataGrid/EUWithdrawal/Installer/PayGlocal/PayU/Razorpay/Shop/Stripe) - unchanged behavior, only its CI database was renamed (see "Safe database naming" below) |
| B. Platform full integration suite | `.github/workflows/platform_tests.yml` | `platform_tests` | manual (`workflow_dispatch`) | The full `tests/Feature/Platform` suite, default test config |
| C. Production-config smoke | `.github/workflows/platform_tests.yml` | `platform_smoke_tests` | manual (`workflow_dispatch`) | A small, focused suite proving the golden path survives under this project's real, production-intended config |

`admin_playwright_tests.yml`, `shop_playwright_tests.yml`, and
`translation_tests.yml` are likewise manual-only now. `docker_publish.yml`
lost its automatic `v*`-tag-push trigger and is manual-only too - a real
image publish now requires deliberately running it from the Actions tab.

## Why the Platform lanes were already manual (TASK-ARCH-017A), and why every other lane joined them (TASK-MVP-011)

This project is on a **GitHub Free plan**. Lanes B and C are both real-MySQL
(Lane C also real-Redis), multi-minute jobs - running them automatically on
every ordinary push/pull request would consume Actions minutes for no
benefit over running the same suites locally (which is faster feedback
anyway, and is already required before pushing). `platform_tests.yml` was
already `workflow_dispatch`-only for this exact reason. TASK-MVP-011
extended the identical reasoning to every remaining lane: Lane A and both
Playwright workflows ran `[push, pull_request]` automatically, consuming
real minutes on every single push (Playwright alone is 10 parallel shards x
2 suites = 20 jobs per push) for coverage this fork's own local commands
already provide faster feedback on - and, per the incompatibility below,
for coverage that currently fails for a known, unrelated reason anyway.

## MySQL/Redis dependencies

All three lanes run against real GitHub Actions MySQL 8.0 service
containers (no mocking of the database). Lane C additionally runs a real
Redis service container (`redis:alpine`), since it specifically exists to
prove `CACHE_STORE=redis`/`QUEUE_CONNECTION=redis` work end-to-end - Lane
A/B's `CACHE_STORE=array` is itself a real, taggable cache store (see
RISK_REGISTER.md R15), so they don't need Redis to be correct, only Lane C
does.

## Production-intended configuration (Lane C)

`phpunit.smoke.xml`'s own `<php>` block sets this project's real
production-intended values, deliberately bypassing `phpunit.xml`'s
CACHE_STORE=array/QUEUE_CONNECTION=sync/SESSION_DRIVER=array test defaults
(PHPUnit's `<env>` directives set real OS environment variables before
Laravel's own `.env` loading runs, and PHP's dotenv does not override an
already-set variable - this is the exact mechanism that let R29/R33 hide as
long as they did):

```
SESSION_DRIVER=database
CACHE_STORE=redis
QUEUE_CONNECTION=redis
RESPONSE_CACHE_ENABLED=false
REDIS_CLIENT=predis
```

One deliberate difference from `.env.example`: `QUEUE_CONNECTION` there is
`sync` (a real TASK-ARCH-006 deployment decision - see
[queues.md](../architecture/queues.md)) - `sync` never fires the queue
events tenant isolation depends on, so it cannot prove smoke item 6
("Redis queue tenant context survives real, asynchronous execution") at
all. `redis` is used here specifically to exercise that real,
already-supported, already-tested code path, not because the recommended
production value has changed.

## Smoke test coverage (`tests/Feature/PlatformSmoke/ProductionConfigSmokeTest.php`)

Nine tests, one per requirement - deliberately small, not a second full
regression suite:

1. Tenant storefront request returns 200.
2. Tenant admin login/session works (`SESSION_DRIVER=database`).
3. Platform Admin login/session works centrally.
4. Tenant session rows land in the tenant database, never central.
5. Redis tenant cache isolation holds.
6. Redis queue tenant context survives real, asynchronous execution.
7. The tenant access gate still blocks a non-ready tenant before any tenant
   DB connection is made.
8. Subscription/Plan "My Plan" basic read path works.
9. INCIDENT-001's central-database destructive-command safeguard
   (`Platform\Tenancy\Services\CentralDatabaseWipeGuard`) remains active
   under this lane's own config - see that test's own docblock for why this
   check is deliberately environment-agnostic (it asserts the classification
   rule, not "this lane's own database is protected" - this lane's own
   database is legitimately disposable and correctly NOT protected).

## Safe database naming (INCIDENT-001, RISK_REGISTER.md R44)

Every CI job's database uses an explicit disposable prefix -
`bagisto_ci_platform` (Lane B), `bagisto_ci_smoke` (Lane C), and
`bagisto_ci_pest` (Lane A, renamed from a bare `bagisto` specifically
because `Platform\Tenancy\Services\CentralDatabaseWipeGuard` treats any
name that is neither `bagisto_central`, tenant-prefixed, nor
`bagisto_test_`/`bagisto_ci_`/`bagisto_probe_`-prefixed as unrecognized and
prohibits `db:wipe`/`migrate:fresh` against it - Lane A's own `bagisto:install`
step would otherwise have its internal db:wipe/migrate:fresh silently
no-op). No CI database is ever named `bagisto_central`, and no CI job uses
real production credentials.

## Why `bagisto:install` is not used for Platform CI

See [docs/incidents/INCIDENT-001-central-db-wipe.md](../incidents/INCIDENT-001-central-db-wipe.md)
in full. Summary: `bagisto:install`'s own `EnvironmentManager` re-reads
`.env` directly from disk and rebinds the database connection from those
file values, bypassing whatever the process's actual environment says -
this caused a real central-database wipe. Lanes B and C use only the
supported Platform bootstrap sequence instead:

```
composer install
cp .env.example .env && (sed overrides for DB_*/REDIS_* to the disposable
  service-container values above)
php artisan key:generate
php artisan platform:mark-installed
php artisan platform:migrate:central
php artisan platform:plans:seed
vendor/bin/pest ...
```

No persistent Platform Admin credentials are created in CI - the smoke
lane's own test file creates its Platform Admin fixture via
`PlatformUser::firstOrCreate()`, scoped to that test run.

Lane A is the one exception: it still runs `bagisto:install` because it
predates this SaaS engagement entirely and tests plain upstream Bagisto
packages, not Platform. Its database is disposable and never
`bagisto_central`, so this is safe by construction and was left unchanged
per "preserve existing Bagisto CI coverage."

## Destructive-command safeguards remain active

`Platform\Tenancy\Services\CentralDatabaseWipeGuard` and
`Platform\Tenancy\Listeners\RejectBagistoInstallAgainstProtectedDatabase`
are registered unconditionally in `TenancyServiceProvider::boot()` - no CI
lane disables, bypasses, or special-cases them. Lane C's smoke test 9
explicitly re-proves the guard's classification rule is intact under that
lane's own distinct config.

## Parallelism

Both Platform CI lanes run **serially** (no `--parallel`). Lane A's
existing Webkul suites keep running `--parallel` (unchanged - upstream
Bagisto's own suites were already parallel-safe before this engagement and
remain scoped away from the new Platform suite via `--testsuite`).

Platform tests are not proven parallel-safe: they perform real MySQL tenant
database creation/migration/deletion, dynamically create and grant
per-tenant MySQL users, and several files deliberately share long-lived
fixtures (`tenant-a`/`tenant-b`/etc.) across many tests within a file for
fixture-cost reasons (see `Tests\Feature\Platform\PlatformIntegrationTestCase`'s
own docblock on why `DatabaseTransactions` is disabled for these files).
Running two such files' tenant-provisioning/cleanup concurrently against
the same MySQL server has not been demonstrated safe, and weakening test
isolation to gain CI speed was explicitly rejected for this task. If
parallel Platform CI is wanted later, it needs its own dedicated
feasibility investigation (per-worker database prefixing, fixture
isolation proof) - not assumed safe by default.

## TASK-ARCH-017A fixes (first real GitHub Actions run)

The first real GitHub Actions run of Lanes B/C (2026-08-15) exposed two
genuine defects, both fixed without touching `CentralDatabaseWipeGuard`,
`RejectBagistoInstallAgainstProtectedDatabase`, or any other runtime
application code:

1. **Lane B** - `tests/Feature/Platform/CentralDatabaseWipeGuardTest.php`'s
   destructive-rejection tests (4-6) originally assumed the active process
   database is always literally `bagisto_central`. True locally, false in
   CI (Lane B's database is correctly the disposable `bagisto_ci_platform`,
   which the guard correctly does NOT protect) - so `db:wipe` genuinely
   executed for real against that CI job's own central tables, cascading
   into most of the rest of the suite. Fixed by making those tests
   environment-independent: they now create their own throwaway database
   literally named `bagisto_central`, drive the guarded command via a real
   subprocess pointed at it, and clean up - never touching whatever the
   ambient default connection happens to be. Guarded by an existence check
   that SKIPS (never creates/touches/drops anything) if a database already
   named `bagisto_central` is found on the connected MySQL server, i.e. a
   real local development environment - test 1's pure classification check
   already covers that safety property there without touching any database.
   A new test 10 explicitly proves the active default-connection database's
   tables are untouched by tests 4-6.
2. **Lane C** (and, less visibly, Lane B - both jobs provision tenants) -
   `platform_tests.yml`'s "Setting Environment" step never set
   `DB_PROVISION_USERNAME`/`DB_PROVISION_PASSWORD`. `config/database.php`'s
   `tenant_provisioning` connection (the elevated connection
   `Platform\Tenancy\Services\TenantProvisioner` uses to create/manage
   tenant databases - see RISK_REGISTER.md R18) fell back to
   `.env.example`'s explicitly-blank defaults, and Laravel's `env()` does
   not fall back to `DB_USERNAME`/`DB_PASSWORD` for a key that is present
   but empty - only for one that is truly unset. Every one of Lane C's 9
   smoke tests failed identically on `Access denied for user ''@...`
   before reaching a real assertion. Fixed by setting both to `root`,
   reusing each job's own already-declared, non-secret MySQL service
   root credentials (the whole service container is disposable and
   destroyed when the job ends) - no real/local secret involved.

## R34 status

**IMPLEMENTED / MANUAL CI VERIFICATION AVAILABLE** - a genuine
production-config smoke lane exists (Lane C above), runs the real
production-intended values, passes locally (9/9), and can be run in GitHub
Actions on demand via `workflow_dispatch`. Not claimed as continuous
per-push CI coverage - every lane, not only B/C, is now deliberately
manual-only to conserve GitHub Free-tier Actions minutes (see "Why the
Platform lanes were already manual (TASK-ARCH-017A), and why every other
lane joined them (TASK-MVP-011)" above). See RISK_REGISTER.md.

## CI gap status

**IMPLEMENTED / MANUAL CI VERIFICATION AVAILABLE** - `tests/Feature/Platform`
is wired into a real CI workflow and can be run on demand, closing the
original "manual-only, no CI wiring at all" gap, but does not run
automatically on every push/PR by deliberate resource-conservation
decision, not oversight. Local execution (`vendor/bin/pest tests/Feature/Platform`)
remains the primary, required verification step for every change. See
RISK_REGISTER.md.

## Upstream Bagisto CI incompatibility (documented, not fixed here)

The first real GitHub Actions run also exposed that Lane A
(`pest_tests.yml`) and both Playwright workflows currently fail in this
fork, for a reason unrelated to Lanes B/C or today's fix: `bagisto:install`'s
internal `migrate` step is rejected by
`Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema` (R30) on
the first Webkul package migration it encounters, since that guard
correctly refuses to let any non-central migration run against the central
connection. This is a genuine, pre-existing incompatibility between R30's
protection and upstream Bagisto's own installer-based CI bootstrap,
first empirically confirmed against real GitHub Actions on this push (it
was suspected and documented locally before INCIDENT-001, but never
verified against real CI until now, since this was the first push of any
Platform-era commit to `origin/2.4`).

**Deliberately not fixed as part of TASK-ARCH-017/017A** - `bagisto:install`
is already explicitly unsupported as a Platform/SaaS bootstrap workflow (see
[provisioning.md](../architecture/provisioning.md) and
[INCIDENT-001-central-db-wipe.md](../incidents/INCIDENT-001-central-db-wipe.md)),
and neither `PreventCentralMigrationOfTenantSchema`, `CentralDatabaseWipeGuard`,
nor `RejectBagistoInstallAgainstProtectedDatabase` should be weakened merely
to make upstream CI green again. Whether/how to repair Lane A and the
Playwright workflows (e.g. bootstrapping them via the supported Platform
sequence instead of `bagisto:install`, or accepting they test a
configuration this fork no longer fully supports) is left for a future,
deliberate decision - out of scope here.
