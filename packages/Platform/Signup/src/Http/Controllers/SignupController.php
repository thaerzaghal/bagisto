<?php

declare(strict_types=1);

namespace Platform\Signup\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Platform\Signup\Http\SignupResultResponder;
use Platform\Signup\Services\MerchantOnboarding;
use Platform\Signup\Services\TurnstileVerifier;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * TASK-MVP-001. The public, unauthenticated merchant signup entry point.
 * Registered under the `platform` middleware group + Platform\Signup's
 * own `EnsureCentralDomain` (see that class's docblock) - structurally
 * the same "can never initialize tenancy" guarantee Platform Admin
 * already has (bootstrap/app.php), reused rather than re-derived.
 *
 * Validation deliberately never uses a bare `unique:tenants,...`/
 * `exists:...` string-table rule - see RISK_REGISTER.md R42
 * (TASK-ARCH-015): those rules query the given TABLE NAME against
 * whatever the ambient default DB connection is, with no awareness
 * `tenants`/`domains` are central-only. Every uniqueness check here
 * resolves through the `Tenant`/`Domain` models directly instead,
 * which is safe regardless of ambient connection state - the same fix
 * R42 already established, applied here from the start rather than
 * discovered the hard way a second time.
 *
 * TASK-MVP-006. `TurnstileVerifier::verify()` runs AFTER cheap local
 * validation but BEFORE `MerchantOnboarding::register()` - every real
 * side effect of a signup (Tenant/Domain rows, a physical MySQL
 * database, a full schema migration, a Subscription, an Admin) is
 * expensive, so nothing past this point ever runs for a request that
 * fails the challenge. A failure is reported via the exact same
 * `ValidationException`/`$errors` mechanism the field-level checks
 * above already use - no new response shape, no new view.
 */
class SignupController
{
    public function create(): View
    {
        return view('signup::create');
    }

    public function store(Request $request, MerchantOnboarding $onboarding, TurnstileVerifier $turnstile, SignupResultResponder $responder): View|RedirectResponse
    {
        $validated = $this->validated($request);

        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'turnstile' => 'We could not verify you are human. Please try again.',
            ]);
        }

        $domain = $validated['slug'].'.'.config('platform.base_domain');

        $result = $onboarding->register(
            $validated['slug'],
            $domain,
            $validated['owner_name'],
            $validated['owner_email'],
            $validated['password'],
        );

        return $responder->respond($result);
    }

    /**
     * @return array{slug: string, owner_name: string, owner_email: string, password: string}
     */
    protected function validated(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'slug' => [
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
            ],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => [
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (Tenant::where('owner_email', $value)->exists()) {
                        $fail('An account with that email already exists.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        return $validator->validate();
    }
}
