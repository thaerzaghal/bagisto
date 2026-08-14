<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-008. Thrown when TenantEntitlements::can() is called against a
 * feature configured as 'numeric'/'unlimited', or ::limit() is called
 * against a feature configured as 'boolean' - calling the wrong accessor
 * for a feature's configured type is a caller bug, not a data question,
 * so it fails loud rather than silently coercing (e.g. a numeric limit of
 * 0 is NOT the same statement as a boolean feature being false).
 */
class FeatureTypeMismatchException extends RuntimeException implements EntitlementException
{
}
