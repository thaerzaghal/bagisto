<?php

/**
 * TASK-MVP-012 (DECISION_LOG.md). Proves `Platform\Tenancy\Services\
 * TenantProvisioner::ensureArabicLocaleSeeded()`: every newly provisioned
 * tenant gets `ar` (Arabic, RTL) as its default channel locale with `en`
 * preserved as a secondary locale, the step is idempotent under
 * provisioning retry, and - critically - a tenant that was already
 * `Ready` before this feature existed is never touched by it (the
 * existing `provision()` no-op-when-Ready guard is the only protection
 * needed; no new flag was added). Also proves Arabic-only catalog/
 * category content (no English translation at all) works end-to-end,
 * including the `config/translatable.php` fallback-locale change to `ar`.
 *
 * Real MySQL, real Bagisto migrations/seeders/repositories - nothing
 * mocked, following this file's own established sibling
 * (TenantProvisioningTest.php)'s conventions exactly.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Webkul\Category\Repositories\CategoryRepository;

uses(PlatformIntegrationTestCase::class);

const TAL_TEST_IDS = ['tal-a', 'tal-b', 'tal-legacy'];

function cleanupTalTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (TAL_TEST_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbUsername = $data['tenancy_db_username'] ?? null;
            $dbName = $data['tenancy_db_name'] ?? null;

            if ($dbUsername) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $dbUsername).'`');
            }

            if ($dbName) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $dbName).'`');
            }
        }

        $central->table('domains')->where('tenant_id', $id)->delete();
        $central->table('tenants')->where('id', $id)->delete();
    }
}

beforeEach(fn () => cleanupTalTestTenants());
afterEach(fn () => cleanupTalTestTenants());

test('1-5. a newly provisioned tenant has ar (rtl, default) and en (secondary) correctly wired on the default channel', function () {
    $tenant = Tenant::create(['id' => 'tal-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-a.platform.test']);

    app(TenantProvisioner::class)->provision($tenant);
    $tenant->refresh();

    expect($tenant->status)->toBe(TenantStatus::Ready);

    $tenant->run(function () {
        $locales = DB::table('locales')->pluck('direction', 'code')->all();

        // 1. Contains ar. 2. Still contains en. 3. ar.direction === rtl.
        expect($locales)->toHaveKey('ar');
        expect($locales)->toHaveKey('en');
        expect($locales['ar'])->toBe('rtl');
        expect($locales['en'])->toBe('ltr');
        expect(DB::table('locales')->count())->toBe(2);

        $arId = DB::table('locales')->where('code', 'ar')->value('id');
        $enId = DB::table('locales')->where('code', 'en')->value('id');

        // 4. Channel offers both.
        $channelLocaleIds = DB::table('channel_locales')->where('channel_id', 1)->pluck('locale_id')->all();
        expect($channelLocaleIds)->toContain($arId);
        expect($channelLocaleIds)->toContain($enId);
        expect($channelLocaleIds)->toHaveCount(2);

        // 5. Channel default locale is ar.
        expect(DB::table('channels')->where('id', 1)->value('default_locale_id'))->toBe($arId);
    });
});

test('a newly provisioned tenant has an Arabic theme-content translation row for every homepage section, matching English 1:1', function () {
    // Found live during this task's own real-browser-equivalent
    // verification (TenantArabicLocaleRenderingTest.php test 6 originally
    // failed with a ViewException before this was fixed) -
    // theme_customization_translations is seeded per-locale by the same
    // "only allowed_locales, defaulting to en" mechanism as `locales`
    // itself; see TenantProvisioner::ensureArabicThemeContentSeeded()'s
    // own docblock for the full root cause.
    $tenant = Tenant::create(['id' => 'tal-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-a.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    $tenant->run(function () {
        $englishSectionIds = DB::table('theme_customization_translations')->where('locale', 'en')->pluck('theme_customization_id')->sort()->values()->all();
        $arabicSectionIds = DB::table('theme_customization_translations')->where('locale', 'ar')->pluck('theme_customization_id')->sort()->values()->all();

        expect($arabicSectionIds)->toBe($englishSectionIds);
        expect($arabicSectionIds)->not->toBeEmpty();

        // Content is cloned verbatim (duplicated, not invented/translated) -
        // every ar row's options JSON matches its en sibling exactly.
        foreach ($englishSectionIds as $id) {
            $enOptions = DB::table('theme_customization_translations')->where('theme_customization_id', $id)->where('locale', 'en')->value('options');
            $arOptions = DB::table('theme_customization_translations')->where('theme_customization_id', $id)->where('locale', 'ar')->value('options');
            expect($arOptions)->toBe($enOptions);
        }
    });
});

test('10. provisioning retry is idempotent: re-running provision() never duplicates locale/pivot rows or changes the default', function () {
    $provisioner = app(TenantProvisioner::class);

    $tenant = Tenant::create(['id' => 'tal-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-a.platform.test']);
    $provisioner->provision($tenant);
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    // Simulate a resumed/retried provisioning run, the same established
    // technique TenantProvisioningTest.php's own idempotency test uses.
    $tenant->forceFill(['status' => TenantStatus::Pending])->save();
    $provisioner->provision($tenant);
    $tenant->refresh();

    expect($tenant->status)->toBe(TenantStatus::Ready);

    $tenant->run(function () {
        expect(DB::table('locales')->count())->toBe(2);
        expect(DB::table('channel_locales')->where('channel_id', 1)->count())->toBe(2);

        $arId = DB::table('locales')->where('code', 'ar')->value('id');
        expect(DB::table('channels')->where('id', 1)->value('default_locale_id'))->toBe($arId);

        // No duplicate theme-content translation rows either.
        $sectionCount = DB::table('theme_customization_translations')->where('locale', 'en')->count();
        expect(DB::table('theme_customization_translations')->where('locale', 'ar')->count())->toBe($sectionCount);
    });
});

test('12. a tenant already READY before this feature existed is never mutated by it', function () {
    // Provision normally (this now includes Arabic seeding), then manually
    // roll the tenant's own database back to exactly what a PRE-TASK-MVP-012
    // tenant would have looked like - en-only, no ar row/pivot at all - to
    // faithfully simulate "an existing production tenant", not merely
    // assert an untested hypothetical.
    $tenant = Tenant::create(['id' => 'tal-legacy', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-legacy.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    $tenant->run(function () {
        $enId = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => $enId]);
        DB::table('channel_locales')->where('channel_id', 1)->where('locale_id', '!=', $enId)->delete();
        DB::table('locales')->where('code', 'ar')->delete();

        expect(DB::table('locales')->count())->toBe(1);
        expect(DB::table('locales')->value('code'))->toBe('en');
    });

    // The tenant is still Ready - provision()'s own top-level guard must
    // make this call a complete no-op, never reaching ensureArabicLocaleSeeded().
    app(TenantProvisioner::class)->provision($tenant);

    $tenant->run(function () {
        expect(DB::table('locales')->count())->toBe(1);
        expect(DB::table('locales')->value('code'))->toBe('en');

        $enId = DB::table('locales')->where('code', 'en')->value('id');
        expect(DB::table('channels')->where('id', 1)->value('default_locale_id'))->toBe($enId);
    });
});

test('two tenants never leak locale/channel configuration into each other', function () {
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::create(['id' => 'tal-a', 'status' => TenantStatus::Pending]);
    $tenantA->domains()->create(['domain' => 'tal-a.platform.test']);
    $provisioner->provision($tenantA);

    $tenantB = Tenant::create(['id' => 'tal-b', 'status' => TenantStatus::Pending]);
    $tenantB->domains()->create(['domain' => 'tal-b.platform.test']);
    $provisioner->provision($tenantB);

    // Mutate tenant A's channel default AFTER provisioning both, to prove
    // this is genuinely per-database isolation, not merely "both happened
    // to get the same value by coincidence."
    $tenantA->run(function () {
        $enId = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => $enId]);
    });

    $tenantA->run(function () {
        $locale = DB::table('locales')->where('id', DB::table('channels')->where('id', 1)->value('default_locale_id'))->value('code');
        expect($locale)->toBe('en');
    });

    $tenantB->run(function () {
        $locale = DB::table('locales')->where('id', DB::table('channels')->where('id', 1)->value('default_locale_id'))->value('code');
        expect($locale)->toBe('ar');
    });
});

test('9. an Arabic-only category (no English translation at all) works correctly - primary read and English->Arabic fallback both', function () {
    $tenant = Tenant::create(['id' => 'tal-a', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-a.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    $tenant->run(function () {
        $originalLocale = app()->getLocale();

        try {
            $rootCategoryId = DB::table('channels')->where('id', 1)->value('root_category_id');

            // Astrotomic\Translatable writes the translation for whatever
            // app()->getLocale() is AT SAVE TIME - not a 'locale' array key
            // (confirmed by reading Prettus\Repository\Eloquent\BaseRepository::
            // create(), which CategoryRepository inherits unmodified: a plain
            // $model->newInstance($attributes)->save()). This is exactly why
            // Platform\Tenancy\Http\Middleware\SetTenantAdminLocale must set
            // the locale BEFORE any Admin controller/repository code runs -
            // mirrored here explicitly since this test calls the repository
            // directly, without going through that middleware.
            app()->setLocale('ar');

            $category = app(CategoryRepository::class)->create([
                'name' => 'إلكترونيات',
                'parent_id' => $rootCategoryId,
                'status' => 1,
            ]);

            // Exactly one translation row exists (ar) - no English row was
            // ever required or auto-created.
            expect(DB::table('category_translations')->where('category_id', $category->id)->count())->toBe(1);
            expect(DB::table('category_translations')->where('category_id', $category->id)->value('locale'))->toBe('ar');

            // Reading it back under Arabic (the primary/default locale) works
            // directly, no fallback needed.
            app()->setLocale('ar');
            expect($category->fresh()->name)->toBe('إلكترونيات');

            // Reading it back under English (secondary locale, no English
            // translation exists) correctly falls back to Arabic content
            // (config/translatable.php's fallback_locale => 'ar', TASK-MVP-012)
            // instead of returning blank/null.
            app()->setLocale('en');
            expect($category->fresh()->name)->toBe('إلكترونيات');
        } finally {
            app()->setLocale($originalLocale);
        }
    });
});

test('existing single-locale (en-only) tenant catalog fallback behavior is unchanged by the fallback_locale config change', function () {
    // Simulates a pre-TASK-MVP-012 tenant: en-only, no ar locale row at all -
    // proves changing config/translatable.php's fallback_locale to 'ar' is a
    // functional no-op here (there is no Arabic content to fall back to).
    $tenant = Tenant::create(['id' => 'tal-legacy', 'status' => TenantStatus::Pending]);
    $tenant->domains()->create(['domain' => 'tal-legacy.platform.test']);
    app(TenantProvisioner::class)->provision($tenant);

    $tenant->run(function () {
        // FK-respecting order: channels.default_locale_id must stop
        // pointing at the ar row BEFORE it can be deleted.
        $enId = DB::table('locales')->where('code', 'en')->value('id');
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => $enId]);
        DB::table('channel_locales')->where('channel_id', 1)->where('locale_id', '!=', $enId)->delete();
        DB::table('locales')->where('code', 'ar')->delete();

        $rootCategoryId = DB::table('channels')->where('id', 1)->value('root_category_id');

        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');

            $category = app(CategoryRepository::class)->create([
                'name' => 'Electronics',
                'parent_id' => $rootCategoryId,
                'status' => 1,
            ]);

            expect($category->fresh()->name)->toBe('Electronics');
        } finally {
            app()->setLocale($originalLocale);
        }
    });
});
