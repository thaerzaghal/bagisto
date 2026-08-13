# ADR-001: Repository Strategy — Fork with Isolated Custom Packages

## Status
Accepted (Phase 0)

## Context
This is a fork of `bagisto/bagisto` (`origin` → `thaerzaghal/bagisto`, `upstream` → `bagisto/bagisto`, already configured). The brief requires that future Bagisto releases remain mergeable. Repository research confirmed ~40 packages under `packages/Webkul/*`, each following a strict, consistent convention (Contract/Model/Proxy, `Providers/{Name}ServiceProvider.php` + `Providers/ModuleServiceProvider.php`, registered in `bootstrap/providers.php` + `config/concord.php`).

## Decision
1. All custom SaaS code lives under a new `packages/Platform/*` directory with its own Composer PSR-4 namespace (`Platform\`), structurally identical in convention to `packages/Webkul/*` but filesystem- and namespace-isolated from it.
2. We only ever *append* to `bootstrap/providers.php`, `config/concord.php`, and `composer.json`'s `require`/autoload sections — never reorder or remove existing entries.
3. No `packages/Webkul/*` file is modified unless a documented ADR justifies a specific, minimal exception (none identified as of Phase 0).

## Consequences
- `git merge upstream/master` should be conflict-free in the overwhelming majority of cases, since our code occupies a namespace upstream will never touch.
- We inherit the same package-anatomy conventions Bagisto itself uses, which lowers onboarding cost for anyone already familiar with Bagisto's package structure, and keeps our code eligible for the same tooling (Pint, Pest, `package:discover`).
- The small number of append-only shared files (`bootstrap/providers.php`, `config/concord.php`, `composer.json`) are the only places a merge conflict is structurally possible; mitigated by keeping our additions clustered with a comment marker (see [UPSTREAM_SYNC.md](../../UPSTREAM_SYNC.md)).

## Alternatives considered
- **Modifying Webkul packages directly** — rejected outright per the brief's explicit "no unnecessary core modification" requirement, and unnecessary given every extension point identified in Phase 0 (config-merge for menus/ACL, events/listeners, Concord bindings) is sufficient.
- **A completely separate Laravel application calling Bagisto via API** — rejected: would require building an API surface Bagisto doesn't have (no `sanctum`/`api` guard is even configured today), duplicates the auth/session/routing work `stancl/tenancy` already solves for a monolithic deployment, and loses the benefit of directly reusing Bagisto's repository/model layer in-process.
