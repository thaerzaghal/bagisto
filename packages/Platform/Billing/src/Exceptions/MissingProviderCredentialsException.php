<?php

declare(strict_types=1);

namespace Platform\Billing\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-018 (task section 4). Thrown at the point a provider adapter
 * is actually constructed/used with missing required credentials - never
 * during application boot merely because a .env value is blank and
 * nothing has tried to use billing yet.
 */
class MissingProviderCredentialsException extends RuntimeException
{
    public function __construct(public readonly string $provider, public readonly string $missingEnvVar)
    {
        parent::__construct(
            "Billing provider [{$provider}] is selected but its required credential [{$missingEnvVar}] is not configured."
        );
    }
}
