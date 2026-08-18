<?php

declare(strict_types=1);

namespace Platform\Signup\Services;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Platform\Tenancy\Models\Tenant;
use Throwable;

/**
 * TASK-MVP-007. The credential mechanism for Platform-Admin-managed
 * merchant creation: the operator never chooses or sees the merchant's
 * password (`Platform\Admin\Http\Controllers\TenantController::store()`
 * generates a random, cryptographically secure one purely to satisfy
 * `MerchantOnboarding::register()`'s existing signature, then discards
 * it) - instead, this class triggers the EXACT SAME real password-reset
 * mechanism already proven end-to-end in production (TASK-MVP-004, real
 * Zoho SMTP delivery, real browser reset) so the merchant sets their OWN
 * password as their very first action. No custom activation-token
 * system, no manually-constructed reset URL - `Illuminate\Auth\
 * Notifications\ResetPassword`/`PasswordBroker` (the identical broker
 * `Webkul\Admin\Http\Controllers\User\ForgetPasswordController::store()`
 * itself calls for the real `/admin/forget-password` form) do the actual
 * work.
 *
 * PROVISIONING SUCCESS AND ACTIVATION-EMAIL DELIVERY ARE TWO SEPARATE
 * CONCERNS (explicit product requirement): `send()` never throws - any
 * failure (SMTP unreachable, DNS failure, an unexpected broker outcome)
 * is caught and reported via Laravel's own `report()`, returning `false`
 * instead. The tenant this is called for is ALREADY `Ready` by the time
 * this runs (see `TenantController::store()`) - a failed activation
 * email must never roll back, downgrade, or otherwise affect that
 * already-successful provisioning. Callable again later, independently,
 * via `TenantController::resendActivation()` - no re-provisioning
 * involved, since this class has no dependency on `TenantProvisioner` at
 * all.
 *
 * PRODUCTION REGRESSION, found live via the real `mvp007-check`
 * managed-onboarding verification: the reset link the merchant received
 * pointed at the CENTRAL domain (`https://app.technify.dev/admin/
 * reset-password/...`), not their own tenant domain - a 404 for the
 * merchant. Root cause: `$tenant->run()` (see `TenantRun::run()` in
 * stancl/tenancy) only runs the configured tenancy BOOTSTRAPPERS
 * (database/cache/filesystem - see `config/tenancy.php`) - it never
 * rebinds the current `Illuminate\Http\Request` or touches
 * `Illuminate\Routing\UrlGenerator`'s root. This method is always called
 * from a CENTRAL Platform Admin HTTP request (`TenantController::
 * store()`/`resendActivation()`), so `route('admin.reset_password.
 * create', $token)` - called deep inside Bagisto's own, unmodified
 * `Webkul\Admin\Mail\Admin\ResetPasswordNotification::toMail()` view -
 * resolved against that central request's own root the whole time.
 * Tenancy initialization is necessary (it's WHY the token itself is
 * correctly created against the tenant's own `admin_password_resets`
 * table and the correct tenant Admin is found) but was never sufficient
 * for URL generation, a wholly separate Laravel subsystem.
 *
 * FIX: `URL::forceRootUrl()` (Laravel's own supported mechanism for
 * exactly this - "generate URLs against a different root than the
 * current request's") is scoped tightly around the `sendResetLink()`
 * call only, using the SAME tenant-domain/scheme resolution `Platform\
 * Signup\Http\SignupResultResponder::tenantAdminLoginUrl()` already
 * uses and has proven correct in production since TASK-MVP-001 - no
 * second domain/scheme policy invented here. Restoration does NOT
 * assume `forceRootUrl(null)` is equivalent to "the previous state":
 * `url('/')` is captured BEFORE forcing and restored via `forceRootUrl()`
 * in a `finally` block - mirroring the exact save/restore idiom
 * Bagisto's own (unmodified) `Webkul\Sitemap\Jobs\ProcessSitemap` already
 * uses for this identical operation, read here only as a reference for
 * the already-accepted pattern, never modified. This is safe against
 * cross-tenant leakage because this project is synchronous PHP-FPM only
 * (no Octane/persistent worker, see docs/architecture/
 * production-deployment.md) - the forced root lives only for the
 * duration of this one method call, inside the one HTTP request that
 * invoked it, and is unconditionally restored before that request's
 * response is even built.
 */
class OwnerActivationMailer
{
    public function send(Tenant $tenant): bool
    {
        $domain = $tenant->domains()->orderBy('id')->value('domain');
        $scheme = request()->isSecure() ? 'https' : 'http';

        return (bool) $tenant->run(function () use ($tenant, $domain, $scheme) {
            $originalRoot = url('/');

            try {
                URL::forceRootUrl("{$scheme}://{$domain}");

                $status = Password::broker('admins')->sendResetLink(['email' => $tenant->owner_email]);

                return $status === Password::RESET_LINK_SENT;
            } catch (Throwable $e) {
                report($e);

                return false;
            } finally {
                URL::forceRootUrl($originalRoot);
            }
        });
    }
}
