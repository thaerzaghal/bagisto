<?php

declare(strict_types=1);

namespace Platform\Signup\Support;

use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * TASK-MVP-007. Extracted from `Platform\Signup\Http\Controllers\
 * SignupController` (originally inline there, TASK-MVP-001) so that
 * `Platform\Admin\Http\Controllers\TenantController`'s new managed-
 * onboarding form can validate a tenant slug/owner email with the
 * IDENTICAL rules the public `/join` form always has - never a second,
 * independently-maintained slug/uniqueness policy that could silently
 * drift from this one. Both controllers call these same two methods;
 * neither has its own copy of the logic.
 */
class SignupValidationRules
{
    /**
     * @return array<int, mixed>
     */
    public static function slug(): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:32',
            // R19 (RISK_REGISTER.md): the physical tenant database
            // name is 'tenant'.$id, backtick-quoted but never
            // escaped by stancl/tenancy's own MySQLDatabaseManager -
            // this allowlist closes that gap at the input boundary
            // (no character this regex permits can break out of a
            // backtick-quoted MySQL identifier) while simultaneously
            // guaranteeing a valid DNS subdomain label.
            'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
            function ($attribute, $value, $fail) {
                $reserved = array_merge(
                    config('platform.signup.reserved_slugs', []),
                    config('tenancy.central_domains', []),
                );

                if (in_array($value, $reserved, true)) {
                    $fail('That store address is reserved. Please choose another.');
                }
            },
            function ($attribute, $value, $fail) {
                if (Tenant::whereKey($value)->exists()) {
                    $fail('That store address is already taken.');
                }
            },
            function ($attribute, $value, $fail) {
                $domain = $value.'.'.config('platform.base_domain');

                if (Domain::where('domain', $domain)->exists()) {
                    $fail('That store address is already taken.');
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function ownerEmail(): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            function ($attribute, $value, $fail) {
                if (Tenant::where('owner_email', $value)->exists()) {
                    $fail('An account with that email already exists.');
                }
            },
        ];
    }
}
