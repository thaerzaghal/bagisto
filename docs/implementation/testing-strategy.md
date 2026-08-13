# Testing Strategy

Tests are designed before implementation, per the brief. This repo already has Pest 3 configured with package-specific `TestCase` classes (`tests/Pest.php`) and defined test suites in `phpunit.xml` (Admin Feature, Core Unit, Customer Unit, DataGrid Unit, Installer Feature, payment-gateway suites, Shop Feature, Stripe Unit/Feature) plus Playwright E2E per package (Admin, Shop) — our tenancy test suite follows the same Pest/Playwright conventions rather than introducing a second testing framework.

## Unit tests

- Tenant creation (central-DB model, no provisioning side-effects) and state-machine transition validation (illegal transitions rejected) — [provisioning.md](../architecture/provisioning.md).
- Domain resolution — central-domain vs. tenant-subdomain vs. unmatched-domain, all three outcomes.
- Plan/feature-limit resolution — boolean, numeric, unlimited cases; plan-to-plan differences.
- Subscription state transitions — trialing → active → past_due → grace → expired/canceled, independent of provisioning state.
- Feature-limit enforcement logic in isolation (given a mocked "tenant is over limit" state, does the check correctly reject).

## Integration tests

- Tenant DB creation, migration, seeding — via the actual provisioning job chain, including the mid-failure-resume scenario (kill after migration, before seeding; re-run; assert no duplicate data) required by the brief's idempotency requirement.
- Bagisto repositories against a tenant-swapped connection — the concrete Pest test described in [IMPLEMENTATION_PLAN.md](../../IMPLEMENTATION_PLAN.md) Phase 5: provision two tenants, create a product in each via `ProductRepository`, assert each tenant's `all()` returns only its own data.
- Cache isolation — direct cache-store key inspection (not just behavioral testing), covering the three specific flagged call sites (response-cache/FPC hasher, `CatalogApiCache`, PhonePe token cache) per [caching.md](../architecture/caching.md).
- Filesystem isolation — assert stored paths are tenant-prefixed for at least one upload flow (product image) per [storage.md](../architecture/storage.md).
- Queue tenant context — dispatch a job from tenant A's context, assert its serialized payload carries tenant identity and that it executes against tenant A's connection when processed, per [queues.md](../architecture/queues.md).
- Search isolation — two tenants, same channel/locale codes, opted into Elasticsearch; assert distinct index names and no cross-tenant search results, per [search.md](../architecture/search.md).

## End-to-end tests (Playwright, following the existing Admin/Shop e2e-pw convention)

- **The core proof required by the brief**: Tenant A creates product A via its admin UI; Tenant B creates product B via its admin UI; assert Tenant A's storefront/admin shows only product A, Tenant B's shows only product B.
- Domain routing: visiting each tenant's subdomain resolves the correct store; visiting an unregistered subdomain 404s; visiting the central/platform domain never resolves any tenant.
- Admin access boundaries: platform-admin routes are unreachable from any tenant subdomain and vice versa (per [security.md](../architecture/security.md)).
- Storefront access: an anonymous shopper on tenant A's domain never sees tenant B's catalog, even via crafted URLs/IDs copied from tenant B.
- Tenant suspension: a suspended tenant's admin and storefront both show the suspension state, never normal content, never a 500 error that could leak details.
- Subscription expiry: a tenant whose subscription has lapsed past grace period shows the expected degraded/blocked state per [subscriptions.md](../architecture/subscriptions.md), not silent full access.
- Plan limits: a tenant at its plan's product limit is blocked from creating another product, with a clear upgrade prompt, not a silent failure.

## What "done" looks like for Phase 17

Every MVP acceptance criterion in the original brief's Section 28 has at least one automated test (unit, integration, or e2e) that would fail if the criterion were violated — not just a manual QA checklist. CI runs the full suite (existing Bagisto Pest/Pint/Playwright/translation checks + our tenancy-specific additions) on every PR, per the existing `.github/workflows/` conventions this repo already has.
