# Project

A multi-tenant SaaS e-commerce platform built on top of Bagisto (open-source Laravel commerce). Each merchant ("tenant") gets an isolated Bagisto store — isolated database, isolated storage, isolated cache namespace, isolated queue context, isolated search index — provisioned on demand, reachable at its own subdomain, managed both by the merchant (their own Bagisto Admin) and by a platform operator (a separate central "Platform Admin" panel).

# Product Goal

Initial target vertical: **general retail** (electronics, clothing, accessories, toys, bookstores, general retail). NOT restaurants — restaurants are explicit future scope and must not shape current decisions.

Intended flow:

```
Platform Owner
    -> creates/manages Plans (entitlements, pricing)
Merchant
    -> becomes a Tenant (currently: CLI-provisioned only, no self-service yet)
    -> gets an isolated store (own DB, own subdomain)
    -> manages products/orders/customers/settings in their own Bagisto Admin
    -> storefront is publicly reachable at their subdomain
    -> operates under Plan entitlements/limits (e.g. product count)
    -> has a Subscription (lifecycle state, independent of billing)
    -> can optionally pay via Stripe (sandbox/reference only today)
```

Bagisto = the commerce engine. `packages/Platform/*` = the SaaS layer wrapped around it, with a hard rule: **`packages/Webkul/*` (Bagisto core) is never modified.**

# Technology

