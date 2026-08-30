<?php

/**
 * TASK-MVP-022 (RISK_REGISTER.md R74). `Webkul\Core\Core::
 * groupedStatesByCountries()` and its sibling `Webkul\CartRule\
 * Repositories\CartRuleRepository::groupedStatesByCountries()` both read
 * `country_states` via a raw `DB::table(...)->get()`, bypassing
 * `Webkul\Core\Models\CountryState` (an Astrotomic Translatable model)
 * entirely - every consumer (storefront checkout/address, six Admin
 * Blade forms, the Shop `api/core/states` JSON endpoint, the Admin Cart
 * Rule condition builder) always sees whichever single language was
 * written into the base `default_name` column at seed time, regardless
 * of the current request's locale.
 *
 * `Platform\Tenancy\Support\LocaleAwareCore`/`LocaleAwareCartRuleRepository`
 * (bound over the real Webkul classes via plain `bind()`, never
 * `singleton()`, in `TenancyServiceProvider::register()`) fix this by
 * routing through `CountryStateRepository` instead and normalizing each
 * model down to a plain `stdClass` with EXACTLY the original five
 * `country_states` columns (`LocaleAwareCountryStates::normalize()`) -
 * never a raw `CountryState` model, which would leak a `translations`
 * array into the JSON payload (see that class's own docblock for the
 * full, verified reasoning).
 *
 * Every assertion below exercises the REAL container-resolved classes -
 * `core()`/`app(CartRuleRepository::class)` - never a direct call to the
 * Platform subclass, so a regression in the binding itself would also be
 * caught here, not just a regression in the override logic.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Platform\Tenancy\Support\LocaleAwareCartRuleRepository;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Webkul\CartRule\Repositories\CartRuleRepository;
use Webkul\Core\Models\CountryState;
use Webkul\Core\Repositories\CountryStateRepository;

uses(PlatformIntegrationTestCase::class);

const CLSA_TENANT = 'clsa-tenant-a';

/**
 * Persistent, idempotently-reused fixture - the same discipline this
 * project's other Platform integration test files already use (e.g.
 * `SalesDataGridTimezoneFormattingTest.php`'s `ensureSdtfTenant()`).
 * Palestine seeding (16 governorates, ar+en translation rows) is part of
 * ordinary `provision()` for every tenant - no extra setup needed.
 */
function ensureClsaTenant(): Tenant
{
    $tenant = Tenant::find(CLSA_TENANT);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => CLSA_TENANT, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => CLSA_TENANT.'.platform.test']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
        $tenant = $tenant->fresh();
    }

    return $tenant->fresh();
}

