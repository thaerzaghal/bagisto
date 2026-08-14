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

];
