<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Webkul\CartRule\Repositories\CartRuleRepository;

/**
 * TASK-MVP-022 (RISK_REGISTER.md R74). Sibling to `LocaleAwareCore` -
 * overrides ONLY `groupedStatesByCountries()`; every other
 * `CartRuleRepository` method/property (including the constructor's
 * `protected $countryStateRepository`) is inherited unchanged. See
 * `LocaleAwareCountryStates`'s own docblock for the shared root-cause
 * record.
 *
 * Placed under `Platform\Tenancy\Support` (the same home as
 * `LocaleAwareCore`) rather than a new `Platform\CartRule` package - no
 * existing repository convention in this codebase requires a dedicated
 * package for a single decorating subclass, and this class has no
 * dependency of its own on anything CartRule-specific beyond the one
 * method it overrides.
 *
 * The `states` value for each country is built via `Collection::where()`
 * (not `groupBy()`) specifically to preserve the ORIGINAL method's own
 * array-key behavior - confirmed by direct inspection, not assumed:
 * upstream's raw `DB::table('country_states')->get()->where('country_id',
 * ...)` preserves each state's original position in the FULL states
 * fetch (not re-indexed per country), which - since PHP's `json_encode()`
 * treats a non-sequential-from-zero array as an object - already makes
 * `states` serialize as a JSON OBJECT keyed by that global position, not
 * a JSON array, in the shipped, unmodified `packages/Webkul` behavior
 * today. This override reproduces that exact quirk rather than
 * "fixing" it, since changing it is outside R74's approved scope.
 *
 * Bound in `TenancyServiceProvider::register()` via `$this->app->bind()`
 * (never `singleton()`), the same reasoning as `LocaleAwareCore`.
 */
class LocaleAwareCartRuleRepository extends CartRuleRepository
{
    /**
     * {@inheritDoc}
     */
    public function groupedStatesByCountries()
    {
        $collection = [];

        $countries = DB::table('countries')->get();

        $countriesStates = LocaleAwareCountryStates::normalize($this->countryStateRepository);

        foreach ($countries as $country) {
            $states = $countriesStates->where('country_id', $country->id);

            if (! count($states)) {
                continue;
            }

            $collection[] = [
                'id' => $country->code,
                'admin_name' => $country->name,
                'states' => $states,
            ];
        }

        return $collection;
    }
}