beforeEach(function () {
    $this->tenant = ensureClsaTenant();
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

// --- A. Core / Arabic locale -------------------------------------------

test('1. core()->groupedStatesByCountries() returns Arabic-correct PS governorate names under the ar locale', function () {
    $names = $this->tenant->run(function () {
        app()->setLocale('ar');

        return collect(core()->groupedStatesByCountries()['PS'])->pluck('default_name', 'code');
    });

    expect($names['JEN'])->toBe('جنين');
    expect($names['GZA'])->toBe('غزة');
    expect($names)->toHaveCount(16);
});

// --- B. Core / English locale -------------------------------------------

test('2. core()->groupedStatesByCountries() returns genuine English PS governorate names under the en locale', function () {
    // Verified directly against this project's real seeded data before
    // writing this test (not assumed): country_state_translations has a
    // real, distinct English row for every one of the 16 PS governorates
    // (Platform\Tenancy\Support\PalestineGovernorates::ALL['JEN']['en'] ===
    // 'Jenin', etc.) - so this proves genuine translation, not merely the
    // Astrotomic fallback path (see test 11 for that).
    $names = $this->tenant->run(function () {
        app()->setLocale('en');

        return collect(core()->groupedStatesByCountries()['PS'])->pluck('default_name', 'code');
    });

    expect($names['JEN'])->toBe('Jenin');
    expect($names['GZA'])->toBe('Gaza');
    expect($names['RBH'])->toBe('Ramallah and Al-Bireh');
    expect($names)->toHaveCount(16);
});

// --- C. Identity preservation across locales ----------------------------

test('3. identity fields are identical between ar and en; only default_name differs', function () {
    [$ar, $en] = $this->tenant->run(function () {
        app()->setLocale('ar');
        $ar = collect(core()->groupedStatesByCountries()['PS'])->keyBy('code');

        app()->setLocale('en');
        $en = collect(core()->groupedStatesByCountries()['PS'])->keyBy('code');

        return [$ar, $en];
    });

    foreach ($ar as $code => $arState) {
        $enState = $en[$code];

        expect($enState->id)->toBe($arState->id);
        expect($enState->country_id)->toBe($arState->country_id);
        expect($enState->country_code)->toBe($arState->country_code);
        expect($enState->code)->toBe($arState->code);
        expect($enState->default_name)->not->toBe($arState->default_name);
    }
});

// --- D. Exact payload contract -------------------------------------------

test('4. every normalized Core state exposes exactly the legacy five-field shape, no translations leak', function () {
    $state = $this->tenant->run(function () {
        app()->setLocale('en');

        return core()->groupedStatesByCountries()['PS'][0];
    });

    expect(array_keys((array) $state))->toBe(['id', 'country_id', 'country_code', 'code', 'default_name']);
});

// --- E. Shop API contract --------------------------------------------------

test('5. the real shop.api.core.states endpoint returns localized, shape-correct PS states per locale', function () {
    $arResponse = $this->tenant->run(fn () => $this->getJson('http://'.CLSA_TENANT.'.platform.test/api/core/states?locale=ar'));
    $arResponse->assertOk();
    $arStates = collect($arResponse->json('data.PS'));

    expect($arStates)->toHaveCount(16);
    expect($arStates->firstWhere('code', 'JEN')['default_name'])->toBe('جنين');
    expect(array_keys($arStates->first()))->toBe(['id', 'country_id', 'country_code', 'code', 'default_name']);

    $enResponse = $this->tenant->run(fn () => $this->getJson('http://'.CLSA_TENANT.'.platform.test/api/core/states?locale=en'));
    $enResponse->assertOk();
    $enStates = collect($enResponse->json('data.PS'));

    expect($enStates->firstWhere('code', 'JEN')['default_name'])->toBe('Jenin');
    expect($enStates->pluck('id')->sort()->values()->all())->toBe($arStates->pluck('id')->sort()->values()->all());

    $raw = $enResponse->getContent();
    expect($raw)->not->toContain('"translations"');
});

// --- F. Admin consumer (base value != translated value, proves the fix path actually ran) ---

test('6. a real Admin page renders the tenant admin-locale-correct governorate name, not the base column value', function () {
    // Palestine's base `default_name` column is Arabic (C91) - an
    // Arabic-locale assertion here would pass even against the OLD,
    // unfixed raw-DB code, since base == ar translation. This test
    // deliberately switches the tenant's channel default locale to
    // English (a controlled fixture change, not a production concern)
    // so the assertion can only pass if LocaleAwareCore's translated
    // read genuinely ran - the unfixed code would still show the
    // Arabic base value regardless.
    $this->tenant->run(function () {
        DB::table('channels')->where('id', 1)->update(['default_locale_id' => DB::table('locales')->where('code', 'en')->value('id')]);

        if (! DB::table('admins')->where('email', 'clsa-admin@example.com')->exists()) {
            DB::table('admins')->insert([
                'name' => 'CLSA Admin',
                'email' => 'clsa-admin@example.com',
                'password' => bcrypt('clsa123'),
                'api_token' => Str::random(80),
                'status' => 1,
                'role_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $login = $this->post('http://'.CLSA_TENANT.'.platform.test/admin/login', [
        'email' => 'clsa-admin@example.com',
        'password' => 'clsa123',
    ]);
    $login->assertRedirect();

    $response = $this->get('http://'.CLSA_TENANT.'.platform.test/admin/settings/inventory-sources/create');
    $response->assertOk();
    $response->assertSee('Jenin');
    $response->assertDontSee('جنين');
});

// --- G. CartRule -----------------------------------------------------------

test('7. the container resolves LocaleAwareCartRuleRepository, and its groupedStatesByCountries() is locale-correct and shape-preserving', function () {
    [$repoClass, $ar, $en] = $this->tenant->run(function () {
        $repo = app(CartRuleRepository::class);
        $repoClass = get_class($repo);

        app()->setLocale('ar');
        $ar = collect($repo->groupedStatesByCountries())->firstWhere('id', 'PS');

        app()->setLocale('en');
        $en = collect($repo->groupedStatesByCountries())->firstWhere('id', 'PS');

        return [$repoClass, $ar, $en];
    });

    expect($repoClass)->toBe(LocaleAwareCartRuleRepository::class);

    expect($ar['id'])->toBe('PS');
    expect($en['id'])->toBe('PS');
    expect($ar['admin_name'])->toBe($en['admin_name']); // country name is out of R74's scope

    $arJen = collect($ar['states'])->first(fn ($s) => $s->code === 'JEN');
    $enJen = collect($en['states'])->first(fn ($s) => $s->code === 'JEN');

    expect($arJen->default_name)->toBe('جنين');
    expect($enJen->default_name)->toBe('Jenin');
    expect($enJen->id)->toBe($arJen->id);
    expect(array_keys((array) $enJen))->toBe(['id', 'country_id', 'country_code', 'code', 'default_name']);

    $encoded = json_encode($ar);
    expect($encoded)->not->toContain('"translations"');
});

// --- H. Fallback semantics (real data: US states have zero translation rows) ---

test('8. a state with no translation rows falls back to Bagisto/Astrotomic\'s own existing behavior, not a reimplemented algorithm', function () {
    [$normalized, $expected] = $this->tenant->run(function () {
        app()->setLocale('en');

        $normalized = collect(core()->groupedStatesByCountries()['US'])->firstWhere('code', 'AL');

        // The same value obtained directly through the real CountryState
        // model/Astrotomic accessor this Platform layer defers to - never
        // an independently hand-coded expectation.
        $model = CountryState::where('country_code', 'US')->where('code', 'AL')->first();
        $expected = $model->default_name;

        return [$normalized, $expected];
    });

    expect($normalized->default_name)->toBe($expected);
    expect($normalized->default_name)->toBe('Alabama'); // the real, pre-existing base column value
});

// --- I. Non-Palestine regression -------------------------------------------

test('9. a non-Palestine state (US/AL) keeps its identity and exact payload shape, unaffected by any Palestine-specific logic', function () {
    $state = $this->tenant->run(function () {
        app()->setLocale('en');

        return collect(core()->groupedStatesByCountries()['US'])->firstWhere('code', 'AL');
    });

    expect($state->code)->toBe('AL');
    expect($state->country_code)->toBe('US');
    expect(array_keys((array) $state))->toBe(['id', 'country_id', 'country_code', 'code', 'default_name']);
    expect($state->default_name)->toBe('Alabama');
});

// --- J. Locale isolation across sequential requests (cache-safety proof) ---

test('10. sequential ar -> en -> ar reads each reflect the current locale, proving the cached CountryStateRepository model graph stays locale-correct', function () {
    $sequence = $this->tenant->run(function () {
        // Force the repository's cache to actually populate first, so this
        // test proves correctness THROUGH a warm cache, not merely on a
        // cold first read - the exact scenario LocaleAwareCountryStates's
        // own docblock reasons about.
        app(CountryStateRepository::class)->all();

        app()->setLocale('ar');
        $first = core()->groupedStatesByCountries()['PS'][0]->default_name;

        app()->setLocale('en');
        $second = core()->groupedStatesByCountries()['PS'][0]->default_name;

        app()->setLocale('ar');
        $third = core()->groupedStatesByCountries()['PS'][0]->default_name;

        return [$first, $second, $third];
    });

    expect($sequence[0])->toBe('جنين');
    expect($sequence[1])->toBe('Jenin');
    expect($sequence[2])->toBe('جنين');
});
