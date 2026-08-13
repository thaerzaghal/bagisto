# Implementation Order — Dependency Rationale

Why the phases in [IMPLEMENTATION_PLAN.md](../../IMPLEMENTATION_PLAN.md) are sequenced the way they are, for anyone tempted to reorder them.

## Hard dependencies

- **Phase 2 (tenancy package) must precede Phase 3 (central schema)**: our central `tenants` table extends `stancl/tenancy`'s base table; we need the package installed and its base migrations reviewed before designing our additions.
- **Phase 4 (provisioning) must precede Phase 5 (Bagisto integration verification)**: Phase 5's test plan is "provision two tenants, verify isolation" — there's nothing to verify isolation *of* until provisioning exists.
- **Phase 6 (domain routing) must precede Phase 7 (tenant admin integration)**: tenant admin pages are reached via a tenant's resolved domain; building the admin UI before routing works means testing it in a broken/fake context.
- **Phase 9 (plans/feature limits) must precede Phase 10 (subscriptions)**: a subscription references a plan; the plan/feature-limit data model needs to exist first, even though both are conceptually designed together in this document set.
- **Phase 9/10 must precede Phase 11 (billing provider)**: the billing abstraction (ADR-004) wraps subscription lifecycle events; there's no subscription lifecycle to wrap until Phase 10 exists. Note Phase 11 is also blocked on a separate, non-technical dependency — the HUMAN DECISION REQUIRED provider choice — and could in principle start later than 11 if that decision takes a long time; it's sequenced at 11 assuming the decision lands promptly.
- **Phases 12–15 (storage/cache/queue/search isolation) can run in parallel with each other** once Phase 6 (domain routing / tenant resolution) is done — each is an independent bootstrapper concern with its own risk items (R5/R1-3/R6-7/R4 respectively) and none depends on the others. They're listed sequentially in the roadmap for readability, not because of a hard ordering constraint between them.
- **Phase 16 (security hardening) intentionally comes after 12–15**, not before: most of the threat model in [security.md](../architecture/security.md) is specifically about cache/storage/queue/search leakage, which can't be meaningfully hardened or tested before those isolation mechanisms exist.
- **Phase 17 (automated tests) is listed last but is not "write tests at the end"** — every phase above already specifies its own acceptance-criteria tests; Phase 17 is about assembling the full cross-cutting suite (the MVP-acceptance-criteria end-to-end tests spanning multiple phases' work) and wiring it into CI, not a big-bang testing phase deferred until the end. See [testing-strategy.md](testing-strategy.md).

## Deliberately deferred, not forgotten

- **Octane adoption** — deferred past MVP (Phase 18+) per DECISION_LOG HUMAN DECISION REQUIRED #4, because R8 (facade/singleton state leaking across requests) requires dedicated audit work with no clear phase of its own yet; forcing it into the MVP critical path would add risk without MVP-required benefit.
- **Custom domains** — deferred to post-Phase 6 per ADR-005, decoupling MVP launch from the unresolved SSL-automation decision.
- **Subscription feature overrides** (tenant-specific limit exceptions) — schema-compatible but not built in Phase 9, per [feature-limits.md](../architecture/feature-limits.md), until a concrete need arises.
