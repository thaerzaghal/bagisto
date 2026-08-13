> Human decisions required before implementation begins: see [Section P — Human Decisions Required](docs/decisions/) and inline `HUMAN DECISION REQUIRED` markers below. This document is Phase 0 output (discovery + architecture). No SaaS functionality has been implemented.

# Platform Architecture — Bagisto Multi-Tenant SaaS

## A. Executive Summary

We are building a database-per-tenant SaaS retail commerce platform on top of an unmodified Bagisto core (this repo, forked at `thaerzaghal/bagisto` from `bagisto/bagisto`, currently at commit `e6307c5`). Each merchant ("tenant") gets a fully isolated MySQL database containing a standard Bagisto install; a central database holds platform-level concerns (tenant registry, domains, plans, subscriptions, billing, platform admins). Tenancy is implemented with `stancl/tenancy` v3.10.1 (confirmed compatible: PHP `^8.0`, Laravel `^10‖^11‖^12‖^13`, actively maintained, released 2026-08-05), which supplies central-connection swapping, domain/subdomain identification, and per-tenant cache/filesystem/queue bootstrapping. Our own code lives in new `packages/Platform/*` packages — a namespace deliberately distinct from `packages/Webkul/*` so future `bagisto/bagisto` releases can never collide with it. Bagisto's own extension points (service providers, Concord module registration, config-mergeable `menu.php`/`acl.php`, events/listeners, the repository pattern) are sufficient to integrate SaaS concerns into the merchant admin without editing any vendor/core file identified so far.

Repository investigation surfaced one important piece of good news and several concrete risks that must be engineered before go-live — see [RISK_REGISTER.md](RISK_REGISTER.md). The good news: **no model, repository, or DataGrid class in `packages/Webkul` hardcodes a database connection name** (verified by repo-wide grep) — everything rides Laravel's default connection, which is exactly the assumption `stancl/tenancy`'s central-connection-swap mechanism depends on. The main risks are cache/response-cache keys, image/import-export file paths, queued jobs, and the Elasticsearch client singleton — none of these are keyed by tenant today, and all are addressable via `stancl/tenancy`'s bootstrappers plus targeted, small, additive code in our own packages (detailed in [docs/architecture/](docs/architecture/)).

## B. Current System Analysis

| Item | Value | Evidence |
|---|---|---|
| Bagisto version | 2.4.x | `CLAUDE.md:7` |
| Laravel | `^12.0` | `composer.json` |
| PHP | `>=8.3 <8.5` | `composer.json` |
| Package count | ~40 packages under `packages/Webkul/` | `AGENTS.md` |
| Module system | Konekt Concord (`konekt/concord ^1.16`) | `config/concord.php` |
| Data-access pattern | `prettus/l5-repository`, base class `Webkul\Core\Eloquent\Repository` | repo research |
| Default DB connection | `mysql`, single connection, no multi-connection pattern | `config/database.php` |
| Default cache store | `file` (`.env.example`: `CACHE_STORE=file`) | repo research |
| Default queue | `sync` (`.env.example`), `database` (config default) | repo research |
| Default session | `database` driver | `.env.example` |
| Full-page cache | `spatie/laravel-responsecache` wrapped by `Webkul\FPC`, **enabled by default** (`RESPONSE_CACHE_ENABLED=true`) | `.env.example`, `config/responsecache.php` |
| Search | Elasticsearch wired via `config/elasticsearch.php`, opt-in per store, MySQL `LIKE` fallback default | repo research |
| Billing (in-app) | `laravel/cashier ^16.0` present in `composer.json` but **entirely unused** — no `Billable` trait anywhere | repo research |
| Async runtime | `laravel/octane ^2.3` present but **not configured** (no `config/octane.php`, no `OCTANE_SERVER`) | repo research |
| Auth guards | `admin` (`Webkul\User\Models\Admin`), `customer` (`Webkul\Customer\Models\Customer`), both session-driver; **no `sanctum`/`api` guard defined** | `config/auth.php` |
| Domain routing | None exists (`Route::domain()` never used); no URL locale-prefix; channel resolved by HTTP Host header | repo research |
| Installer | `packages/Webkul/Installer` — a full first-run wizard (server checks → `.env` write → `migrate:fresh` → seed → optional demo data → create admin → flat "installed" file) | repo research |

Full evidence detail is in [docs/architecture/system-overview.md](docs/architecture/system-overview.md).

## C. Recommended Architecture

```
                         PLATFORM / CENTRAL APP
                        (central DB connection)
                                  |
              +-------------------+--------------------+
              |                                         |
        Platform Admin                          Tenant Resolution
     (guard: platform, own                    (stancl/tenancy domain
      models, own routes,                      identification middleware,
      packages/Platform/Admin)                  runs before Bagisto boot)
              |                                         |
     Tenants, Plans, Subscriptions,                     |
     Billing (Cashier), Domains,                        |
     Usage, Platform Settings                +----------+-----------+
                                              |                      |
                                    Central DB connection   Tenant DB connection
                                    (platform tables)       (per-tenant, swapped by
                                                              stancl/tenancy)
                                                                      |
                                                        +-------------+-------------+
                                                        |                           |
                                                  Bagisto Core                SaaS Tenant-side
                                                  (packages/Webkul/*,          Modules
                                                   UNMODIFIED)                (packages/Platform/
                                                        |                      TenantAdmin — menu.php/
                                                Products, Orders,              acl.php merge only)
                                                Customers, Channels,
                                                Inventory, CMS, etc.
```

Key architectural rule: the tenant-resolution middleware (domain → tenant → DB connection swap) must execute **before** `Webkul\Core\CoreServiceProvider` and any package that resolves `core()->getCurrentChannel()`, because Channel resolution already does its own HTTP-Host lookup against whatever connection is currently active (`packages/Webkul/Core/src/Core.php:137-158`). Getting this ordering wrong silently serves tenant A's channel/currency/locale data inside tenant B's database context. `bootstrap/providers.php` registers `CoreServiceProvider` roughly mid-list; our tenancy bootstrapping provider must be registered first, and stancl/tenancy's own service provider (which hooks route-level middleware, not provider order) must run its `InitializeTenancyByDomain` middleware ahead of every Bagisto route group.

Full narrative in [docs/architecture/system-overview.md](docs/architecture/system-overview.md) and [docs/architecture/tenancy.md](docs/architecture/tenancy.md).

## D–Q

The remaining required sections (Package/Dependency Analysis, Database Architecture, Tenant Lifecycle, Domain Architecture, Subscription/Billing Architecture, Security Model, Testing Model, Documentation Plan, Implementation Roadmap, Task Backlog, Risk Register, Decision Log, Human Decisions Required, First Implementation Task) are each written up in their own file so they stay maintainable as the project evolves:

- [docs/architecture/](docs/architecture/) — system-overview, tenancy, database-per-tenant, domain-routing, provisioning, subscriptions, billing, feature-limits, caching, storage, queues, search, security
- [docs/decisions/](docs/decisions/) — ADR-001 through ADR-006
- [docs/implementation/](docs/implementation/) — roadmap, implementation-order, testing-strategy
- [DECISION_LOG.md](DECISION_LOG.md) — C1–C10 plus new decisions surfaced by repo evidence
- [RISK_REGISTER.md](RISK_REGISTER.md)
- [UPSTREAM_SYNC.md](UPSTREAM_SYNC.md)
- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — phased roadmap + task backlog

**First Implementation Task**: see the bottom of [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Exactly one task is proposed to start with, and it is not to be started without explicit approval.
