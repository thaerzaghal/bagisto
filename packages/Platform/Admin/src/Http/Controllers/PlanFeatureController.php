<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;
use Platform\Plans\Models\PlanFeature;

/**
 * TASK-ARCH-015. Platform Admin CRUD for `Platform\Plans\Models\PlanFeature`
 * rows nested under a plan. Deliberately scoped to the existing
 * `Platform\Plans\Enums\FeatureCode` enum in the UI - per this task's
 * explicit instruction, arbitrary free-text feature codes are NOT exposed
 * here even though the underlying `feature_code` column stays a plain,
 * un-cast string at the model/schema level (unchanged - see PlanFeature's
 * own docblock for why that stays open for future centrally-configured
 * codes not yet enumerated in code).
 *
 * TYPE-DEPENDENT VALUE VALIDATION (task item 4): built from reading
 * Platform\Plans\Services\TenantEntitlements::can()/limit() directly
 * (not guessed) - boolean reads `(bool) $row->value`, so value must be a
 * clean 0/1; numeric returns `$row->value` as-is and TenantLimits::
 * assertWithinLimit()/remaining() only ever do meaningful, safe integer
 * comparisons against a non-negative cap, so value must be a non-negative
 * integer; unlimited's `value` is never read at all (TenantEntitlements::
 * limit() returns null unconditionally before checking it), so this
 * controller forces it to null server-side regardless of what a client
 * sends - "type = unlimited, value = 10" (the task's own example of an
 * inconsistent state) can never be persisted.
 *
 * DUPLICATE-FEATURE INVARIANT (task item 5): investigated before writing
 * this - `database/migrations/2026_08_14_130001_create_plan_features_table.php`
 * ALREADY has `$table->unique(['plan_id', 'feature_code'])` from
 * TASK-ARCH-008 - the database-level half of this invariant already
 * existed and needed no new migration. This controller adds the missing
 * application-level half (a scoped `Rule::unique` producing a clean
 * validation error) so a duplicate attempt fails with a normal form error
 * instead of a raw `QueryException`/500 - see docs/architecture/
 * feature-limits.md for the documented finding.
 */
class PlanFeatureController
{
    public function store(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validateFeature($request, [
            // TASK-ARCH-015: connection-qualified for the same reason
            // PlanController::store()'s own `Rule::unique` is - see that
            // rule's inline comment / TenantController::changePlan()'s
            // docblock for the concrete failure mode this avoids.
            'feature_code' => [
                'required',
                Rule::enum(FeatureCode::class),
                Rule::unique((new PlanFeature)->getConnectionName().'.plan_features', 'feature_code')->where('plan_id', $plan->id),
            ],
        ]);

        $plan->features()->create($validated);

        return redirect()->route('platform.plans.show', $plan)->with('status', 'Feature added.');
    }

    /**
     * `feature_code` is deliberately NOT editable, for the same
     * immutability reasoning as a plan's own `code` - changing which
     * feature a row represents is conceptually "remove this entitlement,
     * add a different one," not an edit; forcing that through remove+add
     * keeps the duplicate-feature invariant simple to reason about (no
     * "am I about to collide with a different existing row" case). Only
     * `type`/`value` are editable.
     */
    public function update(Request $request, Plan $plan, PlanFeature $feature): RedirectResponse
    {
        abort_unless($feature->plan_id === $plan->id, 404);

        $validated = $this->validateFeature($request, []);

        $feature->update($validated);

        return redirect()->route('platform.plans.show', $plan)->with('status', 'Feature updated.');
    }

    public function destroy(Plan $plan, PlanFeature $feature): RedirectResponse
    {
        abort_unless($feature->plan_id === $plan->id, 404);

        $feature->delete();

        return redirect()->route('platform.plans.show', $plan)->with('status', 'Feature removed.');
    }

    /**
     * @return array{feature_code?: string, type: string, value: int|null}
     */
    protected function validateFeature(Request $request, array $extraRules): array
    {
        $rules = array_merge($extraRules, [
            'type' => ['required', Rule::enum(FeatureType::class)],
        ]);

        $type = FeatureType::tryFrom((string) $request->input('type'));

        $rules['value'] = match ($type) {
            FeatureType::Boolean => ['required', 'in:0,1'],
            FeatureType::Numeric => ['required', 'integer', 'min:0'],
            FeatureType::Unlimited, null => ['prohibited'],
        };

        $validated = $request->validate($rules);

        // TASK-ARCH-015: force-null the value for an Unlimited feature
        // server-side, regardless of what the client sent - the one place
        // "type = unlimited, value = N" is structurally prevented from
        // ever being persisted, not just discouraged by a form control.
        $validated['value'] = $type === FeatureType::Unlimited ? null : (int) $validated['value'];

        return $validated;
    }
}
