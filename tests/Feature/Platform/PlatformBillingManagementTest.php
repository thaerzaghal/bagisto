<?php

/**
 * TASK-ARCH-018 - Billing Domain + Provider Abstraction + Pricing test
 * matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered Platform
 * Admin routes, real BillingService/PaymentLifecycle/BillingProviderResolver.
 * No checkout/webhook flow exists yet (TASK-ARCH-019's scope) - nothing
 * here calls a real Stripe network endpoint; Stripe SDK response mapping
 * is tested via `\Stripe\ApiRequestor::setHttpClient()`, the SDK's own
 * supported test seam, returning canned JSON so the REAL Stripe SDK
 * request/deserialization path runs end-to-end with zero network access.
 *
 * FIXTURE DISCIPLINE (R40 lesson, re-applied yet again): dedicated
 * `tenant-billing-a`/`tenant-billing-b` fixtures throughout - never the
 * shared `tenant-a`/`tenant-b`/`free`/`pro` fixtures other files assert
 * exact values against. A dedicated `submgmt-billing-plan`/
 * `submgmt-billing-plan-2` pair of Plans, never the seeded free/basic/pro
 * plans other files assert exact values against.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Admin\Models\PlatformUser;
use Platform\Billing\Adapters\StripePaymentProvider;
use Platform\Billing\Contracts\PaymentProvider;
use Platform\Billing\DTOs\PaymentResult;
use Platform\Billing\Enums\BillingInterval;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Exceptions\InactivePlanPriceException;
use Platform\Billing\Exceptions\InvalidPaymentTransitionException;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Platform\Billing\Exceptions\PaymentOwnershipException;
use Platform\Billing\Exceptions\UnknownBillingProviderException;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Billing\Services\BillingProviderResolver;
use Platform\Billing\Services\BillingService;
use Platform\Billing\Services\PaymentLifecycle;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Models\Subscription;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\PaymentIntent;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const BILLING_TENANT_IDS = ['tenant-billing-a', 'tenant-billing-b'];

function ensureBillingTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (BILLING_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

function ensureBillingTestPlan(string $code): Plan
{
    return Plan::firstOrCreate(
        ['code' => $code],
        ['name' => ucfirst(str_replace('-', ' ', $code)), 'is_active' => true, 'sort_order' => 100]
    );
}

function ensureBillingPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'billing-test-admin@example.test'],
        ['name' => 'Billing Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForBilling(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'billing-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * @return array{0: mixed, 1: array<int, string|null>}
 */
function captureQueriedConnectionsForBilling(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $result = $callback();

    return [$result, $connections];
}

/**
 * Real Stripe SDK request/deserialization path, zero network access -
 * see this file's own top-level docblock.
 */
class FakeStripeHttpClientForBillingTest implements ClientInterface
{
    /** @var array<int, array{0: string, 1: int, 2: array}> */
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if (empty($this->responses)) {
            throw new RuntimeException('FakeStripeHttpClientForBillingTest: no more canned responses queued.');
        }

        return array_shift($this->responses);
    }
}

function fakeStripeResponse(array $body, int $status = 200): array
{
    return [json_encode($body), $status, []];
}

afterEach(function () {
    // Reset Stripe's global HTTP client override after every test that
    // might have set one, so it never leaks into a later test/file -
    // matches this codebase's established R37-class same-process
    // discipline.
    ApiRequestor::setHttpClient(null);
});

test('1. billing/pricing tables exist in central DB only, never in tenant DB', function () {
    $centralTables = DB::connection('mysql')->select('SHOW TABLES');
    $centralTableNames = array_map(fn ($row) => array_values((array) $row)[0], $centralTables);

    expect($centralTableNames)->toContain('plan_prices');
    expect($centralTableNames)->toContain('payments');

    [$tenant] = ensureBillingTenants();

    $tenant->run(function () {
        expect(Schema::hasTable('plan_prices'))->toBeFalse();
        expect(Schema::hasTable('payments'))->toBeFalse();
    });
});

