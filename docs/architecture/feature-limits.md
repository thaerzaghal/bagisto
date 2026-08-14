# Feature / Limit System

**Status: PARTIALLY IMPLEMENTED (TASK-ARCH-008, 2026-08-14).** The plan/feature-definition/tenant-assignment/entitlement-resolution domain is built and tested. Usage tracking, enforcement, and any subscription-aware override behavior are explicitly **not** built yet - see "What TASK-ARCH-008 deliberately does not include" below.

## Why this was pulled ahead of Phase 7

Originally Phase 9 (this document) followed Phase 7 (Tenant Admin Integration) in the roadmap. Phase 7's acceptance criteria ("tenant admin sees their plan/usage/domain") needs real plan data to exist first - building it earlier would mean placeholder UI, which the product owner explicitly rejected. Rather than renumber phases, this task pulled the minimal plan/feature/entitlement *domain* (not the whole of Phase 9) ahead, as TASK-ARCH-008. See IMPLEMENTATION_PLAN.md's "RESEQUENCING NOTE" on Phase 7 for the full record.

## Naming: `plans`, not `subscription_plans`

Phase 0's original sketch (this document and [database-per-tenant.md](database-per-tenant.md)) named the table `subscription_plans`. Renamed to `plans` during TASK-ARCH-008: this task explicitly excludes subscriptions ("This task is NOT subscriptions"), and naming the table after a domain concept (subscriptions) that doesn't exist yet read as confusing. See DECISION_LOG.md for the full reasoning. `plan_features` keeps its original name unchanged.

## Domain boundary

