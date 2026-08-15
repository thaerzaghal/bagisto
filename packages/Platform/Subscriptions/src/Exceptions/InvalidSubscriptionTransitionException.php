<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-016. Mirrors `Platform\Tenancy\Exceptions\
 * InvalidTenantTransitionException`'s shape and role exactly: thrown by
 * `SubscriptionLifecycle` for any attempted transition the matrix in that
 * class's own docblock doesn't allow, rather than silently no-op-ing or
 * corrupting state. Caught directly by `Platform\Admin\Http\Controllers\
 * SubscriptionController`, the same pattern already established for
 * tenant suspend/reactivate.
 */
class InvalidSubscriptionTransitionException extends RuntimeException
{
}
