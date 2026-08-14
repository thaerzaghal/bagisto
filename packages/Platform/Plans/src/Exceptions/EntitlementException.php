<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions;

/**
 * TASK-ARCH-012. A pure marker interface - no methods of its own. Lets
 * enforcement code (e.g. Platform\Enforcement's exception renderer) catch
 * every "this tenant's entitlements don't permit that" failure mode
 * uniformly (NoPlanAssignedException, FeatureNotConfiguredException,
 * FeatureTypeMismatchException, LimitExceededException) without needing
 * to know or enumerate each concrete exception class, and without any of
 * those TASK-ARCH-008 exceptions changing behavior - implementing an
 * interface is purely additive.
 */
interface EntitlementException
{
}
