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

**RESEQUENCING NOTE (2026-08-14, recorded before TASK-ARCH-008 began):** This phase's acceptance criteria assumes real plan/usage data already exists to display. As originally numbered, Phase 7 precedes Phase 9 (Plans & Feature Limits) — building it first would mean either placeholder/stub UI or a later rework once real data exists. The product owner explicitly rejected building placeholder Subscription/Billing/Usage UI merely to prove the already-understood `menu.php`/`acl.php` config-merge mechanism (confirmed viable since Phase 0). **Decision: do not renumber phases** (would make existing cross-references confusing) — instead, a minimal slice of Phase 9 (plan/feature/entitlement domain only, explicitly NOT subscriptions/billing/enforcement/UI) is pulled ahead and executed as **TASK-ARCH-008**, so that when Phase 7 is eventually implemented it has real central SaaS data to bind to. Revised execution order: **minimal Phase 9 foundation (TASK-ARCH-008) → Phase 7 (Tenant Admin Integration, still not started)**. The remainder of Phase 9 (usage tracking, enforcement, any subscription-aware overrides) still follows in its originally planned position — only the narrow plan/entitlement domain model was pulled forward, not the whole phase.

## PHASE 8 — Platform Admin
Objective: build the `platform` guard admin panel (C12) as a new `packages/Platform/Admin` package, kept structurally separate from `packages/Webkul/Admin`.
Acceptance criteria: MVP criteria #17.

## PHASE 9 — Plans &amp; Feature Limits
Objective: generic feature/limit system (boolean + numeric + unlimited), plan-configurable, no hardcoded numbers in code.
Acceptance criteria: MVP criteria #14, #15.

**Partially executed ahead of schedule via TASK-ARCH-008 (2026-08-14, see the dedicated section below)**: the plan/feature-definition/tenant-assignment/entitlement-resolution domain only. Usage tracking, enforcement, and any subscription-aware override behavior remain unbuilt and still belong to this phase's later completion (a future task, not yet numbered).

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

**TASK-ARCH-004 was approved 2026-08-14. A Git checkpoint (commit `d3079acbd1801aeb517da512a67622b24e53bcd5`, branch `2.4`) was created and pushed for TASK-ARCH-001 through TASK-ARCH-004's combined work before TASK-ARCH-005 began.**

## TASK-ARCH-005 — COMPLETE (2026-08-14). "Tenant Filesystem & Storage Isolation."

Root-caused R16 fully: `FilesystemTenancyBootstrapper` remaps disk roots and `storage_path()` but never creates the underlying directory skeleton, and — found during this task's audit, previously undocumented — `Webkul\ImageCache`'s `config/imagecache.php` freezes `storage_path()`/`public_path()` values at boot, the same bug *class* as R17 (new finding, R24). Fixed both with zero `packages/Webkul` changes: `FilesystemTenancyBootstrapper` enabled for `local`/`public`/`private` disks; `Platform\Tenancy\Services\TenantProvisioner::ensureFilesystemPrepared()` added as provisioning step 2 to create the missing skeleton (idempotent, retry-safe); `Platform\Tenancy\Listeners\RetargetImageCachePaths` recomputes `imagecache.paths` post-bootstrap (same fix shape as R17); a new `/storage/{path}` route reuses `Stancl\Tenancy\Controllers\TenantAssetsController` unmodified to serve files at the exact URL `Storage::url()` already generates. A full 17-consumer audit of every real filesystem call site in `packages/Webkul` found ~15 of 17 required no changes at all, because Bagisto's existing flat relative-path convention (`product/{id}/...` etc.) becomes automatically tenant-isolated once the disk root itself is tenant-unique. No Bagisto core modifications (confirmed via `git diff --name-only -- packages/Webkul/`, empty).

