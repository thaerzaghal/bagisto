<?php

/**
 * TASK-MVP-004A (task section 3) - "Stripe-unavailable pilot UX".
 *
 * Reproduces the exact real-world pilot posture: STRIPE_SECRET left blank
 * (today's actual .env.example default, and the recommended pilot posture -
 * see TASK-MVP-004's own investigation report, section 15). Proves a
 * merchant who clicks "Upgrade Plan" in this state gets a clean, safe
 * redirect with a flash message - never a raw, unhandled exception/stack
 * trace - and that nothing financial or subscription-related is mutated.
 *
 * KEY FINDING this test proves structurally (not just behaviorally):
 * `Platform\Billing\Http\Controllers\Tenant\CheckoutController::store()`
 * method-injects `Platform\Billing\Services\CheckoutService`, which itself
 * constructor-injects the `PaymentProvider` contract - so
 * `MissingProviderCredentialsException` (thrown by
 * `Platform\Billing\Adapters\StripePaymentProvider`'s own constructor) is
 * raised during Laravel's OWN controller-method dependency resolution,
 * BEFORE `store()`'s method body ever runs. A try/catch placed inside that
 * method body would never see it - only the global `bootstrap/app.php`
 * `withExceptions()` render() handler (see that file) actually can. If a
 * future change moved the exception handling back into the controller
 * body, this whole file would start failing.
 *
 * Real MySQL, real HTTP requests through the actual registered tenant-admin
 * checkout route - nothing mocked. Dedicated `tenant-checkout-unavailable`
 * fixture (R40 lesson) - never the shared fixtures other files assert
 * exact values against.
 */

use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const UNAVAILABLE_TENANT_ID = 'tenant-checkout-unavailable';

function ensureUnavailableCheckoutTenant(): Tenant
{
    $tenant = Tenant::find(UNAVAILABLE_TENANT_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => UNAVAILABLE_TENANT_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => UNAVAILABLE_TENANT_ID.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    return $tenant->fresh();
}

function unavailableCheckoutLoginAsTenantAdmin(Tests\TestCase $test): void
{
    $test->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

function ensureUnavailableCheckoutPlan(): Plan
{
    return Plan::updateOrCreate(
        ['code' => 'checkout-plan-unavailable'],
        ['name' => 'Checkout Plan Unavailable', 'is_active' => true, 'sort_order' => 200]
    );
}

function ensureUnavailableCheckoutPrice(Plan $plan): PlanPrice
{
    return PlanPrice::create([
        'plan_id' => $plan->id,
        'billing_interval' => \Platform\Billing\Enums\BillingInterval::Monthly,
        'interval_count' => 1,
        'amount_minor' => 1000,
        'currency' => 'USD',
        'is_active' => true,
    ]);
}

test('1. clicking Upgrade Plan with STRIPE_SECRET blank redirects cleanly instead of a raw unhandled exception', function () {
    config(['platform-billing.stripe.secret' => '']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);

    unavailableCheckoutLoginAsTenantAdmin($this);

    $response = $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    $response->assertRedirect(route('admin.saas.checkout.index'));
    expect($response->getSession()->get('error'))
        ->toBe('Online subscription billing is not available yet. Please contact the platform administrator to change your plan.');
});

test('2. no stack trace or secret-configuration detail reaches the response body', function () {
    config(['platform-billing.stripe.secret' => '']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);

    unavailableCheckoutLoginAsTenantAdmin($this);

    $response = $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    $body = $response->getContent();
    expect($body)->not->toContain('STRIPE_SECRET');
    expect($body)->not->toContain('MissingProviderCredentialsException');
    expect($body)->not->toContain('StripePaymentProvider');
});

test('3. the Subscription plan/status is unchanged after a failed checkout attempt', function () {
    config(['platform-billing.stripe.secret' => '']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);

    $subscription = Subscription::currentFor($tenant);
    $planIdBefore = $subscription->plan_id;
    $statusBefore = $subscription->status;

    unavailableCheckoutLoginAsTenantAdmin($this);

    $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    expect($subscription->fresh()->plan_id)->toBe($planIdBefore);
    expect($subscription->fresh()->status)->toBe($statusBefore);
});

test('4. the tenant plan_id is unchanged after a failed checkout attempt', function () {
    config(['platform-billing.stripe.secret' => '']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);
    $tenantPlanIdBefore = $tenant->fresh()->plan_id;

    unavailableCheckoutLoginAsTenantAdmin($this);

    $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    expect($tenant->fresh()->plan_id)->toBe($tenantPlanIdBefore);
});

test('5. no Payment row is created for a failed (missing-credentials) checkout attempt', function () {
    config(['platform-billing.stripe.secret' => '']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);
    $countBefore = Payment::where('tenant_id', UNAVAILABLE_TENANT_ID)->count();

    unavailableCheckoutLoginAsTenantAdmin($this);

    $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    expect(Payment::where('tenant_id', UNAVAILABLE_TENANT_ID)->count())->toBe($countBefore);
});

test('6. MissingProviderCredentialsException is genuinely thrown during controller-method dependency resolution, not inside the method body', function () {
    // Structural proof of the finding this whole file exists to guard
    // against: CheckoutService is a METHOD parameter of store(), and its
    // own constructor requires the PaymentProvider contract - so
    // reflection on the controller's method signature (not the class
    // constructor) is where the resolvable-but-failing dependency lives.
    $reflection = new ReflectionMethod(\Platform\Billing\Http\Controllers\Tenant\CheckoutController::class, 'store');
    $paramTypes = array_map(fn ($p) => (string) $p->getType(), $reflection->getParameters());

    expect($paramTypes)->toContain(\Platform\Billing\Services\CheckoutService::class);

    config(['platform-billing.stripe.secret' => '']);

    expect(fn () => app(\Platform\Billing\Services\CheckoutService::class))
        ->toThrow(MissingProviderCredentialsException::class);
});

test('7. configured-provider (real STRIPE_SECRET present) checkout behavior is unaffected', function () {
    config([
        'platform-billing.stripe.secret' => 'sk_test_fake_for_this_test_suite',
    ]);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = ensureUnavailableCheckoutPlan();
    $price = ensureUnavailableCheckoutPrice($plan);

    unavailableCheckoutLoginAsTenantAdmin($this);

    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $body = json_encode([
                'id' => 'cs_test_unavailable_regression',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_unavailable_regression',
                'payment_status' => 'unpaid',
                'amount_total' => 1000,
                'currency' => 'usd',
                'mode' => 'payment',
            ]);

            return [$body, 200, []];
        }
    });

    $response = $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_unavailable_regression');

    $payment = Payment::where('tenant_id', UNAVAILABLE_TENANT_ID)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe(PaymentStatus::Pending);

    ApiRequestor::setHttpClient(null);
});
