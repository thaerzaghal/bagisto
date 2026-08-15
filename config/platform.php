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

    ],

];
