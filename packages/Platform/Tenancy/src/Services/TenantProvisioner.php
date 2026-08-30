<?php

declare(strict_types=1);

namespace Platform\Tenancy\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Support\PalestineGovernorates;
use RuntimeException;
use Throwable;
use Webkul\Core\Repositories\CoreConfigRepository;

/**
 * Explicit, observable, retryable tenant provisioning - see
 * docs/architecture/provisioning.md for the full design this implements.
 *
 * Deliberately NOT queued/async yet (TASK-ARCH-002 scope: "may be a service +
 * artisan command for now"). Deliberately NOT backed by a step-level audit
 * table (tenant_provisioning_events) yet - status + last_error on the tenant
 * row is the whole observability surface for now; see the class-level note
 * on ensureSeeded() for the one place that tradeoff is not fully idempotent.
 */
class TenantProvisioner
{
    /**
     * Provision (or resume provisioning) a tenant. Safe to call multiple
     * times: a READY tenant is a no-op, a PENDING/PROVISIONING/FAILED tenant
     * (re)runs the remaining steps, each of which checks its own completion
     * state before acting.
     *
     * TASK-MVP-001: `$ownerAdmin` is an OPTIONAL, additive parameter -
     * `['name' => ..., 'email' => ..., 'password' => <plaintext>]`. When
     * given, the final step (ensureOwnerAdminSeeded()) overwrites the
     * seeded placeholder admin (admin@example.com/admin123, identical
     * across every tenant today - see AdminsTableSeeder) with the real
     * merchant identity. Every existing caller (the `tenant:provision`
     * CLI command, Platform Admin's provision/retry action) passes
     * nothing and is completely unaffected - this is the deliberate
     * reason the parameter is optional rather than a second, parallel
     * provisioning method: owner-admin identity is a genuine step in the
     * SAME canonical, retry-safe pipeline every other provisioning
     * concern already goes through, not a bolt-on side effect that could
     * be silently skipped on a resumed/retried attempt. The plaintext
     * password is never written to `$tenant`/`data`/any log by this
     * method or by ensureOwnerAdminSeeded() - it lives only in this
     * call's own stack for the duration of the request; see
     * Platform\Signup\Services\MerchantOnboarding for why a failed
     * attempt requires the merchant to re-supply it on retry rather than
     * this class (or anything else) persisting it anywhere.
     */
    public function provision(Tenant $tenant, ?array $ownerAdmin = null): void
    {
        if ($tenant->status === TenantStatus::Ready) {
            return;
        }

        if (! $tenant->status->isProvisionable()) {
            throw new RuntimeException(
                "Tenant [{$tenant->getTenantKey()}] cannot be provisioned from status [{$tenant->status->value}]."
            );
        }

        $tenant->forceFill(['status' => TenantStatus::Provisioning, 'last_error' => null])->save();

        try {
            $this->ensureDatabaseCreated($tenant);
            $this->ensureFilesystemPrepared($tenant);
            $this->ensureMigrated($tenant);
            $this->ensureSeeded($tenant);
            $this->ensureArabicLocaleSeeded($tenant);
            $this->ensureArabicThemeContentSeeded($tenant);
            $this->ensurePalestineCurrencySeeded($tenant);
            $this->ensurePalestineGovernoratesSeeded($tenant);
            $this->ensurePalestineTimezoneSet($tenant);
            $this->ensurePalestineAddressDefaultsSeeded($tenant);
            $this->ensurePalestinePaymentDefaultsSeeded($tenant);
            $this->ensureChannelHostnameCorrect($tenant);
            $this->ensureInitialSubscriptionStarted($tenant);
            $this->ensureOwnerAdminSeeded($tenant, $ownerAdmin);

            $tenant->forceFill(['status' => TenantStatus::Ready])->save();
        } catch (Throwable $e) {
            $tenant->forceFill(['status' => TenantStatus::Failed, 'last_error' => $e->getMessage()])->save();

            throw $e;
        }
    }

    /**
     * Step 1: create the physical tenant database (+ scoped DB user, via
     * PermissionControlledMySQLDatabaseManager - see config/tenancy.php and
     * docs/architecture/provisioning.md "Database provisioning credentials").
     * Idempotent: skips if the database already exists (e.g. resuming after
     * a crash that happened during a later step).
     */
    protected function ensureDatabaseCreated(Tenant $tenant): void
    {
        $manager = $tenant->database()->manager();

        if ($manager->databaseExists($tenant->database()->getName())) {
            return;
        }

        $tenant->database()->makeCredentials();
        $manager->createDatabase($tenant);
    }

    /**
     * Step 2 (TASK-ARCH-005, RISK_REGISTER.md R16): create the directory
     * skeleton Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
     * needs to already exist. That bootstrapper only REMAPS config (disk
     * roots, storage_path()) - it never creates a single directory itself
     * (confirmed by reading its source). Two things need to physically exist
     * before migrate/seed run inside this tenant's context:
     *
     * 1. storage/tenant{id}/framework/{cache/data,sessions,views,testing} and
     *    storage/tenant{id}/logs - because 'suffix_storage_path' is true,
     *    storage_path() itself (not just disk roots) is suffixed once
     *    tenancy initializes, and Laravel/prettus-l5-repository/session
     *    handling all assume these exist under whatever storage_path()
     *    currently resolves to (this exact gap crashed a warning in
     *    TASK-ARCH-001 before the bootstrapper was disabled to work around
     *    it - now it's handled properly instead of avoided).
     * 2. The 'public' and 'private' disk roots themselves - Storage::put()
     *    on Laravel's local driver does create intermediate directories for
     *    the FILE being written, but not proactively for an empty disk root,
     *    and some read paths (Storage::exists(''), directory listings) are
     *    more predictable if the root already exists.
     *
     * Runs inside tenant->run() so storage_path()/Storage::disk(...) both
     * reflect this tenant's already-remapped paths (FilesystemTenancyBootstrapper
     * has already executed by the time the closure body runs). Idempotent:
     * every directory is guarded with is_dir()/Storage::exists() before
     * creation, safe to call again after a partial-failure retry.
     */
    protected function ensureFilesystemPrepared(Tenant $tenant): void
    {
        $tenant->run(function () {
            foreach ([
                'app/public',
                'framework/cache/data',
                'framework/sessions',
                'framework/views',
                'framework/testing',
                'logs',
            ] as $relative) {
                $path = storage_path($relative);

                if (! is_dir($path)) {
                    mkdir($path, 0755, true);
                }
            }

            foreach (['public', 'private'] as $disk) {
                if (! Storage::disk($disk)->exists('')) {
                    Storage::disk($disk)->makeDirectory('');
                }
            }
        });
    }

