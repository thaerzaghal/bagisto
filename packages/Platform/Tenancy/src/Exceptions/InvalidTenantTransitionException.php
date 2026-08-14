<?php

declare(strict_types=1);

namespace Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * TASK-ARCH-013. Thrown by Platform\Tenancy\Services\TenantLifecycle when
 * asked to move a tenant through a transition its CURRENT status does not
 * allow (e.g. suspending an already-Suspended tenant, reactivating a
 * Ready one) - fail loud rather than silently no-op or silently allow an
 * unintended status overwrite. Mirrors the same "fail clean, don't
 * mutate state through a controller directly" posture
 * `TenantProvisioner::provision()` already uses for its own invalid-status
 * guard (a plain `RuntimeException` there too).
 */
class InvalidTenantTransitionException extends RuntimeException
{
}
