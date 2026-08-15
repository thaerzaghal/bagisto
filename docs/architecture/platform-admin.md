# Platform Admin

TASK-ARCH-011. The CENTRAL area for managing the SaaS platform itself —
tenants, plans, provisioning. This is **not** a tenant's Bagisto store
admin (`Webkul\Admin`, guard `admin`, one per tenant database). The two
share no table, no guard, no session store, no UI code, and no route
namespace.

|                    | Platform Admin                              | Tenant (Bagisto) Admin                 |
|--------------------|----------------------------------------------|-----------------------------------------|
| Manages            | tenants / plans / provisioning               | one merchant's store (catalog, orders…) |
| Identity table      | `platform_users` (central)                   | `admins` (one per tenant database)      |
| Guard               | `platform`                                   | `admin`                                 |
| Reachable at        | central domain only (e.g. `localhost`)       | any tenant domain                       |
| Package             | `packages/Platform/Admin`                    | `packages/Webkul/Admin` (untouched)     |

## Package boundary and dependency direction

`packages/Platform/Admin` (namespace `Platform\Admin`) depends on:

```
Platform\Admin
    -> Platform\Tenancy   (Tenant model, TenantProvisioner, TenantStatus)
    -> Platform\Plans     (Plan, PlanFeature models)
```

Neither `Platform\Tenancy` nor `Platform\Plans` references `Platform\Admin`
anywhere — the dependency is strictly one-way (DECISION_LOG C22). This
package also has zero dependency on `packages/Webkul/*` — no shared
controller base class, no shared Blade component, no shared middleware
(see "UI architecture decision" below).

## Central routing boundary

Platform Admin routes are registered under a dedicated `platform`
middleware group (`bootstrap/app.php`), not `web` — the group Bagisto's
Shop/Admin routes use and which has `Stancl\Tenancy\Middleware\
InitializeTenancyByDomain` prepended to it (TASK-ARCH-003). `platform` is
built from `web`'s own resolved middleware list minus that one entry, so
it always tracks whatever `web` provides (session, CSRF, cookies) without
duplicating it by hand (DECISION_LOG C20).

