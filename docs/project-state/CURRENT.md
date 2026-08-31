# Technify — Current Project State

**Last reviewed:** 2026-08-31
**Branch:** 2.4
**Status:** Living Document
**Source:** TASK-PROJECT-STATE-001 (architecture & readiness audit), reconciled by TASK-PROJECT-STATE-002, extended by TASK-OPS-MONITORING-001 and its own review-and-fix pass TASK-OPS-MONITORING-001A (production monitoring/alerting — implemented, reviewed, hardened, locally tested, see §12/§19.1; not yet deployed or activated)

**Evidence scope:** Production-verification labels retain the dated evidence
from the original audit and closing tasks. This reconciliation follow-up
checked local source, risk/decision records, and Git history only; it did
not independently rerun production checks or change production state.

## Maintenance Policy

This document is the repository-level current-state snapshot for Technify.

Update it whenever a completed task materially changes:
- architecture
- product decisions
- production readiness
- tenancy
- onboarding
- billing/subscriptions
- localization
- payments/shipping
- operations
- known risks
- roadmap priorities

Small implementation details that do not change project state do not
require an update.

Historical implementation detail belongs in the existing task/decision/
risk documentation (`docs/architecture/*`, `docs/decisions/*`,
`RISK_REGISTER.md`, `DECISION_LOG.md`); CURRENT.md should describe the
CURRENT truth rather than accumulate a chronological changelog.

**If this document conflicts with implementation or production evidence,
implementation/production evidence wins and this document must be
corrected.**

**AI-agent rule:** at the end of every completed task, determine whether the
task materially changed Technify's current project state. If yes, update
`docs/project-state/CURRENT.md` in the same documentation close-out. Update
existing sections in place. Do not append chronological task history to
CURRENT.md. Detailed history remains in task docs, ADRs, `DECISION_LOG.md`,
`RISK_REGISTER.md`, and Git history.

This document should answer: *if a new engineer or AI agent joined Technify
today, what must they know about the project's current state?* It is not a
substitute for the detailed architecture docs, ADRs, decision log, or risk
register — it is the map that tells you which of those to go read.

### Status labels used throughout

| Label | Meaning |
|---|---|
| **PRODUCTION VERIFIED** | Confirmed working against real production evidence — real HTTP requests / real data against the live production server, or an equivalent direct, non-mocked proof captured from production itself. Never a substitute label for a passing automated test. |
| **IMPLEMENTED** | Real, working code exists and is covered by automated integration tests (real MySQL, real HTTP where applicable) — this is test evidence, not production evidence. A capability whose only proof is an integration test stays **IMPLEMENTED** even if the underlying mechanism is technically live in production, unless this document also cites a specific production observation. |
| **PARTIAL** | Works for the common case; a specific, disclosed gap remains |
| **NOT IMPLEMENTED** | No code exists for this capability |
| **INTENTIONALLY DEFERRED** | A deliberate product/scope decision, not an oversight — do not treat as a bug |

A capability can be genuinely **IMPLEMENTED and integration-tested** while
the *current production topology* never actually exercises the mechanism —
this is called out explicitly wherever it applies (e.g. queue and search
isolation, §2), rather than implied by the label alone.

---

## Fixed Product Decisions

These are settled. Do not re-litigate them as gaps or propose reversing them
without the product owner explicitly reconsidering.

- **Public merchant signup is CLOSED BY DESIGN.** `PUBLIC_SIGNUP_ENABLED=false`
  in production. Merchants cannot self-register.
- **Merchants are onboarded by the Technify operator only**, through Platform
  Admin's managed "Create Merchant" flow.
- **Storefront payment is currently COD-only.** Money Transfer, Stripe,
  PayPal, Razorpay, PayU, PhonePe, PayGlocal, and every other bundled
  gateway are unsupported/inactive for the current MVP.
- **Merchant → Technify billing is manual/offline**, outside the system.
  Lack of electronic billing automation is a product decision, not a bug.
- **Future electronic payment provider is undecided.** Do not invent or
  assume a provider — integration is deferred until a real local/regional
  provider is selected with official API documentation available.
- **`packages/Webkul/*` remains upstream wherever possible.** No unnecessary
  forks.
- **Technify customization belongs in `packages/Platform/*`.**
- **Custom domains are not an MVP requirement** unless a future decision
  says otherwise.

---

## 1. Architecture

**Stack**: Bagisto 2.4.x on Laravel `^12.0`, PHP `>=8.3 <8.5`. ~40
self-contained `packages/Webkul/*` packages wired through Konekt Concord.
MySQL is the only actively-used DB driver. A repo-wide grep for
`DB::connection(`/`->connection(`/`config('database.default')` across
`packages/Webkul` returns nothing relevant — no model/repository hardcodes a
connection. This is the single fact that makes database-per-tenant possible
without core modification: every query rides Laravel's *default* connection,
which `stancl/tenancy` swaps per request.

**Tenancy package**: `stancl/tenancy` v3.10.1 (MIT, actively maintained —
see [ADR-003](../decisions/ADR-003-tenancy-package.md)).

**Central database**: `tenants`, `domains`, `plans`, `plan_features`,
`plan_prices`, `subscriptions`, `payments`, `billing_provider_events`,
`platform_users`, `tenant_provisioning_events`, plus the central `sessions`
table.

**Tenant database**: deliberately unmodified standard Bagisto schema — every
`packages/Webkul/*/src/Database/Migrations` migration, run as-is per tenant.
No `tenant_id` column exists anywhere; physical database isolation is the
entire mechanism (see [database-per-tenant.md](../architecture/database-per-tenant.md)).

