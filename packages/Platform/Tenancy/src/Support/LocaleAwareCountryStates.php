<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

use Illuminate\Support\Collection;
use Webkul\Core\Repositories\CountryStateRepository;

/**
 * TASK-MVP-022 (RISK_REGISTER.md R74). Shared normalization step used by
 * both `LocaleAwareCore::groupedStatesByCountries()` and
 * `LocaleAwareCartRuleRepository::groupedStatesByCountries()` - the one
 * piece of logic those two otherwise-unrelated overrides would each
 * duplicate.
 *
 * ROOT CAUSE (confirmed by reading source, not assumed): both upstream
 * methods read `country_states` via a raw `DB::table(...)->get()`,
 * bypassing `Webkul\Core\Models\CountryState` entirely - a
 * `Webkul\Core\Eloquent\TranslatableModel` (Astrotomic Translatable,
 * `translatedAttributes = ['default_name']`, `$with = ['translations']`).
 * The raw query therefore always returns whatever single language was
 * written into the base `default_name` column at seed time, never the
 * current request's locale.
 *
 * WHY NOT RETURN `CountryState` MODELS DIRECTLY (a real, verified
 * behavioral difference, not assumed invisible): `CountryState` declares
 * no `$hidden`, so a loaded model's `toArray()`/JSON output includes the
 * eager-loaded `translations` relation as a nested array in addition to
 * the flattened `default_name` - a field that never existed in the
 * original raw-stdClass payload every one of this method's ~13 real
 * consumers (Shop checkout/address, six Admin Blade forms, the Shop
 * `api/core/states` JSON endpoint) already expects. This method
 * therefore normalizes each model down to a plain `stdClass` carrying
 * EXACTLY the original five `country_states` columns
 * (`id`/`country_id`/`country_code`/`code`/`default_name`) - nothing
 * else - preserving the external payload contract byte-for-byte except
 * for `default_name`'s value.
 *
 * WHY THE PRETTUS CACHE ON `CountryStateRepository` (`config/
 * repository.php`) IS SAFE HERE, NOT A NEW R73/C95-STYLE BUG (verified,
 * not assumed): Prettus caches the raw Eloquent Collection `all()`
 * returns - live `CountryState` model instances with `translations`
 * already eager-loaded (all locales present, never locale-filtered).
 * `Translatable::getAttribute()` resolves `default_name` LAZILY, using
 * `app()->getLocale()` AT ACCESS TIME - not whatever locale happened to
 * be active when the cache was first populated. So even a cache hit
 * shared across two different-locale requests to the exact same URL
 * (Prettus's own cache key includes `$request->fullUrl()`, and the Shop
 * `api/core/states` endpoint's URL never carries a `?locale=` query
 * param) still yields a correct, current-locale value once THIS method
 * reads `->default_name` on each model - because that read happens fresh
 * on every call to `normalize()`, never once at cache-population time.
 * This is the reverse of R73/C95's bug class (a stale WRITE bypassing
 * cache invalidation) - this is a READ of already-live, locale-lazy
 * models, safe by construction. No locale-keyed caching is added here,
 * and none is needed.
 *
 * FALLBACK SEMANTICS ARE NEVER REIMPLEMENTED HERE: `default_name` is
 * read via the model's own Astrotomic accessor, which already applies
 * this project's configured `use_fallback`/`fallback_locale=ar`/
 * `use_property_fallback` behavior (`config/translatable.php`) - the
 * exact same mechanism every other translated field in this codebase
 * (product/category names, etc.) already relies on, and the exact
 * mechanism `Core::findStateByCountryCode()` (unmodified `packages/
 * Webkul`) already exercises correctly today via this same repository.
 */
class LocaleAwareCountryStates
{
    /**
     * @return Collection<int, \stdClass>
     */
    public static function normalize(CountryStateRepository $countryStateRepository): Collection
    {
        return $countryStateRepository->all()->map(function ($state) {
            return (object) [
                'id' => $state->id,
                'country_id' => $state->country_id,
                'country_code' => $state->country_code,
                'code' => $state->code,
                'default_name' => $state->default_name,
            ];
        });
    }
}
