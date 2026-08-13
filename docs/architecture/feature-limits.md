# Feature / Limit System

## Design

Generic, data-driven, plan-configurable — no hardcoded numbers or `if ($plan === 'pro')` checks anywhere in application code. Backed by the `plan_features` table (see [database-per-tenant.md](database-per-tenant.md)): `plan_id`, `feature_code`, `type` (`boolean|numeric|unlimited`), `value`.

Conceptual API surface (finalized in Phase 9, sketched here for Phase 0 architecture sign-off):

```php
Feature::PRODUCT_LIMIT      // numeric
Feature::STAFF_LIMIT        // numeric
Feature::CUSTOM_DOMAIN      // boolean
Feature::ADVANCED_REPORTS   // boolean
```

`feature_code` values are string constants (an enum-like class, not a DB-driven list of *codes* — the codes themselves are part of the application's contract with the domain layer; only their *values per plan* are data-driven). A `FeatureLimitService` (or similar, name TBD at Phase 9) resolves `tenant → active plan → plan_features row for the requested code`, returning either a boolean, a numeric cap, or "unlimited" (`type = unlimited`, `value` ignored).

## Enforcement points

- **Numeric limits** (e.g. `product_limit`): checked at the point of creation (e.g. `ProductRepository::create()` call site in our own thin wrapper/listener, not by editing Bagisto's `ProductRepository` itself — likely implemented as an event listener on Bagisto's own product-creation event, rejecting the request before it reaches the repository, or a form-request-level check in a package that extends the relevant admin controller's validation). Needs a Phase 9 investigation into which Bagisto events exist for this (`AGENTS.md`'s "Event-Driven Extensibility" pattern is the intended mechanism — "extend behavior via listeners rather than modifying core packages").
- **Boolean features** (e.g. `custom_domain`): gate access to the relevant platform-side feature (e.g. domain-management UI) rather than anything inside Bagisto core.
- **Unlimited**: `type = unlimited` short-circuits any numeric check.

## Subscription overrides

The brief allows for "subscription overrides if needed" beyond plan defaults — modeled as an optional `subscription_feature_overrides` table (tenant-specific, references `subscriptions.id`) checked before falling back to the plan default. Not built in the MVP (Phase 9) unless a concrete need arises (e.g. negotiated custom limits for a specific tenant) — the schema is mentioned here so Phase 9's `plan_features` design doesn't accidentally make overrides structurally impossible to add later.

## Usage tracking relationship

`usage_records` (central DB, see [database-per-tenant.md](database-per-tenant.md)) stores periodic snapshots (e.g. current product count) used for **display** (tenant-visible usage page) and **soft warnings** (approaching limit). *Hard* enforcement (blocking a create action) should check the live count at the moment of the action where practical (e.g. `COUNT(*)` against the tenant's own products table, which is a same-connection query, not cross-DB) rather than trusting a periodic snapshot that could be stale — `usage_records` is a reporting/analytics convenience, not the enforcement source of truth.
