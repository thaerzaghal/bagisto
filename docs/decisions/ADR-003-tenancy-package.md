# ADR-003: Tenancy Package — stancl/tenancy

## Status
Accepted (Phase 0)

## Context
The brief requires investigating whether `stancl/tenancy` is compatible with this repo's actual Laravel/PHP version before adopting it, and documenting package, version, Laravel compatibility, PHP compatibility, and reasons for selection.

## Decision
`stancl/tenancy` (Packagist: `stancl/tenancy`, GitHub: `archtechx/tenancy`), version `^3.10` (latest stable at time of writing: **v3.10.1**, released 2026-08-05, last package update 2026-08-08).

| Requirement | This repo | stancl/tenancy v3.10.1 | Compatible? |
|---|---|---|---|
| PHP | `>=8.3 <8.5` | `^8.0` | Yes |
| Laravel/illuminate | `^12.0` | `^10.0\|^11.0\|^12.0\|^13.0` | Yes |
| License | $0 budget required | MIT | Yes |
| Maintenance | — | Updated within the last week of writing this ADR; actively maintained | Yes |

Verified directly against Packagist (not from training-data memory) on 2026-08-13. Note: the package's `master`/`dev` branch on GitHub requires PHP `^8.4` and drops Laravel `<12` support — that is a future major-version branch, not what `composer require stancl/tenancy` installs by default; the tagged `^3.10` release line is what this ADR pins to.

## Feature coverage against the brief's evaluation checklist

| Requirement | Coverage |
|---|---|
| Database-per-tenant | `DatabaseTenancyBootstrapper` — core feature |
| Tenant creation / DB provisioning | Base `Tenant` model + events we hook our provisioning pipeline into |
| Tenant DB migration/seeding | Built-in per-tenant migration commands; we wrap, not replace, Bagisto's own migrations/seeders |
| Domain / subdomain identification | `InitializeTenancyByDomain` middleware, `domains` table |
| Custom domains | Same mechanism, our own verification workflow layered on top (see [domain-routing.md](../architecture/domain-routing.md)) |
| Central domains | `central_domains` config + `PreventAccessFromCentralDomains` middleware |
| DB connection switching | Core feature |
| Cache isolation | `CacheTenancyBootstrapper` (opt-in) |
| Filesystem isolation | `FilesystemTenancyBootstrapper` (opt-in) |
| Queue/job isolation | `QueueTenancyBootstrapper` (opt-in) |
| Session isolation | Achieved via DB isolation (sessions table per tenant) + existing unset `SESSION_DOMAIN` default, not a bootstrapper-specific feature |
| Mail isolation | Not a `stancl/tenancy` feature directly; Bagisto's existing `bagisto-dynamic-smtp` per-channel mail mechanism (already present, see [system-overview.md](../architecture/system-overview.md)) is the integration point, extended per-tenant |
| Events | Tenancy lifecycle events (initializing/initialized/ending) — used for our Elasticsearch-singleton-reset listener and other per-switch bookkeeping |
| CLI commands | Per-tenant artisan command execution (`tenants:run`-style) |
| Testing | Testbench-based test helpers ship with the package |
| Maintenance mode | Not directly evaluated in Phase 0 — Bagisto already has its own `PreventRequestsDuringMaintenance` middleware per-channel (`is_maintenance_on` on `Channel`); interaction between the two needs a Phase 6 design note, not assumed identical |
| Tenant deletion | Base feature (drop tenant database) |
| Tenant suspension | Not a built-in concept — our own `tenants.status` state machine and domain-routing-layer enforcement (see [provisioning.md](../architecture/provisioning.md), [security.md](../architecture/security.md)) |

## Consequences
Adopting a well-maintained third-party package for the hardest, most security-critical part of this system (tenant isolation) is lower-risk than building connection-switching/domain-resolution/cache-prefixing from scratch, provided the package stays maintained — mitigated by pinning to a specific minor version line and re-verifying compatibility at each Bagisto/Laravel upgrade (see [UPSTREAM_SYNC.md](../../UPSTREAM_SYNC.md)).

## Alternatives considered
No other actively-maintained, MIT-licensed, database-per-tenant Laravel tenancy package with comparable feature breadth was identified as needing evaluation — `stancl/tenancy` is the de facto standard for this exact use case in the Laravel ecosystem, and its compatibility with this repo's exact stack was independently confirmed rather than assumed.
