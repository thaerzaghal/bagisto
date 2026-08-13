# Implementation Plan — Roadmap &amp; Task Backlog

This is Phase 0 output, plus the results of one approved spike (TASK-ARCH-001, see bottom of this file). **Nothing beyond TASK-ARCH-001 is authorized to start.** Each phase below lists objective, prerequisites, tasks, affected modules, DB changes, tests, acceptance criteria, risks, and rollback strategy, per the brief. Detailed task-level breakdowns for the earliest phases are in the Task Backlog section at the bottom; later phases are scoped at the phase level and will be broken into tasks closer to when they start (breaking down all 18 phases into TASK-### items today would produce speculative tasks that don't reflect what Phase 1–3 actually discover).

## PHASE 0 — Repository Discovery &amp; Compatibility (this document set)
**Status: COMPLETE.**
Objective: establish ground truth about the actual Bagisto codebase before any design commitment.
Output: `ARCHITECTURE.md`, `DECISION_LOG.md`, `RISK_REGISTER.md`, `UPSTREAM_SYNC.md`, this file, and `docs/`.
Acceptance criteria: every architectural claim in this document set is backed by a specific file:line reference or a verified external source (Packagist/GitHub), not general Laravel/Bagisto knowledge. Met.

## PHASE 1 — Architecture Foundation
Objective: land the skeleton that every later phase builds on, with zero behavior change to existing Bagisto functionality.
Prerequisites: Phase 0 sign-off; approval to add `packages/Platform/*` namespace and modify `composer.json` autoload (per `AGENTS.md` "do not add/remove composer dependencies without approval" — adding a *namespace*, not a dependency, but still flagged for approval since it changes `composer.json`).
Tasks: create `packages/Platform/` directory convention doc; add PSR-4 autoload entry; create empty `Platform\Core` package with a service provider that does nothing yet (proves the registration mechanism works end-to-end); add `docs/` structure (this delivery).
Affected: `composer.json` (autoload only), `bootstrap/providers.php` (one new line, additive).
DB changes: none.
Tests: `composer dump-autoload` succeeds; `php artisan optimize:clear` succeeds; existing Pest suite still green (proves zero regression).
Acceptance criteria: a no-op `Platform\Core\Providers\CoreServiceProvider` boots alongside all 38 existing providers with no errors.
Risks: none identified — purely additive.
Rollback: delete the directory, remove the two additive lines.

## PHASE 2 — Tenancy Package Integration
Objective: install and minimally configure `stancl/tenancy` v3.10.1 against this Laravel 12 app, with central-domain protection, but with **no tenant created yet**.
Prerequisites: Phase 1. Approval to add `stancl/tenancy` as a real composer dependency (HUMAN DECISION REQUIRED per `AGENTS.md` dependency rule — distinct from and in addition to the general architecture approval).
Tasks: `composer require stancl/tenancy`; publish config to `config/tenancy.php`; define central domain(s) (e.g. `platform.<ourdomain>`); register `InitializeTenancyByDomain` + `PreventAccessFromCentralDomains` middleware; do NOT yet register bootstrappers beyond `DatabaseTenancyBootstrapper` (defer cache/filesystem/queue bootstrappers to their dedicated phases so each is tested in isolation).
DB changes: `stancl/tenancy`'s own central migrations (`tenants`, `domains`) — reviewed, not yet customized.
Tests: unit test that a request to the central domain is served, and a request to a non-existent tenant subdomain 404s cleanly (proves `PreventAccessFromCentralDomains` + tenant-not-found handling both work before any real tenant exists).
Risks: none yet — no production tenant data involved.
Rollback: `composer remove stancl/tenancy`, drop its migrations.

## PHASE 3 — Central Database &amp; Tenant Model
Objective: design and migrate the real central schema (see [docs/architecture/database-per-tenant.md](docs/architecture/database-per-tenant.md)), extending stancl/tenancy's base `Tenant`/`Domain` models with our own columns (`status`, `plan_id`, etc.) rather than replacing them.
Tasks: `tenants` (extends stancl base), `tenant_provisioning_events` (audit trail of the state machine — see Phase 4), `platform_users`, `platform_settings`.
DB changes: central migrations only.
Tests: model factory + migration tests; state-machine transition unit tests (see [docs/architecture/provisioning.md](docs/architecture/provisioning.md) for the state machine: PENDING → PROVISIONING → READY → FAILED / SUSPENDED → DELETING → DELETED).
Acceptance criteria: a `Tenant` record can be created in isolation (no DB provisioning side-effect yet) and its status transitions are validated (illegal transitions rejected).
Risks: schema churn if Phase 4/9/10 need columns not anticipated here — mitigated by keeping this phase minimal and adding plan/subscription columns in their own phases rather than guessing now.
Rollback: migrate:rollback the central migrations; no tenant DBs exist yet so no cross-DB cleanup needed.

## PHASE 4 — Tenant Database Provisioning
Objective: implement the provisioning pipeline, reusing `BagistoDatabaseSeeder` and package migrations (per DECISION_LOG C13), explicitly NOT reusing `packages/Webkul/Installer`'s command/flat-file flow (R11).
Tasks: `CreateTenantDatabase` job (creates the physical DB + DB user), `MigrateTenantDatabase` job (`migrate` against the dynamically bound tenant connection), `SeedTenantDatabase` job (invokes `BagistoDatabaseSeeder` bound to the tenant connection), `CreateTenantAdmin` job, idempotency: every job checks current `tenants.status` and is safely re-runnable (a job that has already completed its step no-ops rather than erroring).
DB changes: none to schema; heavy use of dynamic tenant DB creation.
Tests: integration test proving that killing the pipeline mid-way (simulate a failure after `MigrateTenantDatabase` but before `SeedTenantDatabase`) and re-running it completes correctly without duplicate data.
Acceptance criteria: MVP criteria #2, #3 (tenant DB auto-created, Bagisto schema installed).
Risks: partial provisioning leaving an orphaned DB if the job pipeline itself crashes outside job-level error handling — mitigate with a cleanup command (`platform:tenants:reap-failed`) run on a schedule.
Rollback: a `DELETING` state that drops the tenant database and marks the tenant `DELETED`.

## PHASE 5 — Bagisto Tenant Database Integration
Objective: verify (with automated tests, not just manual QA) that every Bagisto repository/model genuinely respects the swapped connection, since Phase 0 only confirmed the *absence* of hardcoded connections statically — this phase confirms it dynamically at runtime.
Tasks: write a Pest test that provisions two tenants, creates a product in each via their respective `ProductRepository`, and asserts each tenant's `ProductRepository::all()` returns only its own product.
Acceptance criteria: MVP criteria #8, #9, #10.
Risks: R9 (Channel resolution ordering) — this phase is where that risk is actually exercised end-to-end for the first time.

## PHASE 6 — Tenant Domain Routing
Objective: subdomain-per-tenant (`{slug}.platform.<domain>`) resolving to the correct tenant, verified against R9's middleware-ordering requirement.
Acceptance criteria: MVP criteria #4, #5.
Risks: R9.

## PHASE 7 — Tenant Admin Integration
Objective: add Subscription/Billing/Usage nav items into the existing Bagisto admin via `menu.php`/`acl.php` config-merge (no core edit), per the mechanism confirmed in Phase 0 research.
Acceptance criteria: tenant admin sees their plan/usage/domain without any Bagisto admin view file being modified.

## PHASE 8 — Platform Admin
Objective: build the `platform` guard admin panel (C12) as a new `packages/Platform/Admin` package, kept structurally separate from `packages/Webkul/Admin`.
Acceptance criteria: MVP criteria #17.

## PHASE 9 — Plans &amp; Feature Limits
Objective: generic feature/limit system (boolean + numeric + unlimited), plan-configurable, no hardcoded numbers in code.
Acceptance criteria: MVP criteria #14, #15.

## PHASE 10 — Subscriptions
Objective: subscription state machine (trial/active/past-due/grace/canceled), independent of the tenant provisioning state machine from Phase 4.
Acceptance criteria: MVP criteria #16.

## PHASE 11 — Billing Provider Integration
Objective: wire the chosen provider (HUMAN DECISION REQUIRED, DECISION_LOG item 2) behind a `BillingProvider` interface so the domain layer never imports a vendor SDK directly.
Prerequisites: business decision on provider.

## PHASE 12 — Storage Isolation
Objective: `stancl/tenancy`'s `FilesystemTenancyBootstrapper`, addressing R5.
Acceptance criteria: MVP criteria #11.

## PHASE 13 — Cache Isolation
Objective: addresses R1, R2, R3 — the highest-severity risks in the register. Requires HUMAN DECISION REQUIRED item 1 (cache store) resolved first.
Acceptance criteria: MVP criteria #12; automated test proving R1/R2/R3 cannot reproduce.

## PHASE 14 — Queue/Job Isolation
Objective: addresses R6, R7.
Acceptance criteria: MVP criteria #13.

## PHASE 15 — Search Isolation
Objective: addresses R4 (Elasticsearch singleton + index prefix).

## PHASE 16 — Security Hardening
Objective: full threat-model pass against [docs/architecture/security.md](docs/architecture/security.md), penetration-style tests for every item in that document.

## PHASE 17 — Automated Tests
Objective: the full suite described in [docs/implementation/testing-strategy.md](docs/implementation/testing-strategy.md), run in CI.
Acceptance criteria: MVP criteria #19.

## PHASE 18 — Production Deployment
Objective: infra provisioning (out of scope for this document — application architecture only), rollout plan, monitoring.

---

## Task Backlog (Phases 1–4, detailed)

Only the near-term phases are broken into individual tasks; later phases will be decomposed when their prerequisite phases are actually complete, to avoid speculative planning.

**TASK-ARCH-001 — COMPLETE.** Database-per-tenant feasibility spike. See the "First Implementation Task" section below for the full result report. This superseded an earlier, narrower TASK-ARCH-001 proposal (bare namespace scaffold only) that was explicitly rejected in favor of proving the core architectural assumption first — see conversation record / DECISION_LOG.md.

**Phase 1** (superseded — TASK-ARCH-002 and TASK-ARCH-003, both actually executed, covered everything originally sketched here plus more: `Platform\Tenancy` package created with the real `Tenant` model/`TenancyServiceProvider` inside it from the start, `platform:mark-installed` command shipped (R14), tenant domain routing proven end-to-end against real Bagisto routes. The items below are kept only as a historical record of the original, narrower plan.)
- ~~TASK-ARCH-002 — Add `Platform\\` PSR-4 namespace~~ **DONE**, as part of the actual TASK-ARCH-002 ("Tenant Foundation").
- ~~TASK-ARCH-003 — Scaffold `packages/Platform/Core`, migrate spike models~~ **DONE differently**: landed as `packages/Platform/Tenancy` (not `Core`) in TASK-ARCH-002, since that's what the content actually was. The real TASK-ARCH-003 ("Tenant Domain Routing") is a separate, later, approved task — see its own section below.
- ~~TASK-ARCH-004 — Run full existing Pest suite~~ Not yet done as a full-suite baseline; only the Platform test files have been run so far (12/12 passing). Still a good idea before Phase 5+.
- ~~TASK-ARCH-005 — Pre-create storage/installed~~ **DONE** in TASK-ARCH-002 via `platform:mark-installed`.

**Phase 2** (largely de-risked by TASK-ARCH-001; remaining setup work)
- TASK-TENANCY-001 — ~~Confirm installed PHP version~~ **DONE** in TASK-ARCH-001 (PHP 8.3.33, Laravel 12.62.0, MySQL 8.0.32, stancl/tenancy v3.10.1, all confirmed compatible live).
- TASK-TENANCY-002 — ~~`composer require stancl/tenancy`~~ **DONE** in TASK-ARCH-001 (pinned `^3.10`).
- TASK-TENANCY-003 — ~~Publish `config/tenancy.php`; set `central_domains`~~ **DONE** in TASK-ARCH-001, plus the required `migration_parameters['--path']` fix (RISK_REGISTER.md R17) — production `central_domains` list (real platform domain, not just `127.0.0.1`/`localhost`) still needs to be set when a real domain exists.
- TASK-TENANCY-004 — ~~Register tenancy service provider + middleware~~ **DONE** in TASK-ARCH-001 (`App\Providers\TenancyServiceProvider` registered first in `bootstrap/providers.php`, ahead of `CoreServiceProvider`, per R9).
- TASK-TENANCY-005 — Write the "central domain serves, unknown subdomain 404s" test (not yet written — TASK-ARCH-001's tests only covered the two-tenant-domain-resolves-correctly case, not the central-domain-protection or unknown-domain-404 cases).
- TASK-TENANCY-006 — Re-enable `CacheTenancyBootstrapper`/`FilesystemTenancyBootstrapper` (disabled for the spike, RISK_REGISTER.md R15/R16) once Phase 12/13 land properly, with a tag-capable cache store.

**Phase 3**
- TASK-CENTRAL-001 — Design final central schema in `docs/architecture/database-per-tenant.md` (already drafted, review/approve).
- TASK-CENTRAL-002 — Migration: extend stancl's `tenants` table with `status`, `plan_id`, `slug` columns.
- TASK-CENTRAL-003 — Migration: `tenant_provisioning_events`.
- TASK-CENTRAL-004 — Migration: `platform_users`, `platform_settings`.
- TASK-CENTRAL-005 — `Tenant` model state-machine transition unit tests.

**Phase 4**
- TASK-PROVISION-001 — `CreateTenantDatabase` job + idempotency test.
- TASK-PROVISION-002 — `MigrateTenantDatabase` job.
- TASK-PROVISION-003 — `SeedTenantDatabase` job (wraps `BagistoDatabaseSeeder`).
- TASK-PROVISION-004 — `CreateTenantAdmin` job.
- TASK-PROVISION-005 — Provisioning pipeline orchestrator (job chain/batch) + mid-failure resume integration test.
- TASK-PROVISION-006 — `platform:tenants:reap-failed` cleanup command.

---

## TASK-ARCH-001 Result Report — Database-Per-Tenant Feasibility Spike

**Status: COMPLETE. STOPPED per instructions — no further tasks started. Awaiting approval before any TASK-ARCH-002 work.**

Executed against a real, disposable environment: PHP 8.3.33, Laravel 12.62.0, MySQL 8.0.32, stancl/tenancy v3.10.1, all running in Docker containers (Docker Desktop was not running at task start and had to be launched; a custom minimal `php:8.3-cli-bookworm`-based image was built with the exact PHP extensions `composer.json` requires, since no local PHP/Composer/MySQL were available on the host). Nothing here is simulated or hypothesized — every claim below was produced by an actual command against an actual database.

### 1. Files changed

New files:
- `app/Models/Tenant.php` — extends `Stancl\Tenancy\Database\Models\Tenant`, implements `TenantWithDatabase`, uses `HasDatabase`/`HasDomains`. Required because the bare base `Tenant` model doesn't implement the contract stancl's `CreateDatabase` job needs — this was the first concrete blocker hit, resolved in ~2 minutes.
- `app/Providers/TenancyServiceProvider.php` — scaffolded by `php artisan tenancy:install`, unmodified from package defaults.
- `config/tenancy.php` — scaffolded by the same command, then hand-edited in two places (see §7 below): `tenant_model` pointed at `App\Models\Tenant`, `migration_parameters['--path']` changed from the package default (`database/migrations/tenant` only) to an explicit glob of every `packages/Webkul/*/src/Database/Migrations` directory plus `database/migrations/tenant`, and the `bootstrappers` array had `CacheTenancyBootstrapper`/`FilesystemTenancyBootstrapper` commented out for this spike only (both fully documented inline in the file itself).
- `database/migrations/2019_09_15_000010_create_tenants_table.php`, `..._000020_create_domains_table.php` — stancl's central migrations, unmodified.
- `database/migrations/tenant/2026_08_13_000001_create_spike_items_table.php` — a trivial custom table used only to prove plain DB-switching in isolation, before testing a real Bagisto repository.
- `routes/tenant.php` — stancl-scaffolded file, extended with five spike-only routes (`/spike/whoami`, `/spike/items` GET+POST, `/spike/products` GET+POST) that exercise `ProductRepository` over a real HTTP request. Clearly marked for removal before real tenant routing is built (Phase 6).
- `tests/Feature/Platform/TenancySpikeTest.php` — the automated Pest suite, two tests, 20 assertions total, described in §4-5.
- `docs/`, `ARCHITECTURE.md`, `DECISION_LOG.md`, `RISK_REGISTER.md`, `UPSTREAM_SYNC.md`, `IMPLEMENTATION_PLAN.md` — Phase 0 documentation (predates this task; listed here only because they're part of the same uncommitted working tree).

Modified files:
- `bootstrap/providers.php` — one import + one array entry added (`App\Providers\TenancyServiceProvider::class`), placed **first**, before every Webkul provider, with an inline comment explaining why (R9 ordering requirement). No existing line changed or removed.
- `composer.json` / `composer.lock` — `stancl/tenancy: ^3.10` added to `require` (pulls in `stancl/jobpipeline`, `stancl/virtualcolumn` as transitive deps). No existing dependency version changed.

Not committed to git (this repo has no staged/committed changes from this task — everything above is currently just working-tree state, per the "wait for approval" instruction; nothing has been pushed or committed).

### 2. Packages added/changed

- `stancl/tenancy` `v3.10.1` (new)
- `stancl/jobpipeline` `v1.9.0` (new, transitive)
- `stancl/virtualcolumn` `v1.5.0` (new, transitive)

No existing package's version was changed. No package was removed.

### 3. Database changes

**Central database** (`bagisto_central`): two new tables, `tenants` and `domains` (stancl's own migrations, unmodified). Verified these are the *only* Bagisto-related additions — a deliberate test proved that running `php artisan migrate` **without** an explicit `--path` scope would have also pulled in Bagisto's full ~40-package commerce schema into the central database (a serious, silent architecture violation) — see §7 finding 4. The spike avoids this by always using explicit, scoped `--path` migration commands.

**Tenant databases**: none exist now (both spike tenant databases were created, used, and dropped by the automated test's teardown — see §4). During the spike's manual verification phase, two tenant databases (`tenanttenant-a`, `tenanttenant-b`) were created, each auto-migrated to 137 tables (full Bagisto schema via Concord-registered migrations) and seeded with `BagistoDatabaseSeeder` (default channel, locale, currency, attribute family, admin role, admin user). Confirmed via direct SQL inspection that each tenant database is physically separate (`SHOW DATABASES` listed both), not a shared schema with any tenant-scoping column.

### 4. Tests added

`tests/Feature/Platform/TenancySpikeTest.php`, two tests:

1. **"a real HTTP request initializes the correct tenant and switches the DB connection, isolating a plain tenant table"** — creates two tenants with real domains (`tenant-a.spike.test`, `tenant-b.spike.test`), issues real HTTP GET/POST requests (Laravel's test HTTP client, full request/response lifecycle including all middleware) against each domain, asserts `DB::connection()->getDatabaseName()` differs correctly per domain and that a custom `spike_items` table is fully isolated between tenants.
2. **"a real Bagisto repository (ProductRepository) operates against the tenant database with no cross-tenant leakage"** — seeds both tenants with Bagisto's real `BagistoDatabaseSeeder`, creates a product in each via HTTP POST to a route that calls `Webkul\Product\Repositories\ProductRepository::create()` (the actual, unmodified Bagisto repository class), then asserts via HTTP GET **and** via direct `DB::table('products')` inspection (not just behavioral — per the "inspect, don't just observe" testing principle in `docs/architecture/security.md`) that each tenant sees only its own product.

Both tests include `afterEach` cleanup that drops the physical tenant databases and deletes the central `tenants`/`domains` rows explicitly (see §7 finding 6 for why this couldn't rely on the test framework's transaction rollback).

### 5. Test results

```
PASS  Tests\Feature\Platform\TenancySpikeTest
✓ a real HTTP request initializes the correct tenant and switches the DB connection  82.74s
✓ a real Bagisto repository (ProductRepository) operates against the tenant database  236.75s

Tests: 2 passed (20 assertions)
Duration: 322.67s
```

Both tests pass. Runtime is dominated by real MySQL DDL (creating a database + running ~40 migration files, twice per test) rather than any framework overhead — expected and acceptable for a spike; production provisioning will queue this work asynchronously (already the plan per `docs/architecture/provisioning.md`), and a CI environment would reuse a warm database template rather than migrating from scratch each run — worth a future optimization note, not a blocker.

Post-test state verified clean: `SELECT` against `tenants` returns zero rows, `SHOW DATABASES` shows no `tenanttenant-*` databases left behind.

### 6. Bagisto core modifications

**None.** Every file touched is either: a new file we own (`app/Models/Tenant.php`, `app/Providers/TenancyServiceProvider.php`, `routes/tenant.php`, `config/tenancy.php`, our test file), a Bagisto/Laravel-framework file we only *appended* to (`bootstrap/providers.php`, `composer.json`), or a one-line local dev-environment marker file (`storage/installed`) that Bagisto's own Installer would itself create at the end of its normal wizard — not a code change at all, just data. No file under `packages/Webkul/*` was edited.

### 7. Newly discovered risks (all now recorded in `RISK_REGISTER.md` as R14–R18, all evidence-backed by this live run)

1. **R14 (Critical, CONFIRMED):** `packages/Webkul/Installer/src/Http/Middleware/CanInstall` is registered as a *global* middleware in `bootstrap/app.php` and redirects **every** request — central or tenant — to `/install` unless a flat `storage_path('installed')` marker file exists. This isn't a provisioning-pipeline nuance, it's an active HTTP redirect that would make the entire platform unreachable until fixed. Fixed for the spike with zero code changes (pre-created the marker file); documented as a required one-time deployment step for production, since we never intend to run Bagisto's own Installer wizard per DECISION_LOG C13.
2. **R15 (Critical, CONFIRMED):** `CacheTenancyBootstrapper` **hard-crashes** (`BadMethodCallException`) against the default `file` cache store — not a silent leak as Phase 0 hypothesized, an outright exception on the first cache write inside any tenant context (triggered by `l5-repository`'s cache-clean listener regardless of whether result caching is even enabled). Confirms DECISION_LOG C14 (move to a tag-capable store) is a hard prerequisite for Phase 13, not a hardening nice-to-have.
3. **R16 (High, CONFIRMED):** `FilesystemTenancyBootstrapper` suffixes `storage_path()` per tenant but doesn't pre-create the standard Laravel storage subdirectory skeleton underneath, causing warnings from `l5-repository`. Separately (and independently of that bootstrapper), Bagisto's own seeders write theme/locale asset files to a shared, non-tenant-scoped path even with the bootstrapper off — direct empirical confirmation of the storage-isolation risk already flagged as R5 in Phase 0.
4. **R17 (Critical, CONFIRMED):** `stancl/tenancy`'s default `tenants:migrate` configuration only targets `database/migrations/tenant`, silently excluding all of Bagisto's package migrations. The *naive* fix (removing the `--path` restriction entirely) would have been actively dangerous — it would have also matched `database/migrations` (where the central-only `tenants`/`domains` migrations live), meaning tenant databases would receive the central tenant-registry schema too. Fixed with an explicit `--path` glob; documented as the required pattern.
5. **R18 (Medium, CONFIRMED, operational):** tenant DB provisioning requires a database user with `CREATE DATABASE`/`DROP DATABASE` privileges — a normally-scoped application DB user does not have this. The spike used MySQL root as a documented shortcut; production needs a purpose-scoped provisioning user.
6. **Test-infrastructure finding (not tenancy-specific):** this repo's `tests/TestCase.php` uses the `DatabaseTransactions` trait, but in this Laravel version that trait's rollback hook is only ever invoked internally by `RefreshDatabase` — a bare `DatabaseTransactions`-only `TestCase` does not actually roll back test writes. Worth flagging to the team independent of this task; our test works around it with explicit `afterEach` cleanup.

None of these were fabricated or extrapolated — each was hit as a real error message during this session and resolved (or, for R18, deliberately worked around with a documented shortcut) before the tests could pass.

### 8. Database-per-tenant architecture: **CONFIRMED**

The core assumption — "can Bagisto run correctly against a tenant-specific database selected at runtime using stancl/tenancy, without modifying Bagisto core?" — is **CONFIRMED**, with five real, non-hypothetical production requirements now identified and documented (R14–R18) that Phases 1–4 and 12–14 must account for. None of them require a Bagisto core modification; all have documented, additive fixes. The architecture selected in Phase 0 (`ADR-002-multi-tenancy-model.md`) stands.

**TASK-ARCH-001 was approved 2026-08-13.**

## TASK-ARCH-002 — COMPLETE (2026-08-13). "Tenant Foundation."

Built the production-oriented tenant foundation on top of the spike: `packages/Platform/Tenancy` (Tenant model + TenantStatus enum, TenancyServiceProvider, `TenantProvisioner` service, `tenant:provision`/`platform:mark-installed` commands), resolved R14/R17/R18 (see RISK_REGISTER.md), added a new R19 (low severity), 5/5 permanent Pest integration tests passing (45 assertions) in `tests/Feature/Platform/TenantProvisioningTest.php`. Full result report given in chat per the requested 18-item format; not duplicated here to avoid two sources of truth — see RISK_REGISTER.md for the risk-by-risk resolution detail and the package source itself (heavily docblock-commented) for the implementation rationale.

**TASK-ARCH-002 was approved 2026-08-14.**

## TASK-ARCH-003 — COMPLETE (2026-08-14). "Tenant Domain Routing."

Proved the full request lifecycle (Host header → tenant identification → DB switch → real, unmodified Bagisto Shop/Admin routes) via `InitializeTenancyByDomain` prepended to the `web` middleware group in `bootstrap/app.php` — zero Bagisto core changes. 7 new permanent tests (`TenantDomainRoutingTest.php`) plus TASK-ARCH-002's 5 (still passing) = 12/12, 62 assertions. Two new risks found and confirmed live: R20 (`trustProxies(at:'*')` + Host-header-based tenant resolution — a production deployment checklist item) and R21 (a real, reproduced cross-tenant cache leak on the Shop API's cached listing endpoint — upgrades R2 from theoretical to confirmed). Also corrected a wrong claim from TASK-ARCH-002's report (DatabaseTransactions trait behavior). Full result report given in chat per the requested 16-item format; see RISK_REGISTER.md and docs/architecture/domain-routing.md for the risk-by-risk and implementation detail.

**TASK-ARCH-003 was approved 2026-08-14, and R21 was designated security-critical.**

## TASK-ARCH-004 — COMPLETE (2026-08-14). "Tenant Cache Isolation."

Root-caused both R15 (why `CacheTenancyBootstrapper` crashed: tagging is unconditional in `Stancl\Tenancy\CacheManager::__call()`, and `file`/`database` stores don't support tagging) and R21 (confirmed live cross-tenant leak on the Shop API's cached listing). Fixed both with the smallest possible change — enable the bootstrapper, switch to a taggable cache store (`array` for tests, `redis` for production) — requiring **zero changes** to `CatalogApiCache.php`, `PhonePe.php`, or any repository, since all resolve caching through the same swapped `app('cache')` binding. Verified the fix by watching the R21 regression test fail with the fix reverted and pass with it applied, not by assumption. Found and fixed two new issues along the way: R22 (a previously-undocumented gap in console/CLI-triggered repository caching, closed as a side effect of the same fix) and R23 (an `array`-store-specific in-process persistence limitation, verified absent with real Redis). 17/17 tests pass, 80 assertions, across all three Platform test files. No Bagisto core modifications. Full result report given in chat per the requested 15-item format; see RISK_REGISTER.md and docs/architecture/caching.md for the complete before/after/verification detail.

**Stopping here per instructions. Not proceeding to TASK-ARCH-005 or anything else (subscriptions, billing, plans, feature limits, platform UI, custom domains, SSL, deployment) without explicit approval.**
