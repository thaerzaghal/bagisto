<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\View\View;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-011. Every metric here is a live COUNT query against the
 * central `tenants`/`plans` tables (Platform\Tenancy\Models\Tenant and
 * Platform\Plans\Models\Plan are both structurally central-only - see
 * their own docblocks) - nothing cached, nothing invented/hardcoded, per
 * the task brief's "Do not invent fake metrics."
 */
class DashboardController
{
    public function index(): View
    {
        return view('platform::dashboard', [
            'tenantCount' => Tenant::count(),
            'readyTenantCount' => Tenant::where('status', TenantStatus::Ready)->count(),
            'failedTenantCount' => Tenant::where('status', TenantStatus::Failed)->count(),
            'activePlanCount' => Plan::where('is_active', true)->count(),
        ]);
    }
}
