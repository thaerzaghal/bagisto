<?php

declare(strict_types=1);

namespace Platform\Admin\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TASK-ARCH-011. A PLATFORM admin - manages tenants/plans/provisioning for
 * the SaaS business itself, never a tenant's storefront/store-admin.
 *
 * Uses `Stancl\Tenancy\Database\Concerns\CentralConnection` - the identical
 * mechanism `Platform\Plans\Models\Plan` already relies on (see that
 * model's docblock): `getConnectionName()` reads
 * `config('tenancy.database.central_connection')` fresh on every call, so
 * every query through this model always hits the central connection
 * regardless of whatever tenant context is (or isn't) currently active.
 * In practice this is somewhat belt-and-braces here: Platform Admin routes
 * never run `InitializeTenancyByDomain` at all (see
 * Platform\Admin\Providers\PlatformAdminServiceProvider::boot() and
 * docs/architecture/platform-admin.md, "Central routing boundary"), so no
 * tenant connection is ever active while this model is used in practice -
 * but the trait guarantees it structurally, the same way it does for Plan,
 * rather than relying only on "no code path currently switches tenants
 * here."
 *
 * Deliberately NOT related to Webkul\User\Models\Admin (Bagisto's own
 * tenant-scoped admin/store-staff model) in any way - no shared table, no
 * shared guard, no shared password, no automatic promotion in either
 * direction. See RISK_REGISTER.md / docs/architecture/platform-admin.md
 * for why that separation is a hard requirement, not a convenience.
 */
class PlatformUser extends Authenticatable
{
    use CentralConnection;

    protected $table = 'platform_users';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
