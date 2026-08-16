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

**Built since the section above was last accurate (TASK-MVP-001/002/003/004A)**:
- **Merchant self-service signup — DONE (TASK-MVP-001).** `packages/Platform/Signup`: public `/join` form creates a Tenant + Domain, triggers `TenantProvisioner`, gives the merchant a real self-chosen owner email/password (never a hardcoded seeded default), with signed-URL retry authorization for provisioning failures. R19 resolved at this exact input boundary (slug allowlist regex).
- **Shopper order flow — VERIFIED end-to-end (TASK-MVP-002).** Real browse → cart → checkout → order-placed flow proven against a real tenant storefront (Cash on Delivery), fully tenant-isolated, inventory reservation confirmed, newly-provisioned-merchant usability confirmed (no provisioning gap).
- **First-run merchant readiness — VERIFIED (TASK-MVP-003).** Bagisto Admin confirmed already sufficient for all core merchant capability; one real provisioning gap found and fixed (channel hostname defaulting to the central URL instead of the tenant's own domain); small welcome banner added on first post-signup login.
- **Production application hardening — DONE (TASK-MVP-004A).** `TRUSTED_PROXIES` (closes R20 at the application level), `PLATFORM_CENTRAL_DOMAINS` (explicit, separate from `PLATFORM_BASE_DOMAIN`), a clean Stripe-unavailable checkout UX fix, `platform:production:check`, and `docs/architecture/production-deployment.md`. Actual infrastructure (real domain/server/DNS/TLS/SMTP/Redis, backup scheduling) remains TASK-MVP-004B, not yet started.

**Still NOT built**:
- Merchant onboarding wizard beyond the one-time welcome banner, store branding beyond stock Bagisto Admin settings, custom-domain-per-tenant workflow — none exist (subdomain-only, single domain per tenant).
- **Actual production infrastructure (TASK-MVP-004B)**: no real domain/server has been chosen or provisioned, no real DNS/TLS/SMTP/Redis exists anywhere outside Docker-local, no backup script/schedule exists yet (policy documented, not implemented).

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

Branch `2.4`, latest approved commit as of TASK-MVP-004A: `cbda674cd6a8dc7c26ba89040c706004400233eb` ("feat(platform): improve merchant first-run store readiness", TASK-MVP-003). 7 commits ahead of `origin/2.4`, not pushed (TASK-MVP-001/002/003 plus earlier work). TASK-MVP-004A's own changes are implemented and tested but NOT YET COMMITTED as of this review - pending explicit approval. This is a point-in-time baseline — check `git log`/`git status` fresh rather than trusting this file if it's been a while.

# Completed Foundation

Nineteen-plus architecture tasks (TASK-ARCH-001 through TASK-ARCH-019, plus INCIDENT-001 recovery) built, in order: tenancy feasibility → tenant model/provisioning → domain routing → cache/filesystem/queue/search isolation → plan/entitlement domain → tenant admin plan display → platform admin foundation → product-limit enforcement (+ bulk-import bypass closure) → tenant suspension → full tenant readiness access gate → plan/feature CRUD in Platform Admin → provider-agnostic subscription domain → CI + production-config smoke testing (interrupted by INCIDENT-001, recovered, resumed) → CI stabilization → provider-agnostic billing domain + Stripe reference adapter → live Stripe sandbox checkout + webhook flow.

Followed by the MVP track: TASK-MVP-001 (self-service signup/provisioning) → TASK-MVP-002 (shopper order end-to-end verification) → TASK-MVP-003 (merchant first-run store readiness) → TASK-MVP-004 (read-only production-launch-readiness investigation) → TASK-MVP-004A (application production hardening - `TRUSTED_PROXIES`/R20, central-domain config, Stripe-unavailable UX, `platform:production:check`, production-deployment runbook).

Every task closed with a full evidence-based report (real HTTP requests, real MySQL, real Redis, real queued jobs — no mocking of this project's own infrastructure) before commit, and every commit was individually approved.

# Open Risks

(Full detail in `RISK_REGISTER.md`; only the still-genuinely-open items, reclassified by this review:)

- **R19** — **RESOLVED (TASK-MVP-001)**: self-service signup landed with a slug allowlist regex (`^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`) validated before any tenant `id`/database name is derived - closed for every current tenant-creation path.
- **R20** — **CLOSED AT THE APPLICATION LEVEL (TASK-MVP-004A)**: `TRUSTED_PROXIES` (env-driven, falls back to `'*'` only when unset) replaces the hardcoded `trustProxies(at: '*')`. The real production proxy IP value is still an infrastructure decision (TASK-MVP-004B).
- **DECISION_LOG "HUMAN DECISION REQUIRED" #3** — **PARTIALLY RESOLVED (TASK-MVP-004A)**: wildcard TLS for the platform's own subdomains is confirmed sufficient for MVP (`docs/architecture/production-deployment.md`). True custom-domain-per-tenant SSL remains open/post-MVP.
- **R52** (new, TASK-MVP-004A, CLOSED) — `CheckoutController::store()` could expose a raw, unhandled `MissingProviderCredentialsException` when Stripe is intentionally unconfigured (the recommended pilot posture) - fixed via a global exception-render handler.
- R1 (full-page response cache tenant-safety) — config-level exposure closed (`RESPONSE_CACHE_ENABLED=false` by default), underlying `Webkul\FPC` hasher never audited — safe as long as it stays off; must be re-verified before ever enabling it.
- R3 (PhonePe cache key) — structurally resolved via the same mechanism as everything else, never independently verified, and PhonePe (India-specific) is not a relevant gateway for this vertical/market — safe to defer indefinitely.
- R8/R12 (Octane-related) — Octane is dormant/unconfigured and the recommendation is to keep it off through MVP — safe to defer.
- R13 (Sanctum/API guard unused) — no API planned for MVP — safe to defer.
- R38 (product-limit count-then-create race) — documented, no evidence of concurrent-creation as a realistic pattern for a small pilot — safe to defer.
- R49 (long-suite-duration real-worker/queue-draining test flake) — development/test-infrastructure only, reproduced and root-caused as environment-timing-related, not a Platform/tenancy defect — safe to defer.
- R50 (abandoned `booted()`-callback middleware-attachment technique for one specific route) — development-only, the shipped welcome-banner feature does not depend on it — safe to defer.
- R51 (Vite manifest gap for two Admin-theme placeholder assets, TASK-MVP-003) — **re-checked fresh during the MVP final readiness review (2026-08-16) and found NOT REPRODUCIBLE**: both `src/Resources/assets/images/product-placeholders/front.svg` and `.../icon-add-product.svg` resolve correctly through the real, currently-committed `public/themes/admin/default/build/manifest.json` (verified live via a direct `Vite::asset()` call in the same Docker test environment - no exception). Whatever caused the original observation is no longer present; recommend closing outright rather than carrying as an open risk. If it resurfaces during TASK-MVP-004B, a normal `npm run build` for the Admin theme is the correct fix, not an application code change.

Everything else in the register (R1-R52, minus the above) is RESOLVED/CLOSED with live evidence.

**MVP final readiness review (2026-08-16)**: no application-level blocker found. See the "MVP FINAL READINESS REPORT" delivered at this checkpoint for the full journey-by-journey evidence (merchant onboarding, merchant operations, shopper checkout, Platform Owner - all READY FOR PILOT), the mail-under-`sync` investigation (order placement succeeds independently of mail delivery - `Webkul\Shop\Listeners\Base::prepareMail()` and `Webkul\Shop\Listeners\Order::afterCreated()` both already catch and log any mail exception, including an unconfigured-SMTP `RuntimeException`, without affecting the HTTP response - confirmed via direct source reading of Bagisto's own unmodified code, a structural guarantee, not merely observed behavior), and the R51 re-check above. **Application MVP feature scope is now frozen for the pilot** - see "Post-MVP / explicitly deferred" below for what requires a new product decision before being pulled back in. Remaining work is entirely TASK-MVP-004B (infrastructure/deployment), not further application development.

# MVP Gaps

1. ~~No merchant self-service signup/tenant creation.~~ **DONE (TASK-MVP-001).**
2. ~~No verified shopper order flow.~~ **DONE (TASK-MVP-002).**
3. ~~No onboarding polish.~~ **DONE (TASK-MVP-003 - welcome banner; owner-chosen credentials already landed in TASK-MVP-001).**
4. **Real-domain/production deployment posture — application half DONE (TASK-MVP-004A: R20 closed, central-domain config, Stripe-unavailable UX, production-check command, runbook). Actual infrastructure (TASK-MVP-004B) not started: no real domain/server/DNS/TLS/SMTP/Redis exists anywhere outside Docker-local.**

**Application MVP development is complete.** The 2026-08-16 final readiness review found zero application-level blockers across every real MVP journey (merchant onboarding, merchant operations, shopper checkout, Platform Owner). Everything remaining is TASK-MVP-004B infrastructure/deployment work, not further coding.

# Remaining MVP Roadmap

~~TASK-MVP-001 — Merchant Self-Service Signup & Automatic Provisioning.~~ **DONE, committed, approved.**

~~TASK-MVP-002 — Storefront Shopper Order End-to-End Verification.~~ **DONE, committed, approved.**

~~TASK-MVP-003 — Merchant Store Essentials & First-Run Readiness.~~ **DONE, committed, approved.**

~~TASK-MVP-004 — Production Launch Readiness.~~ **Investigated (read-only), then split (see below).**

**TASK-MVP-004A — Application Production Hardening.** Why: close what's fixable purely at the code/config layer without needing a real server/domain yet. Outcome: `TRUSTED_PROXIES` (closes R20 at the application level), `PLATFORM_CENTRAL_DOMAINS` (explicit, separate from `PLATFORM_BASE_DOMAIN`), a clean Stripe-unavailable checkout UX (no more raw unhandled exception), `platform:production:check`, `docs/architecture/production-deployment.md`. Depends on: nothing new architecturally. Size: S. **DONE - pending commit approval as of this review.**

**TASK-MVP-004B — Actual Pilot Deployment.** Why: TASK-MVP-004A hardens the application but does not deploy it anywhere reachable by a real merchant. Outcome: a real server, real domain, real DNS, wildcard TLS, real Redis, a working SMTP fallback, a production `docker-compose.yml` (or equivalent) including the currently-missing `storage/` persistent volume, backup script + scheduling, first-production-bootstrap executed for real. Depends on: TASK-MVP-004A (done) + human decisions (real domain, hosting provider/server, whether real mail must work for the first external pilot merchant). Size: not meaningfully estimable in engineering-task terms - gated on infrastructure/ops execution, not code. **Blocks MVP** (for real external merchants; not blocking continued internal/Docker testing).

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

**TASK-MVP-004B — Actual Pilot Deployment**, once TASK-MVP-004A is committed/approved and the human decisions it depends on (real domain, hosting provider/server, whether real mail must work for the first external pilot merchant) are made. Until then, this platform is architecturally MVP-complete but only reachable via Docker-local - no real external merchant can use it yet.

# How To Continue In A New Chat

Read this file first, then `IMPLEMENTATION_PLAN.md`, `DECISION_LOG.md`, and `RISK_REGISTER.md` as needed for historical detail on any specific past task. Verify code before implementation — this file and those documents describe the state as of the last review; don't rely solely on historical task reports without checking the current repository (`git log`, `git status`, and the actual files) first. Never modify `packages/Webkul/*`. All new SaaS code goes in `packages/Platform/*`. Real integration tests only, no mocking of this project's own database/filesystem/cache/queue (Stripe SDK's own official test seam for mocking Stripe's network layer is the one accepted exception). Each task ends with a full evidence-based report and stops for explicit approval before commit — this project has never committed or pushed without it, and every task in this document set followed that pattern exactly.
