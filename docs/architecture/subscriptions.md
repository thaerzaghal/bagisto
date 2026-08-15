# Subscription Architecture

**Status: IMPLEMENTED, PROVIDER-AGNOSTIC (TASK-ARCH-016, 2026-08-15).** This document originally recorded Phase 0's speculative sketch (a `past_due`/`grace` status machine, `cancels_at`/`grace_period_ends_at` columns, a `platform_settings`-driven grace window, provider-webhook-driven renewal - none of which exist). It now records what was actually built: a real, minimal, strictly provider-neutral subscription lifecycle domain, with no payment provider, webhook, invoice, or payment method concept anywhere in it. See DECISION_LOG.md C18 (finalized by this task) and C37-C4x for the specific decisions.

## Package

`packages/Platform/Subscriptions` (namespace `Platform\Subscriptions`) - a new, dedicated package, not folded into `Platform\Plans` or `Platform\Tenancy`. Dependency direction:

```
Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
```

Neither `Platform\Plans` nor `Platform\Tenancy` depends on `Platform\Subscriptions` as a general rule. Two narrow, deliberate, documented exceptions exist (both justified the same way DECISION_LOG.md C19 already justified `Platform\Tenancy`'s dependency on `Platform\Plans`):

- `Platform\Tenancy\Services\TenantProvisioner::ensureInitialSubscriptionStarted()` calls `SubscriptionLifecycle::start()` during provisioning - a provisioning-lifecycle concern, not business logic `Platform\Subscriptions` needs to know about.
- `Platform\Plans\Http\Controllers\Admin\MyPlanController` reads the `Subscription` model directly (read-only) to display it on the existing "My Plan" page - the same "controller reads another package's `CentralConnection` model for display" pattern already used elsewhere in this codebase (e.g. `Platform\Admin\Http\Controllers\TenantController` already reads `Plan` directly). This does not create a package cycle: no `Platform\Subscriptions` class imports anything from `Platform\Plans\Http`.

## Schema

`subscriptions` (central DB only - see `database/migrations/2026_08_15_170000_create_subscriptions_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `tenant_id` | string, FK → `tenants.id`, `cascadeOnDelete()` | The first table in this codebase with a direct FK into `tenants` |
| `plan_id` | FK → `plans.id`, `restrictOnDelete()` | |
| `status` | string, cast to `SubscriptionStatus` | |
| `starts_at` | timestamp | |
| `trial_ends_at` | timestamp, nullable | |
| `current_period_start` / `current_period_end` | timestamp, nullable | Administrative metadata only - see "Periods" below |
| `cancel_at_period_end` | boolean, default false | Intent flag only |
| `cancelled_at` / `ended_at` | timestamp, nullable | |

**`unique('tenant_id')` - a database-level "one current subscription per tenant" invariant** (task section 3): a second row can never exist for a tenant that already has one, full stop, regardless of application code. This is a deliberate foundation-task simplification - no subscription history/versioning table exists yet. A canceled/expired subscription is *reused in place* (reset back to Trialing/Active) rather than superseded by a new row when a tenant restarts - see `SubscriptionLifecycle::start()`'s own docblock for the exact mechanism.

**No provider-specific columns anywhere** - no `stripe_id`/`pm_type`/`pm_last_four`/`invoice_*`/currency/price column exists. Those belong on a future, separate `billing_accounts` table (see [billing.md](billing.md)'s own recommendation), whenever Phase 11 actually needs them.

## `SubscriptionStatus`

```php
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
```

Deliberately minimal - only the four statuses this foundation task can control manually today. **`past_due` was deliberately NOT added**: detecting "a payment failed" fundamentally requires a payment-provider signal (webhook/reconciliation, see [billing.md](billing.md)) that doesn't exist anywhere in this codebase - adding the case now, with nothing that could ever transition into it, would be exactly the kind of speculative unused surface this codebase avoids elsewhere. Adding it later needs no migration - `subscriptions.status` is a plain string column, not enum-typed at the schema level (matching `plan_features.type`'s already-established precedent for this same distinction).

## `SubscriptionLifecycle` - the one entry point for every transition

Mirrors `Platform\Tenancy\Services\TenantLifecycle`'s shape exactly - no controller ever mutates `$subscription->status`/`plan_id`/`cancel_at_period_end` directly.

**Transition matrix** (the complete set; every other transition throws `InvalidSubscriptionTransitionException`):

```
(none) or Canceled/Expired -> Trialing    startTrial()
(none) or Canceled/Expired -> Active      start()
Trialing                   -> Active      activate()
Trialing/Active             -> Canceled    cancelImmediately()
Trialing/Active             -> Expired     expire()   (manual only - see "Expiration" below)
Active (status unchanged,
        flag set only)                    cancelAtPeriodEnd()
Trialing/Active             -> (new plan)  changePlan()
(any)                                      setPeriod()  (administrative metadata write, no status change)
```

No scheduler, cron, or automatic time-based transition exists anywhere in this class - `activate()`/`cancelAtPeriodEnd()`/`cancelImmediately()`/`expire()`/`setPeriod()` are all manually invoked today, exclusively from Platform Admin.

**Plan changes always go through `TenantPlanAssignment`** (task section 9, DECISION_LOG C18 finalized): `start()`/`startTrial()`/`changePlan()` all call `Platform\Plans\Services\TenantPlanAssignment::assign()` internally - the *only* place `tenants.plan_id` is ever written, unchanged since TASK-ARCH-015. This is what keeps `subscription.plan_id == tenant.plan_id` true by construction after every mutation, with no separate synchronization mechanism to invent or maintain. `changePlan()` wraps both writes in a single `DB::transaction()`.

## C18 finalized: ownership model (Option C)

**Subscription is the lifecycle source of truth. `tenants.plan_id` remains a synchronized "current plan" pointer.** `TenantEntitlements`/`TenantLimits`/every enforcement listener/the existing My Plan page all continue reading `tenants.plan_id` completely unchanged - this task did **not** rewrite that already-proven, heavily-tested stack to query `Subscription` directly. The synchronized pointer exists specifically to protect that stack from any rework risk.

Why Option C over the alternatives considered in the pre-task roadmap review: Option A (`tenants.plan_id` alone, Subscription as a side record) would let the two values silently drift with no single source of truth. Option B (`Subscription` as the *only* source of truth, `tenants.plan_id` removed) would force every entitlement-resolution call site to change its query shape for no proven benefit. Option C costs nothing new to implement - the synchronization mechanism (`TenantPlanAssignment`) already existed; `SubscriptionLifecycle` simply calls it internally instead of duplicating it.

**Invariant proof**: `subscription.plan_id == tenant.plan_id` is verified after subscription creation (provisioning and manual `start()`), plan change, and backfill - see `tests/Feature/Platform/PlatformSubscriptionManagementTest.php` tests 6, 12. **If an inconsistency is ever detected** (should be structurally impossible given the single-writer design, but documented per the task's own explicit instruction): no generalized reconciliation daemon exists or is planned - the two values are written together, in the same transaction, by the same method, so a divergence would indicate a bug in `SubscriptionLifecycle` itself, not a runtime data-drift condition needing its own remediation tooling.

## Provisioning integration

`Platform\Tenancy\Services\TenantProvisioner::ensureInitialSubscriptionStarted()` (renamed from TASK-ARCH-008's `ensureDefaultPlanAssigned()` - see that method's own docblock) is provisioning step 5: resolves the configured default plan (`config('platform.plans.default_code')`, unchanged), then calls `SubscriptionLifecycle::start()` - which creates the `Subscription` row (status `Active`, no trial) and synchronizes `tenants.plan_id` in one atomic operation. A FREE default tenant gets no trial merely because plans might one day support a `trial_days` column that doesn't exist today (task section 6) - `startTrial()` exists and is fully tested, but provisioning never calls it.

Idempotency guard unchanged (`if ($tenant->plan_id !== null) return;`) - deliberately still keyed on `plan_id`, not "does a Subscription exist": self-healing a tenant that has `plan_id` set but no Subscription row is the backfill's job (below), not provisioning's - keeping this step narrowly scoped to genuinely new tenants.

If subscription creation fails (e.g. the default plan has been deactivated - `TenantPlanAssignment::assign()`'s existing check, reused, not duplicated), `provision()`'s own existing try/catch marks the tenant `Failed` with `last_error` set, exactly as it already did for a bare plan-assignment failure before this task - no new failure-handling code needed.

## Existing-tenant backfill

`Platform\Subscriptions\Services\SubscriptionBackfill::run()`, invoked once by the central migration `database/migrations/2026_08_15_170001_backfill_subscriptions_from_tenant_plan_id.php` (placed immediately after `create_subscriptions_table`, matching RISK_REGISTER.md R31's own precedent for a "new table, need to backfill existing central rows" data migration).

For every tenant with `plan_id IS NOT NULL` and no existing `Subscription` row: creates one with `status = Active`, `plan_id = tenants.plan_id`, `starts_at = tenants.created_at` (the tenant's own creation date - the most defensible available anchor, not `now()`, which would misrepresent every backfilled tenant as starting today). Tenants with `plan_id IS NULL` are deliberately skipped, not given an invented plan (task section 8, explicit).

**What this does NOT claim**: the resulting `Active` status and `starts_at` are not a claim that the tenant historically paid for, or was ever billed for, that plan - there is no payment history anywhere in this codebase. It is only "the current SaaS entitlement/lifecycle baseline at the moment the Subscription domain was introduced."

**Deliberately bypasses `TenantPlanAssignment`'s active-plan gate** - the one place in the whole domain that does. Backfill is not a *new* assignment decision, it's recording an already-existing historical fact; routing it through the active-only gate would mean a tenant whose plan was deactivated between TASK-ARCH-015 and this task gets silently skipped or errored during backfill - exactly the "disturbing an existing assignment" DECISION_LOG C33 already forbids, via a different code path. See `SubscriptionBackfill`'s own docblock.

**Idempotent, central-only, safe to rerun**: `firstOrCreate()`, matching TASK-ARCH-015's `PlanSeeder` fix. Verified live: 23 of 24 pre-existing tenants in this development environment backfilled correctly (the 24th, a deliberately-never-provisioned test fixture with `plan_id IS NULL`, correctly skipped); zero `subscription.plan_id != tenant.plan_id` divergence found afterward.

## Manual Platform Admin plan-assignment path (TASK-ARCH-015 revisited)

TASK-ARCH-015's `TenantController::changePlan()` no longer calls `TenantPlanAssignment` directly - task section 10, explicit: "This can no longer remain an independent mutation path once subscriptions exist." It now:

- Calls `SubscriptionLifecycle::changePlan()` if the tenant already has a Trialing/Active subscription (the normal case for every real tenant after this task).
- Falls back to `SubscriptionLifecycle::start()` if the tenant has no subscription, or an already-Canceled/Expired one - the narrow, temporary compatibility path the task explicitly permits for a transitional tenant-without-subscription state. `start()`'s own "restart in place" behavior (see below) makes this the correct, not merely convenient, choice.

The route/URL/button (`platform.tenants.change-plan`, the existing "Change Plan" form on the tenant detail page) is unchanged - only its internal implementation changed.

## Cancellation semantics

**Cancel at period end** (`cancelAtPeriodEnd()`, task section 11.A): sets `cancel_at_period_end = true` only. Does **not** change `status`, does **not** touch tenant access/entitlements in any way. No scheduler exists anywhere in this codebase to act on this flag later - that rollover mechanism is explicitly out of this task's scope. Only valid from `Active` - a Trialing subscription has no "period" yet to cancel at the end of.

**Cancel immediately** (`cancelImmediately()`, task section 11.B): sets `status = Canceled`, `cancelled_at`, `ended_at` to now. Does **not** touch `tenants.plan_id` and does **not** touch `TenantStatus` - a canceled subscription's tenant keeps its last entitlements and keeps whatever independent `TenantStatus` it already had (typically `Ready`) until an operator, or a future explicit billing policy, separately decides otherwise.

## Expiration

`expire()` (task section 12): manual-only, same TenantStatus-independence guarantee. Not exposed as a Platform Admin UI action in this foundation task (task section 15's own required-action list doesn't include it) - it exists in the service for completeness/testability ("manual expiration through the lifecycle service is sufficient for foundation," the task's own words), without adding UI surface nothing asked for yet.

## Periods

`current_period_start`/`current_period_end` are provider-neutral metadata only (task section 13) - `SubscriptionLifecycle::setPeriod()` validates `current_period_end >= current_period_start` when both are present, and may be called to record a period manually. No automatic billing-cycle rollover, renewal scheduler, invoice generation, or payment collection exists or is planned by this task.

## Trials

`startTrial()` validates `trial_ends_at > starts_at` (task section 14), throwing `InvalidArgumentException` otherwise. `Trialing -> Active` is manual only, via `activate()` - no scheduler auto-converts a trial.

## TenantStatus independence - strict, load-bearing

`SubscriptionLifecycle` never reads or writes `$tenant->status`; nothing in `Platform\Tenancy` ever reads or writes a `Subscription`. `TenantStatus = Ready` + `SubscriptionStatus = Canceled` is a fully valid, unremarkable state (task section 18's own example) - canceling a subscription does not suspend a tenant, and reactivating a suspended tenant (`Platform\Tenancy\Services\TenantLifecycle::reactivate()`) does not touch its subscription. Proven live: `tests/Feature/Platform/PlatformSubscriptionManagementTest.php` tests 16-17. A future, explicit billing-policy task may orchestrate both services together; this task deliberately does not build any hidden coupling between them.

## Platform Admin UI

Tenant-detail integration, not a standalone dashboard (task section 15): a "Subscription" card on the existing tenant detail page shows status/plan/`starts_at`/`trial_ends_at`/current period/`cancel_at_period_end`/`cancelled_at`/`ended_at`, with conditional action buttons (`activate`, `cancel-at-period-end`, `cancel-immediately`) matching the subscription's current status - the same conditional-button pattern already established for tenant suspend/reactivate (TASK-ARCH-013). The existing "Change Plan" form doubles as "start a subscription" when none exists yet (see "Manual Platform Admin plan-assignment path" above) - no separate "Start Subscription" button was needed.

## Tenant-visible subscription status ("My Plan")

Extends the existing TASK-ARCH-009 page (`admin/saas/plan`), not a new route/menu entry. Shows plan, subscription status, trial end (if trialing), current period (if set), cancellation-at-period-end intent (if set). Deliberately does **not** show any price, payment method, invoice, or "next charge" amount - none of that data exists in this domain. Degrades gracefully for a tenant with no `Subscription` row (a genuinely possible transitional/test case; every tenant provisioned after this task, or covered by its backfill, has one).

## What Phase 11 (Billing) will still need, unchanged from the original sketch

See [billing.md](billing.md) for the full boundary. In short: a `BillingProvider` interface, a separate `billing_accounts` table for provider-specific columns, webhook handling, and (only then) a real `past_due` status with an automatic transition trigger. None of that exists yet, and none of it was pulled forward by this task.
