<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

use Webkul\Core\Core;

/**
 * TASK-MVP-022 (RISK_REGISTER.md R74). Overrides ONLY
 * `groupedStatesByCountries()` - every other `Core` method/property is
 * inherited unchanged, including the constructor (its dependencies,
 * `$this->countryStateRepository` among them, are all `protected` on the
 * parent - confirmed by direct source reading - so no re-declaration is
 * needed here to reach it).
 *
 * See `LocaleAwareCountryStates`'s own docblock for the full root-cause
 * record and why the normalized output is a plain `stdClass`, never a
 * `CountryState` model.
 *
 * Bound in `TenancyServiceProvider::register()` via `$this->app->bind()`
 * (never `singleton()` - `Core::class` has no explicit binding upstream
 * today, so the container already constructs a fresh instance per
 * resolution; `bind()` preserves that exact semantics while substituting
 * this subclass, rather than introducing a NEW shared-instance behavior
 * that was never true before this task).
 */
class LocaleAwareCore extends Core
{
    /**
     * {@inheritDoc}
     */
    public function groupedStatesByCountries()
    {
        $collection = [];

        foreach (LocaleAwareCountryStates::normalize($this->countryStateRepository) as $state) {
            $collection[$state->country_code][] = $state;
        }

        return $collection;
    }
}