    /**
     * TASK-ARCH-010 (R33): re-run tenant migrations against an ALREADY-
     * READY tenant, bypassing provision()'s own early return
     * (`if ($tenant->status === TenantStatus::Ready) return;`). Exists
     * specifically as the repair mechanism for tenants provisioned before
     * a new tenant-scoped migration (e.g. the new `sessions` table) was
     * added: safe to call on any tenant regardless of status, any number
     * of times, for the same reason ensureMigrated() itself always has
     * been - Laravel's own `migrations` table tracks what has already run
     * per tenant database and skips it, so this only ever applies
     * genuinely NEW migration files, never re-applies or duplicates
     * anything. Does not touch status/last_error, seeding, or plan
     * assignment - purely the migration step, on demand. See
     * Platform\Tenancy\Console\Commands\MigratePendingTenants, the
     * command that exposes this for existing environments.
     */
    public function remigrate(Tenant $tenant): void
    {
        $this->ensureMigrated($tenant);
    }

    /**
     * TASK-MVP-003 (RISK_REGISTER.md/DECISION_LOG.md): re-run the channel-
     * hostname correctness step against an ALREADY-READY tenant, bypassing
     * provision()'s own early return - the repair mechanism for tenants
     * provisioned before this step existed. Mirrors remigrate()'s exact
     * shape/contract: safe to call on any tenant regardless of status
     * (though `Platform\Tenancy\Console\Commands\RepairChannelHostname`,
     * the command that exposes this, deliberately only ever calls it for
     * Ready tenants - see that command's own docblock for why), any
     * number of times, touches nothing except the one `channels.hostname`
     * column.
     */
    public function repairChannelHostname(Tenant $tenant): void
    {
        $this->ensureChannelHostnameCorrect($tenant);
    }

