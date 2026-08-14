# Database-Per-Tenant Design

## Central Database

Built on `stancl/tenancy`'s base `tenants`/`domains` tables, extended with platform-specific tables. Column lists below are the *proposed* final schema (not upstream-fixed) — confirm during Phase 3 implementation.

**`tenants`** (extends stancl/tenancy's base table)
`id` (tenant identifier, per stancl convention), `uuid`, `slug` (subdomain segment, unique), `name`, `status` (`pending|provisioning|ready|failed|suspended|deleting|deleted` — see [provisioning.md](provisioning.md)), `plan_id` (FK → `plans`, **implemented TASK-ARCH-008** — see below and DECISION_LOG.md for why it lives directly here rather than a separate assignment table), `db_name` (physical tenant database name, may be derivable from `id`/`slug` but stored explicitly for auditability), `data` (JSON, tenant-specific misc config), timestamps.

**IMPORTANT, found live during TASK-ARCH-008**: `status`/`last_error`/`plan_id` must be explicitly declared in `Platform\Tenancy\Models\Tenant::getCustomColumns()` (overriding `Stancl\VirtualColumn\VirtualColumn`'s default of `['id']` only) or they silently get written into the `data` JSON blob instead of their real columns - see RISK_REGISTER.md R31 for the full finding (this had been true of `status`/`last_error` since TASK-ARCH-002, invisible until TASK-ARCH-008 ran the first raw SQL query against `tenants` in this project's history).

**`domains`** (stancl/tenancy base table, used as-is)
`domain`, `tenant_id`, plus our additions: `is_primary` (bool), `is_custom` (bool — false for `{slug}.platform.<domain>`, true for a bring-your-own domain), `verified_at` (nullable — custom domains require DNS verification before activation, see [domain-routing.md](domain-routing.md)).

**`plans`** (renamed from the originally-sketched `subscription_plans` — **implemented TASK-ARCH-008**, see DECISION_LOG.md for the rename reasoning)
`id`, `code` (e.g. `free`, `basic`, `pro`), `name`, `description`, `is_active`, `sort_order`. `price_monthly`/`price_yearly`/`trial_days` deliberately **not** added yet — TASK-ARCH-008 explicitly excludes billing/subscription concerns; these are a simple additive migration whenever Phase 10/11 actually need them.

**`plan_features`** (**implemented TASK-ARCH-008**)
`plan_id` (FK), `feature_code` (string, e.g. `products.limit`, `staff.limit`, `domains.custom`), `type` (`boolean|numeric|unlimited`), `value` (nullable integer — null + type=`unlimited` means no cap; `0`/`1` for `boolean`). See [feature-limits.md](feature-limits.md) for why this is a generic key-value design rather than hardcoded columns.

**`subscriptions`**
`id`, `tenant_id` (FK), `plan_id` (FK), `status` (`trialing|active|past_due|grace|canceled|expired`), `trial_ends_at`, `current_period_start`, `current_period_end`, `cancels_at`, `canceled_at`, `grace_period_ends_at`. See [subscriptions.md](subscriptions.md).

**Billing** — whether this needs its own table beyond what `laravel/cashier`'s `Billable` trait adds to whichever model it's attached to is an open question pending the provider decision (HUMAN DECISION REQUIRED, DECISION_LOG item 2). If Cashier is used, its own migrations add `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` etc. directly onto the billable model's table — likely `tenants` itself, or a dedicated `billing_accounts` 1:1 with `tenants` if we want to keep Cashier's columns out of the core tenant table. Recommend the latter (a dedicated `billing_accounts` table) to keep `tenants` provider-agnostic per the billing abstraction requirement (ADR-004) — deferred to Phase 11.

**`usage_records`**
`tenant_id` (FK), `metric_code` (e.g. `products_count`, `orders_this_month`), `value`, `period_start`, `period_end`. Populated by scheduled jobs that query each tenant's own database for the actual counts (e.g. `SELECT COUNT(*) FROM products` against the tenant connection) and roll the result up centrally — this is the one place where a central table intentionally stores a derived summary of tenant-DB data, for cheap plan-limit enforcement without cross-DB queries on every request.

**`platform_users`**
Central-DB-only admin identities for the `platform` guard (see [tenancy.md](tenancy.md) C12) — `id`, `name`, `email`, `password`, `role` (simple enum or FK to a small `platform_roles` table if granular platform ACL is needed later; start with an enum, add a table only if a second platform role beyond "full access" is actually required).

**`platform_settings`**
Simple key-value table for platform-wide configuration (e.g. default trial length, support email) — mirrors the shape of Bagisto's own `core_config` table conceptually, kept separate since it's a different domain (platform ops vs. store config).

**`tenant_provisioning_events`**
Append-only audit log of state transitions during provisioning (`tenant_id`, `from_status`, `to_status`, `error` nullable, `created_at`) — supports the idempotent-retry requirement in the brief by giving the provisioning pipeline a durable record of exactly which step last succeeded.

## Tenant Database

**Deliberately unmodified standard Bagisto schema** — every `packages/Webkul/*/src/Database/Migrations` migration, run as-is via `stancl/tenancy`'s per-tenant migration command. No `tenant_id` column is added to any Bagisto table, because physical database isolation already provides that scoping — adding a redundant `tenant_id` column everywhere would be exactly the single-database-with-tenant_id architecture the brief explicitly says NOT to build, and would require touching ~40 packages' migrations, violating the "no core modification" principle for zero benefit.

The only *additive* schema inside a tenant database is our own SaaS-tenant-side package(s), if any turn out to be needed beyond what's queried centrally — e.g. a lightweight cached snapshot of the tenant's current plan/limits (`platform_tenant_snapshot` — one row, refreshed on subscription change) to avoid a central-DB round-trip on every admin page load. Whether this cache table is actually necessary is a Phase 9 implementation decision, not decided here — start without it (query central DB directly, it's one extra connection, not a cross-tenant query) and add the snapshot only if profiling shows it's needed.

## Why no `tenant_id` in Bagisto tables — explicit rationale

The brief asks us to explain this explicitly (Section 8). Database-per-tenant's entire value proposition is that isolation is enforced by the database engine and connection routing, not by application-level `WHERE tenant_id = ?` filtering that a bug could omit. Every Bagisto repository/query already omits `tenant_id` filtering today (there's no such column, and adding one everywhere would be a large, invasive, upgrade-hostile change). Keeping tenant databases schema-identical to stock Bagisto is also what makes `git merge upstream/master` safe — a new Webkul migration merges cleanly and just needs to run once per tenant, with no adaptation.
