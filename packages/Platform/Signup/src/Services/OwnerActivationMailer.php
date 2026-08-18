<?php

declare(strict_types=1);

namespace Platform\Signup\Services;

use Illuminate\Support\Facades\Password;
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
 */
class OwnerActivationMailer
{
    public function send(Tenant $tenant): bool
    {
        return (bool) $tenant->run(function () use ($tenant) {
            try {
                $status = Password::broker('admins')->sendResetLink(['email' => $tenant->owner_email]);

                return $status === Password::RESET_LINK_SENT;
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        });
    }
}
