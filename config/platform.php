<?php

declare(strict_types=1);

/**
 * TASK-ARCH-008. Root config file (matching config/tenancy.php,
 * config/elasticsearch.php - Platform packages currently use plain root
 * config files rather than package-published/merged config, since none
 * of them have needed per-package config isolation yet).
 */
return [

    'plans' => [

        /**
         * Platform\Tenancy\Services\TenantProvisioner::
         * ensureDefaultPlanAssigned() looks up this CODE (never a raw
         * database id - ids are environment-specific/seed-order-
         * dependent, codes are a stable, human-chosen identifier) and
         * fails provisioning loudly if no plan with this code exists.
         * Run `php artisan platform:plans:seed` once per environment
         * before provisioning any tenant.
         */
        'default_code' => env('PLATFORM_DEFAULT_PLAN_CODE', 'free'),

    ],

    /**
     * TASK-MVP-001. The root domain self-service signup builds tenant
     * subdomains under (`{slug}.{base_domain}`). Local/dev default only -
     * the real production value is a TASK-MVP-004 concern (real domain,
     * SSL, `tenancy.central_domains`), deliberately not decided here.
     */
    'base_domain' => env('PLATFORM_BASE_DOMAIN', 'platform.test'),

    'signup' => [

        /**
         * TASK-MVP-007. Master switch for the entire PUBLIC, anonymous
         * self-service signup flow (`GET`/`POST /join`) - see
         * `Platform\Signup\Http\Middleware\EnsurePublicSignupEnabled`.
         * `false` (the production default/posture) means both routes
         * 404 - a deliberate PRODUCT decision, not a temporary flag: the
         * initial commercial/pilot phase uses MANAGED onboarding only
         * (Platform Admin creates merchants directly - see
         * `Platform\Admin\Http\Controllers\TenantController::store()`),
         * reusing the exact same `Platform\Signup\Services\
         * MerchantOnboarding`/`TenantProvisioner` pipeline this flag
         * gates for the public path. Does NOT affect the already-created-
         * tenant signed retry flow (`/join/retry/{tenant}`), which stays
         * available regardless of this flag - see that route's own
         * docblock in `signup-routes.php` for why. See
         * docs/architecture/onboarding.md for the full policy record.
         */
        'enabled' => (bool) env('PUBLIC_SIGNUP_ENABLED', false),

        /**
         * TASK-MVP-001. Slugs a merchant may not claim as their own store
         * address, checked in addition to (never instead of) the
         * database-level uniqueness check against existing tenants. Kept
         * here, centrally configurable, rather than hardcoded inside
         * Platform\Signup's own validation logic - `config('tenancy.
         * central_domains')` is merged in at the point of use too (a
         * slug identical to a configured central domain must also be
         * rejected), so this list only needs genuinely platform-specific
         * reserved words, not every central domain by hand.
         */
        'reserved_slugs' => [
            'www',
            'admin',
            'api',
            'platform',
            'app',
            'mail',
            'central',
        ],

        /**
         * TASK-MVP-006. Server-side gate `Platform\Signup\Services\
         * TurnstileVerifier` reads - see that class's own docblock for the
         * full fail-closed-when-enabled contract. `enabled => false` (the
         * default) is the deliberate posture for local dev, automated
         * tests, and an invited-only pilot where public signup abuse is
         * not yet a realistic threat - `php artisan platform:production:check`
         * WARNs (never fails) when disabled, since that is a legitimate
         * choice, not a misconfiguration, until signup is actually opened
         * publicly. `site_key` is safe to render into the signup page's
         * HTML/JS; `secret_key` is server-only and must never reach a
         * response body.
         */
        'turnstile' => [
            'enabled' => (bool) env('TURNSTILE_ENABLED', false),
            'site_key' => env('TURNSTILE_SITE_KEY', ''),
            'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
        ],

    ],

];
