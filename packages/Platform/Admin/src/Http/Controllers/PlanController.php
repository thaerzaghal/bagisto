<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\View\View;
use Platform\Plans\Models\Plan;

/**
 * TASK-ARCH-011. Read-only listing of the real Platform\Plans domain
 * (Platform\Plans\Models\Plan, central-only - see that model's docblock).
 * Deliberately shows only fields that already exist on `plans`/
 * `plan_features` (code, name, is_active, sort_order, feature count) -
 * no pricing/billing fields, since those tables don't have any and this
 * task explicitly excludes subscriptions/billing.
 */
class PlanController
{
    public function index(): View
    {
        return view('platform::plans.index', [
            'plans' => Plan::withCount('features')->orderBy('sort_order')->get(),
        ]);
    }
}
