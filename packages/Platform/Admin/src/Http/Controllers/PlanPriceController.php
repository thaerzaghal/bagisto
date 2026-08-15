<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Platform\Billing\Enums\BillingInterval;
use Platform\Billing\Models\PlanPrice;
use Platform\Plans\Models\Plan;

/**
 * TASK-ARCH-018 (task section 19/20). Platform Admin CRUD for
 * `Platform\Billing\Models\PlanPrice` rows nested under a plan - operators
 * must be able to configure billing prices without tinker/raw SQL, the
 * same reasoning `PlanFeatureController` (TASK-ARCH-015) already
 * established for plan features. Mirrors that controller's shape exactly.
 *
 * NO PAYMENT/CHECKOUT ACTIONS HERE (task section 19/28, strict): this
 * controller manages PRICING only - no "create payment"/"charge tenant"
 * button exists anywhere in this task. Deactivating a price never touches
 * an existing Payment's own snapshot amount (task section 10) - it only
 * prevents that price from being selected for a NEW payment going
 * forward (`Platform\Billing\Services\BillingService`'s own
 * `InactivePlanPriceException` check).
 *
 * MONEY VALIDATION (task section 7/20): `amount_minor` is validated as a
 * non-negative integer server-side - the server owns conversion/validation
 * semantics, never a client-calculated float-to-minor-units conversion.
 * `currency` is validated as exactly 3 uppercase letters (ISO-4217 shape)
 * and normalized to uppercase - no fixed currency allowlist, since this
 * platform does not hardcode USD (task section 7, explicit).
 */
class PlanPriceController
{
    /**
     * Deliberately `PlanPrice::create(['plan_id' => ...])`, not
     * `$plan->prices()->create(...)` - `Platform\Plans\Models\Plan` has no
     * `prices()` relation and must never gain one: the dependency
     * direction is `Platform\Billing -> Platform\Plans`, never the
     * reverse (task section 2, strict). `Platform\Admin` (this
     * controller's own package) is a top-level UI package already
     * reading multiple domain packages' models directly, so it may depend
     * on both `Platform\Plans` and `Platform\Billing` without creating any
     * cycle between THEM.
     */
    public function store(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePrice($request);

        PlanPrice::create($validated + ['plan_id' => $plan->id]);

        return redirect()->route('platform.plans.show', $plan)->with('status', 'Price added.');
    }

    public function update(Request $request, Plan $plan, PlanPrice $price): RedirectResponse
    {
        abort_unless($price->plan_id === $plan->id, 404);

        $validated = $this->validatePrice($request);

        $price->update($validated);

        return redirect()->route('platform.plans.show', $plan)->with('status', 'Price updated.');
    }

    /**
     * Deactivation only, matching `PlanController::deactivate()`'s own
     * "the only lifecycle mechanism is a flag flip, no hard delete"
     * precedent - a PlanPrice already referenced by a historical Payment
     * (via `plan_price_id`) must remain queryable for that Payment's own
     * `planPrice()` relation, not disappear.
     */
    public function deactivate(Plan $plan, PlanPrice $price): RedirectResponse
    {
        abort_unless($price->plan_id === $plan->id, 404);

        $price->update(['is_active' => false]);

        return back()->with('status', 'Price deactivated. It can no longer be selected for a new payment.');
    }

    public function activate(Plan $plan, PlanPrice $price): RedirectResponse
    {
        abort_unless($price->plan_id === $plan->id, 404);

        $price->update(['is_active' => true]);

        return back()->with('status', 'Price activated.');
    }

    /**
     * @return array{billing_interval: string, interval_count: int, amount_minor: int, currency: string}
     */
    protected function validatePrice(Request $request): array
    {
        $validated = $request->validate([
            'billing_interval' => ['required', Rule::enum(BillingInterval::class)],
            'interval_count' => ['required', 'integer', 'min:1'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $validated['currency'] = strtoupper($validated['currency']);

        return $validated;
    }
}
