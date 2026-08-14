<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-008. Thrown when a tenant's current plan has no
 * `plan_features` row at all for the requested feature code - fail loud
 * rather than silently treating "not configured" as "denied"/"zero limit",
 * which would be indistinguishable from a genuinely-seeded, deliberately
 * restrictive value.
 */
class FeatureNotConfiguredException extends RuntimeException implements EntitlementException
{
}
