# Merchant Onboarding

## Managed-only onboarding for the initial commercial/pilot phase — IMPLEMENTED (TASK-MVP-007)

**This is a deliberate product decision, not a temporary hack.** Public, anonymous self-service signup (`GET`/`POST /join`, [provisioning.md](provisioning.md)'s "Merchant self-service signup" section, TASK-MVP-001, hardened by TASK-MVP-006's Cloudflare Turnstile) is **disabled by default in production** (`PUBLIC_SIGNUP_ENABLED=false`, `config('platform.signup.enabled')`). Merchants are onboarded manually/curated by the platform operator through Platform Admin's own "Create Merchant" action instead.

**Reasons** (recorded here so a future reader does not mistake this for an unfinished feature):

- Limited initial VPS/server resources — every tenant is a real, permanent cost (a physical MySQL database, migrations, seeded schema, local + offsite R2 backups, ongoing ops overhead), not a free row.
- Real humans, not just bots, can create abandoned/never-used stores even past Turnstile's bot defense — Turnstile solves the abuse problem, not the "well-intentioned but unused signup" problem.
- The expected initial merchant count is small enough that deliberate, curated onboarding is the right operational posture — not a scaling bottleneck yet.
- Nothing about the public-signup implementation is removed, rewritten, or degraded — it is fully preserved, fully tested, and can be re-enabled later behind the same flag with zero code change (see "Re-enabling public signup later" below).

**The flag**: `PUBLIC_SIGNUP_ENABLED` (`.env`) → `config('platform.signup.enabled')` (`config/platform.php`), defaulting to `false`. Gates `GET`/`POST /join` via `Platform\Signup\Http\Middleware\EnsurePublicSignupEnabled` (`packages/Platform/Signup/src/Http/Middleware/EnsurePublicSignupEnabled.php`), applied only to those two routes in `packages/Platform/Signup/src/Routes/signup-routes.php`.

**When disabled, both routes genuinely 404** — not a "signup disabled" page, not a `403`. `EnsurePublicSignupEnabled::handle()` calls `abort(404)` before the controller, validation, or `TurnstileVerifier` ever run, for both the `GET` (form) and `POST` (submission) routes — a disabled flag has zero observable difference from a route that was never registered at all, and zero provisioning side effects. See `tests/Feature/Platform/PublicSignupFlagTest.php` for the automated proof, including that Turnstile is never even consulted while disabled.

**The signed retry flow is explicitly NOT gated by this flag.** `GET`/`POST /join/retry/{tenant}` (`Platform\Signup\Http\Controllers\SignupRetryController`, `signed` middleware) remains fully reachable regardless of `PUBLIC_SIGNUP_ENABLED` — it is cryptographically authorized (a time-limited signed URL) and tied to an already-created tenant from a prior, already-authorized attempt (either a public signup made while the flag was briefly on, or a Platform-Admin-managed creation whose provisioning failed), a materially different risk profile from anonymous public registration. An unsigned retry URL is still rejected (`403`) regardless of the flag — the `signed` middleware itself is never weakened by this task. See `signup-routes.php`'s own docblock and `PublicSignupFlagTest.php` tests 5-6.

## Platform Admin managed creation — IMPLEMENTED (TASK-MVP-007)

`Platform\Admin\Http\Controllers\TenantController::create()`/`store()`/`resendActivation()` are the initial commercial/pilot phase's real onboarding entry point. **Reuses `Platform\Signup\Services\MerchantOnboarding::register()` verbatim** — the exact same Tenant+Domain creation transaction, the exact same `TenantProvisioner::provision()` call, the exact same success/failure contract `/join` itself uses. There is deliberately no second, parallel provisioning implementation.

