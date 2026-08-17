<?php

/**
 * TASK-MVP-004A (task section 3) - "Stripe-unavailable pilot UX".
 * RISK_REGISTER.md R59 (TASK-MVP-004B) - this file's own tests, as
 * originally written, passed cleanly for months while NEVER actually
 * exercising the real production condition: nothing in this file (nor
 * phpunit.xml/.env.testing) ever pinned `APP_DEBUG`, so every test here
 * silently inherited the ambient local/CI default (`true`) - under which
 * `Webkul\Core\Exceptions\Handler::register()` early-returns and registers
 * NOTHING, meaning `bootstrap/app.php`'s per-exception-type `$exceptions->
 * render()` registration always won by default, whether or not it could
 * ACTUALLY win a real registration-order race. The real pilot server runs
 * `APP_DEBUG=false`, under which that registration was proven (live, via a
 * real merchant checkout attempt) to silently lose the exact same
 * registration-order race RISK_REGISTER.md R53 already found and fixed for
 * a DIFFERENT exception (`TenantCouldNotBeIdentifiedException`) - producing
 * a raw, generic 500 instead of the graceful redirect this file's tests
 * believed they were proving. Every test below now explicitly forces
 * `config(['app.debug' => false])` (see beforeEach()), the exact same
 * technique already proven live to reproduce this class of bug in
 * `ProductionHostErrorHandlingTest.php` (R53) - the exception Handler is
 * constructed FRESHLY per-request/per-exception, so a runtime `config()`
 * override made before the simulated request dispatches is correctly seen
 * by `Webkul\Core\Exceptions\Handler::register()`'s own construction-time
 * logic; no real subprocess is needed the way R57's Router-ordering bug
 * needed one.
 *
 * THE FIX (R59): `Platform\Billing\Http\Controllers\Tenant\
 * CheckoutController::store()` no longer method-injects `Platform\Billing\
 * Services\CheckoutService` as a typed parameter (the ORIGINAL R52 design,
 * which this file's OLD test 6 used to assert as correct) - Laravel
 * resolves typed method parameters BEFORE the method body runs, which is
 * exactly why the old design needed a GLOBAL exception-render callback in
 * the first place, and exactly why that callback's registration-order
 * fragility mattered. `CheckoutService` is now resolved manually, as an
 * ordinary statement inside `store()`'s own body, wrapped in a try/catch
 * scoped to ONLY `MissingProviderCredentialsException` - an entirely
 * normal, LOCALLY-catchable PHP exception at that point, with zero
 * dependency on global exception-handler registration order. The
 * now-dead, now-removed `bootstrap/app.php` render() registration for this
 * exception is confirmed (full-codebase audit, see that file's own
 * updated docblock) to have had no other real caller.
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

beforeEach(function () {
    // R59 - see file docblock. Forces the exact real production condition
    // (`Webkul\Core\Exceptions\Handler::register()` registering its
    // catch-all `Throwable` renderable) every test in this file needs in
    // order to actually prove anything about registration-order safety,
    // rather than silently inheriting the ambient local/CI APP_DEBUG=true
    // default under which Bagisto's catch-all never registers at all.
    config(['app.debug' => false]);
});

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

test('6. CheckoutService is deliberately NOT an auto-resolved method parameter of store() (R59 structural proof)', function () {
    // The OLD (R52-era, now-broken-under-APP_DEBUG=false) design had
    // CheckoutService as a typed METHOD parameter of store() specifically
    // so Laravel's own dependency resolution would raise
    // MissingProviderCredentialsException BEFORE the method body ran -
    // which is exactly why it could only ever be caught by a global,
    // registration-order-fragile bootstrap/app.php render() callback. R59
    // deliberately moved resolution inside the method body instead, so it
    // is an ordinary, locally-catchable statement. If a future change
    // reintroduced CheckoutService as a typed method parameter, this
    // assertion would fail immediately, flagging that the R59 fix (and
    // this file's own APP_DEBUG=false tests) would silently stop proving
    // what they claim to prove.
    $reflection = new ReflectionMethod(\Platform\Billing\Http\Controllers\Tenant\CheckoutController::class, 'store');
    $paramTypes = array_map(fn ($p) => (string) $p->getType(), $reflection->getParameters());

    expect($paramTypes)->not->toContain(\Platform\Billing\Services\CheckoutService::class);

    config(['platform-billing.stripe.secret' => '']);

    expect(fn () => app(\Platform\Billing\Services\CheckoutService::class))
        ->toThrow(MissingProviderCredentialsException::class);
});

test('8. an unrelated exception (inactive plan) is NOT converted into the billing-unavailable message - R59 catch is scoped only to MissingProviderCredentialsException', function () {
    // A real, configured Stripe provider (credentials present, so
    // MissingProviderCredentialsException cannot fire at all here) - the
    // ONLY thing wrong with this attempt is an inactive Plan, an entirely
    // different, unrelated failure CheckoutService::initiate() itself
    // throws (InactivePlanException). Proves R59's try/catch in
    // CheckoutController::store() is narrowly scoped, not a broad
    // catch-and-redirect-everything.
    config(['platform-billing.stripe.secret' => 'sk_test_fake_for_this_test_suite']);

    $tenant = ensureUnavailableCheckoutTenant();
    $plan = Plan::updateOrCreate(
        ['code' => 'checkout-plan-inactive-for-r59'],
        ['name' => 'Inactive Plan For R59', 'is_active' => false, 'sort_order' => 201]
    );
    $price = ensureUnavailableCheckoutPrice($plan);

    unavailableCheckoutLoginAsTenantAdmin($this);

    $response = $this->post('http://'.UNAVAILABLE_TENANT_ID.'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    // Must NOT be R59's own graceful billing-unavailable redirect/message -
    // an unrelated failure must follow Bagisto's normal (if generic, under
    // APP_DEBUG=false) exception handling instead. session('error') (not
    // $response->getSession(), which only exists on a RedirectResponse -
    // an uncaught exception's response here is a plain error-page Response)
    // still correctly reflects whatever this request's session ended up
    // holding.
    expect(session('error'))
        ->not->toBe('Online subscription billing is not available yet. Please contact the platform administrator to change your plan.');
    expect($response->status())->not->toBe(302);
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
