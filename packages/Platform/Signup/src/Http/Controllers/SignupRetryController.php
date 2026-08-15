<?php

declare(strict_types=1);

namespace Platform\Signup\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Platform\Signup\Http\SignupResultResponder;
use Platform\Signup\Services\MerchantOnboarding;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-MVP-001. REQUIRED SECURITY ADJUSTMENT from the approved plan:
 * authorization to retry a failed signup is NEVER "knowledge of the
 * tenant id/slug" (public information - it's the merchant's own
 * subdomain) - both routes require Laravel's own `signed` middleware
 * (`Illuminate\Routing\Middleware\ValidateSignature`), i.e. a
 * cryptographically signed, time-limited URL that only ever exists
 * because it was handed directly to the merchant on their own failed
 * signup attempt's response page (see SignupResultResponder). A request
 * with a missing/tampered/expired signature never reaches either method
 * below at all - enforced by route middleware, not application logic
 * that could be gotten wrong here.
 *
 * `show()` mints a FRESH signed URL for the POST target every time the
 * retry form is rendered, rather than reusing the GET link's own
 * signature for both verbs - two independently-signed, single-purpose
 * URLs is the more standard `URL::temporarySignedRoute()` usage and
 * avoids any question about whether one signature is valid for two
 * different route names.
 *
 * Both methods additionally re-check `TenantStatus::isProvisionable()`
 * (the exact same guard `TenantProvisioner::provision()` itself already
 * enforces) - belt-and-braces, since `provision()`'s own top-of-method
 * `if (status === Ready) return;` already makes a stale/reused signed
 * link against an ALREADY-Ready tenant a safe no-op (the owner-admin
 * identity step is never reached again), but a clear "already set up"
 * page is better UX than a silent no-op redirect.
 */
class SignupRetryController
{
    public function show(Request $request, Tenant $tenant): View
    {
        if (! $tenant->status->isProvisionable()) {
            return view('signup::retry-unavailable', ['tenant' => $tenant]);
        }

        return view('signup::retry', [
            'tenant' => $tenant,
            'submitUrl' => URL::temporarySignedRoute(
                'signup.retry.store',
                now()->addHours(24),
                ['tenant' => $tenant->getKey()]
            ),
        ]);
    }

    public function store(Request $request, Tenant $tenant, MerchantOnboarding $onboarding, SignupResultResponder $responder): View|RedirectResponse
    {
        if (! $tenant->status->isProvisionable()) {
            return view('signup::retry-unavailable', ['tenant' => $tenant]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $onboarding->retry($tenant, $validated['password']);

        return $responder->respond($result);
    }
}