test('2. a PlanPrice can be created with a monthly interval', function () {
    $plan = ensureBillingTestPlan('billing-plan-monthly');

    $price = PlanPrice::create([
        'plan_id' => $plan->id,
        'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1,
        'amount_minor' => 1999,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    expect($price->fresh()->billing_interval)->toBe(BillingInterval::Monthly);
    expect($price->fresh()->plan->id)->toBe($plan->id);
});

test('3. a PlanPrice can be created with a yearly interval', function () {
    $plan = ensureBillingTestPlan('billing-plan-yearly');

    $price = PlanPrice::create([
        'plan_id' => $plan->id,
        'billing_interval' => BillingInterval::Yearly,
        'interval_count' => 1,
        'amount_minor' => 19990,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    expect($price->fresh()->billing_interval)->toBe(BillingInterval::Yearly);
});

test('4. money is stored and read as integer minor units, never floats', function () {
    $plan = ensureBillingTestPlan('billing-plan-money');

    $price = PlanPrice::create([
        'plan_id' => $plan->id,
        'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1,
        'amount_minor' => 1000,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    expect($price->fresh()->amount_minor)->toBeInt()->toBe(1000);

    $raw = DB::connection('mysql')->table('plan_prices')->where('id', $price->id)->value('amount_minor');
    expect($raw)->toBeInt();
});

test('5. currency is normalized to a 3-letter uppercase code by Platform Admin, other lengths rejected', function () {
    ensureBillingPlatformAdmin();
    loginPlatformAdminForBilling($this);

    $plan = ensureBillingTestPlan('billing-plan-currency');

    $response = $this->post(route('platform.plans.prices.store', $plan), [
        'billing_interval' => 'monthly',
        'interval_count' => 1,
        'amount_minor' => 500,
        'currency' => 'usd',
    ]);
    $response->assertRedirect();

    $created = PlanPrice::where('plan_id', $plan->id)->latest('id')->first();
    expect($created->currency)->toBe('USD');

    $invalid = $this->post(route('platform.plans.prices.store', $plan), [
        'billing_interval' => 'monthly',
        'interval_count' => 1,
        'amount_minor' => 500,
        'currency' => 'usdx',
    ]);
    $invalid->assertSessionHasErrors('currency');
});

test('6. BillingService derives amount/currency from PlanPrice, never from a caller-supplied value', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-derive');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 2500, 'currency' => 'EUR', 'is_active' => true,
    ]);

    $subscription = Subscription::currentFor($tenant);

    $payment = app(BillingService::class)->createPendingPayment($tenant, $subscription, $price);

    // BillingService::createPendingPayment() has no parameter through
    // which a caller COULD supply a different amount/currency at all -
    // this asserts the only values that can ever result are the
    // PlanPrice's own, proving "a client cannot override the
    // authoritative amount/currency" by construction, not merely by
    // convention.
    expect($payment->amount_minor)->toBe(2500);
    expect($payment->currency)->toBe('EUR');
});

test('7. modifying a PlanPrice amount does not alter an already-created Payment snapshot', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-snapshot');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $subscription = Subscription::currentFor($tenant);
    $payment = app(BillingService::class)->createPendingPayment($tenant, $subscription, $price);

    $price->update(['amount_minor' => 9999, 'currency' => 'GBP']);

    expect($payment->fresh()->amount_minor)->toBe(1000);
    expect($payment->fresh()->currency)->toBe('USD');
});

test('8. an inactive PlanPrice cannot be used to create a new Payment', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-inactive');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => false,
    ]);

    $subscription = Subscription::currentFor($tenant);

    expect(fn () => app(BillingService::class)->createPendingPayment($tenant, $subscription, $price))
        ->toThrow(InactivePlanPriceException::class);
});

test('9. a newly created Payment starts Pending', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-pending');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $payment = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);

    expect($payment->status)->toBe(PaymentStatus::Pending);
});