Consequence: **no request to a Platform Admin route can ever initialize
tenancy or switch to a tenant database connection, structurally** — the
one middleware that does that (`InitializeTenancyByDomain`) never runs
for this route group, regardless of Host header. This is what makes
"tenant list/plan list cannot accidentally query a tenant DB" true by
construction rather than by discipline. `tests/Feature/Platform/
PlatformAdminTest.php` ("rendering the tenant list never initializes any
tenant database connection") asserts `tenancy()->initialized` stays
`false` across a real request, as direct proof.

On top of that structural guarantee, `Platform\Admin\Http\Middleware\
EnsureCentralDomain` explicitly checks the request's Host header against
`config('tenancy.central_domains')` and 404s anything else — this is the
"do not rely only on URL prefix" requirement: without it, a tenant domain
(or any host) could still reach `/platform/*` and see the login page or
(if somehow authenticated) tenant/plan data, even though no tenant DB
would ever be touched to render it. Verified live: `tenant-platform-
check.localhost/platform/login` and `.../platform` both 404, identically
to a wholly unregistered domain.

## Authentication model

- **Guard**: `platform` (`config/auth.php`).
- **Provider**: `platform_admins`, Eloquent, model `Platform\Admin\Models\
  PlatformUser`.
- **Model**: `platform_users` table, central migration (`database/
  migrations/2026_08_14_160000_create_platform_users_table.php`, applied
  only via `php artisan platform:migrate:central`, TASK-ARCH-008/R30's
  established mechanism). `PlatformUser` uses `Stancl\Tenancy\Database\
  Concerns\CentralConnection` — the same trait `Platform\Plans\Models\
  Plan` already relies on — so it resolves against the central connection
  regardless of any active tenant context, structurally (belt-and-braces:
  in practice no tenant context is ever active for these routes at all,
  see above).
- **Session behavior**: since Platform Admin routes never touch
  `InitializeTenancyByDomain`, the default DB connection is always
  central for the whole request — `Illuminate\Session\Middleware\
  StartSession` (part of the copied `platform` group) therefore reads/
  writes the CENTRAL `sessions` table automatically, with zero extra
  configuration. This is the same table TASK-ARCH-010/R33 left dormant
  ("currently unused, harmless") — Platform Admin is its first real
  consumer.
- **Route middleware**: `Platform\Admin\Http\Middleware\Authenticate`
  (a one-method override of Laravel's stock `auth` middleware that
  redirects unauthenticated guests to `platform.login` instead of the
  nonexistent global `login` route — Bagisto itself has no single global
  login route either, each guard has its own), applied as `auth:platform`
  to every route except the login routes themselves.

## Bootstrap: the first platform admin

```
php artisan platform:admin:create --name="..." --email="..." --password="..."
```

or run with no options for interactive prompts (name/email/password,
password hidden). Validates input, hashes the password (`PlatformUser`'s
`password` cast is `'hashed'`), and rejects a duplicate email outright —
there is no seeder and no default/known credential anywhere in this
codebase (`grep`-verified: `platform_users` is never touched by any
seeder, factory, or migration other than the schema migration itself).

## Routes

All under prefix `platform`, group middleware `[EnsureCentralDomain,
'platform']`, named `platform.*`:

| Method | URI                                    | Name                             | Guard      |
|--------|-----------------------------------------|-----------------------------------|------------|
| GET    | `/platform/login`                       | `platform.login`                  | guest      |
| POST   | `/platform/login`                       | `platform.login.store`            | guest      |
| POST   | `/platform/logout`                      | `platform.logout`                 | `platform` |
| GET    | `/platform`                             | `platform.dashboard`              | `platform` |
| GET    | `/platform/tenants`                     | `platform.tenants.index`          | `platform` |
| GET    | `/platform/tenants/{tenant}`            | `platform.tenants.show`           | `platform` |
| POST   | `/platform/tenants/{tenant}/provision`  | `platform.tenants.provision`      | `platform` |
| POST   | `/platform/tenants/{tenant}/migrate-pending` | `platform.tenants.migrate-pending` | `platform` |
| POST   | `/platform/tenants/{tenant}/suspend`    | `platform.tenants.suspend`        | `platform` |
| POST   | `/platform/tenants/{tenant}/reactivate` | `platform.tenants.reactivate`     | `platform` |
| POST   | `/platform/tenants/{tenant}/change-plan` | `platform.tenants.change-plan`   | `platform` |
| GET    | `/platform/plans`                       | `platform.plans.index`            | `platform` |
| GET    | `/platform/plans/create`                | `platform.plans.create`           | `platform` |
| POST   | `/platform/plans`                       | `platform.plans.store`            | `platform` |
| GET    | `/platform/plans/{plan}`                | `platform.plans.show`             | `platform` |
| PATCH  | `/platform/plans/{plan}`                | `platform.plans.update`           | `platform` |
| POST   | `/platform/plans/{plan}/activate`       | `platform.plans.activate`         | `platform` |
| POST   | `/platform/plans/{plan}/deactivate`     | `platform.plans.deactivate`       | `platform` |
| POST   | `/platform/plans/{plan}/features`       | `platform.plans.features.store`   | `platform` |
| PATCH  | `/platform/plans/{plan}/features/{feature}` | `platform.plans.features.update` | `platform` |
| DELETE | `/platform/plans/{plan}/features/{feature}` | `platform.plans.features.destroy` | `platform` |
| POST   | `/platform/tenants/{tenant}/subscription/activate` | `platform.tenants.subscription.activate` | `platform` |
| POST   | `/platform/tenants/{tenant}/subscription/cancel-at-period-end` | `platform.tenants.subscription.cancel-at-period-end` | `platform` |
| POST   | `/platform/tenants/{tenant}/subscription/cancel-immediately` | `platform.tenants.subscription.cancel-immediately` | `platform` |

## Pages

- **Dashboard**: tenant count, ready count, failed count, active plan
  count — each a live `COUNT` query against `Platform\Tenancy\Models\
  Tenant` / `Platform\Plans\Models\Plan` (both central-only). No cached or
  invented numbers.
- **Tenant list**: id, status, domain(s), current plan name, last error,
  created date — from `Tenant::with('domains')` plus a batched `Plan::
  whereIn('id', ...)` lookup. No tenant commerce database is queried.
- **Tenant detail**: the same fields plus updated timestamp, plus (since
  TASK-ARCH-015) a "Change Plan" form offering every currently ACTIVE plan
  plus the tenant's own current plan even if it has since been
  deactivated, plus (since TASK-ARCH-016) a "Subscription" card - status,
  plan, `starts_at`, `trial_ends_at`, current period, `cancel_at_period_end`,
  `cancelled_at`/`ended_at` where relevant, with conditional action
  buttons matching the subscription's current status. No billing/payment
  section (out of scope, no such data exists - see
  docs/architecture/subscriptions.md).
- **Plan list**: code, name, active flag, sort order, configured feature
  count (`Plan::withCount('features')`), each code linking to its detail
  page (TASK-ARCH-015). No pricing/billing fields — `plans`/`plan_features`
  don't have any (TASK-ARCH-008 deliberately didn't add them, TASK-ARCH-015
  deliberately didn't either).
- **Plan create/detail** (TASK-ARCH-015): create form (code/name/
  description/sort_order/active); detail page combines an edit form
  (name/description/sort_order — `code` is displayed but disabled, see
  "Plan code policy" below) with the plan's feature list (add/edit/remove,
  scoped to the known `FeatureCode` enum) and activate/deactivate actions.

## Actions

- **Provision / retry** (`platform.tenants.provision`) — calls the
  existing `TenantProvisioner::provision()` (TASK-ARCH-002), guarded by
  `$tenant->status->isProvisionable()` (Pending/Provisioning/Failed only).
  Ready tenants have no provision button; a Ready tenant posting here
  anyway is a no-op inside `provision()` itself regardless.
- **Run pending migrations** (`platform.tenants.migrate-pending`) — calls
  the existing `TenantProvisioner::remigrate()` (TASK-ARCH-010/R33),
  safe on any tenant, any status, any number of times.