**Tenant resolution**: `Stancl\Tenancy\Middleware\InitializeTenancyByDomain`,
prepended to the `web` middleware group itself in `bootstrap/app.php` (not
just Platform's own routes) — every real Bagisto Admin/Shop route is
tenant-aware automatically, zero `packages/Webkul` changes.

**Platform Admin**: separate `platform` guard, `platform_users` table
(central DB), separate `platform` middleware group that never runs
`InitializeTenancyByDomain` — no inbound HTTP request to a Platform Admin
route automatically initializes tenancy or opens a tenant database
connection, by construction. This is narrower than "Platform Admin cannot
touch a tenant database": several explicit, operator-triggered actions
deliberately DO enter a specific tenant's own connection via
`$tenant->run()` — the Arabic-locale onboarding-readiness check
(`Platform\Admin\Http\Controllers\TenantController::arabicLocaleReadiness()`),
Provision/Retry (`Platform\Tenancy\Services\TenantProvisioner::provision()`),
and Migrate-pending (`TenantProvisioner::remigrate()`). These are
authorized, single-tenant-scoped reads/writes an operator explicitly
requests — not automatic tenancy initialization from routing or middleware,
and not a leak — see §4.

**Merchant Admin**: unmodified `packages/Webkul/Admin`, one per tenant
database, guard `admin`.

**Storefront**: unmodified `packages/Webkul/Shop`.

**Authentication / owner activation**: reuses Laravel's own
`PasswordBroker`/`ResetPassword` notification exactly as Bagisto's own
"forgot password" flow does — no custom activation-token system.

**Queues**: `QUEUE_CONNECTION=sync` in production (no worker process
required). `Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper` is proven
correct against a real Redis worker in the test suite even though production
doesn't currently run one.

**Cache**: `CACHE_STORE=redis` in production, required for
`CacheTenancyBootstrapper` (a taggable store is structurally required —
`file`/`database` stores are not taggable and would crash the bootstrapper).

**Mail**: Bagisto's own `bagisto-dynamic-smtp` transport — per-tenant SMTP
first, central `.env` fallback second.

**Storage**: `FilesystemTenancyBootstrapper` enabled for `local`/`public`/
`private` disks, each suffixed per tenant (`storage/tenant{id}/...`).

**Docker/production topology**: one Oracle Cloud VPS, Docker Compose,
fronted by an aaPanel-managed Apache reverse proxy → `app-web-1` (nginx,
serves static assets) → `app-app-1` (PHP-FPM) → `app-mysql-1` / `app-redis-1`.

**Production source-of-truth**: the host source tree (`/opt/estore/app/`) is
the one thing every deployment is built from — updated first (tar+scp+
sha256-verify), *then* the image is rebuilt. A hotfix applied only to a
running container (never the source tree) caused a real incident
(`docs/incidents/INCIDENT-001-central-db-wipe.md` covers a related incident;
the source-of-truth rule itself is documented under RISK_REGISTER R65).

**Deployment mechanism**: `docker/production/deploy.sh <commit-sha>` — the
only supported way to rebuild/recreate containers. Always rebuilds and
recreates `app` AND `web` together (a real incident, R76, came from
rebuilding `app` alone while `web` served a 10-day-stale JS/CSS bundle).
Gates its own exit code on `php artisan platform:production:check`.

**Backup system**: `platform:backup:run` (central DB + every tenant DB +
every tenant's persistent files, `mysqldump --single-transaction` +
`tar -czf`, manifest + SHA-256 checksums, atomic finalize). Daily at 03:15
server time via root crontab.

**Offsite backup**: `platform:backup:sync-offsite` → Cloudflare R2
(S3-compatible, provider-neutral contract). Every uploaded file is
round-trip verified (size + re-downloaded SHA-256, never trusting a
provider ETag). Daily at 03:30.

**Scheduler/cron**: `bootstrap/app.php`'s `withSchedule()` closure is empty
— no Platform-owned `Schedule::` task exists there. `routes/console.php`
does register one stock Laravel scaffolding command on the schedule
(`Artisan::command('inspire', ...)->hourly()`, the default "inspire" quote
command every fresh Laravel app ships with) — an empty `withSchedule()`
closure is not the same claim as "no scheduled command exists at all," and
this document does not make that stronger claim. What genuinely is true:
**no scheduled Platform production-readiness monitoring was found anywhere
in repository source** — `platform:production:check` is never invoked by
Laravel's scheduler or by any cron entry found in this codebase. All
documented scheduled operational work (backups, offsite sync, cleanup, disk
hygiene) runs via root crontab entries outside Laravel's scheduler
entirely — this is historical production evidence from the original
audit/closing tasks (`docs/implementation/backup-and-recovery.md`,
`docs/architecture/production-deployment.md`), not independently
re-inspected against the live crontab for this document.

**Readiness/health checks**: `php artisan platform:production:check` — 19
checks (`APP_DEBUG`, `TRUSTED_PROXIES`, domain config, cache store, session
driver, response-cache-disabled, DB provisioning user, deployed source
commit, Admin/Shop static asset consistency, public signup posture,
Turnstile config, Redis reachability, Stripe posture, mail config, local
backup health, offsite backup health). `php artisan platform:production:monitor`
(TASK-OPS-MONITORING-001) wraps this same check set with opt-in operator
email alerting — implemented and locally tested, **not yet deployed or
activated**. See §12 for the health-checking vs. monitoring distinction
and the full monitor design/status.

### packages/Platform module map

| Package | Owns |
|---|---|
| `Tenancy` | `Tenant` model, provisioning pipeline (`TenantProvisioner`), domain routing/access gate (`TenantAccessGate`), cache/storage/queue/search isolation listeners, lifecycle (`TenantLifecycle`: suspend/reactivate) |
| `Admin` | Platform Admin — the central operator panel (guard `platform`, table `platform_users`) |
| `Signup` | `MerchantOnboarding::register()`, the gated public `/join` flow, `OwnerActivationMailer`, Turnstile abuse protection, `SignupValidationRules` |
| `Plans` | `Plan`/`PlanFeature`/entitlement resolution (`TenantEntitlements`, `TenantLimits`) — central-DB-only, zero Webkul dependency |
| `Subscriptions` | Subscription lifecycle state machine (trialing/active/canceled/expired) — independent of `TenantStatus` by design |
| `Billing` | Provider-neutral `PaymentProvider` contract, `PlanPrice`/`Payment` domain, Stripe sandbox reference adapter, signed/idempotent webhook processing |
| `Enforcement` | The one Platform package deliberately allowed to know a specific Webkul package — wires `products.limit` to `Product::creating` and the bulk importer |
| `Backup` | Local + offsite backup runner, manifest/checksums, retention |

---

## 2. Tenancy

| Capability | Status | Evidence |
|---|---|---|
| Tenant model / physical DB-per-tenant isolation | **PRODUCTION VERIFIED** | 7 real tenant databases live in production; zero `tenant_id` columns anywhere |
| Provisioning pipeline (DB create → migrate → seed → owner admin → Ready) | **PRODUCTION VERIFIED** | `Platform\Tenancy\Services\TenantProvisioner::provision()`, idempotent per-step (`ensure*()` methods), audited via `tenant_provisioning_events` |
| Domain resolution + fail-closed lifecycle gate | **PRODUCTION VERIFIED** | `Platform\Tenancy\Http\Middleware\TenantAccessGate` — `423 Locked` for Suspended, `503` for every other non-Ready status (including any future/unrecognized status, fail-closed by `match`), before any tenant DB connection opens |
| Cache isolation | **PRODUCTION VERIFIED** | `CacheTenancyBootstrapper`, Redis tag-per-tenant, verified via direct Redis key inspection (`tag:tenant{id}:entries`) |
| Filesystem isolation | **PRODUCTION VERIFIED** | `FilesystemTenancyBootstrapper` + `TenantProvisioner::ensureFilesystemPrepared()`, per-tenant `storage_path()` suffix, dedicated `/storage/{path}` route |
| Queue isolation | **IMPLEMENTED** | `QueueTenancyBootstrapper`, payload-tagged with `tenant_id`, proven against a real daemon worker in tests — production runs `sync` (no worker), so this is proven-but-currently-inert in prod |
| Search isolation | **IMPLEMENTED** | Elasticsearch index-prefix-per-tenant listener proven correct; Elasticsearch itself is **not** the active search engine anywhere in production (`database` engine is default and what every tenant uses) |
| Suspend / Reactivate | **IMPLEMENTED** | `Platform\Tenancy\Services\TenantLifecycle::suspend()/reactivate()` — pure central metadata write, tenant DB never touched, reactivation is instantaneous |
| Deletion / decommissioning | **NOT IMPLEMENTED** | `Deleting`/`Deleted` enum cases exist and fail closed at the traffic gate, but nothing transitions a tenant into them — no export/backup-before-delete flow exists |
| Subscription relation | **IMPLEMENTED** | Independent state machine — see §5 |

---

## 3. Managed Onboarding

Flow: **Platform Admin → Create Merchant → `MerchantOnboarding::register()`**
(the exact same code path `/join` uses, when enabled) → `TenantProvisioner::provision()`
→ owner Admin row seeded with a server-generated, discarded temp password →
a real Bagisto password-reset email → merchant sets their own password →
Merchant Admin → Ready storefront.

**Can a second real merchant be onboarded entirely through supported
operator UI, with no shell/CLI/SQL?** Yes. The one exception in this
engagement — Rashet Bhar was created via `tinker`, not the HTTP form — was
a deliberate choice at the time (no Platform Admin HTTP credentials existed
yet for this operator), not a structural requirement of the platform. The
documented, repeatable operator path is
`docs/operations/merchant-onboarding-checklist.md`. The tenant detail page
carries a live "Onboarding Status" panel (tenant provisioned / domain
configured / owner identity recorded / plan & subscription consistent /
Arabic locale configured) — all derived facts, not hand-tracked state.

Validation (slug/owner-email uniqueness) is shared, not duplicated, between
`/join` and Platform Admin's form via `Platform\Signup\Support\SignupValidationRules`
— the two entry points cannot drift into different acceptance policies.

**Known, disclosed limitations (INTENTIONALLY DEFERRED):**
- No email-ownership verification at signup.
- Provisioning is synchronous — a ~20-30s blocking request. Accepted for
  current MVP scale; async provisioning is deferred.
- If a merchant loses their one-time activation link, a **Resend
  activation email** action exists, but Platform Admin has no way to detect
  whether the merchant actually logged in — no activation-tracking table
  exists by design. The onboarding checklist says explicitly: "ask the
  merchant to confirm."

---

## 4. Platform Admin

Status labels below use the same legend as the rest of this document (no
separate "PRODUCTION READY" tier) — **IMPLEMENTED** here means real, tested
code with no independent production re-verification for this document,
matching that label's own definition; it is not upgraded to **PRODUCTION
VERIFIED** merely because the underlying route is reachable.

| Capability | Status |
|---|---|
| Dashboard (live tenant/plan counts, no invented numbers) | **IMPLEMENTED** |
| Tenant list / detail / Create Merchant / Provision-Retry / Migrate-pending | **IMPLEMENTED** |
| Suspend / Reactivate | **IMPLEMENTED** |
| Change Plan, Subscription card (activate / cancel-at-period-end / cancel-immediately) | **IMPLEMENTED** |
| Plan / feature CRUD (create, edit, activate/deactivate, pricing rows) | **IMPLEMENTED** |
| Recent Payments (read-only, last 10, no raw provider payload rendered) | **IMPLEMENTED** |
| RBAC / multiple platform-admin personas | **NOT IMPLEMENTED** |
| Admin impersonation / "login as tenant" | **NOT IMPLEMENTED** |
| Audit/history log of operator actions | **NOT IMPLEMENTED** — `tenant_provisioning_events` covers provisioning transitions only, not every admin action (suspend/reactivate/plan-change have no durable "who did this and when" record) |

Auth model is intentionally minimal: one guard (`platform`), no
roles/permissions — any authenticated `PlatformUser` can do everything
above. Authentication and platform metadata (tenants, plans, subscriptions,
payments) are entirely central-DB: separate guard, separate `platform_users`
table, no shared session store with any tenant's `admins` table. One
specific, narrow claim is confirmed by an automated integration test, not
production evidence: rendering the tenant list never initializes tenancy
(`tests/Feature/Platform/PlatformAdminTest.php`, "rendering the tenant list
never initializes any tenant database connection" — a Pest/HTTP test against
a real MySQL fixture, not a live production observation).

This should not be generalized into "Platform Admin can never reach a
tenant database" — see §1. Several explicit, operator-triggered actions on
this same page deliberately DO enter a tenant's own connection via
`$tenant->run()`: the Arabic-locale onboarding-readiness indicator
(`TenantController::arabicLocaleReadiness()`), Provision/Retry, and
Migrate-pending. Each is a single-tenant-scoped, operator-authorized
operation, not automatic tenancy initialization from the routing layer.

---

## 5. Plans & Subscriptions

A real, minimal, provider-neutral commercial domain — more is built than
the pilot currently operationally uses.

- **Plan/Feature/Entitlement resolution** — **PRODUCTION VERIFIED**.
  `Platform\Plans\Services\TenantEntitlements::can()/limit()`, zero cache
  layer, resolved fresh on every call.
- **`products.limit` enforcement** — **PRODUCTION VERIFIED**, including the
  bulk CSV/XLS/XML import bypass (closed in TASK-ARCH-012A via
  `Platform\Enforcement\Importers\EnforcingProductImporter`). Every other
  defined feature code (`staff.limit`, `domains.custom`, `reports.advanced`)
  is **NOT ENFORCED** anywhere — illustrative identifiers only, no
  enforcement listener exists for them.
- **Subscription lifecycle** (`trialing`/`active`/`canceled`/`expired`) —
  **IMPLEMENTED**. `Platform\Subscriptions\Services\SubscriptionLifecycle`
  is the sole entry point for every transition; all transitions are manual
  (Platform Admin button), no scheduler, no automatic renewal/expiry.
  `TenantStatus` and `SubscriptionStatus` are deliberately uncoupled —
  canceling a subscription never suspends a tenant today (see
  DECISION_LOG C40).
- **Billing** — **IMPLEMENTED, Stripe sandbox only**. A real
  `PaymentProvider` contract, `Payment`/`PlanPrice` domain, Stripe Checkout
  ("payment" mode, never "subscription" mode — Stripe never becomes a
  second subscription source of truth), a signature-verified and
  idempotent webhook endpoint. `STRIPE_SECRET` is unset in production **by
  policy** — `platform:production:check` reports this as an **INFO** row
  ("billing checkout is intentionally unavailable"), not a failure.

**Can the operator reliably manage merchant subscriptions manually/offline
for the current MVP?** Yes — every transition (start, activate, cancel,
change plan) is a single Platform Admin action, immediately effective on
the very next request (no cache, no worker restart needed). This is the
actual, complete operating model today for 1-5 merchants.

---

## 6. Merchant Admin

Standard upstream Bagisto — catalog, categories, inventory, customers,
orders, invoices, shipments, refunds, CMS/configuration, staff/roles — is
relied on **unmodified**. TASK-MVP-003's own explicit finding: a merchant
needs nothing beyond existing Bagisto Admin; no duplicate Merchant
Dashboard/product/order/settings screens were built.

Platform-owned customizations affecting Merchant Admin:
- Tenant-scoped database (structural isolation, not an application filter).
- Arabic/RTL as the default locale, English secondary
  (`Platform\Tenancy\Http\Middleware\SetTenantAdminLocale`).
- A first-login welcome banner (`?welcome=1` marker,
  `Platform\Signup\Http\Middleware\FlagFirstLoginWelcome`).
- The "My Plan" page (`admin/saas/plan`) + "Upgrade Plan" checkout link.
- `products.limit` enforcement surfacing as a plain `422` on the
  create-product form, using Bagisto's own existing error-display
  convention (no `packages/Webkul` change needed).

**Disclosed environment gap, recorded as open (RISK_REGISTER R51, not a
Technify defect):** any Admin page rendering a product with no uploaded
image was originally found to throw a Vite-manifest error for a placeholder
SVG (`product-placeholders/front.svg`) — reproduced against the Admin-theme
build in the environment R51 was found in (`tests/Feature/Platform/StoreReadinessTest.php`,
a real HTTP integration test run against this repo's local/dev Docker
image), not against the live production server. R51's own record classifies
it as an environment-specific asset-build gap, unrelated to multi-tenancy,
that would need a real Admin-theme rebuild to close. `Dockerfile.production`
does run a real `npm run build` for both the Admin and Shop themes — that
fact alone neither confirms nor rules out whether this exact failure still
reproduces in the current production image; this document retains R51's
**open** status rather than asserting either closure or a fresh reproduction
against production, since no such investigation was performed here. Every
real Rashet Bhar product has an uploaded image and is unaffected regardless.

---

## 7. Storefront Readiness

| Item | Status |
|---|---|
| Arabic default + RTL, English secondary | **PRODUCTION VERIFIED** — Merchant Admin and storefront both, human-confirmed live against a real tenant |
| Palestine country default, 16 governorates seeded | **PRODUCTION VERIFIED** |
| ILS-only currency | **IMPLEMENTED** — deliberately no USD secondary (Bagisto's `Core::convertPrice()` silently returns the unconverted number when no exchange-rate row exists; automation exists upstream but its correctness in this fork's scheduler context was never verified, so dual-currency is **INTENTIONALLY DEFERRED**) |
| Asia/Hebron channel timezone | **PRODUCTION VERIFIED** — order detail view, invoices, shipments, and every order-related email (22 confirmed call sites) use `Core::formatDate()`, which reads `channels.timezone`. The Admin Orders DataGrid (and 6 sibling Sales/RMA grids — invoices, refunds, shipments, customer order view, RMA, transactions) also now renders in the tenant's own timezone: `Platform\Tenancy\Support\SalesDataGridTimezoneFormatter` attaches a `core()->formatDate()`-backed closure to all 8 columns across those 7 grids via Bagisto's own `datagrid.*.columns.prepare.after` event (RISK_REGISTER R75, **CLOSED**, production-verified against a real order 2026-08-30 — raw UTC `22:37:04` → grid display `01:37:04`, matching `core()->formatDate()` exactly). **Residual, disclosed gap**: CSV/XLS export of these same grids (`Webkul\DataGrid\Exports\DataGridExport`) reads the raw query builder directly and never calls the column's closure — exported files still show raw UTC. Out of scope for R75's fix, not yet a separate task. |
| Postcode optional, governorate required | **PRODUCTION VERIFIED** |
| Guest checkout, COD-only enforced at provisioning | **PRODUCTION VERIFIED** — see §9 |
| Storefront state dropdown honors the shopper's active locale | **PRODUCTION VERIFIED** — `Platform\Tenancy\Support\LocaleAwareCore`/`LocaleAwareCartRuleRepository` (bound via `bind()`, never `singleton()`) override `groupedStatesByCountries()` to read through the `CountryState` Eloquent model's own Astrotomic-translated accessor instead of the raw, locale-blind query Bagisto ships. RISK_REGISTER R74, **CLOSED**, production-verified 2026-08-30: a real `ar → en → ar` sequence against the live `shop.api.core.states` endpoint returned Jenin as `جنين → Jenin → جنين`, all 16 governorates correct in both locales, identity fields (`id`/`country_id`/`country_code`/`code`) unchanged. |
| Per-tenant mail sender identity | **PRODUCTION VERIFIED** — see §11 |
| Country-name Arabic translation | **NOT IMPLEMENTED** — `country_translations` is empty for every country platform-wide; a pre-existing, platform-wide upstream Bagisto content gap, not Palestine-specific |

Homepage/theme default marketing content for a fresh Arabic-first tenant is
**cloned English text under an `ar` locale row, not translated** — this
closed a real crash (product/theme content missing for `ar`, R71), it does
not deliver localized placeholder copy. Every merchant, Palestinian or not,
is expected to write their own homepage copy, same as always.

---

## 8. Shipping

Stock Bagisto Flat Rate and Free Shipping, both active by default at
provisioning (`packages/Webkul/Shipping/src/Config/carriers.php`), fully
Merchant-Admin-configurable per tenant. Confirmed this is exactly what the
one real production merchant runs (Flat Rate, 20 ILS).

**Real, disclosed gap for a Palestinian multi-merchant SaaS
(INTENTIONALLY DEFERRED, not a bug):** no governorate- or city-specific
rate table exists anywhere in Bagisto or Platform. A merchant delivering
across multiple governorates at genuinely different real costs can only set
one flat number or one free-shipping toggle today. Explicitly named as
future work in TASK-MVP-016's own non-goals list; never built.

---

## 9. Payments

**Two structurally separate domains that never touch** — worth stating
plainly since they're easy to conflate:

1. **Shopper → merchant** (storefront order payment) — `Webkul\Payment\*`,
   `orders`/`order_payment` tables, tenant-DB-only.
2. **Merchant → Technify** (SaaS billing) — `Platform\Billing`, central-DB-only.

**COD-only enforcement — PRODUCTION VERIFIED, R78 CLOSED (2026-08-30).**
Two layers, both required:

1. `TenantProvisioner::ensurePalestinePaymentDefaultsSeeded()` writes
   `sales.payment_methods.cashondelivery.active = 1` and an explicit
   `sales.payment_methods.moneytransfer.active = 0` — required because stock
   Bagisto's own config-fallback (`packages/Webkul/Payment/src/Config/payment-methods.php`)
   defaults **both** to `active => true` when no `core_config` row exists.
2. `TenantProvisioner::ensureUnsupportedPaymentGatewaysDeactivated()`
   (`Platform\Tenancy\Support\UnsupportedPaymentGateways`, a closed, reviewed
   7-code list) explicitly deactivates **every other bundled gateway** —
   Stripe, Razorpay, PayU, PhonePe, PayPal Smart Button, PayPal Standard,
   PayGlocal — at provisioning time. This closed a real gap (R78): each of
   these seven ships `active => true` as its own raw package default, and
   five of the seven satisfy their own `hasValidCredentials()` check trivially
   against non-empty **placeholder** credential strings; the two PayPal
   methods only check whether they are active — meaning every
   tenant provisioned before this fix had all seven genuinely selectable at
   real checkout with fake credentials that would fail against the real
   provider the moment a customer tried to use one.

**Existing tenants remediated**: `platform:tenants:enforce-cod-only-payments
{--tenant=*} {--dry-run}` (deliberately *enforces* policy rather than merely
seeding-if-missing, since a pre-existing `active=1` here was never a
merchant's deliberate choice). Production-verified across all 6 tenants that
existed at the time: a before-snapshot confirmed all 7 gateways `AVAILABLE`
via the real `Payment::getSupportedPaymentMethods()` checkout-enforcement
path on every one of them; the real remediation run changed exactly 47
`active` fields (no credential/title/image/sandbox field ever touched);
`palestine-mvp-check`'s real checkout listing went from
`["stripe","razorpay","payu","phonepe","paypal_smart_button","paypal_standard","payglocal"]`
to `[]`; a second run reported "already fully compliant" for all 6 tenants
(genuine, live-verified idempotency). Rashet Bhar (provisioned after this
fix shipped) received the correct posture automatically at provisioning —
confirmed directly during this platform's own first real order (TASK-PILOT-002).

Product decision recorded alongside the fix (DECISION_LOG C98): this is not
"ensure real credentials before exposing a gateway," it is "these methods
are not exposed at all for the current MVP, regardless of credentials" —
Cash On Delivery is the only supported storefront payment method today, by
policy, not by omission.

The provider-agnostic `PaymentProvider` contract built for merchant→
Technify billing (§5) is the ready abstraction point for a future regional
payment gateway. **No provider is selected. None is assumed.** This is a
fixed product decision (§0), not a gap.

---

## 10. Palestine / Arabic Localization

Covered functionally in §7. Genuine remaining gaps, separated from
upstream-Bagisto-inherited ones:

- **Platform Admin stays English** — deliberate, not a gap (internal
  operator tool, separate audience).
- **Country-name Arabic translation** — empty platform-wide, upstream
  Bagisto content gap, not Platform's to bulk-fix.
- **Phone-number format** — `Webkul\Core\Rules\PhoneNumber` accepts
  digits + optional leading `+` only; dashes/spaces rejected. Pre-existing,
  global, not Palestine-specific.
- **Exported (CSV/XLS) Sales/RMA grid timestamps still show raw UTC** — the
  live grid display itself is correct; only the export path is unaffected.
  See §7.

The original audit incorrectly listed the following as open, despite their
documented **closure and production verification (2026-08-30)** — not carried
forward here as open items: Admin Orders/Sales/RMA DataGrid timezone
rendering (R75), storefront state-dropdown locale-blindness (R74), and
per-tenant mail sender identity (R73). See §7/§11 for current state and
`RISK_REGISTER.md` for the full evidence chain.

What genuinely IS done and **PRODUCTION VERIFIED**: `[ar, en]` locale rows,
`ar.direction=rtl`, Arabic as `channels.default_locale_id`, RTL rendering
across Admin and storefront alike, real Arabic Admin UI text (human-
confirmed, e.g. dashboard title rendering as "لوحة التحكم"), Arabic catalog
content created through the real Admin UI, correct activation-email
reset-link host, and zero disturbance to pre-existing English-only tenants
(`pilot-smoke`/`test1`/`thaertest`/`mvp007-check`).

---

## 11. Email / Notifications

Bagisto's own `bagisto-dynamic-smtp` transport
(`Webkul\Core\Mail\Transport\DynamicSmtpTransport`): per-tenant SMTP
settings first (Merchant-Admin-configurable, Configuration → Emails),
falling back to central `.env`/`config('mail.mailers.smtp.*')`, throwing a
clean exception if neither is configured. A working central SMTP fallback
is a hard production-readiness requirement (`platform:production:check`'s
`Mail` row) — **PRODUCTION VERIFIED** configured and PASS.

Owner activation mail correctly renders in the tenant's own locale and
resolves the tenant's own domain for the reset link (a real fixed bug, R69
— `$tenant->run()` alone does not affect `app()->getLocale()`/URL root; both
are explicitly saved/set/restored around the send).

Order/shipment/invoice mail is `ShouldQueue` but runs **synchronously**
under `QUEUE_CONNECTION=sync` — checkout blocks for the duration of the
send. Acceptable at pilot volume; a real latency cost if volume grows.

**Per-tenant mail sender identity — PRODUCTION VERIFIED, R73 CLOSED
(2026-08-30).** Every tenant's order/shipment/invoice/refund mail and
password-reset notification now sends with the merchant's own store name as
the display name (`From: {Store Name} <support@technify.dev>`), not
"Technify." The underlying mechanism — `Webkul\Core\Core::getSenderEmailDetails()`
reading a tenant-scoped `core_config` row — already existed unmodified in
Bagisto; the gap was that the row was never populated. `TenantProvisioner::seedSenderIdentity()`
now writes it automatically at managed-onboarding time (idempotent, never
overwrites a merchant's own later change), and `platform:tenants:repair-sender-identity`
backfills any tenant provisioned before this fix existed. Writes go through
`Webkul\Core\Repositories\CoreConfigRepository::create()` (not a raw insert)
specifically so Prettus's cache-invalidation event fires — a real production
regression (stale cached "Technify" surviving a correct DB write) was found
and fixed live before this closed (DECISION_LOG C95). Independently
confirmed via a real, externally-delivered Gmail message:
`From: Palestine MVP Check Store <support@technify.dev>`.

**Deliberate, narrower scope (Model A only, not a gap):** only the sender
**display name** is ever seeded — `sender_email` is never written, so
delivery still goes out from the already-proven-deliverable
`support@technify.dev`. No Reply-To is set (the merchant's `owner_email` is
never verified at onboarding, so routing shopper replies to it was judged a
real risk not worth taking without that verification step existing first).
No per-tenant SMTP account/domain is provisioned. These are recorded product
decisions, not follow-up work.

---

## 12. Production Operations

| Mechanism | Status |
|---|---|
| Host-source-first deploy (`deploy.sh`), app+web rebuilt/recreated together | **PRODUCTION VERIFIED** |
| `APP_COMMIT` drift detection + live-served-asset proof (not just "file exists on disk") | **PRODUCTION VERIFIED** |
| Rollback pair (`:rollback` image tags + `ROLLBACK_COMMIT` marker) | **IMPLEMENTED, manual only** — image rollback alone is explicitly insufficient (never reverts the host source tree or DB migrations); this project implements/endorses no automatic rollback |
| Disk pre-flight gate + bounded/age-limited cleanup + daily cron backstop | **PRODUCTION VERIFIED** — proven flat (66%→66%, 29G free) across a real subsequent source-changing deploy |
| Daily local backup, offsite sync to Cloudflare R2, checksum-verified restore drill | **PRODUCTION VERIFIED** — proven via a real restore drill against disposable databases, and via this project's own TASK-PILOT-003A backup run |
| `platform:production:check` readiness gate (19 checks in current source) | **PRODUCTION VERIFIED** — gates `deploy.sh`'s own exit code |
| Structured/per-tenant logging | **PARTIAL** — per-tenant log files exist as a side effect of filesystem isolation (`storage/tenant{id}/logs`), not a deliberate observability design |
| Proactive alerting (a human is notified when a check fails) | **IMPLEMENTED, REVIEWED, HARDENED — NOT DEPLOYED, NOT PRODUCTION VERIFIED** (TASK-OPS-MONITORING-001, hardened by TASK-OPS-MONITORING-001A) — see below |

**Health checking vs. monitoring/alerting — narrowed, not yet closed.**
`platform:production:check` is thorough and battle-tested, but on its own
it is **pulled, never pushed**: nothing runs it on a schedule, and nothing
notifies an operator when it would report a failure. A stale backup, a
failed offsite sync, or a broken static asset only surfaces the moment
someone thinks to run the command by hand — this is exactly what happened
during this project's own TASK-PILOT-006 close-out, where a real WARN
(backup-directory permissions, resolved as a false alarm from the
invocation context — see that task's own report) would have gone unnoticed
without a manual run.

**`php artisan platform:production:monitor` now exists to close this** — a
small, opt-in alerting command wrapped around the exact same
`ProductionReadinessCheck::collectResults()` (no duplicated check logic,
console output/exit-code behavior unchanged and regression-tested).
Real/new/changed/reminder-due/recovered incidents are emailed to one
configured operator recipient through the existing central SMTP mailer;
duplicates are suppressed (keyed on check+status, never on volatile detail
text); a failed delivery leaves the incident pending for the next
scheduled run rather than being silently dropped; state persists to a
durable JSON file (a sibling of the existing backup volume); overlap is
prevented via a non-blocking file lock, bounded by an external `timeout`
wrapper at the process level (proven via a real subprocess test, not just
reasoned about).

**A first implementation pass (TASK-OPS-MONITORING-001) was reviewed before
any deployment was considered, and the review found real, since-fixed
defects (TASK-OPS-MONITORING-001A)** — this history is recorded here
deliberately, not smoothed over: a caught exception's raw message could
reach the notification/state boundary; a blank `.env` value for
`MONITOR_STATE_PATH` (exactly what `.env.example` shipped) silently
resolved to an unsafe path; an enabled-but-misconfigured recipient looked
identical to "all healthy" when no incident happened to exist; a monitor-
level collection failure erased every other check's tracked history and
never generated its own recovery notice; and the documented execution
bound relied on mail/HTTP timeouts alone, which do not cover every
internal call (Redis has no explicit client-level bound in this project's
source configuration). All five are fixed and covered by dedicated
regression tests (43 total: `tests/Feature/Platform/ProductionMonitorTest.php`,
`ProductionMonitorTimeoutTest.php`) plus one manual, local-only
verification against Mailpit (never a real send). This document does not
claim the implementation is now "fully reviewed, only activation remains"
— a second pass already found real problems once; see
`docs/architecture/production-deployment.md` "Monitoring / alerting" for
the full mechanism, configuration, the execution-user pre-flight
verification (not a default-to-root recommendation), the prepared
(not-installed) cron entry with its execution-bound wrapper, and the
corrected disable/rollback/log-retention steps.

**Explicitly NOT yet true**: this has never run against the real
production server, `MONITOR_ALERT_ENABLED` is unset everywhere, no cron
entry exists, and no real notification has ever been sent. The
"operational blind spot" this closes remains open in PRODUCTION until a
deliberate activation step (deploy → dry-run verify → enable → confirm one
real delivery → install the cron entry) is performed and evidenced — see
§18/§19.

---

## 13. Testing / Quality Gates

57 real Platform integration test files under `tests/Feature/Platform/` —
real MySQL, real HTTP requests, real Redis/queue workers where the claim
under test requires it (deliberately avoiding `sync`/`array` test defaults
that would mask the exact bugs being proven against). Seven CI workflows:
`pest_tests.yml`, `pint_tests.yml`, `admin_playwright_tests.yml` /
`shop_playwright_tests.yml` (sharded), `translation_tests.yml`,
`platform_tests.yml`, `docker_publish.yml`.

Coverage is genuinely strong for: tenant isolation (cache/storage/queue/
search/domain), provisioning, billing/webhook security, plan/subscription
lifecycle, backup manifest/retention/offsite-key-safety.

**Weaker spots, Technify-caused (not generic Bagisto gaps — do not count
upstream test gaps as Technify blockers):**
- The Platform `products.limit` TOCTOU race (R38, §15) remains open;
  concurrent product creation is not covered by a passing test proving
  strict limit enforcement. The upstream cart-add inventory race is a
  separate limitation (§15), not a Platform-caused test gap.
- No end-to-end Playwright coverage exercises Platform Admin itself (its
  tests are Pest/HTTP-level only); Bagisto's own Admin/Shop Playwright
  suites have no awareness Platform Admin exists.
- No automated, scheduled restore-verification test — restore was proven
  manually, twice, not wired into a recurring check.

---

## 14. Security / Isolation

Tenant isolation is treated as a security boundary, not a data-hygiene
nicety, and the evidence backs that framing: physical DB-per-tenant + Redis
tag-per-tenant + storage-root-per-tenant + queue-payload tenant-tagging +
Elasticsearch index-prefix-per-tenant, each independently proven with real
cross-tenant tests (HTTP requests, direct connection-name inspection, raw
cache-key inspection, raw queue-payload inspection) — not just behavioral
testing or code review.

**Billing security invariants** (§5/§9) are unusually thorough for a
sandbox-only feature: the client never supplies amount/currency
(structurally impossible, no such parameter exists), ownership is enforced
structurally (`PaymentOwnershipException`), webhook signatures are verified
using Stripe's own official mechanism, replayed events are idempotent via a
database-level `unique(provider, provider_event_id)` constraint, amount/
currency mismatches are rejected, and no "mark paid" endpoint exists
anywhere in the codebase.

**Known, open, disclosed gaps:**
- **`products.limit` TOCTOU race** (RISK_REGISTER R38) — two simultaneous
  product-creation requests can both pass a limit check and both insert.
  Deliberately left unfixed: no evidence of live/likely threat at current
  scale, and Bagisto's own `Configurable::create()` isn't transactionally
  atomic either, so a correct fix needs a lock spanning check+insert, real
  non-trivial coordination for a currently-speculative benefit.
  R39's bulk-import enforcement bypass is **RESOLVED** (TASK-ARCH-012A);
  it is not another open race. The upstream inventory race remains a
  separate limitation (§15).
- **`trustProxies(at: '*')`** — a pre-existing Bagisto setting; production
  sets `TRUSTED_PROXIES` to the real reverse-proxy IP, closing this at the
  app layer, but it is worth re-confirming on any infrastructure change.
- **No admin-action audit log** in Platform Admin (§4) — fine under a
  single-operator posture, a real gap the moment a second operator exists.
- **No rate limiting** on Platform Admin login beyond Laravel's stock
  throttle.

---

## 15. Known Technical Debt / Risks

| Item | Severity | Blocks onboarding more merchants? | Recommended timing |
|---|---|---|---|
| `products.limit` enforcement TOCTOU race (no lock across the count-check and the real product insert) | Low at current scale | No | Before public scale |
| **Known upstream inventory-availability concurrency limitation** — Bagisto's own `Simple::haveSufficientQuantity()` (the cart-add stock check) holds no row lock across the availability check and the reservation write; two simultaneous checkouts for the last unit of a managed-stock product could both pass the check. A genuinely separate finding from the `products.limit` race above (unrelated code path, unrelated package). Confirmed via source trace (TASK-PILOT-005); not a Platform defect — Bagisto's own architecture. Low risk at current single-merchant, low-order-volume scale. | Low at current scale | No | Before public scale |
| Proactive alerting implemented and locally tested but not yet deployed/activated in production (`platform:production:monitor`) | Medium — operational blind spot remains open in production until activated | No, but risky | Immediate next step (deploy + activate — see §12) |
| No tenant deletion/export lifecycle | Medium — offboarding has no supported path | Soft — matters once a merchant churns | Before 5 merchants |
| No Platform Admin RBAC / action audit log | Medium — fine for one operator | Yes, once staff grows | Before public scale |
| No governorate/city shipping-rate differentiation | Product gap, not a defect | No | Before public scale |
| No automated, scheduled backup restore-verification | Medium — restore proven manually only | No | Before 5 merchants |
| Global `APP_DEFAULT_COUNTRY=PS` (not per-tenant) | Low today, structural later | Only once a non-Palestine merchant onboards | Before public scale |
| Sales/RMA grid CSV/XLS exports still show raw UTC (grid display itself is correct) | Very low — cosmetic export-only gap | No | Later |

**Already closed in the cited 2026-08-30 evidence; stale audit findings corrected:**
per-tenant mail sender identity (R73), storefront state-dropdown
locale-blindness (R74), Admin Sales/RMA DataGrid timezone rendering (R75),
and unsupported bundled payment gateways left exposed with placeholder
credentials (R78) — see §7/§9/§11 for current state.

All items above are drawn from `RISK_REGISTER.md` (see R38, R51) and
this document's own production/documentation cross-check — none are newly
invented for this document.

---

## 16. Documentation / Decision Log

Documentation quality is unusually high for this project — nearly every
architecture doc under `docs/architecture/` explicitly distinguishes "Phase
0 speculation" from "IMPLEMENTED, verified live," carries task IDs, and
records dated production-verification passes. `RISK_REGISTER.md` and
`DECISION_LOG.md` are current and actively referenced by the architecture
docs (not stale side documents).

**Three documentation inconsistencies surfaced by this audit, disclosed rather than silently
resolved:**

1. **`localization.md` vs. `palestine-readiness.md`, timezone defaults.**
   `localization.md`'s "Explicit non-goals" section (TASK-MVP-012) states
   current defaults are "`Asia/Kolkata` timezone... unchanged" as a
   forward-looking non-goal at the time it was written.
   `palestine-readiness.md` (TASK-MVP-016, later) correctly implemented
   `Asia/Hebron` per-channel — the later document supersedes the earlier
   one's own statement, but `localization.md` itself was never retroactively
   corrected to note that.
2. **`subscriptions.md`'s own status header vs. its body.** The file's
   header states Phase 0's speculative sketch was fully superseded, yet
   retains original speculative `past_due`/`grace_period_ends_at` prose
   further down (clearly framed as historical, but a reader skimming past
   the header could miss that it no longer applies). Not a functional
   contradiction — a document-hygiene one.
3. **`palestine-readiness.md`'s "Explicit non-goals" section is stale
   (identified by TASK-PROJECT-STATE-002; corrected in CURRENT.md only).**
   That section still lists mail sender identity, the storefront
   state-dropdown locale gap, and the Admin Orders DataGrid timezone gap as
   deferred/future work (R73/R74/R75). All three were implemented and
   production-verified on 2026-08-30 (TASK-MVP-018/019, TASK-MVP-020,
   TASK-MVP-022) — `RISK_REGISTER.md` reflects this correctly; the
   architecture doc itself was never edited by any of those later tasks
   (confirmed via `git log -- docs/architecture/palestine-readiness.md`,
   last touched by the original TASK-MVP-016 commit only). This document
   (`CURRENT.md`) now reflects the correct, current state (§7/§9/§11); the
   architecture doc's own text should be corrected in a future
   documentation pass, but `RISK_REGISTER.md`/`DECISION_LOG.md` remain the
   authoritative record in the meantime.

No contradiction was found between any doc and actual production behavior
for the load-bearing claims this audit cross-checked directly against
production (tenancy, provisioning, payment posture, backups) — see §17.

---

## 17. Production Reality

Read-only, checked directly against the live production server as of this
audit (2026-08-31); no mutation performed.

| Fact | Value |
|---|---|
| Tenants | 7 total, all `Ready` — 6 internal test/dev fixtures (`arabic-mvp-check`, `mvp007-check`, `palestine-mvp-check`, `pilot-smoke`, `test1`, `thaertest`) + 1 real merchant (`rashet-bhar`) |
| Rashet Bhar health | status `Ready`, subscription `Active`, storefront/Admin both HTTP `200` for live products, COD-only payment posture confirmed automatic at provisioning (post-R78) |
| Rashet Bhar managed inventory | **Corrected and production-verified (TASK-PILOT-006, 2026-08-31).** Both approved products (RB-PILOT-001, RB-PILOT-002) now have `manage_stock = 1` with physical stock counts reflecting real, accounted-for quantity — Bagisto's stock-availability checks are enabled; the upstream concurrency limitation in §15 remains. Exact quantities are operational data, tracked in the tenant's own database, not repeated here. The controlled pilot can continue. |
| `platform:production:check` | Prior audit reported all checks PASS, 0 warnings (2026-08-31). Its stated count of 18 conflicts with the 19 checks registered in current source; no fresh production run was performed in this follow-up. |
| Backups | Latest local backup (7 tenants) checksum-verified via the daily 03:15 scheduled run; offsite R2 sync current, both PASS |

---

## 18. What Is Actually Missing

Prioritized, evidence-backed. Excludes: public signup, arbitrary catalog
enhancements, re-implementation of already-working Bagisto features,
electronic payment integration without a selected provider, and speculative
enterprise features.

| Item | Who needs it | Scope | Classification |
|---|---|---|---|
| Deploy + activate `platform:production:monitor` against real production (implemented, reviewed, hardened, tested locally, TASK-OPS-MONITORING-001/001A — see §12) | Operator | S (deploy + the documented multi-step activation procedure, including a pre-flight execution-user check and a real container recreation) | Scale readiness |
| Tenant deletion / export / offboarding lifecycle | Operator | L | Scale readiness |
| Platform Admin RBAC + action audit log | Operator | M | Scale readiness |
| Scheduled, unattended backup restore-verification | Operator | S | Scale readiness |
| Governorate/city-differentiated shipping rates | Merchant, customer | M | Product improvement |
| `products.limit` TOCTOU lock | Merchant (data integrity) | S | Deferred — low current risk |
| Bagisto's own cart-add inventory-availability lock (upstream, not Platform) | Merchant, customer | M (would need care around Bagisto's own architecture) | Deferred — low current risk |
| Per-tenant `APP_DEFAULT_COUNTRY` | Operator, future non-PS merchant | S | Deferred until first non-Palestine merchant |

**Removed as stale findings during reconciliation** (already completed and
production-verified — see §7/§9/§11): per-tenant mail sender identity,
storefront state-dropdown locale-awareness, Admin Sales/RMA DataGrid
timezone rendering, and explicit deactivation of unsupported bundled
payment gateways.

(S/M/L/XL = estimated implementation scope, smallest to largest.)

---

## 19. Recommended Next Tasks

Ordered by real value/risk, not novelty. Each is scoped to move Technify
from its current proven state toward supporting additional operator-managed
merchants safely.

### 1. Deploy and activate production monitoring & alerting — Size S
- **Status**: the mechanism is **built, locally tested, and has already
  been through one review-and-fix cycle** (TASK-OPS-MONITORING-001, then
  TASK-OPS-MONITORING-001A — five real defects found and fixed before any
  deployment was considered: secret disclosure at the notification
  boundary, a configuration-validation gap, incident-history loss through
  monitor-level failures, no real execution bound, and several activation-
  instruction inaccuracies; see §12). This is not claimed as a closed,
  fully-certified implementation — only that this specific list of
  problems is now fixed and regression-tested (43 tests). A production
  activation should still budget time for whatever the FIRST real run
  against production evidence surfaces, not be treated as a formality.
- **Why now**: still the single largest gap between "we'd notice a real
  problem" and "a merchant hits a failure before we do."
- **Outcome**: run the execution-user pre-flight check against real
  production (`docs/architecture/production-deployment.md`'s own
  documented command — do not default to root by imitation), deploy,
  `platform:production:monitor --dry-run` to verify safely, set
  `MONITOR_ALERT_ENABLED`/`MONITOR_ALERT_RECIPIENT` and recreate the `app`
  container (a bare `config:clear` is not sufficient — `env_file` values
  are fixed at container creation), send one `--test-notification` and
  confirm it reaches the operator inbox, then install the prepared
  (not-yet-installed) 5-minute cron entry with its `timeout`-wrapped
  execution bound.
- **Not building**: a full observability platform (Grafana/Sentry/etc.),
  or a second (non-application-based) uptime/heartbeat monitor — both
  remain genuinely out of scope; see `docs/architecture/production-
  deployment.md`'s own explicit "what this MVP does NOT detect" section
  for the boundary between the two.
- **Deploy expected**: yes.

### 2. Scheduled backup restore-verification — Size S
- **Why now**: backups are real and checksum-verified on write; restore has
  only ever been proven by hand, twice. A monthly automated restore-and-diff
  closes the one meaningful gap in an otherwise solid backup story.
- **Outcome**: a cron job that restores the newest backup into a disposable
  `bagisto_probe_*` database, sanity-checks row counts, and reports
  PASS/FAIL — reusing the exact drill already run manually.
- **Not building**: automated disaster recovery/failover — this is a
  verification check, not a recovery system.
- **Deploy expected**: yes.

### 3. Tenant offboarding (suspend-then-delete, with export) — Size L
- **Why now**: every current lifecycle path only ever adds tenants. The
  moment a merchant churns or a test fixture needs real cleanup, there is
  no supported path — a real operational/data-retention gap before
  onboarding more merchants who will eventually need it.
- **Outcome**: a Platform Admin action that exports the tenant's data
  (reusing the existing backup mechanism), then physically drops the
  tenant database, on explicit confirmation.
- **Not building**: self-service merchant account deletion — operator-only,
  matching every other lifecycle action on this platform.
- **Deploy expected**: yes.

### 4. Platform Admin action audit log — Size M
- **Why now**: currently one operator, one shared trust boundary. The
  moment a second person gets Platform Admin access (support hire,
  co-founder), "who suspended this tenant and why" has no answer. Cheap to
  build now, structurally awkward to retrofit later.
- **Outcome**: a simple append-only log of every state-changing Platform
  Admin action (suspend/reactivate/change-plan/provision/subscription
  transitions), visible on the tenant detail page.
- **Not building**: full RBAC/roles — a real follow-on, not bundled here.
- **Deploy expected**: yes.

### 5. Governorate/city-differentiated shipping rates — Size M
- **Why now**: the only remaining product-level (not purely operational)
  gap in the current top-5 — a real, evidenced need for a Palestinian
  multi-merchant SaaS, not a speculative feature. Today a merchant can only
  set one flat rate or one free-shipping toggle platform-wide, even though
  real delivery cost genuinely varies by governorate. Confirmed as the
  single real gap between current Bagisto shipping and what this market
  will eventually need (originally scoped and deliberately deferred in
  TASK-MVP-016).
- **Outcome**: Merchant-Admin-configurable shipping rates that vary by the
  customer's selected governorate (or, at minimum, a documented two-tier
  West Bank/Gaza split if a full 16-governorate table proves too heavy for
  a first pass).
- **Not building**: real-time carrier-rate APIs, weight/dimension-based
  rating, or anything beyond governorate-level differentiation — no
  evidence any current or near-term merchant needs more than that.
- **Deploy expected**: yes.

---

## 20. Executive Summary

**1. What is Technify today?**
A real, working database-per-tenant SaaS platform on top of unmodified
Bagisto, with a genuinely complete operator-managed onboarding, plan/
subscription, and billing-abstraction layer — running one real Palestinian
merchant in production and six internal test tenants.

**2. What parts are genuinely production-proven?**
Database, cache, and storage tenant isolation, the full onboarding-to-Ready
pipeline, Arabic/RTL + Palestine defaults, COD-only enforcement, and the
deploy/backup/production-check operational chain — all confirmed with real
HTTP requests and, for the DB/cache/storage isolation claims specifically,
direct connection/cache-key/file-path inspection, not just behavioral
testing. **Queue and search isolation are implemented and covered by real
integration tests (a live daemon worker, real payload/index-name
inspection) but are not exercised by the current production topology at
all** — production runs `QUEUE_CONNECTION=sync` (no worker process) and the
`database` search engine (Elasticsearch is configured-but-unused), so
neither mechanism's tenant-isolation code path actually runs against real
production traffic today. See §2.

**3. Can we onboard merchant #2 tomorrow?**
Yes, entirely through the supported operator UI — Create Merchant, confirm
Ready, activation email, done. Nothing structural blocks it.

**4. What would stop us from onboarding 5 merchants?**
Nothing structural. The honest risk is operational blindness — no
*activated* alerting means a real failure (a stale backup, a broken asset)
is still discovered by luck today, not by the system telling anyone. The
mechanism to close this is now built, tested, and has been through one
review-and-fix cycle (TASK-OPS-MONITORING-001/001A, §12/§19.1) but **not
yet deployed or turned on in production** — the remaining gap is
deployment/activation, not undesigned engineering, though activation
should still budget for whatever real production evidence surfaces on
first use. Recommended task 2 adds recurring proof that backups restore.

**5. What would stop us from onboarding 20 merchants?**
The single-operator trust model (no RBAC, no audit trail), no tenant
offboarding path, and the flat/free shipping ceiling for a genuinely
multi-governorate delivery reality — none of these are close blockers
today; all three become real at that scale.

**6. What is the single most important next task?**
Deploying and activating production monitoring & alerting (§19.1) —
**re-confirmed across three passes now, not assumed.** The underlying
reasoning has not changed: `platform:production:check` alone remains a
*pulled* check, and the one place operational discipline could fail
silently (a missed manual check) stays open in production until this is
actually turned on. What changed across TASK-OPS-MONITORING-001 and its
own review pass (TASK-OPS-MONITORING-001A) is that the mechanism moved
from undesigned work, to built-and-tested, to reviewed-and-hardened
against five real, since-fixed defects — not to "trivial, nothing left to
find." The remaining task is deploy, run the execution-user pre-flight
check, verify with `--dry-run`, enable (with a real container recreation,
not just `config:clear`), confirm one real delivery via
`--test-notification`, and install the prepared, execution-bounded cron
entry — genuinely the smallest *remaining* task on this list, without
claiming the implementation itself is beyond finding more problems.

**7. What should we deliberately NOT build yet?**
These fall into different categories — not one blanket "future work" bucket:
- **Public merchant signup** is not future work at all. It is **CLOSED BY
  DESIGN** (§0) — a settled product decision that stays off the roadmap
  entirely unless the product owner explicitly reconsiders it, not a
  deprioritized backlog item.
- **A specific electronic payment gateway/provider** (storefront checkout
  or merchant→Technify billing alike) is not scheduled future work either —
  it is explicitly **conditional** on the product owner first selecting a
  real local/regional provider and obtaining its official API documentation
  (§0). Nothing should be built or assumed in the meantime.
- **Governorate/city-differentiated shipping rates** (§8, §19.5) is a real,
  evidenced gap and a genuine backlog candidate — but it is **deferred**,
  not an approved immediate requirement; it should be picked up when a real
  merchant's delivery-cost reality actually demands it, not preemptively.
- **An observability/monitoring platform beyond scheduled alerting, and
  Platform Admin RBAC beyond a basic audit log** genuinely are not
  warranted at the current 7-tenant scale — §19's recommended tasks
  intentionally stop short of either.
- **A genuine, external uptime/heartbeat monitor** (something outside this
  host confirming the application is alive at all) is a real, disclosed
  gap `platform:production:monitor`'s own application-based design
  structurally cannot close (see `docs/architecture/production-
  deployment.md` "Monitoring / alerting" — "what this MVP detects, and
  what it structurally cannot"), but is deliberately not built here —
  it needs a real external service decision this document does not make
  for the operator.

---

*This document was produced by TASK-PROJECT-STATE-001, a read-only audit,
reconciled against current evidence by TASK-PROJECT-STATE-002 (2026-08-31)
— four sections (mail sender identity, storefront state localization,
Admin grid timezone rendering, COD-only payment enforcement) were updated
from NOT IMPLEMENTED/PARTIAL to PRODUCTION VERIFIED based on earlier work
whose closure evidence the original audit had missed (`RISK_REGISTER.md`
R73/R74/R75/R78, `DECISION_LOG.md` C95-C98) — and extended by
TASK-OPS-MONITORING-001 (2026-08-31), which implemented and locally tested
`platform:production:monitor`, then TASK-OPS-MONITORING-001A (2026-08-31),
a review-and-fix pass that found and corrected five real defects
(secret disclosure at the notification boundary, a configuration-
validation gap, incident-history loss through monitor-level failures, no
real execution bound, and several activation-instruction inaccuracies)
before any deployment was considered (§12/§19.1). Neither monitoring pass
deployed, activated, or sent any real notification at any point. No code,
production data, or configuration was modified in producing the first two
(project-state) passes; the third and fourth passes added Platform-owned
code (`packages/Platform/Tenancy`) and documentation only, with no
production access, deployment, or real notification at any point. Evidence
was drawn from `docs/architecture/*`, `docs/decisions/*`,
`RISK_REGISTER.md`, `DECISION_LOG.md`, `tests/Feature/Platform/*` (59
files as of this pass), `git log`, local Sail-container test runs
(including real subprocess tests proving the execution-bound mechanism),
and read-only passes against the live production
server (TASK-PROJECT-STATE-001/002 only).*