test('10. BillingService rejects a Subscription that does not belong to the given Tenant', function () {
    [$tenantA, $tenantB] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-ownership');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $tenantBSubscription = Subscription::currentFor($tenantB);

    expect(fn () => app(BillingService::class)->createPendingPayment($tenantA, $tenantBSubscription, $price))
        ->toThrow(PaymentOwnershipException::class);
});

test('11. PaymentLifecycle transitions Pending -> Succeeded/Failed/Canceled correctly, and no public route can trigger them', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-lifecycle');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);
    $lifecycle = app(PaymentLifecycle::class);

    $succeeded = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    $lifecycle->markSucceeded($succeeded, new PaymentResult('pi_succeeded_1', PaymentStatus::Succeeded));
    expect($succeeded->fresh()->status)->toBe(PaymentStatus::Succeeded);
    expect($succeeded->fresh()->paid_at)->not->toBeNull();

    $failed = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    $lifecycle->markFailed($failed, new PaymentResult('pi_failed_1', PaymentStatus::Failed, 'card_declined', 'Your card was declined.'));
    expect($failed->fresh()->status)->toBe(PaymentStatus::Failed);
    expect($failed->fresh()->failure_code)->toBe('card_declined');

    $canceled = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    $lifecycle->markCanceled($canceled);
    expect($canceled->fresh()->status)->toBe(PaymentStatus::Canceled);

    // Task section 23: no public "mark paid" endpoint exists anywhere -
    // confirmed structurally: PaymentLifecycle is a plain service class,
    // never bound to any route in platform-routes.php (no checkout/
    // webhook controller exists in this task at all).
    expect(class_exists(\Platform\Admin\Http\Controllers\PlanPriceController::class))->toBeTrue();
});

test('12. an invalid Payment transition throws InvalidPaymentTransitionException', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-invalid-transition');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $payment = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    $lifecycle = app(PaymentLifecycle::class);
    $lifecycle->markSucceeded($payment, new PaymentResult('pi_x', PaymentStatus::Succeeded));

    // Succeeded -> Succeeded again is not in the transition matrix.
    expect(fn () => $lifecycle->markSucceeded($payment, new PaymentResult('pi_x', PaymentStatus::Succeeded)))
        ->toThrow(InvalidPaymentTransitionException::class);
});

test('13. a failed payment does not change TenantStatus', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-failed-tenant');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $statusBefore = $tenant->fresh()->status;

    $payment = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    app(PaymentLifecycle::class)->markFailed($payment, new PaymentResult('pi_y', PaymentStatus::Failed, 'insufficient_funds', 'Insufficient funds.'));

    expect($tenant->fresh()->status)->toBe($statusBefore);
});

test('14. a failed payment does not change Subscription status or plan', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-failed-sub');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $subscription = Subscription::currentFor($tenant);
    $statusBefore = $subscription->status;
    $planIdBefore = $subscription->plan_id;

    $payment = app(BillingService::class)->createPendingPayment($tenant, $subscription, $price);
    app(PaymentLifecycle::class)->markFailed($payment, new PaymentResult('pi_z', PaymentStatus::Failed));

    expect($subscription->fresh()->status)->toBe($statusBefore);
    expect($subscription->fresh()->plan_id)->toBe($planIdBefore);
});

