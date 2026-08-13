# Tenancy

## Package Selection

**`stancl/tenancy` v3.10.1** (latest stable, released 2026-08-05, updated 2026-08-08 per Packagist — actively maintained). Verified requirements: `php: ^8.0`, `illuminate/support: ^10.0|^11.0|^12.0|^13.0`. Both are satisfied by this repo (`PHP >=8.3 <8.5`, `laravel/framework ^12.0`). MIT-licensed (zero license cost, per the $0 budget constraint). Full rationale in [ADR-003](../decisions/ADR-003-tenancy-package.md).

Note: the package's `master` branch on GitHub (not the tagged release) currently requires PHP `^8.4` and Laravel `^12|^13` only — that's an in-progress next-major-version branch, not what `composer require stancl/tenancy` installs today. Pin to the `^3.10` release line, not `dev-master`.

## What `stancl/tenancy` provides out of the box

- **Tenant identification** by domain/subdomain via `InitializeTenancyByDomain` middleware, with a `central_domains` allowlist and `PreventAccessFromCentralDomains` guard middleware — directly answers the brief's requirement to protect the central domain from being treated as a tenant.
- **`DatabaseTenancyBootstrapper`** — swaps the default Laravel DB connection per request based on the resolved tenant. This is the mechanism that makes DECISION_LOG's C3/C5 work, and it depends entirely on the "no hardcoded connections" finding in [system-overview.md](system-overview.md) being true — which it is.
- **`CacheTenancyBootstrapper`**, **`FilesystemTenancyBootstrapper`**, **`QueueTenancyBootstrapper`**, **`RedisTenancyBootstrapper`** — opt-in bootstrappers for the other isolation concerns. See [caching.md](caching.md), [storage.md](storage.md), [queues.md](queues.md).
- **Tenant-aware migration/seed commands** (`tenants:migrate`, `tenants:seed` or equivalent artisan commands) that run against every tenant's connection in turn — we will wrap, not replace, Bagisto's own package migrations and `BagistoDatabaseSeeder` with these.
- **Events** for tenant lifecycle (initializing/initialized/ending tenancy) — this is where our Elasticsearch-singleton-reset listener (R4) and any other per-tenant-switch bookkeeping hooks in.

## Critical ordering requirement (R9)

Bagisto's own `Channel` resolution (`Webkul\Core\Core::getCurrentChannel()`, HTTP-Host-header lookup) runs independently of tenant resolution and will silently succeed against **whatever connection happens to be active**, right or wrong. `stancl/tenancy`'s `InitializeTenancyByDomain` middleware — and the DB connection swap it triggers — **must execute before** any Bagisto route-group middleware (`admin`, `web`, `locale`, `theme`, `currency`) runs, and therefore before `Webkul\Core\CoreServiceProvider`-dependent code resolves a channel. Concretely: register the tenancy initialization middleware globally (or as the outermost group) in `bootstrap/app.php`, not nested inside Bagisto's own route groups. Phase 5/6 must include an automated test that provisions two tenants and asserts tenant A's resolved channel/locale/currency never reflects tenant B's data, specifically to catch a regression in this ordering.

## Guard model for platform vs. tenant admins (C12)

Bagisto's `Role`/ACL system (`packages/Webkul/User/src/Models/Role.php`) has no tenant/store scoping column — it assumes one Bagisto instance's admins. Rather than extending it, we add a third auth guard, `platform`, backed by a `platform_users` table in the **central** database, entirely separate from the `admin` guard/model that lives inside each tenant's own database. This gives us tenant-admin/platform-admin separation for free via physical database isolation, with no schema change to any Webkul package.

## What "tenant" means concretely here

`stancl/tenancy`'s base `Tenant` model (central DB) is extended with our own columns: `status` (state machine, see [provisioning.md](provisioning.md)), `plan_id` (see [subscriptions.md](subscriptions.md)), `slug` (subdomain segment). We do not create a separate "Merchant"/"Account" concept distinct from Tenant unless billing (Cashier's `Billable` trait requirement) turns out to need one — see [billing.md](billing.md) for that open question.

## Session, cookies, and the `admin`/`customer` guards under tenancy

Sessions use the `database` driver (`config/session.php` default, confirmed in `.env.example`) — with database-per-tenant, the `sessions` table itself must exist inside **each tenant's** database (not centrally), since Bagisto's `admin`/`customer` guards are meant to be tenant-scoped identities. `SESSION_DOMAIN` should be left unset/`null` (its current `.env.example` default) rather than set to a wildcard — an unset `SESSION_DOMAIN` scopes the cookie to the exact host, which naturally isolates tenant subdomains from each other without any extra code. Do not widen this to a wildcard domain unless a deliberate "single sign-on across tenant subdomains" feature is scoped later (not currently in the MVP).