**First finalization round:** `TenantImageCacheIsolationTest.php` (new, 2 tests) proved the `RetargetImageCachePaths` listener itself works correctly in isolation, but its real end-to-end test against the actual `cache/{template}/{filename}` route surfaced a deeper, previously-undiscovered blocker (new finding, **R25**): that route is registered directly on the router by `ImageCacheServiceProvider::bootImageCache()`, entirely outside the `'web'` middleware group, so it never ran `InitializeTenancyByDomain` — confirmed both by direct route-object inspection (empty middleware array) and by live HTTP proof (two tenants' distinct images at the identical path both 404 through the real route; the identical request against central storage succeeded). Reported rather than silently fixed, per instructions. Also added a dedicated real end-to-end test for the previously-flagged-but-untested `ThemeCustomizationRepository` DB-stored-path case (now passing), surfacing and reproducing one genuine, tenancy-unrelated Bagisto precondition (`translate()` returns null without a pre-existing translation row) rather than working around it.

**Second finalization round (R25 fix, required for acceptance):** the product owner did not approve deferring R25 and explicitly rejected a global-middleware fix unless a narrow one proved impossible. A narrow fix was found and implemented: `Platform\Tenancy\Providers\TenancyServiceProvider::attachTenancyToImageCacheRoute()`, using the same `$this->app->booted(...)` mechanism this file's own `mapRoutes()` already used, looks up the already-registered `imagecache` named route (guaranteed to exist by that point regardless of provider boot order) and calls the route object's own `->middleware()` method — a standard, supported Laravel mechanism — to attach `PreventAccessFromCentralDomains` and `InitializeTenancyByDomain` to that **one route only**. Zero `packages/Webkul` changes; zero global middleware/route-group changes. `TenantImageCacheIsolationTest.php` was rewritten from a documented-blocker test into a full passing 5-test matrix: both tenants 200 with pixel-verified correct-tenant content at the identical relative path, no cross-tenant retrieval, unknown-domain 404, and both configured central domains 404 (a deliberate policy choice — ImageCache serves only tenant-owned media and no real central use case exists yet). Also fixed, per the same review: `TenantStorageIsolationTest.php`'s `tenant-storage-c` skeleton test was cleaning up its database rows but leaving its physical `storage/tenanttenant-storage-c/` directory behind — now removed via `File::deleteDirectory()` alongside the DB cleanup, confirmed empty on disk after a fresh run. R24 and R25 are both now marked **RESOLVED** in RISK_REGISTER.md; R16 is marked **fully resolved**. Full Platform suite re-run fresh: **30/30 tests pass, 149 assertions**, across all five test files (cache, domain routing, image-cache, provisioning, storage). See RISK_REGISTER.md and docs/architecture/storage.md ("The ImageCache route fix (R25)") for the complete detail.

**TASK-ARCH-005 was approved, committed (`8b7675b07b60928672e3922a3274f86765808106`, branch `2.4`), and pushed.**

## TASK-ARCH-006 — COMPLETE (2026-08-14). "Queue / Background Job Tenant Isolation."

Proved R6/R7 (previously unaddressed Critical/Medium risks) end-to-end against real, asynchronous execution: a real Redis queue (`config(['queue.default' => 'redis'])`, set only inside the test suite — the app's actual `QUEUE_CONNECTION=sync` default was deliberately left unchanged, a deployment decision out of scope here) and a real `Illuminate\Queue\Worker` pop/process cycle via `Artisan::call('queue:work', [..., '--once' => true])` — never `sync`, which never fires the `JobProcessing`/`JobProcessed`/`JobFailed` events the entire mechanism (and every finding in this task) depends on. `Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper` was already enabled since TASK-ARCH-002/003 but never exercised; used as-is, no parallel custom queue-tenancy framework built.

Two real gaps were found and handled differently: **R26** (fixed) — stancl's own cleanup only fires on `JobProcessed`/`JobFailed`, neither of which Laravel fires when a job throws but still has retries remaining (`JobReleasedAfterException` fires instead), reproducibly leaving a real long-running worker's `tenancy()->initialized` stuck on a failed job's tenant; fixed via a new `Platform\Tenancy\Listeners\EndTenancyAfterJobRelease`, watched fixed in both directions. **R27** (documented) — two related PHP/package gotchas: dispatching a job as the implicit return of an arrow function passed to `tenant->run()` silently pushes it under the wrong (already-reverted) tenant context, due to `PendingDispatch`'s destructor-based dispatch interacting with `TenantRun::run()`'s capture-then-revert order (fixed structurally in this task's own tests via a documented block-closure convention, not a code bug to patch); and `queue:retry` leaves tenancy dangling with no matching cleanup event, judged low real-world impact given its normal one-off-CLI-process deployment topology. A new Platform test job, `Platform\Tenancy\Jobs\TenantIsolationProbeJob` (kept in `packages/Platform/Tenancy`, not `tests/`, as genuine reusable tenancy infrastructure), proved DB/cache/filesystem isolation through a real queued job, A→B→A in one worker lifecycle, central-job non-inheritance, failed/retried jobs, and a deleted-tenant job failing safely (not centrally, not under another tenant). Queue infrastructure (`jobs`/`failed_jobs`/`job_batches`) confirmed central-only via schema inspection; a real Bagisto job (`Webkul\Marketing\Jobs\UpdateCreateSearchTerm`) confirmed tenant-isolated end-to-end. Raw Redis payload inspection confirmed only the trusted `tenant_id` (a UUID) is carried, never credentials or Host-header-derived data. `DB_QUEUE_CONNECTION=mysql` pinned defensively in `.env`/`.env.example` after finding (and empirically clearing, though not fully tracing) an ambiguity in the unused `'database'` queue driver's own connection resolution. Suspended-tenant and Octane/long-lived-worker risks investigated and documented per instructions, not expanded into new work.

**Product-owner review round (same day)**: the A→B→A proof was flagged as needing stronger evidence — three separate `Artisan::call('queue:work', ['--once' => true])` calls are mechanistically the same OS process (confirmed by reading `Illuminate\Foundation\Console\Kernel::call()`: no `exec`/`proc_open`/`shell_exec` involved) but the test captured no hard evidence of it and used three separate worker-loop invocations rather than one continuous loop. Added: a TRUE single-invocation daemon-worker test (`--stop-when-empty`, draining a pre-queued Tenant A → Tenant B → Tenant A → Central sequence in `Illuminate\Queue\Worker::daemon()`'s own loop) with `getmypid()` recorded per job as concrete evidence — all four jobs and the test method itself share one PID. Also investigated, per explicit instruction, whether `Webkul\Core\Core`'s memoized channel/currency/locale state (a real, plausible hypothesis, not a hypothetical) leaks across tenants within that one worker process — **tested and disproven**: `Illuminate\Queue\QueueServiceProvider`'s `$resetScope` closure (`Facade::clearResolvedInstances()`, among other resets) runs before every job in `Worker::daemon()` mode, confirmed via three consecutive jobs each getting a fresh `Core` instance and correctly reading only their own tenant's data (new finding, **R28**, documented as tested-and-disproven per this register's established convention for such findings). R26 was re-verified under the true daemon loop (temporarily disabled and re-confirmed necessary), with a precision correction: even without the fix, a following job always writes to the *correct* tenant's database — the bug is dangling process state, not data misattribution. R27b's mechanism was traced further and found to compound through a second, unrelated job (tenancy can end up reverted to the *original* stale tenant, not central, after that second job completes) — data isolation held throughout the full chain in every case tested; only process-level state was ever affected. Judged not to need a fix, for the same already-documented reason (process separation between `queue:retry` and `queue:work` in real deployment). 4 additional tests; full Platform suite re-run fresh: **45/45 tests pass, 263 assertions**, across all six test files (`TenantQueueIsolationTest.php` now 15 tests, 114 assertions on its own). No `packages/Webkul` modifications. See RISK_REGISTER.md (R6, R7 resolved; R26, R27 refined; R28 new) and docs/architecture/queues.md ("TRUE long-lived worker verification") for the complete detail.

**Final cleanup round (same day)**: asked to classify the queue probe tables (`queue_isolation_probes`, `central_queue_isolation_probes`) as production infrastructure or test-only. Correctly category B — both hold nothing but diagnostic data (markers, observed tenant ids, worker PIDs, `spl_object_id()` values, a channel-code marker), no real SaaS value, yet were shipped as permanent migrations, meaning every real tenant database and the real central database would have carried this schema forever. Deleted all four migration files (`database/migrations/2026_08_14_000002...`/`000004...`, `database/migrations/tenant/2026_08_14_000001...`/`000003...`) and moved table creation into `tests/Feature/Platform/TenantQueueIsolationTest.php` itself (`ensureTenantQueueProbeSchema()`/`ensureCentralQueueProbeSchema()`, plain `Schema::create()` calls, idempotent). `Platform\Tenancy\Jobs\TenantIsolationProbeJob` needed no code change (it only ever referenced the table by name); its docblock was corrected to state plainly that it must not be dispatched outside the Platform test suite. Full Platform suite re-confirmed green after the change.

**TASK-ARCH-006 was approved and committed (`0b21ef33bcbb7a565e3fc8c5e9a93f6fc0c96f14`, branch `2.4`).**

## TASK-ARCH-007 — COMPLETE (2026-08-14). "Tenant Search Isolation."

Confirmed before starting: Phase 15 ("Search Isolation") is the next unresolved phase in the roadmap immediately after Phase 14 (Queue/Job Isolation, TASK-ARCH-006) — no mismatch to report.

Audited the real, installed search architecture rather than assuming: `catalog.products.search.engine` is a per-**tenant**, database-stored `core_config` value (already isolated by database-per-tenant), defaulting to `'database'` — confirmed live to be the active mode in this environment (no `ELASTICSEARCH_HOST` configured, no ES server running). Every real search entry point (`Webkul\Shop\Http\Controllers\API\ProductController`/`CategoryController`/`SearchController`) funnels through `Webkul\Product\Repositories\ProductRepository::getAll()`, which dispatches to either `searchFromDatabase()` (a plain Eloquent query, tenant-isolated for free via the same DB-connection-swap mechanism proven since TASK-ARCH-002/003/006 — **classification A**) or `searchFromElastic()` (a single shared Elasticsearch cluster — **classification B as shipped**).

Found and fixed the real structural risk in the elastic path: `Webkul\Product\Helpers\Product::formatElasticSearchIndexName($channelCode, $localeCode)` — the one helper every real ES read/write/reindex/purge call site funnels through — builds index names with no tenant identifier at all (`{prefix}products_{channelCode}_{localeCode}_index`, and `$channelCode`/`$localeCode` are tenant-local values that commonly collide, e.g. both tenants' default channel coded `'default'`). Fixed the same way R24 fixed the analogous `imagecache.paths` bug: `Platform\Tenancy\Listeners\RetargetElasticsearchIndexPrefix` retargets `config('elasticsearch.index_prefix')` per tenant (read fresh on every call, not frozen at boot) — zero `packages/Webkul` changes. Verified live: the real, unmodified helper produces genuinely different index names for two tenants sharing identical channel/locale codes — the exact collision scenario — including through a real queued `UpdateCreateIndex` job drained by a real continuous multi-tenant `queue:work --stop-when-empty` worker loop (reusing TASK-ARCH-006's TRUE daemon-worker proof technique).

Investigated the Phase-0-flagged Elasticsearch `Client::class` container singleton (R4) fully: confirmed the real code path never consumes it (everything goes through the `ElasticSearch` facade, which rebuilds a fresh client from current config on every call) — and found something stronger than "unused": the singleton's own binding is **broken** (`CoreServiceProvider.php:133` calls `->connection()` on the Laravel `Application` instance, which has no such method), so resolving it throws immediately in any context. A pre-existing Bagisto bug, confirmed live, documented as new finding **R4b**, not fixed (dead code, core file, no isolation benefit either way).

Added a minimal, single-tenant CLI wrapper, `php artisan tenant:index {tenant} {--type=*} {--mode=*}` (`Platform\Tenancy\Console\Commands\ReindexTenant`), mirroring `tenant:provision`'s shape — Bagisto's own `indexer:index` has no tenant awareness at all and, run bare, executes centrally (failing loudly, since the central DB has no commerce tables — safe but useless without a wrapper). Deliberately not a fleet-wide orchestrator, per instructions.

10 new tests (`tests/Feature/Platform/TenantSearchIsolationTest.php`): active-engine confirmation, real HTTP search-by-term isolation (both directions), same-term-different-results, cached-and-uncached repeated-search isolation with no cache clearing, unknown-domain rejection, the index-prefix fix (real helper, real tenant contexts), the real queued-job proof, the `Client::class` singleton finding, and the CLI wrapper. External-engine document-level isolation against a live Elasticsearch cluster is explicitly **not** claimed as proven — no such server exists in this environment; documented plainly in docs/architecture/search.md rather than overclaimed. No `packages/Webkul` modifications. See RISK_REGISTER.md (R4 resolved, R4b new) and docs/architecture/search.md for the complete detail.

**Cross-file fixture bug found and fixed during regression re-run**: the new test file initially reused the shared `tenant-a`/`tenant-b` fixtures (adding a `SEARCH-A` product), which broke 4 exact-match assertions in `TenantCacheIsolationTest.php`/`TenantDomainRoutingTest.php` when the full suite ran together — the same class of cross-file-fixture-contamination bug `TenantProvisioningTest.php` hit in TASK-ARCH-002. Fixed by switching to dedicated `tenant-search-a`/`tenant-search-b` fixtures (matching the `tenant-queue-a/b`/`tenant-prov-a/b` precedent), and cleaned up the stray `SEARCH-A`/`SEARCH-B`/`PROD-A2` rows the earlier broken run had left in the real `tenant-a`/`tenant-b` databases.

**Full Platform suite re-run fresh, final result: 6 of 7 files clean, 1 pre-existing failure unrelated to this task.** `TenantSearchIsolationTest` (10/10), `TenantCacheIsolationTest` (5/5), `TenantDomainRoutingTest` (7/7), `TenantImageCacheIsolationTest` (5/5), `TenantProvisioningTest` (5/5), `TenantStorageIsolationTest` (8/8) all pass clean — 40/40. `TenantQueueIsolationTest` shows 4 of 15 tests failing (`cache isolation survives real queue execution`, both `TRUE long-lived worker` tests, `retrying a failed Tenant A job`), reproducible in isolation and deterministically (not flaky). **Proven via `git stash -u` to fail identically on the pristine, already-approved TASK-ARCH-006 commit (`0b21ef33bc`) with zero TASK-ARCH-007 changes present** — not a regression introduced by this task. Root-caused and recorded as new finding **R29** in RISK_REGISTER.md: `Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper` creates a brand-new, empty `CacheManager`/`ArrayStore` on every tenancy bootstrap cycle (proven via `spl_object_id`), so raw `Cache::put()`/`Cache::get()` pairs spanning a bootstrap-cycle boundary silently lose data under `CACHE_STORE=array` — masked everywhere else in the suite because every other cache assertion uses read-through `Cache::remember()`, which recomputes from the database on a "miss" and looks correct regardless. Flagged for a future dedicated task; not fixed here (vendor code, out of this task's search-isolation scope, and touches already-approved TASK-ARCH-006's own test suite).

TASK-ARCH-007 provisionally approved (2026-08-14): search architecture/implementation accepted in principle, but held from commit because the full Platform suite wasn't green (R29, above). Instructed to stabilize before any new architectural checkpoint is committed — see TASK-ARCH-007A below.

## TASK-ARCH-007A — COMPLETE (2026-08-14). "R29 Cache/Queue Test Stabilization."

Re-derived R29 from the smallest possible reproduction (`php artisan tinker`, no test framework): `spl_object_id(app('cache'))` differs across two consecutive `$tenant->run()` calls under **both** `array` and `redis` stores — confirming `CacheTenancyBootstrapper` creates a fresh `CacheManager` on every tenancy bootstrap cycle regardless of backing store (that's the isolation mechanism itself, not a bug). The divergence is only in which store's *data* survives that boundary: a value written under `array` reads back `NULL` in the next cycle; the identical sequence under `redis` reads back correctly. Explicitly tested for cross-tenant leakage under `redis` with the exact shared-key scenario R29's failing tests use (`'queue-isolation'`, both tenants) — zero leakage, distinct tag-membership sets and value-hash keys per tenant confirmed via raw `redis-cli` inspection.

Before proposing any fix, reread `docs/architecture/caching.md` (TASK-ARCH-004) in full — it had **already** documented this exact nuance ("An important nuance found while writing the tests: `array` store and bootstrap-cycle persistence"), already establishing `redis` as the production-intended, persistence-capable cache store and `array` as local-dev/automated-test-only, with the `array` limitation explicitly called out as "not an isolation bug." So R29 was a violation of an already-established project contract, not a new architectural decision to make.

**Classification: B + C, not A.** (B) A real, already-documented limitation of `stancl/tenancy`'s per-bootstrap-cycle `CacheManager` combined with the `array` store's in-process-only storage — proven not to cause any cross-tenant leak. (C) `TenantQueueIsolationTest.php`'s own `beforeEach()` already flushed the real `Redis::connection('cache')` and its own comment already claimed "real redis... external, persistent state" — but never actually called `config(['cache.default' => 'redis'])`, so it silently ran against the local-dev/test-only `array` default instead. Not classification A: no production correctness/security issue: data isolation was proven intact under both stores, in both directions, both before and after the fix.

**Fix**: added the single missing line, `config(['cache.default' => 'redis']);`, to `TenantQueueIsolationTest.php`'s `beforeEach()`, immediately after the pre-existing `config(['queue.default' => 'redis']);` line it should have matched from the start. Test-file-only change — no vendor code, no `packages/Webkul`, no `packages/Platform` production code touched. No isolation assertion weakened, removed, or worked around; no `Cache::clear()` added between tenants; no values manually repopulated.

**Verification**: `TenantQueueIsolationTest.php` alone: 15/15 passing (113 assertions, up from 11/15). `TenantCacheIsolationTest.php` (TASK-ARCH-004's own suite) re-run clean, 5/5, confirming the fix didn't touch or regress the established cache-isolation contract. `TenantSearchIsolationTest.php` (TASK-ARCH-007) re-run clean, 10/10, confirming zero interaction with the search work — the two tasks are fully independent (search's own listener/config concern `elasticsearch.index_prefix` was never involved in R29 at all).

**Full `tests/Feature/Platform/` suite, fresh run: 55 passed, 0 failed, 303 assertions, 441.90s.** All 7 files clean: `TenantCacheIsolationTest` (5), `TenantDomainRoutingTest` (7), `TenantImageCacheIsolationTest` (5), `TenantProvisioningTest` (5), `TenantQueueIsolationTest` (15), `TenantSearchIsolationTest` (10), `TenantStorageIsolationTest` (8). `packages/Webkul/*` remains untouched; no vendor code modified. R29 updated to **RESOLVED** in RISK_REGISTER.md.

**Per explicit instruction, all TASK-ARCH-007 and TASK-ARCH-007A changes remain uncommitted for review — nothing has been committed, pushed, branched, rebased, or merged. Stopping here per instructions. Not proceeding to TASK-ARCH-008 or anything else without explicit approval.**

## TASK-ARCH-008 — COMPLETE (2026-08-14). "Plan & Feature Entitlement Foundation."

Built the minimal real SaaS plan/feature domain needed before Phase 7 (Tenant Admin Integration) can display real data — see the "RESEQUENCING NOTE" on Phase 7 above for why this was pulled ahead, and Phase 9's own note. Explicitly NOT subscriptions, billing, enforcement, usage tracking, or any Tenant Admin UI.

New package `packages/Platform/Plans`: `Plan`/`PlanFeature` models (both `Stancl\Tenancy\Database\Concerns\CentralConnection` — the same mechanism `Stancl\Tenancy\Database\Models\Tenant` already relies on, so every query hits the central connection regardless of active tenant context, with no `tenancy()->end()`/`tenancy()->central()` needed anywhere), `FeatureCode`/`FeatureType` enums, `TenantEntitlements` resolution service (`current()`/`for()`/`currentPlan()`/`can()`/`limit()`), `PlanSeeder` (idempotent, seeds FREE/BASIC/PRO with four example features), `platform:plans:seed` command. New central schema: `plans`, `plan_features` (generic key-value: `type` `boolean|numeric|unlimited`, single nullable `value`), `tenants.plan_id` (nullable FK, directly on `tenants` — not a separate assignment table, see DECISION_LOG.md C18 for the reasoning and the deliberately-not-yet-committed Phase 10 evolution path). `TenantProvisioner::ensureDefaultPlanAssigned()` (new provisioning step 5) assigns the configured default plan (`config('platform.plans.default_code')`, code-based not id-based) and fails provisioning loudly if it's missing. 12 new tests, `TenantEntitlementsTest.php`. Zero `packages/Webkul` changes.

**Cleanup round (same day), R30 + R31 only, per explicit instruction — no Tenant Admin UI, no TASK-ARCH-009 work:**

**R31 (real-column backfill)**: added a permanent, idempotent, central-only migration (`2026_08_14_140000_backfill_tenant_real_columns_from_legacy_data.php`) so pre-existing tenant rows (status/last_error/plan_id previously trapped in the `data` JSON blob — see RISK_REGISTER.md R31) get their real columns corrected. Found and fixed a second, deeper bug while writing this migration's own regression test: `VirtualColumn::decodeVirtualColumn()` marks every decoded attribute "not dirty" relative to itself, so a naive `$tenant->save()`-based backfill silently omits those columns from its `UPDATE` entirely — the fix reads the correct value via the trusted Eloquent decode path but writes it via a plain, targeted `DB::table('tenants')->update()`, bypassing dirty-tracking. Verified via raw SQL exactly as instructed (`SELECT id, status, last_error, plan_id, data FROM tenants`) — every existing tenant row now shows correct real-column values.

**R30 (central migration safety)**: investigated why every Webkul package registers its migrations into the global `Migrator` path list (necessary — `TenantProvisioner::ensureMigrated()` depends on it for tenant provisioning, not a bug to remove). Built two additive, non-invasive layers instead of relying on operator discipline: `php artisan platform:migrate:central` (the one supported, documented central-migration command, always scoped to `database/migrations`), and `Platform\Tenancy\Listeners\PreventCentralMigrationOfTenantSchema` — a defense-in-depth guard on Laravel's own `Illuminate\Database\Events\MigrationStarted` event that uses reflection (the same technique Laravel's own `Migrator::resolvePath()` already uses internally) to detect a package-registered migration about to run against the central connection, and aborts it before it executes. Verified live and via a new dedicated test file (`CentralMigrationSafetyTest.php`, 3 tests): a bare `php artisan migrate` is blocked with zero tables/migration-records created; `platform:migrate:central` succeeds installing no Bagisto commerce table; tenant provisioning still installs the full 137-table schema, completely unaffected by the guard. Zero `packages/Webkul` changes, no Laravel/Bagisto class overridden or rebound.

DECISION_LOG.md C18 revised per explicit instruction: no longer commits to `tenants.plan_id` permanently becoming a synced subscription cache — that decision is deferred to Phase 10 itself, to avoid inventing a synchronization invariant before `subscriptions` exists to justify it.

Along the way, fixing R31 surfaced (and required repairing) a related, pre-existing data-integrity gap in this environment's own accumulated test fixtures: several long-standing shared tenants (`tenant-a`, `tenant-b`, `tenant-queue-a/b`, `tenant-search-a/b`) had never had `status='ready'` correctly persisted anywhere (neither the real column nor `data`, for reasons predating this task), despite being fully, functionally provisioned and used successfully throughout the whole engagement — invisible until the real column became authoritative. Self-healed automatically via each file's own genuine `forceFill()->save()` fixture-check during the full-suite run (not a code change, and not reproducible in a fresh environment, where `TenantProvisioner` always persists this correctly the first time now).

**Full `tests/Feature/Platform/` suite, fresh run: 71 passed, 0 failed, 352 assertions, 491.30s.** All 9 files clean: `CentralMigrationSafetyTest` (3), `TenantCacheIsolationTest` (5), `TenantDomainRoutingTest` (7), `TenantEntitlementsTest` (13), `TenantImageCacheIsolationTest` (5), `TenantProvisioningTest` (5), `TenantQueueIsolationTest` (15), `TenantSearchIsolationTest` (10), `TenantStorageIsolationTest` (8). `packages/Webkul/*` remains untouched; no vendor code modified.

**Per explicit instruction, all TASK-ARCH-008 changes (original + cleanup round) remain uncommitted for review — nothing has been committed, pushed, branched, rebased, or merged. Stopping here per instructions. Not proceeding to TASK-ARCH-009 or anything else without explicit approval.**
