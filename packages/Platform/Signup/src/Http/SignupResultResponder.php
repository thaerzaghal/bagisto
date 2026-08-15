<?php

declare(strict_types=1);

namespace Platform\Signup\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-MVP-001. Shared by `SignupController::store()` and
 * `SignupRetryController` so the two never diverge in how a
 * `MerchantOnboarding::register()/retry()` result is turned into a
 * response - a plain, small, single-purpose class rather than one
 * controller depending on the other.
 */
class SignupResultResponder
{
    public function respond(array $result): View|RedirectResponse
    {
        $tenant = $result['tenant'];

        if (! $result['succeeded']) {
            return view('signup::failed', [
                'tenant' => $tenant,
                'retryUrl' => URL::temporarySignedRoute(
                    'signup.retry.show',
                    now()->addHours(24),
                    ['tenant' => $tenant->getKey()]
                ),
            ]);
        }

        return redirect()->away($this->tenantAdminLoginUrl($tenant));
    }

    /**
     * Redirects to the merchant's OWN tenant Admin login - never an
     * auto-authenticated session. Central and tenant sessions are
     * deliberately isolated in this codebase (separate database
     * connections); bridging them would need a signed one-time login
     * token, a real but explicitly non-MVP enhancement. The merchant logs
     * in manually with the email/password they just chose.
     */
    protected function tenantAdminLoginUrl(Tenant $tenant): string
    {
        $domain = $tenant->domains()->first()->domain;
        $scheme = request()->isSecure() ? 'https' : 'http';

        return "{$scheme}://{$domain}/".config('app.admin_url').'/login?welcome=1';
    }
}
