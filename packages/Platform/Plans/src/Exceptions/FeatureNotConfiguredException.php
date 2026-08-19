<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use Platform\Plans\Exceptions\Concerns\RendersAsEntitlementFailure;
use RuntimeException;

/**
 * TASK-ARCH-008. Thrown when a tenant's current plan has no
 * `plan_features` row at all for the requested feature code - fail loud
 * rather than silently treating "not configured" as "denied"/"zero limit",
 * which would be indistinguishable from a genuinely-seeded, deliberately
 * restrictive value. HTTP 422 rendering via `RendersAsEntitlementFailure`
 * (TASK-MVP-013, RISK_REGISTER.md R72) - see that trait's own docblock.
 */
class FeatureNotConfiguredException extends RuntimeException implements EntitlementException
{
    use RendersAsEntitlementFailure;
}