`plans`/`plan_features` are **central-database-only**. Both `Platform\Plans\Models\Plan` and `Platform\Plans\Models\PlanFeature` use `Stancl\Tenancy\Database\Concerns\CentralConnection` (`getConnectionName()` reads `config('tenancy.database.central_connection')` fresh on every call - the same mechanism `Stancl\Tenancy\Database\Models\Tenant`, the base class our own `Tenant` model extends, already relies on). Every query through these models hits the central connection regardless of what tenant context is (or isn't) currently active - proven live in `tests/Feature/Platform/TenantEntitlementsTest.php` ("tenant databases do not contain plan tables", "entitlements can be queried during an active tenant context...").

**Migration placement is load-bearing, not incidental**: `plans`/`plan_features`/the `tenants.plan_id` column migrations live in `database/migrations/` (root), never registered via a package's own `loadMigrationsFrom()`. `Platform\Tenancy\Services\TenantProvisioner::ensureMigrated()` runs `tenants:migrate --path=app('migrator')->paths()` - `Migrator::paths()` returns every path registered via `loadMigrationsFrom()` from *any* service provider. If Plan's migrations had been registered that way (the normal, self-contained-package convention every Webkul package uses), they would have been swept into every tenant database, violating this exact domain boundary. See RISK_REGISTER.md R30 for how this was nearly gotten wrong live during this task, and how it was caught and fixed.

## Design

Generic, data-driven, plan-configurable - no hardcoded numbers or `if ($plan === 'pro')` checks anywhere in application code. Backed by `plan_features`: `plan_id`, `feature_code`, `type` (`boolean|numeric|unlimited`), `value` (nullable integer - `null` + `type=unlimited` means no cap; `0`/`1` for `boolean`; the cap itself for `numeric`).

```php
Platform\Plans\Enums\FeatureCode::ProductsLimit    // 'products.limit', numeric
Platform\Plans\Enums\FeatureCode::StaffLimit       // 'staff.limit', numeric
Platform\Plans\Enums\FeatureCode::CustomDomain     // 'domains.custom', boolean
Platform\Plans\Enums\FeatureCode::AdvancedReports  // 'reports.advanced', boolean
```

These four are illustrative example identifiers (matching this task's own instructions and this document's original Phase 0 sketch), not a finalized business catalog. `feature_code` is a plain string column on `plan_features` - deliberately **not** cast to the `FeatureCode` enum, so centrally-configured entitlements can use codes not yet enumerated in code; the enum exists for call-site type safety (`Platform\Plans\Services\TenantEntitlements::can()`/`limit()` accept `FeatureCode|string` and normalize an enum instance to `->value`).

`Platform\Plans\Services\TenantEntitlements` resolves `tenant → current plan (tenants.plan_id) → plan_features row for the requested code`:

- `TenantEntitlements::current()` - resolves for the currently initialized tenant (throws if no tenancy is active).
- `TenantEntitlements::for($tenant)` - resolves for an explicit tenant, active tenancy or not.
- `->currentPlan(): Plan`
- `->can(FeatureCode|string $feature): bool` - for `boolean`-type features; throws `FeatureTypeMismatchException` if the feature isn't boolean.
- `->limit(FeatureCode|string $feature): ?int` - for `numeric`/`unlimited`-type features; `null` means unlimited; throws `FeatureTypeMismatchException` if the feature is boolean.

Both `can()`/`limit()` throw `FeatureNotConfiguredException` if the current plan has no row at all for the requested code (fail loud, not "silently denied"), and `currentPlan()` throws `NoPlanAssignedException` if `tenants.plan_id` is null.

## Tenant → plan relationship

`tenants.plan_id` (nullable FK → `plans.id`, `restrictOnDelete()`) - directly on the `tenants` table, not a separate assignment table. See DECISION_LOG.md for the full reasoning and how this evolves once Phase 10 builds real `subscriptions`.

## Default plan assignment

`Platform\Tenancy\Services\TenantProvisioner::ensureDefaultPlanAssigned()` is provisioning step 5 (after seeding, before marking a tenant `Ready`): looks up the plan whose `code` matches `config('platform.plans.default_code')` (default `'free'`, never a raw database id) and assigns it if `plan_id` is still null. **Fails provisioning loudly** if that plan doesn't exist - `php artisan platform:plans:seed` (idempotent, safe to rerun) must be run once per environment before any tenant is provisioned, the same deployment-step category as `platform:mark-installed`.

## Seeding

`Platform\Plans\Services\PlanSeeder::seed()` - central-only, idempotent (`updateOrCreate` throughout), seeds FREE/BASIC/PRO with the four example features above. Exposed both via `php artisan platform:plans:seed` and directly as a service (also called from `Tests\Feature\Platform\PlatformIntegrationTestCase::setUp()`, so every Platform integration test has a default plan available before it provisions a tenant - the test-suite equivalent of the required one-time production deployment step).

## What TASK-ARCH-008 deliberately does not include

- **Enforcement**: numeric limits (e.g. `products.limit`) are not checked at product-creation time. No Plan logic is coupled into Bagisto's `ProductRepository`/controllers.
- **Usage tracking**: no `usage_records`/metering infrastructure. A future enforcement task can evaluate a live count (e.g. `COUNT(*)` against the tenant's own database) directly rather than trusting a periodic snapshot - see the original Phase 0 sketch's reasoning below, unchanged.
- **Tenant Admin UI**: no `menu.php`/`acl.php` changes, no Subscription/Billing/Usage pages. That's Phase 7, which this task exists to unblock.
- **Subscriptions/billing**: no lifecycle, trial, renewal, payment status, or provider integration. `plans`/`plan_features` are designed so a future `subscriptions` table (Phase 10) references `plans.id` with no structural rework needed.
- **Subscription overrides**: the brief allows for per-tenant overrides beyond plan defaults - not built, but not structurally precluded either (an optional `subscription_feature_overrides` table, checked before falling back to the plan default, remains addable later without touching `plan_features`' shape).

## Enforcement points (future, unchanged from Phase 0 sketch)

- **Numeric limits**: checked at the point of creation via an event listener on Bagisto's own product-creation event (or a form-request-level check), not by editing `packages/Webkul/Product` directly.
- **Boolean features**: gate access to the relevant platform-side feature (e.g. domain-management UI) rather than anything inside Bagisto core.
- **Unlimited**: `limit()` returning `null` short-circuits any numeric check unconditionally.

## Usage tracking relationship (future, unchanged from Phase 0 sketch)

`usage_records` (central DB) would store periodic snapshots (e.g. current product count) for **display** and **soft warnings**. *Hard* enforcement should check the live count at the moment of the action (a same-connection tenant-DB query) rather than trusting a possibly-stale snapshot - `usage_records` remains a reporting convenience, not the enforcement source of truth, if/when it's built.
