# Localization / Arabic-First Merchant & Storefront Experience

## Product decision — IMPLEMENTED (TASK-MVP-012)

**Merchant and shopper experiences are Arabic-first; the internal Platform Admin operator panel stays English.** The initial target market is Arabic-speaking merchants/customers (Palestine and the wider Arabic-speaking region). Every newly provisioned tenant gets Arabic (`ar`, RTL) as its default storefront and Merchant Admin language automatically, with English preserved as an available secondary locale — no manual switching required. This is a deliberate product decision (see `DECISION_LOG.md` C84), not a translation patch.

**Explicitly NOT part of this decision**: Platform Admin (`/platform/*`, the internal Technify operator panel) remains English/LTR unconditionally — it is a separate audience with no reason to change. Existing production tenants are never auto-migrated. Palestine-specific defaults (timezone/currency/country) are a separate, deliberately deferred future task — see "Explicit non-goals" below.

## What already existed vs. what this task added

The audit behind this task found Bagisto's own upstream support unusually complete — most of the work was *activating* dormant capability, not building new capability, and **zero `packages/Webkul/*` files were modified**:

- **Arabic translations**: already shipped, structurally 1:1 with English (`packages/Webkul/{Admin,Shop,Core,...}/src/Resources/lang/ar/app.php`), for every package that has any `lang/` directory at all.
- **RTL support**: already fully wired and dormant. `locales.direction` is a real DB column (`enum('ltr','rtl')`); both Shop's and Admin's own root Blade layouts already emit `<html lang="{{ app()->getLocale() }}" dir="{{ core()->getCurrentLocale()->direction }}">` (including Admin's pre-auth login layout); RTL-aware CSS and Tailwind's built-in `rtl:`/`ltr:` variants are already used throughout both themes. None of this needed to be built — it activates automatically the instant the active locale's `direction` is `rtl`.
- **Storefront locale resolution**: already fully dynamic and tenant-safe. `Webkul\Shop\Http\Middleware\Locale` resolves per request (`?locale=` query → `session('locale')` → the current channel's `default_locale`), calls `app()->setLocale()`, and persists the choice to session. Untouched by this task.
- **Catalog/content locale architecture**: already locale-clean with no hardcoded English dependency. Product content is EAV (`product_attribute_values` → `product_flat`, one flat row per product×channel×locale, built only for locales the channel actually offers); Category/CMS use Astrotomic Translatable (`category_translations`/`cms_page_translations`, one row per locale). No English fallback/requirement exists anywhere in `ProductForm`/`ProductRepository` — every locale default resolves dynamically via `core()->getDefaultLocaleCodeFromDefaultChannel()`, i.e. whatever the store's own configured default is.
- **URLs**: no locale prefix exists anywhere in Shop's routes (`{locale}` never appears) — locale is entirely query/session/channel-driven. Making Arabic the default channel locale changes no existing canonical URL.

**The one genuine gap, and the one this task actually had to close**: **Bagisto Admin has no locale-selection mechanism of its own at all.** Unlike Shop, there is no `Locale` middleware for Admin, no `locale` column on `admins`, nothing. Admin's active locale was simply whatever the single, process-wide `config('app.locale')` happened to be at boot — shared by *every* tenant **and** Platform Admin, since this is one shared codebase/process. This is exactly why blindly setting `APP_LOCALE=ar` in the shared `.env` was rejected as a solution: it would have made Platform Admin Arabic too, and could never vary per tenant regardless.

## The three Platform-owned changes

### 1. Provisioning — `Platform\Tenancy\Services\TenantProvisioner`

Two new idempotent steps, inserted between the existing `ensureSeeded()` and `ensureChannelHostnameCorrect()`:

- **`ensureArabicLocaleSeeded()`**: `TenantProvisioner::ensureSeeded()` runs Bagisto's own `db:seed` with no `allowed_locales` parameter, so `Webkul\Installer\Database\Seeders\Core\LocalesTableSeeder` only ever seeds `en` (it defaults `allowed_locales` to `[config('app.locale')]` when no parameter is passed). This step inserts an `ar` row (`direction = rtl`) if one doesn't already exist, attaches both `ar` and `en` to the tenant's one channel (`channel_locales`), and sets `channels.default_locale_id` to `ar`. Raw `DB::table()` calls throughout, matching `ensureChannelHostnameCorrect()`'s own established Webkul-independent style — never `Webkul\Core`'s `LocaleRepository`/`ChannelRepository` (which need a dependency this package doesn't have and are awkward for a narrow single-locale write).
- **`ensureArabicThemeContentSeeded()`** (RISK_REGISTER.md R71, found live during this task's own real-storefront-rendering verification): `theme_customization_translations` (homepage slider/services/footer/etc. content) turned out to be seeded by the *identical* "only `allowed_locales`, defaulting to `en`" mechanism — so the moment `ar` became the tenant's default locale, Bagisto's own storefront views (which read a section's content for the active locale with no fallback) crashed with "Trying to access array offset on null". This step clones each already-seeded `en` row's `options` JSON **verbatim** into a new `ar` row, per section, idempotently. **This is content duplication, not translation** — see "Important caveat" below.

Both steps are protected, for free, by `provision()`'s own pre-existing `if ($tenant->status === TenantStatus::Ready) { return; }` guard at the top of the method — no new "is this a new tenant" flag was needed. See "Existing-tenant policy" below for the one deliberate exception.

### 2. Merchant Admin locale — `Platform\Tenancy\Http\Middleware\SetTenantAdminLocale` (new)

Appended to the shared `web` middleware group in `bootstrap/app.php` (the same established extension point `FlagFirstLoginWelcome`/`TenantAccessGate`/`InitializeTenancyByDomain` already use — `packages/Webkul/Admin` has no route-group hook of its own to attach a locale middleware to). Runs on every `web`-group request but is a no-op unless the current route is genuinely an Admin route (`Request::routeIs('admin.*')`) **and** tenancy is initialized — Platform Admin's own separate `platform` middleware group never initializes tenancy, so it is structurally exempt, not merely by convention. When active, sets `app()->setLocale()` from `core()->getCurrentChannel()->default_locale->code` — **the exact same value Shop's own `Locale` middleware already trusts**, so Admin and Shop can never disagree about a given tenant's language. Deliberately **no new persisted "Admin locale" setting** was introduced — a smaller, single-source-of-truth design over inventing a second, independently-driftable configuration surface.

No explicit locale-restore step exists in this middleware (unlike the mailer below) — safe only because this project runs synchronous PHP-FPM, never Octane/a persistent worker (`docs/architecture/production-deployment.md`); each HTTP request boots an entirely fresh `Application` instance, so `app()->setLocale()` here can never bleed into a later, unrelated request. **Revisit this class the moment Octane adoption (RISK_REGISTER.md R8/R12, currently deferred) is ever seriously considered.**

### 3. Owner activation email — `Platform\Signup\Services\OwnerActivationMailer`

The identical class of bug R69 already fixed for the URL root applies equally to locale: `$tenant->run()` only runs the configured tenancy bootstrappers (database/cache/filesystem) — it has no effect on `app()->getLocale()`. Since this method always runs from a **central** Platform Admin HTTP request, the very first Arabic-first merchant's activation email would otherwise render in the shared central process's English default. Fixed with the exact same save/set/restore idiom already established for the URL root (R69/C81): `app()->getLocale()` is captured before forcing, the tenant's own channel `default_locale` is applied if resolvable, and the original locale is unconditionally restored in the same `finally` block that already restores the URL root — both together, always, on success or failure alike.

## Translatable fallback — `config/translatable.php`

`fallback_locale` changed from the hardcoded `'en'` to `'ar'` (DECISION_LOG.md C85) — a project-level Laravel config file, never a `packages/Webkul/*` change. Controls what Category/CMS content Astrotomic Translatable shows when the *requested* locale's own translated field is genuinely empty. With Arabic now primary, defaulting the fallback to English no longer matched the product's own priority. Confirmed a functional no-op for every existing English-only tenant (no `ar` locale row or content exists there to "wrongly" fall back to) — proven by a dedicated regression test, not merely asserted.

## Important caveat — cloned theme content is NOT translated marketing copy

`ensureArabicThemeContentSeeded()` duplicates the English homepage section content (slider titles, service descriptions, image references) into an `ar`-locale row **byte-for-byte, untranslated**. This closes the crash (RISK_REGISTER.md R71), it does **not** deliver localized default marketing copy. A merchant's Arabic-default homepage will show English words inside an RTL layout until the merchant (or a future dedicated task) writes real Arabic copy — exactly the same posture every fresh `en` tenant already has with its own generic placeholder content, which merchants are already expected to customize. **Do not read R71's closure as "the storefront ships in Arabic content" — it ships in Arabic *language mechanics* (locale, direction, translated UI chrome), with placeholder-quality default marketing content**, same as always.

## Existing-tenant policy

New tenants get Arabic-first defaults automatically. **Existing Ready tenants (`pilot-smoke`/`test1`/`thaertest`/`mvp007-check`) are never auto-migrated** — protected by `provision()`'s own no-op-when-`Ready` guard, proven by a dedicated regression test that simulates a pre-task `en`-only Ready tenant and confirms zero change after a re-provision call. The one deliberate, accepted exception: a `Pending`/`Failed` tenant that never successfully completed its *first* provisioning attempt **will** receive Arabic-first defaults if retried via Platform Admin's existing retry action — consistent with "new tenant" framing (such a tenant was never actually live for a real merchant), not a violation of "existing tenant" policy. A future, separate, explicitly-approved migration command would be needed only if an already-live tenant is later chosen to convert to Arabic — not built here.

## Explicit non-goals (this task)

- Platform Admin translation — stays English, deliberately.
- Palestine-specific timezone/currency/country defaults — a separate future task; current out-of-the-box defaults (`Asia/Kolkata` timezone, `USD` currency, no country default) are unchanged.
- Bulk-editing/fixing upstream Arabic translation strings — a small number of genuine untranslated leftovers exist in Bagisto's own shipped `ar/app.php` files; out of scope here, not this project's content to bulk-fix.
- Real Arabic default marketing copy for theme/homepage content — see "Important caveat" above.
- Migrating any existing Ready tenant to Arabic.
- Async provisioning, email-ownership verification, re-enabling public signup — all unrelated, untouched.

See `RISK_REGISTER.md` R71 (theme-content gap, closed) and R72 (a separate, pre-existing, unrelated `LimitExceededException`/422-rendering regression found as a byproduct of this task's own full regression run — explicitly not caused by this task, not fixed here, recorded as its own high-priority follow-up).
