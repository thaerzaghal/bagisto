<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;

/**
 * TASK-ARCH-015. Platform Admin CRUD for the `Platform\Plans\Models\Plan`
 * domain (TASK-ARCH-008/TASK-ARCH-011 shipped the data model and a
 * read-only listing only - creating/editing a plan or changing its
 * active flag previously required tinker/raw SQL/editing `PlanSeeder`
 * and redeploying). Strictly entitlement-focused, per this task's own
 * explicit instruction: no pricing/monetary/trial fields exist on `plans`
 * and none are introduced here - see docs/architecture/feature-limits.md
 * "Plan management" for the full design and DECISION_LOG.md for why.
 *
 * PLAN CODE POLICY: `code` is settable only at creation (store()) and is
 * never accepted by update() - see that method's own docblock for why.
 *
 * Every method here reads/writes ONLY the central `plans`/`plan_features`
 * tables (both CentralConnection-backed, see Plan's own docblock) -
 * nothing here ever initializes tenancy or touches a tenant database.
 */
class PlanController
{
    public function index(): View
    {
        return view('platform::plans.index', [
            'plans' => Plan::withCount('features')->orderBy('sort_order')->get(),
        ]);
    }

    public function create(): View
    {
        return view('platform::plans.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // TASK-ARCH-015: `Rule::unique()` queries the given TABLE NAME
            // against whatever the ambient "default" DB connection
            // currently is - it has no awareness that `plans` is
            // central-only. Explicitly connection-qualifying the table
            // name (`{connection}.plans`, a Laravel-supported syntax)
            // makes this rule always resolve against the SAME connection
            // Plan's own CentralConnection trait would, regardless of
            // ambient state - see TenantController::changePlan()'s
            // docblock for the concrete failure mode this avoids
            // (RISK_REGISTER.md, this task).
            'code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/', Rule::unique((new Plan)->getConnectionName().'.plans', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Plan code may only contain lowercase letters, numbers, hyphens, and underscores.',
        ]);

        $plan = Plan::create([
            // Normalized consistently: lowercased, trimmed - matches the
            // existing seeded convention ('free'/'basic'/'pro') and avoids
            // two codes differing only by case/whitespace ever coexisting.
            'code' => Str::of($validated['code'])->trim()->lower()->value(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('platform.plans.show', $plan)->with('status', "Plan [{$plan->code}] created.");
    }

    public function show(Plan $plan): View
    {
        $features = $plan->features()->orderBy('feature_code')->get();

        return view('platform::plans.show', [
            'plan' => $plan,
            'features' => $features,
            'availableFeatureCodes' => collect(FeatureCode::cases())
                ->reject(fn ($case) => $features->contains('feature_code', $case->value)),
            'featureTypes' => FeatureType::cases(),
        ]);
    }

    /**
     * `code` is deliberately NOT accepted here - see class docblock /
     * docs/architecture/feature-limits.md "Plan code policy". Only
     * name/description/sort_order/is_active are editable metadata.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $plan->update($validated);

        return redirect()->route('platform.plans.show', $plan)->with('status', "Plan [{$plan->code}] updated.");
    }

    /**
     * TASK-ARCH-015 "Plan deactivation semantics" (docs/architecture/
     * feature-limits.md): deactivation is the ONLY lifecycle mechanism for
     * a plan in this task - there is no hard delete. Flips `is_active`
     * only; never touches `tenants.plan_id` for any tenant, never queries
     * or initializes any tenant database. A tenant already assigned to
     * this plan keeps it and keeps resolving its entitlements from it
     * completely normally - `TenantEntitlements`/`TenantLimits` never
     * check `is_active` at all (confirmed by reading both classes; that
     * flag is consulted in exactly one place, `TenantPlanAssignment::
     * assign()`, which only gates NEW/manual assignment). Proven live:
     * tests/Feature/Platform/PlatformPlanManagementTest.php.
     */
    public function deactivate(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => false]);

        return back()->with('status', "Plan [{$plan->code}] deactivated. Existing tenants on this plan are unaffected.");
    }

    public function activate(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => true]);

        return back()->with('status', "Plan [{$plan->code}] activated.");
    }
}
