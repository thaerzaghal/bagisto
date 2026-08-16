<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-MVP-004A. Comma-separated env-value parser shared by TRUSTED_PROXIES
 * (bootstrap/app.php) and PLATFORM_CENTRAL_DOMAINS (config/tenancy.php) -
 * both need the exact same "trim whitespace, drop empty entries" semantics,
 * kept here once instead of duplicated inline in two config files. Pure and
 * dependency-free so it is directly unit-testable without booting the app.
 */
class EnvList
{
    /**
     * @return list<string>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $value) => $value !== ''
        ));
    }
}