**Form fields**: store name, tenant slug, owner first name, owner last name, owner email, initial plan. **Deliberately no password field** — see "Credential mechanism" below. `slug`/`owner_email` validation is shared, not duplicated, via `Platform\Signup\Support\SignupValidationRules` (extracted from `SignupController`'s own inline rules in this same task) — used verbatim by both `/join` and `TenantController::validatedMerchant()`, so the two entry points can never drift into two different acceptance policies for the same fields.

**Plan assignment**: the operator-selected plan is started via `Platform\Subscriptions\Services\SubscriptionLifecycle::start()` — the same service/rules `TenantController::changePlan()` already uses (an inactive plan is rejected the identical way, before any provisioning side effect) — called from `MerchantOnboarding::register()` *before* `TenantProvisioner::provision()` runs, so `ensureInitialSubscriptionStarted()`'s own pre-existing `if ($tenant->plan_id !== null) return;` guard correctly treats the plan as already assigned and never double-starts a second subscription. See `MerchantOnboarding`'s own docblock.

**Store name**: applied to `channel_translations.name` (Bagisto's actual channel-display-name column — not a direct `channels` column) after successful provisioning, inside `MerchantOnboarding::attempt()`, mirroring the identical `$tenant->run()` + `DB::table(...)->update()` shape `TenantProvisioner::ensureChannelHostnameCorrect()` already establishes for the sibling `channels.hostname` correction.

**Synchronous, like `/join`**: no queue, no polling UI — the operator's "Create Merchant" submission blocks for the real provisioning duration (~20-30s, the same latency `/join` itself has, TASK-MVP-006's own production-proven observation). Converting to async provisioning is explicitly out of scope for this task (see "Explicit non-goals" below). The create form disables its submit button on submit (`create.blade.php`) purely to discourage an accidental double-click; the real safety net is the same one `/join` already has — a duplicate slug is rejected by `SignupValidationRules::slug()` before any provisioning side effect, regardless of how the duplicate request arrived.

## Credential mechanism — the operator never sees a merchant's password

Platform Admin's "Create Merchant" form asks for no password. The flow:

```
Platform Admin submits "Create Merchant"
  → server generates a cryptographically-random temporary password (Str::password(40))
  → temporary password exists only in the current PHP call stack of TenantController::store()
  → MerchantOnboarding::register(...) — the SAME method /join calls
  → tenant Admin row (id=1) created with the hashed password, owner's real name/email
  → temporary plaintext value discarded immediately (never logged, cached, queued, or persisted anywhere)
  → real, already-production-proven Bagisto Admin password-reset flow triggered
    (Platform\Signup\Services\OwnerActivationMailer → Password::broker('admins')->sendResetLink())
  → merchant receives an email with a real, signed reset link
  → merchant sets their OWN password as their first action
```

No custom activation-token system, no manually-constructed reset URL — `OwnerActivationMailer` reuses `Illuminate\Auth\Notifications\ResetPassword`/`PasswordBroker` exactly as the real `/admin/forget-password` form already does (the identical mechanism TASK-MVP-004 proved end-to-end against real Zoho SMTP delivery in production). This closes the one option that would have required inventing new authentication architecture — reusing what already exists and is already proven was the explicitly approved path.

**Failure semantics — provisioning success and activation-email delivery are two separate concerns.** A tenant that provisions successfully is `Ready` regardless of whether the activation email could be sent — `OwnerActivationMailer::send()` never throws (any failure is caught and reported via `report()`, returning `false`) and a failed send never rolls back, downgrades, or otherwise disturbs an already-`Ready` tenant. On success, Platform Admin sees a clean confirmation; on email failure, it sees "store was created successfully, but owner activation email could not be sent" plus a **Resend activation email** action (`TenantController::resendActivation()`) that re-triggers `OwnerActivationMailer` only — it never calls `TenantProvisioner` again, and is refused for a tenant that is not yet `Ready` (there is no owner Admin row to send a reset link for otherwise). Note the real `PasswordBroker`'s own recently-created-token throttle (`config('auth.passwords.admins.throttle')`, 60s) applies here exactly as it would for any admin's own forgot-password request — an immediate resend within that window is correctly throttled, not a bug.

## Arabic-first provisioning defaults — IMPLEMENTED (TASK-MVP-012)

Both onboarding entry points (`/join` and Platform Admin's "Create Merchant") call the same `MerchantOnboarding::register()` → `TenantProvisioner::provision()` pipeline described above, which now includes two additional idempotent steps (`ensureArabicLocaleSeeded()`, `ensureArabicThemeContentSeeded()`) that make Arabic the tenant's default storefront/Admin locale automatically, with English preserved as a secondary locale. This is a provisioning-pipeline addition, not a second pipeline — neither entry point needed any change of its own. Full design, rationale, and the Merchant-Admin/Platform-Admin locale-boundary mechanism: see `docs/architecture/localization.md`.

## Production readiness

`php artisan platform:production:check` reports a `Public signup` row (`Platform\Tenancy\Console\Commands\ProductionReadinessCheck::checkPublicSignup()`), separate from the pre-existing `Signup abuse protection` (Turnstile) row:

- Disabled (the production default/posture) → **PASS** `"disabled (managed onboarding)"`. This is the expected pilot-phase state, not a gap to flag.
- Enabled + Turnstile enabled and correctly configured → **PASS**.
- Enabled + Turnstile disabled, or enabled with a missing site/secret key → **FAIL**. If public signup is ever turned on with broken abuse protection, readiness must clearly fail, never silently pass — this row reuses the same underlying key-presence check `checkSignupAbuseProtection()` uses (`signupAbuseProtectionStatus()`) rather than re-deriving a second copy of that validation matrix.

## What remains deferred (explicitly, not accidentally)

- **Turnstile remains fully implemented and ready.** TASK-MVP-006's abuse protection (`TurnstileVerifier`, the widget, throttling, the full test matrix) is completely untouched by this task and is exercised in full whenever `PUBLIC_SIGNUP_ENABLED=true` — see `tests/Feature/Platform/SignupTurnstileTest.php`.
- **Email verification** for a new merchant's owner address remains deferred — neither `/join` nor managed creation verifies ownership of the supplied email beyond it being syntactically valid and not already in use.
- **Async/queued provisioning** remains deferred — both `/join` and managed creation are synchronous by deliberate, repeated decision (TASK-MVP-006, reaffirmed here).
- **Public signup redesign** is out of scope — the existing `/join` implementation is preserved byte-for-byte behaviorally when the flag is on; this task only adds a gate in front of it and a second, admin-facing entry point into the same underlying pipeline.

## Re-enabling public signup later

Flipping `PUBLIC_SIGNUP_ENABLED=true` requires no code change — but doing so in production requires a deliberate readiness review first, covering (at minimum): server/database capacity for uncurated growth, a tenant lifecycle/cleanup policy for abandoned or never-activated stores (none exists today — see [provisioning.md](provisioning.md) and the deferred items above), abuse-control posture beyond Turnstile if volume grows, and commercial/pricing policy for self-served signups. This document exists so that review has a clear, honest starting point rather than rediscovering why the flag was off in the first place.
