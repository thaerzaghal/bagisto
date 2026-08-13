# Upstream Sync Strategy

## Remotes

Already configured in this fork:

```
origin    https://github.com/thaerzaghal/bagisto.git   (our fork — read/write)
upstream  https://github.com/bagisto/bagisto.git        (official Bagisto — read-only, never push)
```

## What we will never modify

- Anything under `packages/Webkul/*` — this is upstream's namespace. If Phase 0 discovery had found a case requiring a core edit, it would be listed in [DECISION_LOG.md](DECISION_LOG.md) with an explicit ADR; as of this writing, **no core edit has been identified as necessary**.
- `bootstrap/providers.php` and `config/concord.php` — we only ever *append* our own provider entries to these two files; we never remove or reorder existing Webkul entries (per `AGENTS.md`'s own safety rail: "Never modify `bootstrap/providers.php` or `config/concord.php` without understanding the full provider chain").
- `composer.json`'s existing `require` block — we only add new packages (`stancl/tenancy`, etc.), never change existing version constraints, per `AGENTS.md`: "Do not add/remove composer dependencies without approval" (flagging this: adding `stancl/tenancy` and a billing package *does* require approval — see Phase 2/11 tasks).

## What we own outright

Everything under:
- `packages/Platform/*` — all custom SaaS packages (see ADR-001, DECISION_LOG C11).
- `config/tenancy.php`, `config/platform.php` (new config files, additive).
- `database/migrations/central/*` (or an equivalent path scoped by `stancl/tenancy`'s config) for platform-level schema.
- `docs/`, and this set of root-level architecture files.

## How overrides work when Bagisto extension points fall short

Every integration identified in Phase 0 uses a documented Bagisto extension mechanism:

| Concern | Mechanism | Where |
|---|---|---|
| Admin nav item for Subscription/Billing | `mergeConfigFrom(..., 'menu.admin')` appends to `packages/Webkul/Admin/src/Config/menu.php`'s numerically-indexed array | `packages/Platform/TenantAdmin` |
| ACL entry for the new nav item | Same merge pattern against `Config/acl.php` | `packages/Platform/TenantAdmin` |
| Elasticsearch tenant-scoped index prefix | `app()->forgetInstance(Client::class)` + config mutation in a tenancy-initialized listener — no `CoreServiceProvider` edit | `packages/Platform/Search` (or folded into Tenancy package) |
| Tenant provisioning | Reuses `BagistoDatabaseSeeder` and package migrations (both already public, package-scoped classes), invoked against a dynamically bound connection — does not touch `packages/Webkul/Installer` | `packages/Platform/Provisioning` |
| Cache/filesystem/queue isolation | `stancl/tenancy` bootstrappers, config-only | `config/tenancy.php` |

If a future Bagisto feature genuinely cannot be extended this way, follow the brief's required process before touching a vendor file: (1) identify the exact reason, (2) explain why extension architecture cannot solve it, (3) propose the smallest possible modification, (4) document it as an ADR here and in DECISION_LOG.md, (5) assess upstream-merge impact of that specific diff.

## Branch strategy

```
main         — always deployable; protected; only merges from develop or hotfix/*
develop      — integration branch for Platform work
feature/*    — one branch per task-backlog item (see IMPLEMENTATION_PLAN.md)
fix/*        — bug fixes against develop
upstream-sync/YYYY-MM-DD — periodic branch that merges upstream/master into develop
```

Rationale for a dedicated `upstream-sync/*` branch type (not just merging upstream straight into `develop`): because all our code is isolated in `packages/Platform/*` and additive config, `git merge upstream/master` should almost always be conflict-free. Running it on its own branch first (rather than directly on `develop`) gives us a safe place to run the full Pest + Playwright suite and manually smoke-test tenant provisioning against the new upstream code before it lands on `develop`, without blocking other in-flight feature branches.

## Sync cadence

Recommend syncing `upstream/master` monthly, or immediately when Bagisto ships a security release (watch `SECURITY.md` / GitHub security advisories on `bagisto/bagisto`). Because `AGENTS.md` documents Bagisto's own CI (Pest, Pint, Playwright, translation checks), a sync branch should pass all four before merging to `develop` — this is free regression coverage for "did upstream break something we depend on," in addition to our own tenancy-specific test suite (see [docs/implementation/testing-strategy.md](docs/implementation/testing-strategy.md)).

## Conflict handling

Because `packages/Platform/*` never overlaps with `packages/Webkul/*` at the filesystem level, the only files where a merge conflict is structurally possible are the ones we deliberately append to: `bootstrap/providers.php`, `config/concord.php`, `composer.json`. Keep our additions clustered at the bottom of each list/array with a `// --- Platform packages below ---` comment marker (data, not logic, so this is safe) to make conflict resolution mechanical rather than requiring re-reasoning about intent each time.
