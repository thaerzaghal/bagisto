# ADR-002: Multi-Tenancy Model — Database-Per-Tenant

## Status
Accepted (Phase 0)

## Context
The brief mandates database-per-tenant as the selected architecture, with single-database `tenant_id` isolation explicitly ruled out as the primary approach unless repository investigation reveals a critical blocker. Phase 0 investigated whether Bagisto's codebase contains any assumption that would make database-per-tenant infeasible.

## Decision
Database-per-tenant, confirmed feasible. Central database (platform data: tenants, domains, plans, subscriptions, platform admins) + one fully isolated database per tenant (unmodified Bagisto schema).

## Evidence supporting feasibility
- No model, repository, or DataGrid class in `packages/Webkul` hardcodes a database connection name (repo-wide grep confirmed only two irrelevant hits: an Elasticsearch client reference and `Installer\Helpers\DatabaseManager`'s connection-agnostic `DB::connection()->getPDO()` call).
- Every data-access path goes through the repository pattern (`prettus/l5-repository`), which resolves models via Concord's Contract/Proxy binding — connection resolution happens at Eloquent's default-connection layer, not hardcoded per-repository.
- `stancl/tenancy` v3.10.1 (verified compatible: PHP `^8.0`, Laravel `^10-13`) provides exactly the central-connection-swap mechanism this design needs, via a well-maintained, MIT-licensed package (zero cost, per the $0 license budget).

## Blockers considered and resolved (not silently dismissed — see corresponding docs)
- Bagisto's own `Channel` hostname resolution runs independently and must be sequenced *after* tenant resolution — solved by middleware ordering, not a code change to `Channel`/`Core` (see [tenancy.md](../architecture/tenancy.md), R9).
- Cache, filesystem, and queue subsystems have zero existing tenant-awareness — solved by `stancl/tenancy`'s optional bootstrappers plus (for cache) a store change to Redis (see [caching.md](../architecture/caching.md), HUMAN DECISION REQUIRED).
- ACL/Role system has no tenant-scoping — solved architecturally (separate `platform` guard, physical DB separation) rather than by extending Bagisto's `Role` model (see [tenancy.md](../architecture/tenancy.md), C12).

None of these rose to the level of "critical blocker requiring the fallback single-database model" — every one has a concrete, additive, non-core-modifying solution.

## Consequences
- Strongest possible tenant isolation (a bug cannot leak data across a database connection that was never opened for the wrong tenant), at the cost of more provisioning complexity (N databases to create/migrate/manage) versus a single shared schema.
- No `tenant_id` column needs to be retrofitted onto ~40 packages' worth of tables — avoids a large, invasive, upgrade-hostile change that the rejected single-database alternative would have required.
- Requires genuine operational investment in per-tenant backup/restore, connection pooling at scale (hundreds→thousands of tenants), and cache/queue/filesystem isolation that a single-database design would not have needed — accepted as the correct tradeoff per the brief's explicit priority on isolation strength over operational simplicity.

## Alternatives considered
- **Single database, `tenant_id` on every table** — the brief's fallback option. Rejected: would require modifying every Webkul package's migrations and adding global scopes to every model (large surface area of core modification, directly conflicting with the brief's "no unnecessary core modification" principle), and provides weaker isolation (a missing `WHERE tenant_id` clause is an application bug that leaks data; a wrong DB connection is far less likely to happen silently given `stancl/tenancy`'s bootstrapper approach).
- **Schema-per-tenant (single database, multiple schemas)** — not evaluated in depth; MySQL (Bagisto's primary supported driver, confirmed via `config/database.php`) does not have PostgreSQL-style schemas in the same sense, making this a poor fit for the actual database engine in use.
