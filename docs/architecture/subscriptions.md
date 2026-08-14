# Subscription Architecture

## Model

`subscriptions` (central DB, one active row per tenant, historical rows kept for audit): `tenant_id`, `plan_id`, `status`, `trial_ends_at`, `current_period_start`, `current_period_end`, `cancels_at`, `canceled_at`, `grace_period_ends_at`. See [database-per-tenant.md](database-per-tenant.md) for the full column list.

## Status values

`trialing` → `active` → (`past_due` → `grace` → `canceled`) or (`active` → `canceled` directly, user-initiated) → `expired` (grace period ran out with no payment recovery).

This state machine is intentionally independent from the tenant-provisioning state machine in [provisioning.md](provisioning.md) — a tenant can be `READY` (technically operational) while its subscription is `past_due` (a billing concern, not a provisioning concern). The two only intersect at enforcement time: [security.md](security.md) and [feature-limits.md](feature-limits.md) both need to know both a tenant's provisioning status AND subscription status to decide whether to serve a request.

## Plans

`FREE`, `BASIC`, `PRO`, and a placeholder `ENTERPRISE` for later — plan *codes* are just data rows in `plans` (renamed from the originally-sketched `subscription_plans` — **the plan/feature domain itself was implemented ahead of schedule in TASK-ARCH-008**, see [feature-limits.md](feature-limits.md) and DECISION_LOG.md; FREE/BASIC/PRO already exist as real seeded rows, ENTERPRISE does not yet), never hardcoded in application logic (no `if ($plan === 'pro')` anywhere in domain code; always resolve via the plan's `plan_features` rows). This is what lets pricing/limits change without a deploy.

Each plan will support monthly and yearly pricing (`price_monthly`, `price_yearly` columns) and a configurable trial length (`trial_days`, can be 0) once Phase 10/11 actually build subscriptions/billing — **not added to `plans` yet** (TASK-ARCH-008 explicitly excludes billing/subscription concerns; adding these columns later is a simple additive migration). Trial handling: `subscriptions.trial_ends_at` set at subscription creation from the plan's `trial_days`; a scheduled job transitions `trialing` → `active` (if payment method on file) or → `canceled`/prompts payment at trial end, depending on the billing provider's own trial semantics (see [billing.md](billing.md) — if using Cashier/Stripe, much of this is Stripe's own subscription-lifecycle webhook handling rather than code we write ourselves).

`subscriptions.plan_id` (when Phase 10 builds this table) will FK to `plans.id`, the same table `tenants.plan_id` already references (TASK-ARCH-008) — see DECISION_LOG.md for how `tenants.plan_id` (a fast, denormalized "current effective plan" pointer, implemented now) and the future `subscriptions` table (the real billing-lifecycle source of truth) are intended to coexist.

## Renewal / cancellation / grace period

- **Renewal**: driven by the billing provider's own recurring-billing cycle (webhook-driven status sync, not a cron job we invent — see [billing.md](billing.md)).
- **Cancellation**: `cancels_at` set to end of current paid period (no immediate service cutoff — a canceled subscription remains `active` until `current_period_end`, then transitions to `canceled`), consistent with standard SaaS billing UX and how Stripe/Cashier model cancellation.
- **Past due / grace period**: `past_due` → `grace` gives a tenant a configurable window (`platform_settings` value, not hardcoded) to update payment before `expired`. During `grace`, enforce read-only or degraded access rather than full suspension — a full `SUSPENDED` (provisioning-level) status is reserved for platform-admin-initiated suspension (ToS violation, etc.), not routine billing lapses, to keep the two state machines' semantics distinct.

## Tenant-visible subscription status (MVP criterion #16)

A Subscription/Billing page inside the tenant's own Bagisto admin (added via the `menu.php`/`acl.php` config-merge mechanism confirmed in [system-overview.md](system-overview.md), no core edit) — reads the tenant's current `subscriptions` row from the central DB connection, displays plan, status, renewal date, and current usage against limits.