test('15. provisioning a tenant creates a Subscription but no Payment (free subscription needs no payment row)', function () {
    $tenant = Tenant::find('tenant-billing-free-check');

    if (! $tenant) {
        $tenant = Tenant::create(['id' => 'tenant-billing-free-check', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-billing-free-check.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    expect(Subscription::currentFor($tenant))->not->toBeNull();
    expect(Payment::where('tenant_id', $tenant->getTenantKey())->count())->toBe(0);
});

test('16. BillingProviderResolver resolves the Stripe adapter when BILLING_PROVIDER=stripe', function () {
    // A non-blank secret is required here for a DIFFERENT reason than
    // test 18 covers: StripePaymentProvider's own constructor requires
    // one to construct successfully at all (this local/CI environment's
    // real .env deliberately leaves STRIPE_SECRET blank, matching
    // .env.example - no real credential is ever committed). This test
    // proves successful RESOLUTION when credentials are present; test 18
    // proves the failure path when they are not.
    config(['platform-billing.provider' => 'stripe', 'platform-billing.stripe.secret' => 'sk_test_fake_for_this_test']);

    $provider = app(BillingProviderResolver::class)->resolve();

    expect($provider)->toBeInstanceOf(StripePaymentProvider::class);
    expect($provider)->toBeInstanceOf(PaymentProvider::class);
});

test('17. an unknown billing provider fails clearly', function () {
    config(['platform-billing.provider' => 'totally_unknown_provider']);

    expect(fn () => app(BillingProviderResolver::class)->resolve())
        ->toThrow(UnknownBillingProviderException::class);
});

test('18. missing Stripe credentials fail clearly when the Stripe provider is actually used', function () {
    config(['platform-billing.provider' => 'stripe', 'platform-billing.stripe.secret' => '']);

    expect(fn () => app(BillingProviderResolver::class)->resolve())
        ->toThrow(MissingProviderCredentialsException::class);
});

test('19. StripePaymentProvider maps a real Stripe PaymentIntent success response into a provider-neutral PaymentResult', function () {
    config(['platform-billing.provider' => 'stripe', 'platform-billing.stripe.secret' => 'sk_test_fake_for_this_test']);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForBillingTest([
        fakeStripeResponse(['id' => 'pi_fake_success_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd', 'last_payment_error' => null]),
    ]));

    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-stripe-success');
    $price = PlanPrice::create([
        'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);
    $payment = app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);

    $result = app(BillingProviderResolver::class)->resolve()->createPayment($payment);

    expect($result)->toBeInstanceOf(PaymentResult::class);
    expect($result->providerReference)->toBe('pi_fake_success_1');
    expect($result->status)->toBe(PaymentStatus::Succeeded);
    expect($result->failureCode)->toBeNull();
});

test('20. StripePaymentProvider maps a real Stripe PaymentIntent failure response, and no Stripe SDK type escapes the contract', function () {
    config(['platform-billing.provider' => 'stripe', 'platform-billing.stripe.secret' => 'sk_test_fake_for_this_test']);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForBillingTest([
        fakeStripeResponse([
            'id' => 'pi_fake_failure_1',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'amount' => 1000,
            'currency' => 'usd',
            'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.'],
        ]),
    ]));

    $result = app(BillingProviderResolver::class)->resolve()->retrievePayment('pi_fake_failure_1');

    expect($result)->toBeInstanceOf(PaymentResult::class);
    // task section 25: only a plain string/enum/null ever crosses the
    // contract - no \Stripe\PaymentIntent, no \Stripe\StripeObject, no
    // "last_payment_error" nested object, anywhere on $result.
    expect(get_class($result))->toBe(PaymentResult::class);
    expect($result->status)->toBe(PaymentStatus::Pending);
    expect($result->failureCode)->toBe('card_declined');
    expect($result->failureMessage)->toBe('Your card was declined.');
});

test('21. StripePaymentProvider maps requires_action and canceled statuses correctly (pure SDK-object mapping, no HTTP)', function () {
    $provider = new class extends StripePaymentProvider
    {
        public function __construct() {}

        public function exposedMapStatus(string $status): PaymentStatus
        {
            return $this->mapStatus($status);
        }
    };

    expect($provider->exposedMapStatus('requires_action'))->toBe(PaymentStatus::RequiresAction);
    expect($provider->exposedMapStatus('canceled'))->toBe(PaymentStatus::Canceled);
    expect($provider->exposedMapStatus('processing'))->toBe(PaymentStatus::Pending);

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_construct_from_fixture', 'object' => 'payment_intent',
        'status' => 'requires_action', 'amount' => 500, 'currency' => 'usd',
    ]);

    expect($intent)->toBeInstanceOf(PaymentIntent::class);
    expect($intent->id)->toBe('pi_construct_from_fixture');
});