- Laravel 12 (12.62.0 confirmed live), PHP 8.3, MySQL 8.0, Redis (production cache/queue store).
- Bagisto 2.4.x, unmodified, in `packages/Webkul/*` (~42 packages, Concord + repository pattern).
- `stancl/tenancy` v3.10.1 — database-per-tenant isolation library (DB/cache/filesystem/queue bootstrappers all enabled and hardened).
- Custom SaaS code lives entirely in `packages/Platform/*`, its own PSR-4 namespace (`Platform\`), following the same "package = ServiceProvider + own Config/Routes/Models" shape Webkul packages use, but structurally separate so upstream Bagisto merges can never collide with it.
- Test framework: Pest 3. Platform's own suite lives in `tests/Feature/Platform/` (~22 files) plus a production-config "smoke" lane in `tests/Feature/PlatformSmoke/`.

# Architecture

**Central DB (`bagisto_central`) vs tenant DBs**: one central database holds `tenants`, `domains`, `plans`, `plan_features`, `plan_prices`, `subscriptions`, `payments`, `billing_provider_events`, `platform_users`, plus a central-only `sessions` table (used by Platform Admin's own login). Every tenant gets its own physical MySQL database, migrated with the full ~137-table Bagisto commerce schema (products, orders, customers, admins, its own `sessions` table, etc.) plus a couple of Platform-added tenant-schema tables. `platform:migrate:central` is the only supported way to run central migrations; a defense-in-depth guard (`PreventCentralMigrationOfTenantSchema`) blocks a bare `migrate` from accidentally sweeping Bagisto's schema into the central DB, and `CentralDatabaseWipeGuard` blocks `db:wipe`/`migrate:fresh`/`migrate:refresh`/`migrate:reset` from ever running against the real `bagisto_central` database (see INCIDENT-001 below).

**`packages/Platform/*` package boundaries and dependency direction** (one-way, enforced by absence of imports, not just convention):
```
Platform\Billing -> Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
Platform\Enforcement -> Platform\Plans (+ the one deliberate Webkul\Product dependency)
Platform\Admin -> Tenancy, Plans, Subscriptions, Billing (reads their central models directly)
```
- `Platform\Tenancy` — `Tenant` model/state machine, provisioning pipeline (`TenantProvisioner`), domain routing/access gate, suspension/reactivation, all isolation bootstrapping (cache/filesystem/queue/search retargeting listeners), the central-DB safety guards.
- `Platform\Plans` — `Plan`/`PlanFeature` models, `TenantEntitlements`/`TenantLimits` resolution, Plan CRUD, the tenant-facing "My Plan" page.
- `Platform\Subscriptions` — `Subscription` model/state machine (Trialing/Active/Canceled/Expired), `SubscriptionLifecycle` (the sole writer of `tenants.plan_id` via `TenantPlanAssignment`).
- `Platform\Billing` — `PlanPrice`/`Payment` models, provider-agnostic `PaymentProvider`/`WebhookVerifier` contracts, `StripePaymentProvider`/`StripeWebhookVerifier` reference adapter, checkout + webhook flow.
- `Platform\Enforcement` — the one package allowed to depend on a specific `Webkul\*` package; wires `products.limit` entitlement enforcement into real Bagisto extension points (`Product::creating`, a swapped-in import subclass).
- `Platform\Admin` — the central "Platform Admin" panel (guard `platform`, own login, own layout, zero Bagisto Admin asset dependency): tenant list/detail, provision/suspend/reactivate actions, plan/feature/price CRUD, subscription actions, recent-payments visibility.

**Tenant routing/isolation model**: subdomain-per-tenant. `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` is prepended to the `web` middleware GROUP itself (`bootstrap/app.php`), so every Shop/Admin/API route automatically resolves the tenant from the Host header and swaps DB/cache/filesystem/queue/search context before any Bagisto code runs — zero Bagisto core changes needed. `Platform\Tenancy\Http\Middleware\TenantAccessGate` sits at the very front of that same group and fail-closed rejects any non-`Ready` tenant (423 for Suspended, 503 for everything else including unrecognized future statuses) *before* the tenant DB connection is ever opened. Platform Admin routes run under a separate `platform` middleware group (the `web` group's own resolved middleware, minus `InitializeTenancyByDomain`) plus `EnsureCentralDomain` — structurally incapable of ever touching a tenant DB connection, not just by convention.

**Plans/entitlements**: `Plan` (code/name/is_active/sort_order) has many `PlanFeature` (feature_code/type[boolean|numeric|unlimited]/value). `TenantEntitlements`/`TenantLimits` resolve a tenant's current plan fresh on every read (no cache), so a plan change takes effect on the very next request. `tenants.plan_id` is a synchronized pointer, written exclusively by `TenantPlanAssignment::assign()` — the single source of truth for "what plan does this tenant have right now" that both manual admin changes and subscription-driven changes funnel through, so they can never disagree.

**Subscriptions**: one `Subscription` row per tenant (`unique(tenant_id)`), independent state machine from `TenantStatus` (canceling a subscription does NOT suspend the tenant; suspending a tenant does NOT touch its subscription — verified, not assumed). `SubscriptionLifecycle::start()/startTrial()/changePlan()/cancel...()` is the sole entry point; every plan-affecting transition calls `TenantPlanAssignment::assign()` internally.

**Billing**: `PlanPrice` (separate table from `Plan` — `billing_interval`/`interval_count`/`amount_minor`/`currency`/`is_active`) and `Payment` (historical snapshot of amount/currency at creation, never re-derived). `PaymentProvider`/`WebhookVerifier` are provider-neutral contracts; `StripePaymentProvider`/`StripeWebhookVerifier` are the one reference implementation (Stripe Checkout in `mode: 'payment'`, never `'subscription'` — Stripe never becomes a second subscription-lifecycle source of truth). Checkout → webhook → `WebhookEventProcessor` (one DB transaction, idempotent via a `unique(provider, provider_event_id)` row) → `PaymentLifecycle::markSucceeded()` → `SubscriptionLifecycle::changePlan()`. The browser success/cancel redirect NEVER marks a payment succeeded — only a verified webhook does.

# Current Capabilities

**Fully built and tested** (see `IMPLEMENTATION_PLAN.md`/`RISK_REGISTER.md` for the evidence trail):
- Tenant model/state machine, CLI-driven provisioning (`tenant:provision`), full isolation (DB/cache/filesystem/queue/search — each independently proven with real HTTP requests, real queued jobs, real Redis).
- Domain routing (subdomain → correct tenant DB), tenant access gate (suspended/pending/failed/etc. all correctly blocked before DB access).
- Platform Admin: login, dashboard, tenant list/detail, provision/retry/suspend/reactivate, plan/feature/price CRUD, subscription actions, recent payments.
- Tenant Admin: "My Plan" page (plan + subscription + upgrade link), real Bagisto Admin otherwise completely stock.
- Plans/entitlements/product-limit enforcement (Admin UI path AND bulk CSV import path both enforced).
- Subscriptions (Trialing/Active/Canceled/Expired, manual transitions only, no scheduler).
- Billing: Stripe sandbox checkout + webhook, signature-verified, idempotent, wired to `SubscriptionLifecycle::changePlan()`.
- CI: `pest_tests.yml` (upstream Bagisto lane, automatic) + `platform_tests.yml` (Platform suite + production-config smoke lane, manual `workflow_dispatch` to conserve GitHub Free-tier minutes).

**NOT built (the real MVP gaps)**:
- **No merchant self-service signup.** The only way a tenant comes into existence is `php artisan tenant:provision {id} --domain=...` on the CLI, or Platform Admin re-provisioning an *already-existing* tenant row. There is no public route, no signup form, no way for a prospective merchant to create their own store.
- **No verified shopper order flow under tenancy.** Every isolation proof in this codebase stops at "a repository call / the homepage renders correctly per-tenant." No test has ever driven a full browse → cart → checkout → order-placed flow against a tenant storefront. Bagisto's own checkout logic is untouched and presumably works, but this has never been exercised end-to-end the way e.g. the sessions-table gap (R33) was only found by actually rendering a real page.
- Merchant onboarding wizard, store branding, custom-domain workflow — none exist (subdomain-only, single domain per tenant via CLI).
- Production deployment posture: still Docker-local, `trustProxies(at: '*')` unaddressed (R20), no real domain/SSL strategy decided, Redis not provisioned anywhere real.

# Important Invariants

- Never modify `packages/Webkul/*` — verify via `git diff --name-only -- packages/Webkul/` (must be empty) after every task.
- All custom code lives in `packages/Platform/*`.
- Database-per-tenant; central DB never receives tenant commerce schema, tenant DBs never receive central schema (`platform:migrate:central` / `TenantProvisioner::ensureMigrated()` are the only supported paths).
- `bagisto:install` is **unsupported** against this project's central database — it wipes tables via internal `db:wipe`/`migrate:fresh` calls (see INCIDENT-001). `CentralDatabaseWipeGuard` blocks this at the `db:wipe`/`migrate:fresh`/`migrate:refresh`/`migrate:reset` level whenever the active database resolves to the literal, hardcoded name `bagisto_central`.
- Platform Admin routes structurally never initialize tenancy (by middleware-group construction, not by discipline).
- `tenants.plan_id` is written exclusively by `TenantPlanAssignment::assign()` — never set directly anywhere else.
- `SubscriptionLifecycle` and `TenantStatus`/`TenantLifecycle` are fully independent state machines with zero automatic coupling.
- Payment redirect (browser success/cancel URL) never proves payment success — only a signature-verified webhook does.
- Webhook/provider event processing is idempotent via a database-level `unique(provider, provider_event_id)` constraint, and wraps its entire effect (event row, Payment, Subscription plan change) in one DB transaction.
- Money is always an integer minor-unit value (`amount_minor`), never a float; currency is a plain normalized string, no hardcoded currency allowlist.
- Stripe is a reference/sandbox adapter only — not a production commitment. A real payment gateway (likely a Palestinian bank/provider) remains an open, deliberately deferred business decision; `PaymentProvider` exists specifically so that choice costs nothing architecturally later.
- Test fixtures: `PlatformIntegrationTestCase` disables `DatabaseTransactions` for its files — shared fixture tenants (`tenant-a`/`tenant-b` etc.) persist real state across test runs; never use a shared fixture tenant as a scratch/smoke-test subject (this has caused real, repeated regressions — see RISK_REGISTER R40).

# Local Development / Testing

- Docker: `bagisto-mysql-1` (root/`password`, app user `sail`/`password`), `bagisto-redis-1`.
- Tests run via: `MSYS_NO_PATHCONV=1 docker run --rm --network bagisto_sail -v /e/Dev/EStore/bagisto:/var/www/html -w /var/www/html bagisto-spike-php:8.3 vendor/bin/pest ...`
- Bootstrap a fresh environment: `platform:mark-installed` → `platform:migrate:central` → `platform:plans:seed` → (optionally) `tenant:provision {id} --domain=...`. **Never** `bagisto:install` against the real central DB.
- Full Platform suite: `vendor/bin/pest tests/Feature/Platform` (273 tests as of the last completed task, ~13 minutes).
- Production-config smoke lane (`SESSION_DRIVER=database`/`CACHE_STORE=redis`/`QUEUE_CONNECTION=redis`/`RESPONSE_CACHE_ENABLED=false` — catches bugs the default test config masks, e.g. R29/R33): `vendor/bin/pest -c phpunit.smoke.xml`.
- CI: `.github/workflows/pest_tests.yml` (automatic, upstream Bagisto lane, currently failing on a genuine pre-existing incompatibility between `bagisto:install` and this project's central-migration guard — documented, not fixed, deliberately). `.github/workflows/platform_tests.yml` (manual `workflow_dispatch` only, two jobs: full Platform suite + production-config smoke).
- No passwords/secrets in this file or in git; `.env` stays untracked (verify with `git ls-files .env` — must be empty).

# Current Git Baseline

Branch `2.4`, latest approved commit: `f7c0ab74f2e5b6a4c80efb6b17049a77ccb0ac25` ("feat(platform): add Stripe sandbox checkout and webhook payment confirmation"). 3 commits ahead of `origin/2.4`, not pushed. This is a point-in-time baseline — check `git log`/`git status` fresh rather than trusting this file if it's been a while.

# Completed Foundation

Nineteen-plus architecture tasks (TASK-ARCH-001 through TASK-ARCH-019, plus INCIDENT-001 recovery) built, in order: tenancy feasibility → tenant model/provisioning → domain routing → cache/filesystem/queue/search isolation → plan/entitlement domain → tenant admin plan display → platform admin foundation → product-limit enforcement (+ bulk-import bypass closure) → tenant suspension → full tenant readiness access gate → plan/feature CRUD in Platform Admin → provider-agnostic subscription domain → CI + production-config smoke testing (interrupted by INCIDENT-001, recovered, resumed) → CI stabilization → provider-agnostic billing domain + Stripe reference adapter → live Stripe sandbox checkout + webhook flow. Every task closed with a full evidence-based report (real HTTP requests, real MySQL, real Redis, real queued jobs — no mocking of this project's own infrastructure) before commit, and every commit was individually approved.

# Open Risks

(Full detail in `RISK_REGISTER.md`; only the still-genuinely-open items, reclassified by this review:)

- **R19** (Low, previously deferred as "tenant IDs are platform-generated, not user input") — **becomes MUST-FIX if/when self-service signup lands**: `MySQLDatabaseManager` interpolates the tenant database name into raw DDL with no identifier escaping. Safe today only because every tenant `id` is chosen by an operator/CLI. The moment a merchant-chosen subdomain/slug feeds tenant `id` creation (TASK-MVP-001), this needs input validation/character allowlisting.
- **R20** (High, documented not mitigated) — `trustProxies(at: '*')` trusts `X-Forwarded-Host` from anyone, and tenant resolution depends entirely on `$request->getHost()`. Must be restricted to the real proxy/LB IP range before any real external deployment.
- **DECISION_LOG "HUMAN DECISION REQUIRED" #3** — custom-domain/SSL strategy, unresolved. For MVP this can be narrowed to "wildcard cert for our own subdomains," deferring true custom-domain-per-tenant SSL.
- R1 (full-page response cache tenant-safety) — config-level exposure closed (`RESPONSE_CACHE_ENABLED=false` by default), underlying `Webkul\FPC` hasher never audited — safe as long as it stays off; must be re-verified before ever enabling it.
- R3 (PhonePe cache key) — structurally resolved via the same mechanism as everything else, never independently verified, and PhonePe (India-specific) is not a relevant gateway for this vertical/market — safe to defer indefinitely.
- R8/R12 (Octane-related) — Octane is dormant/unconfigured and the recommendation is to keep it off through MVP — safe to defer.
- R13 (Sanctum/API guard unused) — no API planned for MVP — safe to defer.
- R38 (product-limit count-then-create race) — documented, no evidence of concurrent-creation as a realistic pattern for a small pilot — safe to defer.

Everything else in the register (R1-R48, minus the above) is RESOLVED/CLOSED with live evidence.

# MVP Gaps

1. **No merchant self-service signup/tenant creation.** The single largest gap — see above.
2. **No verified shopper order flow.** Real risk of an undiscovered bug (matching the pattern of R32/R33, both found only once someone actually rendered a real page under real config).
3. No onboarding polish (initial admin credentials are whatever `BagistoDatabaseSeeder` hardcodes today — needs to become merchant-chosen).
4. No real-domain/production deployment posture (R20, SSL, Redis provisioning).

# Remaining MVP Roadmap

**TASK-MVP-001 — Merchant Self-Service Signup & Automatic Provisioning.** Why: the only way a tenant exists today is a CLI command. Outcome: a public signup form creates a tenant + domain, triggers `TenantProvisioner`, and gives the merchant real, self-chosen Bagisto Admin credentials (not a hardcoded seeded default). Must also resolve R19 (validate/allowlist merchant-chosen subdomain/slug characters) as part of this task. Depends on: nothing new architecturally, reuses `TenantProvisioner`/`SubscriptionLifecycle::start()`/default plan assignment as-is. Size: L. **Blocks MVP.**

**TASK-MVP-002 — Storefront Shopper Order End-to-End Verification (and fix whatever it finds).** Why: never proven, and this codebase's history shows exactly this kind of untested path hides real bugs (R32, R33). Outcome: a real Pest test (and any fixes it surfaces) proving browse → cart → checkout → real order placed, using a real Bagisto-provided payment method (e.g. Cash on Delivery), fully isolated per tenant. Depends on: an already-provisioned tenant (TASK-MVP-001 not strictly required first, could run in either order). Size: M. **Blocks MVP.**

**TASK-MVP-003 — Merchant Store Essentials & Sane First-Run Defaults.** Why: a merchant's first login needs to land in a coherent, ready-to-sell state. Outcome: confirmed sane default channel/currency/locale, no placeholder content, "My Plan"/upgrade path visible and correct. Likely small once 001/002 exist — may fold into 001's own acceptance criteria if scope allows. Depends on: TASK-MVP-001. Size: S. **Blocks MVP.**

**TASK-MVP-004 — Production Launch Readiness (Real Domain, Basic Hardening).** Why: testing with real external merchants means leaving Docker-local. Outcome: reachable at a real domain, `central_domains` set correctly, `trustProxies` restricted to the actual proxy (closing R20), a working HTTPS story for subdomains (wildcard cert is enough for MVP scope — resolves DECISION_LOG item 3's narrow case), real Redis provisioned. Size: M. **Blocks MVP** (for real external merchants; not blocking for continued internal/Docker testing).

**Post-MVP / explicitly deferred** (do not pull forward without a real merchant need):
- Real (non-Stripe) payment gateway integration — explicit product-owner decision: Palestine payment options are limited, pending bank/provider coordination, not a blocker.
- Refund execution, additional Stripe webhook event types, invoices, automatic renewal, failed-payment suspension policy.
- Custom-domain-per-tenant (BYO domain) workflow — subdomain-of-platform-domain is sufficient for MVP.
- Onboarding wizard / store branding UI beyond stock Bagisto Admin settings.
- Advanced RBAC, accounting dashboards, usage metering/analytics.
- Product-limit race-condition hardening (R38) — no evidence it's needed yet.
- Tenant data export/backup tooling beyond the manual `mysqldump` process used in INCIDENT-001 recovery — worth doing before scaling past a small pilot, not before it.
- Octane adoption.

# Deferred Post-MVP Work

See the "Post-MVP / explicitly deferred" list immediately above — treat that list as authoritative for what NOT to pull into MVP scope without a concrete, real merchant-driven reason.

# Next Task

**TASK-MVP-001 — Merchant Self-Service Signup & Automatic Provisioning.** This is the single biggest gap between "architecturally complete" and "a merchant can actually use this," and every other MVP task is more useful once it exists (there's no one to onboard onto a store, and no store to test a shopper order against, without a way to create a tenant that isn't an engineer typing a CLI command).

# How To Continue In A New Chat

Read this file first, then `IMPLEMENTATION_PLAN.md`, `DECISION_LOG.md`, and `RISK_REGISTER.md` as needed for historical detail on any specific past task. Verify code before implementation — this file and those documents describe the state as of the last review; don't rely solely on historical task reports without checking the current repository (`git log`, `git status`, and the actual files) first. Never modify `packages/Webkul/*`. All new SaaS code goes in `packages/Platform/*`. Real integration tests only, no mocking of this project's own database/filesystem/cache/queue (Stripe SDK's own official test seam for mocking Stripe's network layer is the one accepted exception). Each task ends with a full evidence-based report and stops for explicit approval before commit — this project has never committed or pushed without it, and every task in this document set followed that pattern exactly.