- **Suspend** (`platform.tenants.suspend`, TASK-ARCH-013) / **Reactivate**
  (`platform.tenants.reactivate`) — calls `Platform\Tenancy\Services\
  TenantLifecycle::suspend()`/`reactivate()`; visible only for a Ready
  (Suspend) or Suspended (Reactivate) tenant respectively, requires an
  explicit JS `confirm()` before submitting (state-changing POST, real
  CSRF token via the existing `platform` middleware group - no GET-based
  mutation). An invalid attempt (e.g. a stale page double-submit) fails
  cleanly with a flashed error, not a 500. See
  docs/architecture/provisioning.md "Suspension and reactivation" for the
  full lifecycle design and docs/architecture/domain-routing.md for how
  enforcement actually rejects a suspended tenant's requests.
- **Change Plan** (`platform.tenants.change-plan`) — since TASK-ARCH-016,
  routes through `Platform\Subscriptions\Services\SubscriptionLifecycle::
  changePlan()` (or `start()` for a tenant with no subscription yet - a
  narrow, temporary compatibility path), which itself calls
  `TenantPlanAssignment::assign()` internally; only an ACTIVE plan may be
  selected in the dropdown (an inactive plan is rejected server-side too,
  not just hidden from the UI). Immediate effect on the next enforcement
  check - see docs/architecture/subscriptions.md and
  docs/architecture/feature-limits.md "Plan management".
- **Create / edit / activate / deactivate a plan, add / edit / remove a
  plan feature** (TASK-ARCH-015) — see docs/architecture/feature-limits.md
  "Plan management" for the full CRUD design, plan code immutability
  policy, deactivation semantics, and duplicate-feature/type validation.
- **Activate / cancel at period end / cancel immediately** (TASK-ARCH-016,
  `platform.tenants.subscription.*`) — call
  `Platform\Subscriptions\Services\SubscriptionLifecycle`'s matching
  transition methods; visible only when the subscription's current status
  makes that transition valid (mirroring the suspend/reactivate
  conditional-button pattern above). Canceling a subscription never
  suspends the tenant or changes its plan - see
  docs/architecture/subscriptions.md "TenantStatus independence".

Still no delete for either tenants or plans — tenant deletion requires a
fully-defined backup/export lifecycle this task does not build
(provisioning.md defines `Deleting`/`Deleted` states, but nothing in the
codebase transitions a tenant into them yet); plan deletion is
deliberately never offered — deactivation is the only lifecycle mechanism
for a plan (see docs/architecture/feature-limits.md "Plan deactivation
semantics").

## ACL / authorization

Minimal, per the task brief: `auth:platform` is the entire authorization
model for this foundation task — an authenticated `PlatformUser` can do
everything above; there are no platform-level roles/permissions yet. A
tenant admin session (guard `admin`, tenant database) has zero
relationship to the `platform` guard by construction (different guard
name, different provider, different underlying identity table) — "tenant
admin is not automatically a platform admin" requires no extra code, only
that the two guards stay separate, which they structurally are. Future
platform roles/permissions are deferred, as instructed.

## UI architecture decision

A small, self-contained Blade layout (`packages/Platform/Admin/src/
Resources/views/layouts/app.blade.php`, inline CSS, no build step) —
**not** Bagisto Admin's own layout/components. See DECISION_LOG C21 for
the full reasoning: reusing Bagisto Admin's Blade/Vue/Vite stack would
create exactly the kind of "future Bagisto admin changes silently break
Platform Admin" coupling the task brief warned against.

## Testing

`tests/Feature/Platform/PlatformAdminTest.php` — 15 tests, real HTTP
requests through the actual registered routes, real `platform_users`
row, real tenant provisioning (dedicated fixture `tenant-platform-
check`), `session.driver` explicitly forced to `database` (the real
`.env` value — phpunit.xml overrides it to `array`, which would make
every session-based assertion here pass regardless of correctness; the
same R29/R33/R34 lesson applied again). Covers: central-DB model
placement, secure CLI bootstrap (incl. duplicate-email rejection), login
success/failure, unauthenticated redirect, central-domain reachability,
tenant-domain 404 (both the login page and an authenticated dashboard
request), live (non-invented) dashboard metrics, tenant list/detail
correctness, the "never initializes tenancy" structural proof, plan list
correctness, and platform-vs-tenant session isolation proven at the
`sessions`-table-row level in both directions (14a/14b — see that test
file's docblock for why cross-guard isolation is proven as two single-
request tests rather than one test doing both logins; a real, documented
testing-harness finding, not a production defect — see RISK_REGISTER.md
R35).

## Future direction

- **RBAC**: a real platform-role/permission system once more than one
  platform admin persona exists (e.g. "billing viewer" vs "full admin").
  Out of scope here per instruction.
- **Tenant deletion**: suspend/reactivate shipped in TASK-ARCH-013;
  deletion still requires a fully-defined backup/export lifecycle this
  engagement has not built — `Deleting`/`Deleted` remain unreachable
  states (see provisioning.md's state diagram).
- **Subscriptions/billing**: explicitly out of scope for this task and
  the whole engagement so far (Phase 10/11, still unstarted).