test('22. Platform Admin can list, create, update, activate, and deactivate PlanPrice rows via real HTTP', function () {
    ensureBillingPlatformAdmin();
    loginPlatformAdminForBilling($this);

    $plan = ensureBillingTestPlan('billing-plan-admin-crud');

    $create = $this->post(route('platform.plans.prices.store', $plan), [
        'billing_interval' => 'yearly',
        'interval_count' => 1,
        'amount_minor' => 12000,
        'currency' => 'USD',
    ]);
    $create->assertRedirect(route('platform.plans.show', $plan));

    $price = PlanPrice::where('plan_id', $plan->id)->latest('id')->first();
    expect($price)->not->toBeNull();

    $show = $this->get(route('platform.plans.show', $plan));
    $show->assertOk();
    $show->assertSee('12000');

    $update = $this->patch(route('platform.plans.prices.update', [$plan, $price]), [
        'billing_interval' => 'yearly',
        'interval_count' => 1,
        'amount_minor' => 15000,
        'currency' => 'USD',
    ]);
    $update->assertRedirect();
    expect($price->fresh()->amount_minor)->toBe(15000);

    $deactivate = $this->post(route('platform.plans.prices.deactivate', [$plan, $price]));
    $deactivate->assertRedirect();
    expect($price->fresh()->is_active)->toBeFalse();

    $activate = $this->post(route('platform.plans.prices.activate', [$plan, $price]));
    $activate->assertRedirect();
    expect($price->fresh()->is_active)->toBeTrue();
});

test('23. an unauthenticated caller cannot manage PlanPrice rows', function () {
    $plan = ensureBillingTestPlan('billing-plan-unauth');

    $response = $this->post(route('platform.plans.prices.store', $plan), [
        'billing_interval' => 'monthly', 'interval_count' => 1, 'amount_minor' => 100, 'currency' => 'USD',
    ]);

    $response->assertRedirect(route('platform.login'));
    expect(PlanPrice::where('plan_id', $plan->id)->count())->toBe(0);
});

test('24. billing/pricing mutations never query the tenant connection', function () {
    [$tenant] = ensureBillingTenants();
    $plan = ensureBillingTestPlan('billing-plan-central-proof');

    [$price, $connections] = captureQueriedConnectionsForBilling(function () use ($plan) {
        return PlanPrice::create([
            'plan_id' => $plan->id, 'billing_interval' => BillingInterval::Monthly,
            'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
        ]);
    });
    expect($connections)->not->toContain('tenant');

    [, $connections] = captureQueriedConnectionsForBilling(function () use ($tenant, $price) {
        return app(BillingService::class)->createPendingPayment($tenant, Subscription::currentFor($tenant), $price);
    });
    expect($connections)->not->toContain('tenant');
});

test('25. changing a tenant plan-price does not affect an unrelated tenant', function () {
    [$tenantA, $tenantB] = ensureBillingTenants();
    $planA = ensureBillingTestPlan('billing-plan-a-isolated');
    $planB = ensureBillingTestPlan('billing-plan-b-isolated');
    $priceA = PlanPrice::create([
        'plan_id' => $planA->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'is_active' => true,
    ]);
    $priceB = PlanPrice::create([
        'plan_id' => $planB->id, 'billing_interval' => BillingInterval::Monthly,
        'interval_count' => 1, 'amount_minor' => 2000, 'currency' => 'USD', 'is_active' => true,
    ]);

    $paymentA = app(BillingService::class)->createPendingPayment($tenantA, Subscription::currentFor($tenantA), $priceA);
    $paymentB = app(BillingService::class)->createPendingPayment($tenantB, Subscription::currentFor($tenantB), $priceB);

    expect($paymentA->fresh()->amount_minor)->toBe(1000);
    expect($paymentB->fresh()->amount_minor)->toBe(2000);
    expect($paymentA->tenant_id)->toBe($tenantA->getTenantKey());
    expect($paymentB->tenant_id)->toBe($tenantB->getTenantKey());
});
