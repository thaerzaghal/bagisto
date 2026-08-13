# ADR-006: Upstream Strategy — Namespace Isolation + Periodic Sync Branch

## Status
Accepted

## Context
This repo is a fork (`origin` = `thaerzaghal/bagisto`, `upstream` = `bagisto/bagisto`, both already configured as git remotes). The brief requires the architecture to make future Bagisto upgrades manageable, with explicit documentation of what's never modified, what we own, and how conflicts are handled.

## Decision
See [UPSTREAM_SYNC.md](../../UPSTREAM_SYNC.md) for the full strategy. Summary:
- `packages/Platform/*` (our namespace) vs. `packages/Webkul/*` (upstream's namespace) — zero filesystem overlap, by construction (ADR-001).
- `bootstrap/providers.php`, `config/concord.php`, `composer.json` — append-only from our side.
- Dedicated `upstream-sync/YYYY-MM-DD` branches run the full existing Bagisto CI suite (Pest, Pint, Playwright, translation checks — all already defined in this repo's `.github/workflows/`) plus our own tenancy test suite before merging into `develop`.
- Branch model: `main` (deployable), `develop` (integration), `feature/*`, `fix/*`, `upstream-sync/*`.

## Consequences
- Upstream merges should be low-friction and low-risk by default, since the only files where a conflict is even structurally possible are three specific, append-only files.
- We get Bagisto's own CI suite as free regression coverage for "did upstream break something we build on," in addition to tenancy-specific tests we own.
- Requires discipline to actually keep custom code out of `packages/Webkul/*` over time — worth a lightweight CI check (e.g. a CI job that fails if any diff touches `packages/Webkul/*` without an accompanying ADR reference in the PR description) once the project has more than one contributor; not needed for Phase 0.

## Alternatives considered
- **Merging upstream directly into `develop`** — rejected in favor of a dedicated sync branch, specifically so a bad upstream sync can't destabilize in-flight feature work; the sync branch is validated (full CI + manual tenant-provisioning smoke test) before it ever touches `develop`.
- **Rebasing our fork onto upstream instead of merging** — rejected: rebasing rewrites commit history, which is disruptive for a team fork with an already-established commit history (`e6307c5` and prior commits on `origin`), and provides no isolation benefit over the namespace-separation approach already adopted.
