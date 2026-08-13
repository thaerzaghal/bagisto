<?php

declare(strict_types=1);

namespace Platform\Tenancy\Enums;

/**
 * Tenant lifecycle state. Deliberately does not yet include the full state
 * machine from docs/architecture/provisioning.md (e.g. distinct DELETING vs
 * DELETED) — TASK-ARCH-002 only implements the states actually exercised by
 * TenantProvisioner. Suspended/Deleting/Deleted are defined now (cheap, and
 * referenced by the provisioning docs) but nothing in this task transitions
 * a tenant into them yet; that's platform-admin / Phase 8+ work.
 */
enum TenantStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
    case Suspended = 'suspended';
    case Deleting = 'deleting';
    case Deleted = 'deleted';

    /**
     * Statuses from which (re)provisioning is a valid, safe operation.
     */
    public function isProvisionable(): bool
    {
        return in_array($this, [self::Pending, self::Provisioning, self::Failed], true);
    }
}