    /**
     * TASK-MVP-018 (RISK_REGISTER.md R73). Seeds the tenant-scoped mail
     * sender DISPLAY NAME - `emails.configure.email_settings.sender_name`,
     * a plain `core_config` row - with the tenant's own store name, so
     * transactional email shows "From: <Store Name> <support@technify.dev>"
     * instead of the platform's own "Technify" identity.
     *
     * ROOT CAUSE THIS CLOSES (confirmed by reading source, not assumed):
     * `Webkul\Core\Core::getSenderEmailDetails()` already reads this exact
     * tenant-scoped `core_config` field (falling back to
     * `config('mail.from.name')` = "Technify" only when no row exists), and
     * every Bagisto order/invoice/shipment/refund/cancellation Mailable
     * plus the customer/Admin password-reset Notifications already consume
     * it via `Shop\Mail\Mailable::buildFrom()` / `Admin\Mail\Mailable::
     * buildFrom()` / explicit `->from(core()->getSenderEmailDetails()...)`
     * calls - unmodified `packages/Webkul` code. The defect was never
     * missing architecture, only a `core_config` row nothing had ever
     * written, since populating it has always required a human to visit
     * Admin -> Configuration -> Emails -> Email Settings and save the form.
     *
     * ADDRESS IS DELIBERATELY NEVER TOUCHED HERE (Model A, explicit product
     * decision): only `sender_name` is written - `sender_email` is left
     * completely alone, so `getSenderEmailDetails()` keeps falling back to
     * `config('mail.from.address')` (`support@technify.dev`, the already-
     * proven-deliverable central address - see docs/implementation/
     * email-delivery.md). No merchant-controlled or arbitrary From address
     * is ever introduced by this method, and no Reply-To is set anywhere in
     * this task's scope - `owner_email` is unverified input and is
     * deliberately not used here.
     *
     * SINGLE SOURCE OF TRUTH (this task's own explicit requirement): both
     * `Platform\Signup\Services\MerchantOnboarding::attempt()` (the moment a
     * NEW tenant's real store name first becomes known) and
     * `Platform\Tenancy\Console\Commands\RepairSenderIdentity` (the backfill
     * command for tenants provisioned before this method existed) call this
     * exact method - neither has its own copy of the seed-or-skip logic.
     * Trustworthiness of `$storeName` (e.g. rejecting Bagisto's own generic
     * seeded placeholder channel name, "Default"/"افتراضي") is deliberately
     * the CALLER's responsibility, not this method's - this is a pure,
     * unopinionated "seed if absent" primitive, matching the callers'
     * different needs (onboarding always has an operator-supplied name,
     * trusted by definition; the repair command must inspect existing data
     * and decide for itself what counts as trustworthy).
     *
     * IDEMPOTENT / NEVER OVERWRITES: a no-op (returns 'already_configured')
     * if a `sender_name` row already exists for this tenant's default
     * channel - whether seeded by an earlier call to this same method or
     * manually configured by a merchant/admin through the real Admin
     * Configuration UI. Safe to call any number of times. The existence
     * check itself is a plain `DB::table()` read, deliberately NOT routed
     * through `CoreConfigRepository` - reads never need cache invalidation,
     * only writes do (see the PRODUCTION REGRESSION note below), so there is
     * no reason to pay for the repository/event/cache machinery here.
     *
     * PRODUCTION REGRESSION (found live, first real deployment, RISK_REGISTER.md
     * R73): this method originally wrote the row via a raw `DB::table(
     * 'core_config')->insert()`, matching this class's own established
     * "narrow, known-shape write, no Webkul Eloquent/Repository dependency"
     * convention (see `ensurePalestineAddressDefaultsSeeded()`/
     * `ensurePalestinePaymentDefaultsSeeded()`). That convention is SAFE for
     * every OTHER field those methods write, because nothing else in this
     * codebase reads `core_config` through a CACHED path - but
     * `emails.configure.email_settings.*` is a genuine exception:
     * `config/repository.php` (stock, unmodified Bagisto config - not
     * introduced by this project) explicitly enables Prettus L5 Repository
     * caching for `Webkul\Core\Repositories\CoreConfigRepository`
     * specifically (`'repositories' => ['Webkul\Core\Repositories\
     * CoreConfigRepository' => ['enabled' => true]]`), backed by
     * `CACHE_STORE=redis` in production. That cache is only ever invalidated
     * by the `RepositoryEntityCreated`/`RepositoryEntityUpdated` events
     * Prettus's OWN `create()`/`update()` methods dispatch - a raw
     * `DB::table()->insert()` never fires them. Found live: a real
     * `palestine-mvp-check` password-reset email still showed "Technify"
     * after this method had correctly written the DB row, because
     * `Core::getSenderEmailDetails()`'s read had already been cached (empty/
     * fallback) before this method ever ran, and the raw insert never
     * invalidated it - proven by direct Redis inspection (DB 1, the real
     * cache store - `REDIS_CACHE_DB=1` - not DB 0, which a first, incomplete
     * check wrongly read as empty) showing 1000+ live `CoreConfigRepository@
     * findWhere-*` keys, and by reproducing the exact stale-vs-fresh split
     * via `CoreConfigRepository::findWhere()`'s own cache-key composition
     * (keyed off `serialize(func_get_args())`, so the 1-argument call shape
     * this method's own read never used differs from the 2-argument shape
     * `getConfigData()`'s real call chain always uses internally).
     *
     * FIX: write through `Webkul\Core\Repositories\CoreConfigRepository::
     * create()` instead - the SAME method `Webkul\Admin\Http\Controllers\
     * ConfigurationController::store()` already calls for every real Admin
     * Configuration save (confirmed by reading that controller directly:
     * `$this->coreConfigRepository->create($request->except([...]))`), so
     * this reuses Bagisto's own already-correct, already-cache-invalidating
     * write path instead of re-implementing it. `create()` itself performs
     * its own existence check internally and would UPDATE an existing row
     * rather than insert a duplicate - this method's own PRIOR existence
     * check above is what preserves the "never overwrite" guarantee (create()
     * is only ever reached when nothing exists yet).
     *
     * DELIBERATE, NARROW EXCEPTION to `Platform\Tenancy`'s own established
     * "no direct `Webkul\*` class dependency" boundary (see
     * `ensurePalestineCurrencySeeded()`'s own docblock for that boundary and
     * its one prior exception, `Platform\Enforcement`, DECISION_LOG C23):
     * unlike a plain data value (a currency symbol, hardcodable without any
     * Webkul import), the correctness property this fix needs - cache
     * invalidation tied to Prettus's own event dispatch - cannot be
     * replicated by any raw-write technique; only calling the real
     * repository class produces it. This is intentionally the SECOND such
     * exception, not a silent precedent break.
     *
     * Nested `Tenant::run()` calls (this method always calls its own,
     * regardless of whether the caller is already inside one) are safe by
     * construction - confirmed by reading `Stancl\Tenancy\Database\
     * Concerns\TenantRun::run()` directly: it captures `tenant()` as the
     * "original" tenant BEFORE switching, so a nested call re-initializes
     * the SAME tenant and correctly restores to it afterward, never ending
     * tenancy early the way a naive nested-lock implementation might.
     *
     * @return 'seeded'|'already_configured'|'blank'
     */
    public function seedSenderIdentity(Tenant $tenant, string $storeName): string
    {
        $storeName = trim($storeName);

        if ($storeName === '') {
            return 'blank';
        }

        return $tenant->run(function () use ($storeName) {
            if (! Schema::hasTable('core_config') || ! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'Cannot seed sender identity: core_config/channels table does not exist yet (seeding step did not complete as expected).'
                );
            }

            $channelCode = DB::table('channels')->where('id', 1)->value('code');

            $exists = DB::table('core_config')
                ->where('code', 'emails.configure.email_settings.sender_name')
                ->where('channel_code', $channelCode)
                ->exists();

            if ($exists) {
                return 'already_configured';
            }

            app(CoreConfigRepository::class)->create([
                'locale' => null,
                'channel' => $channelCode,
                'emails' => [
                    'configure' => [
                        'email_settings' => [
                            'sender_name' => $storeName,
                        ],
                    ],
                ],
            ]);

            return 'seeded';
        });
    }

    /**
     * Step 3: run every Bagisto package migration (discovered dynamically,
     * not a maintained list - see docs/architecture/provisioning.md "Bagisto
     * tenant migration strategy") plus anything under database/migrations/tenant,
     * against this tenant's database only. Idempotent via Laravel's own
     * migrations table (already-run migrations are skipped automatically).
     */
    protected function ensureMigrated(Tenant $tenant): void
    {
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
            '--path' => app('migrator')->paths(),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Step 4: seed Bagisto's own default data (channel, locale, currency,
     * attribute family, admin role/user, ...) via the same DatabaseSeeder
     * class Bagisto's own Installer uses - NOT via the Installer command
     * itself (see RISK_REGISTER.md R11/ADR-001).
     *
     * Idempotency caveat (documented, not hidden): this guards on the
     * `admins` table already having a row, which is seeded 9th of the 10
     * sub-seeders BagistoDatabaseSeeder runs (Attribute, Category, Core,
     * Customer, CMS, Inventory, SocialLogin, Shop, User, RMA). A crash
     * between User and RMA would look "seeded" on retry and skip RMA's 3
     * rows. This is accepted as "idempotent where practical" for this
     * foundation task; closing the gap completely needs the step-level
     * tenant_provisioning_events audit table, deferred to Phase 3/4.
     */
    protected function ensureSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (Schema::hasTable('admins') && DB::table('admins')->count() > 0) {
                return;
            }

            Artisan::call('db:seed', ['--force' => true]);
        });
    }

    /**
     * TASK-MVP-012 (DECISION_LOG.md). Arabic-first default for every newly
     * provisioned tenant, product decision: `ar` becomes the tenant's
     * default channel locale, `en` remains available as a secondary
     * locale. Runs AFTER ensureSeeded() (so the `en`-only baseline
     * `Webkul\Installer\Database\Seeders\Core\LocalesTableSeeder`/
     * `ChannelTableSeeder` already produce - see this class's own audit
     * notes in DECISION_LOG.md - is guaranteed to already exist) and
     * BEFORE ensureChannelHostnameCorrect(), which also writes to
     * `channels.id = 1` and is the established precedent this method's
     * own raw-`DB::table()` style deliberately mirrors, for the identical
     * reason that method already gives (no `packages/Webkul` Eloquent
     * dependency needed for a narrow, known-shape write).
     *
     * WHY NOT `LocalesTableSeeder`/`ChannelTableSeeder` THEMSELVES: both
     * are Bagisto's own, called via the single, parameterless
     * `Artisan::call('db:seed', ...)` in ensureSeeded() - passing
     * `allowed_locales => ['ar', 'en']` there would need a custom
     * DatabaseSeeder wiring (RISK_REGISTER.md R11/ADR-001 already
     * rejected using the Installer command itself for the identical
     * "don't fork Bagisto's own seeding pipeline" reason), and
     * `LocalesTableSeeder::run()` unconditionally `DELETE`s and re-
     * inserts every `locales`/`channels` row from scratch, which is
     * unsafe to call a second time against an already-partially-
     * provisioned tenant on a retried attempt. A small, purely additive,
     * idempotent step here - never touching what `db:seed` already
     * correctly produced - is both smaller and safer.
     *
     * IDEMPOTENT / RETRY-SAFE, matching every other step in this class:
     * the `ar` locale row is inserted only if a `code = 'ar'` row does
     * not already exist (`insertGetId` is never called twice for the
     * same tenant); `channel_locales` attachment uses `insertOrIgnore`
     * (safe against the composite primary key already existing from a
     * prior partial run); the final `UPDATE default_locale_id` is a
     * plain idempotent write, identical in shape to
     * ensureChannelHostnameCorrect()'s own `hostname` update.
     *
     * EXISTING-TENANT SAFETY: this method is only ever reachable through
     * `provision()`, which is a documented no-op the instant
     * `$tenant->status === TenantStatus::Ready` (see that method's own
     * top). Every tenant already in production
     * (`pilot-smoke`/`test1`/`thaertest`/`mvp007-check`) is already
     * `Ready`, so this method can never execute against them under
     * normal operation - no extra "is this a new tenant" flag or guard
     * was needed on top of that pre-existing boundary. The one accepted
     * edge case (explicitly approved, not an oversight): a tenant stuck
     * `Pending`/`Failed` that never successfully completed its FIRST
     * provisioning attempt, if retried via Platform Admin's existing
     * retry action, WILL receive Arabic-first defaults - consistent with
     * "new tenant" framing, since such a tenant was never actually live
     * for any real merchant.
     */
    protected function ensureArabicLocaleSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('locales') || ! Schema::hasTable('channels') || ! Schema::hasTable('channel_locales')) {
                throw new RuntimeException(
                    'Cannot seed Arabic locale: locales/channels/channel_locales tables do not exist yet (seeding step did not complete as expected).'
                );
            }

            $englishLocaleId = DB::table('locales')->where('code', 'en')->value('id');

            if (! $englishLocaleId) {
                throw new RuntimeException(
                    'Cannot seed Arabic locale: no English locale row exists yet (seeding step did not complete as expected).'
                );
            }

            $arabicLocaleId = DB::table('locales')->where('code', 'ar')->value('id');

            if (! $arabicLocaleId) {
                $arabicLocaleId = DB::table('locales')->insertGetId([
                    'code' => 'ar',
                    'name' => 'Arabic',
                    'direction' => 'rtl',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('channel_locales')->insertOrIgnore([
                ['channel_id' => 1, 'locale_id' => $arabicLocaleId],
                ['channel_id' => 1, 'locale_id' => $englishLocaleId],
            ]);

            DB::table('channels')->where('id', 1)->update(['default_locale_id' => $arabicLocaleId]);
        });
    }

    /**
     * TASK-MVP-012 (DECISION_LOG.md). Found live, empirically, while
     * verifying the storefront actually renders under Arabic (not merely
     * that the database configuration was correct) - a real, previously-
     * unknown consequence of Arabic becoming the default channel locale,
     * fixed within this same task rather than silently worked around.
     *
     * ROOT CAUSE: `theme_customizations` (the homepage's slider/services/
     * footer/etc. SECTIONS - id/type/name/sort_order, locale-independent)
     * is seeded once, but `theme_customization_translations` (the actual
     * CONTENT per section - a `json` `options` column, e.g. the services
     * list `packages/Webkul/Shop/src/Resources/views/components/layouts/
     * services.blade.php` reads) is seeded by `Webkul\Installer\Database\
     * Seeders\Shop\ThemeCustomizationTableSeeder` inside the exact same
     * `foreach ($locales as $locale)` loop as `LocalesTableSeeder` -
     * meaning it ALSO only ever gets an `en` row per section, for the
     * identical "ensureSeeded() calls db:seed with no allowed_locales
     * parameter" reason `ensureArabicLocaleSeeded()` above already
     * documents. Once the channel's default locale becomes `ar`, any
     * Blade view that unconditionally reads that section's `options` for
     * the ACTIVE locale (several do, `services.blade.php` among them)
     * finds no row at all and throws "Trying to access array offset on
     * null" - a real storefront homepage crash, not merely missing text.
     *
     * FIX: clone each section's already-seeded `en` `options` JSON
     * verbatim into a new `ar` row - the SAME established principle as
     * `ensureArabicLocaleSeeded()` (duplicate existing seeded content
     * under the new locale key, never invent new copy) - deliberately
     * NOT a translation of the actual marketing content (slider titles,
     * service descriptions): that is real merchant-facing copy this task
     * has no business writing on a merchant's behalf, exactly like a
     * freshly seeded `en` tenant today ships with generic placeholder
     * content the merchant is expected to customize. This only ensures
     * Bagisto's own existing view code has SOME row to read under the
     * tenant's own new default locale, so the homepage renders instead
     * of crashing - a correctness fix, not a content/translation task.
     *
     * IDEMPOTENT: only inserts an `ar` row for a `theme_customization_id`
     * that doesn't already have one - safe to call again on a retried
     * attempt without duplicating rows. No unique DB constraint exists on
     * `(theme_customization_id, locale)` (confirmed by reading that
     * table's own migration), so this method's own `exists()` guard is
     * the only protection - acceptable given, like every other step in
     * this class, provisioning a single tenant is never run concurrently
     * with itself.
     */
    protected function ensureArabicThemeContentSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('theme_customization_translations')) {
                throw new RuntimeException(
                    'Cannot seed Arabic theme content: theme_customization_translations table does not exist yet (seeding step did not complete as expected).'
                );
            }

            $englishRows = DB::table('theme_customization_translations')->where('locale', 'en')->get();

            foreach ($englishRows as $row) {
                $alreadyHasArabic = DB::table('theme_customization_translations')
                    ->where('theme_customization_id', $row->theme_customization_id)
                    ->where('locale', 'ar')
                    ->exists();

                if ($alreadyHasArabic) {
                    continue;
                }

                DB::table('theme_customization_translations')->insert([
                    'theme_customization_id' => $row->theme_customization_id,
                    'locale' => 'ar',
                    'options' => $row->options,
                ]);
            }
        });
    }

    /**
     * TASK-MVP-016 (RISK_REGISTER.md / DECISION_LOG.md). Palestine-first
     * default: replaces the tenant's single seeded currency (always
     * `config('app.currency')`, USD by default - see CurrencyTableSeeder,
     * the same "ensureSeeded() calls db:seed with no parameters" reason
     * `ensureArabicLocaleSeeded()` already documents) with ILS.
     *
     * DELIBERATELY ILS-ONLY, not a second (USD) currency: enabling a
     * secondary currency without a matching `currency_exchange_rates` row
     * would make `Core::convertPrice()` silently return the UNCONVERTED
     * number for that currency (confirmed by reading its source - it
     * falls back to the raw amount when no exchange rate exists) - a real
     * risk of a shopper seeing "100" and not knowing whether that means
     * ILS or USD. Dual-currency support is explicitly deferred until the
     * multi-tenant correctness of Bagisto's exchange-rate mechanism
     * (`exchange-rate:update`, scheduled per `general.exchange_rates.
     * schedule.*` config) is verified - see docs/architecture/
     * palestine-readiness.md.
     *
     * WHY AN UPDATE-IN-PLACE, NOT A NEW CURRENCY ROW: every fresh tenant
     * has exactly one `currencies` row (id from `CurrencyTableSeeder`)
     * and `channels.base_currency_id`/`channel_currencies` already point
     * at it - correcting that one row's `code`/`name`/`symbol`/`decimal`
     * needs no FK rewiring at all, the same "correct in place" principle
     * `ensureChannelHostnameCorrect()` already establishes for `hostname`.
     * `symbol`/`decimal` are hardcoded here (not imported from
     * `Webkul\Core\Helpers\SupportedCurrencies`, which already fully
     * supports ILS - confirmed during this task's own audit) precisely
     * BECAUSE `Platform\Tenancy` does not depend on a specific `Webkul\*`
     * class - that is a narrow, deliberate exception this file's own
     * `ensureOwnerAdminSeeded()` docblock already documents belongs only
     * to `Platform\Enforcement` (DECISION_LOG C23). A raw `DB::table()`
     * write to a Webkul-owned TABLE (already this whole class's
     * established pattern) is not the same coupling as importing a
     * Webkul PHP class.
     *
     * IDEMPOTENT: no-ops if the currency is already ILS (covers both a
     * retried attempt and this method running twice).
     *
     * EXISTING-TENANT SAFETY: only reachable through `provision()`, which
     * is a no-op the instant `$tenant->status === TenantStatus::Ready` -
     * identical protection to every other TASK-MVP-012/016 addition.
     */
    protected function ensurePalestineCurrencySeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('currencies')) {
                throw new RuntimeException(
                    'Cannot seed Palestine currency: currencies table does not exist yet (seeding step did not complete as expected).'
                );
            }

            $currency = DB::table('currencies')->orderBy('id')->first();

            if (! $currency || $currency->code === 'ILS') {
                return;
            }

            DB::table('currencies')->where('id', $currency->id)->update([
                'code' => 'ILS',
                'name' => trans('installer::app.seeders.core.currencies.ILS', [], 'en'),
                // ₪ / 2 decimal places - matches Webkul\Core\Helpers\
                // SupportedCurrencies::ALL['ILS'] exactly; duplicated
                // here rather than imported, see this method's own
                // docblock for why.
                'symbol' => '₪',
                'decimal' => 2,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * TASK-MVP-016. Seeds the 16 governorates of the State of Palestine
     * into `country_states`/`country_state_translations` - Platform-owned
     * DATA, zero `packages/Webkul` changes (mirrors the exact principle
     * `ensureArabicLocaleSeeded()` already established: these are plain
     * seeded tables, not code).
     *
     * AUTHORITATIVE SOURCE: the 16 governorates of the State of Palestine
     * (11 West Bank + 5 Gaza Strip) and their ISO 3166-2:PS subdivision
     * codes, per established public administrative-geography knowledge
     * (matches the Palestinian Central Bureau of Statistics' own
     * governorate list and the ISO 3166-2:PS standard). This was applied
     * from that knowledge, not fetched from a live registry during this
     * task - if these codes are ever relied on for an external
     * integration that requires certified ISO compliance, cross-check
     * against the official ISO 3166 registry first. The English/Arabic
     * NAMES are not in question (standard, uncontroversial); the exact
     * 3-letter CODE suffixes carry that one disclosed caveat. See
     * docs/architecture/palestine-readiness.md for the full list as
     * committed.
     *
     * CODE FORMAT: a bare suffix (e.g. `JEN`), matching this project's
     * own existing `states.json` convention for every other country
     * (e.g. `code: 'AL'` for Alabama, not `US-AL`) - not a new format.
     *
     * IMPORTANT, DISCLOSED TRADE-OFF: `Webkul\Core\Core::
     * groupedStatesByCountries()` - which powers the REAL storefront
     * checkout state dropdown (`shop.api.core.states`) - reads
     * `country_states.default_name` via a raw `DB::table(...)->get()`,
     * confirmed by direct source reading to bypass the `CountryState`
     * Eloquent model (and therefore its Astrotomic Translatable
     * mechanism) ENTIRELY. This means the live checkout dropdown will
     * always show whichever single language is in the base
     * `default_name` column, regardless of the shopper's active locale.
     * Since Arabic is this tenant type's PRIMARY locale (English is
     * explicitly secondary - DECISION_LOG.md C84), the base column is
     * set to the ARABIC name - the opposite of every other country in
     * `states.json`, which stores English in that column, because for
     * every other country English generally IS the primary experience.
     * `country_state_translations` is ALSO populated (`ar` + `en` rows)
     * for schema completeness and any future code path that does honor
     * the Eloquent model - but will NOT change what the live storefront
     * dropdown shows today. Residual, disclosed limitation: a shopper who
     * switches to the secondary `en` locale will still see Arabic
     * governorate names in the state dropdown - fixing that needs a
     * `packages/Webkul` change to `groupedStatesByCountries()` itself,
     * out of scope here (see docs/architecture/palestine-readiness.md
     * "Deferred").
     *
     * IDEMPOTENT: guarded per-governorate by `code`, safe to call again
     * on a retried tenant without duplicating rows.
     */
    protected function ensurePalestineGovernoratesSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('country_states') || ! Schema::hasTable('country_state_translations')) {
                throw new RuntimeException(
                    'Cannot seed Palestine governorates: country_states/country_state_translations tables do not exist yet (seeding step did not complete as expected).'
                );
            }

            $countryId = DB::table('countries')->where('code', 'PS')->value('id');

            if (! $countryId) {
                throw new RuntimeException(
                    'Cannot seed Palestine governorates: no PS row exists in countries (seeding step did not complete as expected).'
                );
            }

            foreach (PalestineGovernorates::ALL as $code => $names) {
                $existingId = DB::table('country_states')
                    ->where('country_code', 'PS')
                    ->where('code', $code)
                    ->value('id');

                if ($existingId) {
                    continue;
                }

                $stateId = DB::table('country_states')->insertGetId([
                    'country_id' => $countryId,
                    'country_code' => 'PS',
                    'code' => $code,
                    // Arabic in the base column deliberately - see this
                    // method's own docblock ("IMPORTANT, DISCLOSED
                    // TRADE-OFF") for why.
                    'default_name' => $names['ar'],
                ]);

                DB::table('country_state_translations')->insert([
                    ['country_state_id' => $stateId, 'locale' => 'ar', 'default_name' => $names['ar']],
                    ['country_state_id' => $stateId, 'locale' => 'en', 'default_name' => $names['en']],
                ]);
            }
        });
    }

    /**
     * TASK-MVP-016. Sets the tenant's channel-facing timezone to
     * `Asia/Hebron` (the operator's approved decision) WITHOUT touching
     * the global `config('app.timezone')` (kept at `UTC` - the operator's
     * explicit instruction, protecting Platform Admin and any future
     * non-Palestine tenant).
     *
     * MECHANISM, confirmed by direct source reading during this task's
     * own audit (correcting an earlier, narrower finding - DECISION_LOG
     * C60 - that no per-tenant timezone override existed anywhere):
     * `channels.timezone` is a real, pre-existing, nullable column
     * `Webkul\Core\Core::formatDate()` already reads FIRST (`$channel->
     * timezone ?: config('app.timezone', 'UTC')`) - and `formatDate()` is
     * what the Admin order detail view, the Shop customer order view,
     * invoices, refunds, shipments, AND every order-related email
     * template (created/canceled/invoiced/refunded/shipped, both
     * Admin-side and Shop-side - 22 call sites, confirmed via grep)
     * already use. Setting this ONE column is a data-only correction on
     * an existing column, needing zero `packages/Webkul` change - exactly
     * the `ensureChannelHostnameCorrect()` precedent.
     *
     * NOT COMPREHENSIVE, disclosed honestly: the Admin Orders DataGrid
     * listing page was audited during this task and does NOT go through
     * `formatDate()` for its date column - see docs/architecture/
     * palestine-readiness.md "Admin Orders DataGrid timezone" for the
     * full finding. That surface remains UTC-displayed; fixing it (if
     * ever needed) is a separate, deferred item, not implemented here.
     *
     * IDEMPOTENT: a plain conditional `UPDATE`, safe to repeat.
     */
    protected function ensurePalestineTimezoneSet(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'Cannot set Palestine timezone: channels table does not exist yet (seeding step did not complete as expected).'
                );
            }

            DB::table('channels')
                ->where('id', 1)
                ->where(function ($query) {
                    $query->whereNull('timezone')->orWhere('timezone', '!=', 'Asia/Hebron');
                })
                ->update(['timezone' => 'Asia/Hebron']);
        });
    }

    /**
     * TASK-MVP-016. Two Palestine-first address defaults, both operator-
     * approved, both written as plain `core_config` rows in exactly the
     * shape `Webkul\Core\Repositories\CoreConfigRepository::create()`
     * itself would produce (confirmed by reading that class directly) -
     * a raw `DB::table('core_config')` write, not a call into that
     * repository, matching this class's own established "narrow, known-
     * shape write, no Webkul Eloquent/Repository dependency" convention
     * (`ensureOwnerAdminSeeded()`, `ensureChannelHostnameCorrect()`):
     *
     * 1. `customer.address.requirements.postcode` -> `0` (OFF). Palestine
     *    has no nationwide postal-code system in common use - confirmed
     *    during this task's audit that this is a plain per-channel
     *    boolean toggle Bagisto itself already supports, defaulting ON.
     * 2. `country` is deliberately NOT touched here - the operator
     *    approved a GLOBAL default country (`config('app.default_country')`
     *    = 'PS', see `config/app.php` and `.env`), not a per-tenant
     *    `core_config` row - country pre-selection is a single Laravel
     *    config value Bagisto's own address-edit views already read
     *    (`config('app.default_country')`), no per-channel mechanism
     *    exists for it.
     * 3. `state` requirement is deliberately left at Bagisto's own
     *    default (ON, untouched) - real governorates are now seeded
     *    (`ensurePalestineGovernoratesSeeded()`), so the requirement is
     *    meaningful, not a blocker (see that method's own docblock for
     *    the storefront free-text/dropdown behavior either way).
     *
     * IDEMPOTENT: guarded by `channel_code`, safe to call again.
     */
    protected function ensurePalestineAddressDefaultsSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('core_config') || ! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'Cannot seed Palestine address defaults: core_config/channels tables do not exist yet (seeding step did not complete as expected).'
                );
            }

            $channelCode = DB::table('channels')->where('id', 1)->value('code');

            $exists = DB::table('core_config')
                ->where('code', 'customer.address.requirements.postcode')
                ->where('channel_code', $channelCode)
                ->exists();

            if ($exists) {
                return;
            }

            DB::table('core_config')->insert([
                'code' => 'customer.address.requirements.postcode',
                'value' => '0',
                'channel_code' => $channelCode,
                'locale_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * TASK-MVP-016. Sets both payment-method defaults the operator
     * approved: Cash on Delivery explicitly ACTIVE (with a real title),
     * Money Transfer explicitly INACTIVE.
     *
     * CORRECTION, found live by this task's own regression test (test 9
     * in `tests/Feature/Platform/TenantPalestineDefaultsProvisioningTest.php`
     * failed against the first draft of this method, which wrote nothing
     * for Money Transfer): the original assumption - that with ZERO
     * `core_config` rows a payment method resolves to falsy/inactive via
     * `SystemConfig::getConfigData()`'s own schema-`'default'` fallback -
     * is WRONG for this specific field. `SystemConfig::getDefaultConfig()`
     * falls through to `Config::get($strippedField, ...)`, which resolves
     * against a COMPLETELY SEPARATE config file,
     * `packages/Webkul/Payment/src/Config/payment-methods.php` (a
     * `'payment_methods'`-namespaced Laravel config, unrelated to the
     * `'sales.payment_methods.*'` ADMIN FORM SCHEMA in `system.php`) -
     * and that file hardcodes `'active' => true` for BOTH `cashondelivery`
     * AND `moneytransfer`. Both payment methods are therefore ALREADY
     * ACTIVE out of the box in stock Bagisto, with no `core_config` row
     * at all - the opposite of what was assumed. Cash on Delivery's own
     * explicit `active=1` write below was harmless either way (redundant
     * with the true default, but makes the intent durable/explicit
     * regardless of any future upstream change); Money Transfer's
     * explicit `active=0` write is NOT optional - without it, Money
     * Transfer would be live at checkout with generic English wording and
     * no real bank details, the exact opposite of the approved "available
     * but inactive until the merchant's real bank details are configured"
     * posture.
     *
     * `Webkul\Payment\Listeners\GenerateInvoice` remains the only real
     * consumer of the OTHER cashondelivery config fields -
     * `order_status`/`invoice_status`/`generate_invoice` - and only when
     * `generate_invoice` is itself truthy, which stays unset/off here;
     * `active` + a real `title` are sufficient for a functional,
     * selectable checkout option.
     *
     * Writes `active` (channel-based only) for both methods and `title`
     * (channel- AND locale-based, one row per tenant locale - `ar`/`en`)
     * for Cash on Delivery only, as plain `core_config` rows, the same
     * shape/convention as `ensurePalestineAddressDefaultsSeeded()` above.
     *
     * IDEMPOTENT: guarded by existence of the cashondelivery `active` row.
     */
    protected function ensurePalestinePaymentDefaultsSeeded(Tenant $tenant): void
    {
        $tenant->run(function () {
            if (! Schema::hasTable('core_config') || ! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'Cannot seed Palestine payment defaults: core_config/channels tables do not exist yet (seeding step did not complete as expected).'
                );
            }

            $channelCode = DB::table('channels')->where('id', 1)->value('code');

            $exists = DB::table('core_config')
                ->where('code', 'sales.payment_methods.cashondelivery.active')
                ->where('channel_code', $channelCode)
                ->exists();

            if ($exists) {
                return;
            }

            $now = now();

            DB::table('core_config')->insert([
                [
                    'code' => 'sales.payment_methods.cashondelivery.active',
                    'value' => '1',
                    'channel_code' => $channelCode,
                    'locale_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'sales.payment_methods.cashondelivery.title',
                    'value' => 'الدفع عند الاستلام',
                    'channel_code' => $channelCode,
                    'locale_code' => 'ar',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'sales.payment_methods.moneytransfer.active',
                    'value' => '0',
                    'channel_code' => $channelCode,
                    'locale_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'sales.payment_methods.cashondelivery.title',
                    'value' => 'Cash on Delivery',
                    'channel_code' => $channelCode,
                    'locale_code' => 'en',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }

    /**
     * TASK-MVP-003. `Webkul\Installer\Database\Seeders\Core\ChannelTableSeeder`
     * always seeds the tenant's one default channel (id=1) with
     * `hostname = config('app.url')` - the CENTRAL app's own URL, never
     * this tenant's own domain (there is no per-tenant concept in that
     * seeder at all). This step corrects it to the tenant's real primary
     * domain, resolved from the central `Tenant`/`Domain` data (never
     * guessed from the tenant id) - the only real consumers found
     * (`Webkul\Shop\Http\Controllers\SitemapController`,
     * `Webkul\Sitemap\Jobs\ProcessSitemap`, both via `sitemap.xml`/
     * `robots.txt` generation) would otherwise silently advertise the
     * platform's own central domain instead of the merchant's store.
     *
     * FORMAT, verified from Bagisto source, not assumed: a BARE hostname
     * (no scheme) - confirmed three ways: (1) the Admin form field is
     * literally labelled "Host Name" with a plain `unique:channels,
     * hostname` validation rule, no `url:` rule; (2) `Webkul\Core\Core::
     * getCurrentChannel()`'s own hostname lookup explicitly checks THREE
     * candidate values - the bare hostname, `http://`+hostname, and
     * `https://`+hostname - proving the bare form is a first-class,
     * correctly-handled value, not a degraded one; (3) both real
     * consumers (`SitemapController::channelBaseUrl()`, `ProcessSitemap::
     * channelBaseUrl()`) already normalize a schemeless value to
     * `https://` themselves before using it as a URL base - storing a
     * scheme here would be redundant at best and would hardcode a
     * scheme decision (this environment currently serves tenants over
     * plain `http://` locally) this step has no business making. This is
     * exactly why the earlier TASK-MVP-003 plan's "prefixed with the
     * correct scheme" assumption was verified and NOT implemented -
     * writing the bare domain is both simpler and the only choice that
     * doesn't bake in a possibly-wrong scheme for the eventual real
     * (TASK-MVP-004) production domain.
     *
     * Targets `channels.id = 1` (the one channel every tenant has -
     * `ChannelTableSeeder`'s own hardcoded id) and ONLY the `hostname`
     * column - no other channel field is read or written, so this can
     * never disturb a merchant's own locale/currency/theme/design
     * configuration. Idempotent (a plain conditional `UPDATE`, safe to
     * repeat any number of times) and side-effect-free if the domain is
     * already correct.
     *
     * No domain is guessed from `$tenant->getTenantKey()` - the real
     * `Domain` row (created once, at tenant-creation time, by whichever
     * caller created this tenant - CLI, Platform Admin, or `Platform\
     * Signup`) is the only source of truth. A tenant with no domain row
     * at all (should never happen by the time provision() reaches this
     * step - every tenant-creation call site creates its domain in the
     * same transaction as the tenant row) is treated as a hard failure,
     * matching this class's established "fail loudly, don't guess"
     * convention (see ensureInitialSubscriptionStarted()'s own missing-
     * plan case).
     */
    protected function ensureChannelHostnameCorrect(Tenant $tenant): void
    {
        $domain = $tenant->domains()->orderBy('id')->value('domain');

        if (! $domain) {
            throw new RuntimeException(
                "Cannot correct channel hostname: tenant [{$tenant->getTenantKey()}] has no domain record."
            );
        }

        $tenant->run(function () use ($domain) {
            if (! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'Cannot correct channel hostname: channels table does not exist yet (seeding step did not complete as expected).'
                );
            }

            DB::table('channels')
                ->where('id', 1)
                ->where(function ($query) use ($domain) {
                    $query->whereNull('hostname')->orWhere('hostname', '!=', $domain);
                })
                ->update(['hostname' => $domain]);
        });
    }

    /**
     * Step 5 (TASK-ARCH-008, rewritten TASK-ARCH-016): start the
     * tenant's initial subscription on the default plan. A CENTRAL
     * operation (writes `subscriptions` + `tenants.plan_id`), NOT wrapped
     * in tenant->run() - none of this has anything to do with the
     * tenant's own database. Idempotent: no-ops if a plan is already
     * assigned (covers both "resuming a partially-provisioned tenant" and
     * "provision() called again on an already-READY tenant") - the
     * check is deliberately still `tenants.plan_id !== null`, not "does a
     * Subscription row exist": self-healing a MISSING subscription for an
     * already-plan_id-assigned tenant is `Platform\Subscriptions\
     * Services\SubscriptionBackfill`'s job (task section 8), not this
     * method's - keeping this step narrowly scoped to genuinely NEW
     * tenants.
     *
     * Looked up by a stable CODE (config('platform.plans.default_code'),
     * default 'free'), never a raw database id - ids are seed-order-
     * dependent per environment. Fails loudly (not silently) if the
     * configured default plan does not exist, per this task's explicit
     * "provisioning must fail clearly" instruction - see
     * Platform\Plans\Console\Commands\SeedPlans, which must be run once
     * per environment before any tenant is provisioned.
     *
     * TASK-ARCH-016: `SubscriptionLifecycle::start()` (not
     * `TenantPlanAssignment::assign()` directly, though it still calls
     * that internally) is now the single entry point - it creates the
     * tenant's Subscription row (status Active, no trial - a FREE
     * default tenant gets no trial merely because plans might one day
     * support a trial_days column that doesn't exist today, task section
     * 6) AND synchronizes `tenants.plan_id` in one atomic operation, so
     * "resolve default plan -> create subscription -> tenants.plan_id
     * synchronized" (task section 7's own sequence) can never partially
     * apply. TASK-ARCH-015's own active-plan-deactivated failure mode is
     * unchanged - still fails loudly, still marks the tenant FAILED via
     * provision()'s existing try/catch, now surfaced through
     * SubscriptionLifecycle -> TenantPlanAssignment rather than
     * TenantPlanAssignment directly, no behavior change.
     *
     * DEPENDENCY-DIRECTION NOTE: this is now the SECOND deliberate
     * exception (after Platform\Plans, DECISION_LOG C19) to the general
     * rule that Platform\Tenancy is not depended on the other way -
     * Platform\Tenancy now also depends on Platform\Subscriptions for
     * this one call. Justified identically to C19: starting a tenant's
     * initial subscription is fundamentally a provisioning-lifecycle
     * concern (matching this class's existing responsibility for every
     * other tenant-readiness step), not business logic Platform\
     * Subscriptions itself needs to know about; Platform\Subscriptions
     * has zero knowledge of provisioning in return. An event-listener
     * alternative was rejected for the same reason C19 already rejected
     * one for Plan assignment: it would fire on every `Tenant::create()`
     * call across the whole test suite, forcing every fixture to have
     * subscription machinery available merely to create a tenant row.
     * See DECISION_LOG.md.
     */
    protected function ensureInitialSubscriptionStarted(Tenant $tenant): void
    {
        if ($tenant->plan_id !== null) {
            return;
        }

        $code = config('platform.plans.default_code');
        $plan = Plan::where('code', $code)->first();

        if (! $plan) {
            throw new RuntimeException(
                "Default plan [{$code}] does not exist. Run `php artisan platform:plans:seed` before provisioning tenants."
            );
        }

        app(SubscriptionLifecycle::class)->start($tenant, $plan);
    }

    /**
     * Step 6 (TASK-MVP-001, optional - see provision()'s own docblock):
     * replaces the seeded placeholder admin (id=1, always
     * admin@example.com/admin123 today - AdminsTableSeeder has no
     * per-tenant identity concept of its own) with the real merchant
     * identity collected at self-service signup.
     *
     * No-ops (does nothing at all) when $ownerAdmin is null - the
     * unchanged behavior for every existing caller (CLI, Platform Admin).
     * Deliberately a plain `DB::table('admins')->update()`, not an
     * `Webkul\User\Models\Admin` Eloquent write - `Platform\Enforcement`
     * is the one Platform package with a standing exception to depend on
     * a specific `Webkul\*` package (DECISION_LOG C23); this does not
     * need that exception at all, since a raw table update needs no
     * model import, keeping `Platform\Tenancy` exactly as
     * Webkul-independent as it already is.
     *
     * Idempotent by construction: an UPDATE against a known row (id=1,
     * guaranteed to exist by the time this step runs, since
     * ensureSeeded() already succeeded without throwing) is safe to
     * repeat any number of times, including with a corrected password on
     * a retried attempt - unlike ensureDatabaseCreated()/ensureSeeded(),
     * there is no "must not redo" hazard here at all.
     *
     * Password is hashed here, at the single point of use, via
     * Illuminate\Support\Facades\Hash (Webkul\User\Models\Admin has no
     * 'hashed' cast - AdminsTableSeeder itself hashes manually before its
     * own raw insert, the same pattern this mirrors). The plaintext value
     * passed in is never written anywhere else by this method.
     */
    protected function ensureOwnerAdminSeeded(Tenant $tenant, ?array $ownerAdmin): void
    {
        if ($ownerAdmin === null) {
            return;
        }

        $tenant->run(function () use ($ownerAdmin) {
            if (! Schema::hasTable('admins') || ! DB::table('admins')->where('id', 1)->exists()) {
                throw new RuntimeException(
                    'Cannot set owner admin identity: no seeded admin row exists yet (seeding step did not complete as expected).'
                );
            }

            DB::table('admins')->where('id', 1)->update([
                'name' => $ownerAdmin['name'],
                'email' => $ownerAdmin['email'],
                'password' => Hash::make($ownerAdmin['password']),
                'updated_at' => now(),
            ]);
        });
    }
}
