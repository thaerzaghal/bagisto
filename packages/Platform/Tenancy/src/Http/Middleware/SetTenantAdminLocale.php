<?php

declare(strict_types=1);

namespace Platform\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * TASK-MVP-012 (DECISION_LOG.md). Gives tenant Admin (`packages/Webkul/Admin`)
 * requests the tenant's own default locale (Arabic-first for every newly
 * provisioned tenant - see `Platform\Tenancy\Services\TenantProvisioner::
 * ensureArabicLocaleSeeded()`).
 *
 * WHY THIS IS NEEDED (verified by direct audit, not assumed): Bagisto
 * Admin has NO locale-selection mechanism of its own at all - no `locale`
 * column on `admins`, no locale middleware registered anywhere in
 * `Webkul\Admin\Providers\AdminServiceProvider` (unlike the Shop theme's
 * own `Webkul\Shop\Http\Middleware\Locale`, which resolves per-request
 * from a query param/session/the current channel's default locale).
 * Without this class, Admin's active locale is simply whatever the
 * single, process-wide `config('app.locale')` happens to be at boot -
 * shared by EVERY tenant AND Platform Admin alike, since this is one
 * shared codebase/process. That is exactly why blindly setting
 * `APP_LOCALE=ar` in the shared `.env` was rejected as the fix (TASK-
 * MVP-012 checkpoint, items 18/20): it would make Platform Admin Arabic
 * too, and it could never vary per tenant in the first place.
 *
 * SOURCE OF TRUTH: the SAME value `Webkul\Shop\Http\Middleware\Locale`
 * already trusts for its own channel-default fallback -
 * `core()->getCurrentChannel()->default_locale->code` - so Admin and Shop
 * can never disagree about a given tenant's language. Deliberately no
 * new persisted "Admin locale" column/table/setting is introduced (a
 * deliberate product decision - see DECISION_LOG.md); Admin simply
 * follows whatever the tenant's own channel is already configured with,
 * the exact same value `TenantProvisioner::ensureArabicLocaleSeeded()`
 * writes.
 *
 * REGISTRATION AND SCOPE: appended to the 'web' middleware GROUP itself
 * (`bootstrap/app.php`), the same established extension point
 * `Platform\Signup\Http\Middleware\FlagFirstLoginWelcome`/
 * `TenantAccessGate`/`InitializeTenancyByDomain` already use -
 * `packages/Webkul/Admin` has no route-group-level extension point of
 * its own to hook safely (a route-name-targeted `$this->app->booted()`
 * registration was already tried and abandoned for a different feature,
 * see `FlagFirstLoginWelcome`'s own docblock / RISK_REGISTER.md R50).
 * Runs on every 'web'-group request (Shop, tenant Admin - never Platform
 * Admin, which uses the entirely separate 'platform' group that never
 * initializes tenancy at all) but is a no-op for anything that is not
 * genuinely an Admin route (`Request::routeIs('admin.*')`, safe to call
 * from 'web'-group middleware since routing has already matched by the
 * time group middleware runs) with tenancy actually initialized - Shop's
 * own, already-correct `Locale` middleware (query param + session +
 * channel fallback) is completely untouched and always wins for Shop
 * requests, since this class never acts on them.
 *
 * NO EXPLICIT RESTORE STEP, unlike `OwnerActivationMailer`'s
 * `URL::forceRootUrl()` (TASK-MVP-007/R69) or its own locale handling
 * (TASK-MVP-012): correct and safe ONLY because this project runs
 * synchronous PHP-FPM, never Octane/a persistent worker (see
 * `docs/architecture/production-deployment.md`) - each HTTP request
 * boots an entirely fresh `Illuminate\Foundation\Application` instance,
 * so `app()->setLocale()` here can never bleed into a later, unrelated
 * request or a different tenant. Revisit this class the moment Octane
 * adoption (RISK_REGISTER.md R8/R12, currently deferred) is ever
 * seriously considered.
 */
class SetTenantAdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('admin.*') && $this->tenancyInitialized()) {
            $this->applyTenantLocale();
        }

        return $next($request);
    }

    protected function tenancyInitialized(): bool
    {
        try {
            return (bool) tenancy()->initialized;
        } catch (Throwable) {
            return false;
        }
    }

    protected function applyTenantLocale(): void
    {
        try {
            $localeCode = core()->getCurrentChannel()?->default_locale?->code;

            if ($localeCode) {
                app()->setLocale($localeCode);
            }
        } catch (Throwable) {
            // Never let a locale-resolution failure break Admin rendering -
            // falls back to whatever config('app.locale') already is.
        }
    }
}
