<?php

declare(strict_types=1);

namespace Platform\Billing\Http\Controllers\Tenant;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Billing\Services\CheckoutService;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Models\Subscription;

/**
 * TASK-ARCH-019 (task section 3). The minimum real tenant-facing checkout
 * initiation flow, reached from the existing "My Plan" page (task section
 * 3, explicit: "preferred location"). Deliberately NOT a pricing
 * marketplace and NOT self-service cancellation/downgrade - just "view
 * active paid prices, pick one, go to Stripe's hosted checkout page."
 *
 * Registered under the exact same tenant-admin route/middleware/ACL stack
 * `Platform\Plans\Http\Controllers\Admin\MyPlanController` already uses
 * (see `Platform\Billing\Providers\BillingServiceProvider::boot()`) - an
 * unauthenticated/unauthorized request behaves identically to every other
 * Bagisto tenant-admin route.
 *
 * AUTHORITATIVE PRICE ONLY (task section 4, strict): the browser submits
 * only `plan_price_id` - never amount/currency/plan code/interval. `store()`
 * resolves the real `PlanPrice` via `PlanPrice::find()`, never a bare
 * `exists:plan_prices,id` validation rule - the SAME R42-class ambient-
 * connection gap this codebase already learned from applies here: this
 * controller's own ambient default connection is the TENANT'S, not
 * central, so a raw `exists:` rule against `plan_prices` (a central-only
 * table) would query a table that does not exist on this connection at
 * all, failing loudly and pointlessly rather than validating anything.
 */
class CheckoutController extends Controller
{
    public function index(): View
    {
        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get();

        $prices = PlanPrice::where('is_active', true)
            ->whereIn('plan_id', $plans->pluck('id'))
            ->orderBy('plan_id')
            ->orderBy('billing_interval')
            ->get()
            ->groupBy('plan_id');

        return view('billing::tenant.checkout.index', [
            'plans' => $plans,
            'prices' => $prices,
            'subscription' => Subscription::currentFor(tenant()),
        ]);
    }

    public function store(Request $request, CheckoutService $checkoutService): RedirectResponse
    {
        $validated = $request->validate([
            'plan_price_id' => ['required', 'integer'],
        ]);

        $planPrice = PlanPrice::find($validated['plan_price_id']);

        abort_unless($planPrice, 404);

        $subscription = Subscription::currentFor(tenant());

        abort_unless($subscription, 404, 'This tenant has no subscription to check out against.');

        $result = $checkoutService->initiate(
            tenant(),
            $subscription,
            $planPrice,
            route('admin.saas.checkout.success'),
            route('admin.saas.checkout.cancel')
        );

        return redirect()->away($result['redirectUrl']);
    }

    /**
     * Task section 8, strict: "Payment submitted / waiting for
     * confirmation" - NEVER "payment succeeded". This route only ever
     * READS the tenant's own most recent Payment's current status,
     * whatever it happens to be at page-load time (Pending if the webhook
     * has not arrived yet, Succeeded if it has already landed by the time
     * the browser redirects back - both are simply what the database
     * already says, never written here). No URL-embedded payment
     * identifier exists to spoof (task section 27) - the query is
     * unconditionally scoped to the CURRENT tenant context.
     */
    public function success(): View
    {
        return view('billing::tenant.checkout.result', [
            'payment' => $this->mostRecentPayment(),
            'outcome' => 'success',
        ]);
    }

    public function cancel(): View
    {
        return view('billing::tenant.checkout.result', [
            'payment' => $this->mostRecentPayment(),
            'outcome' => 'cancel',
        ]);
    }

    protected function mostRecentPayment(): ?Payment
    {
        return Payment::where('tenant_id', tenant()->getTenantKey())->latest('id')->first();
    }
}
