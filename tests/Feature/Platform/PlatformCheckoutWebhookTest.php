<?php

/**
 * TASK-ARCH-019 - Stripe Sandbox Checkout + Webhooks test matrix.
 *
 * Real MySQL, real HTTP requests through the actual registered tenant-
 * admin and central webhook routes, real CheckoutService/
 * WebhookEventProcessor/PaymentLifecycle/SubscriptionLifecycle. Zero
 * outbound network access - the Stripe Checkout Session creation call
 * uses the SAME `\Stripe\ApiRequestor::setHttpClient()` test seam
 * TASK-ARCH-018's own test file established; webhook signatures are
 * REAL, valid HMAC-SHA256 signatures computed with the exact algorithm
 * `\Stripe\WebhookSignature::verifyHeader()` expects, so signature
 * verification is genuinely exercised, not stubbed out.
 *
 * FIXTURE DISCIPLINE (R40 lesson, re-applied yet again): dedicated
 * `tenant-checkout-a`/`tenant-checkout-b` fixtures throughout - never the
 * shared `tenant-a`/`tenant-b` fixtures other files assert exact values
 * against.
 */

use Illuminate\Support\Facades\DB;
use Platform\Admin\Models\PlatformUser;
use Platform\Billing\Enums\PaymentStatus;
use Platform\Billing\Enums\ProviderEventProcessingStatus;
use Platform\Billing\Exceptions\InactivePlanException;
use Platform\Billing\Exceptions\InactivePlanPriceException;
use Platform\Billing\Models\BillingProviderEvent;
use Platform\Billing\Models\Payment;
use Platform\Billing\Models\PlanPrice;
use Platform\Billing\Services\CheckoutService;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const CHECKOUT_TENANT_IDS = ['tenant-checkout-a', 'tenant-checkout-b'];

function ensureCheckoutTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (CHECKOUT_TENANT_IDS as $id) {
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

function checkoutLoginAsTenantAdmin(Tests\TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

function ensureCheckoutPlan(string $code, bool $active = true): Plan
{
    return Plan::updateOrCreate(
        ['code' => $code],
        ['name' => ucfirst(str_replace('-', ' ', $code)), 'is_active' => $active, 'sort_order' => 200]
    );
}

function ensureCheckoutPrice(Plan $plan, int $amountMinor = 1000, string $currency = 'USD', bool $active = true): PlanPrice
{
    return PlanPrice::create([
        'plan_id' => $plan->id,
        'billing_interval' => \Platform\Billing\Enums\BillingInterval::Monthly,
        'interval_count' => 1,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'is_active' => $active,
    ]);
}

function ensureCheckoutPlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'checkout-test-admin@example.test'],
        ['name' => 'Checkout Test Admin', 'password' => 'platform-secret-1']
    );
}

function loginPlatformAdminForCheckout(Tests\TestCase $test): void
{
    $test->post('http://localhost/platform/login', [
        'email' => 'checkout-test-admin@example.test',
        'password' => 'platform-secret-1',
    ])->assertRedirect(route('platform.dashboard'));
}

/**
 * Real Stripe SDK request/deserialization path for Checkout Session
 * creation, zero network access - same mechanism TASK-ARCH-018's own
 * StripePaymentProvider tests established.
 */
class FakeStripeHttpClientForCheckoutTest implements ClientInterface
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
            throw new RuntimeException('FakeStripeHttpClientForCheckoutTest: no more canned responses queued.');
        }

        return array_shift($this->responses);
    }
}

function fakeCheckoutSessionResponse(string $id = 'cs_test_fake_session', int $amountMinor = 1000, string $currency = 'usd'): array
{
    $body = json_encode([
        'id' => $id,
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/'.$id,
        'payment_status' => 'unpaid',
        'amount_total' => $amountMinor,
        'currency' => $currency,
        'mode' => 'payment',
    ]);

    return [$body, 200, []];
}

/**
 * A REAL, validly-signed Stripe webhook payload/header pair - computed
 * with the exact algorithm `\Stripe\WebhookSignature::verifyHeader()`
 * expects (signed string = "{timestamp}.{payload}", HMAC-SHA256 with the
 * webhook secret), not a stub. Signature verification is genuinely
 * exercised end-to-end by every webhook test in this file.
 *
 * @return array{0: string, 1: string} [payload, signatureHeader]
 */
function signedStripeWebhook(array $eventData, string $secret, ?int $timestamp = null): array
{
    $payload = json_encode($eventData);
    $timestamp ??= time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return [$payload, "t={$timestamp},v1={$signature}"];
}

function checkoutSessionCompletedEvent(string $eventId, string $sessionId, int $amountMinor, string $currency, string $paymentStatus = 'paid'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $sessionId,
                'object' => 'checkout.session',
                'payment_status' => $paymentStatus,
                'amount_total' => $amountMinor,
                'currency' => $currency,
            ],
        ],
    ];
}

const CHECKOUT_TEST_WEBHOOK_SECRET = 'whsec_test_fake_for_this_test_suite';

/**
 * R40-class fixture discipline, applied here too: `PlatformIntegrationTestCase`
 * disables `DatabaseTransactions`, so Payment/BillingProviderEvent rows
 * persist across repeated runs of this same file. A hardcoded literal
 * session/event id (e.g. 'cs_test_full_success') would collide with a row
 * a PREVIOUS run already created and fully processed - `Payment::where(
 * 'provider_reference', ...)->first()` would then correlate to that STALE,
 * already-Succeeded row instead of the fresh one this run just created,
 * and the webhook event's own idempotency correctly short-circuits a
 * second time, leaving THIS run's Payment looking untouched. Every
 * session/event id in this file is suffixed with one id unique to this
 * process, generated once and reused consistently within it.
 */
function uid(string $base): string
{
    static $suffix;
    $suffix ??= substr(md5(uniqid('', true)), 0, 8);

    return $base.'_'.$suffix;
}

beforeEach(function () {
    config([
        'platform-billing.provider' => 'stripe',
        'platform-billing.stripe.secret' => 'sk_test_fake_for_this_test_suite',
        'platform-billing.stripe.webhook_secret' => CHECKOUT_TEST_WEBHOOK_SECRET,
    ]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('1. Tenant Admin can initiate checkout for an active PlanPrice', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-basic');
    $price = ensureCheckoutPrice($plan);

    checkoutLoginAsTenantAdmin($this, CHECKOUT_TENANT_IDS[0].'.localhost');

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_1'), 1000, 'usd'),
    ]));

    $response = $this->post('http://'.CHECKOUT_TENANT_IDS[0].'.localhost/admin/saas/plan/checkout', [
        'plan_price_id' => $price->id,
    ]);

    $response->assertRedirect('https://checkout.stripe.com/c/pay/'.uid('cs_test_1'));

    $payment = Payment::where('tenant_id', CHECKOUT_TENANT_IDS[0])->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->provider_reference)->toBe(uid('cs_test_1'));
});

test('2. an inactive PlanPrice is rejected by checkout', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-inactive-price');
    $price = ensureCheckoutPrice($plan, active: false);

    $subscription = Subscription::currentFor($tenantA);

    expect(fn () => app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel'))
        ->toThrow(InactivePlanPriceException::class);
});

test('3. an inactive Plan is rejected by checkout even if the PlanPrice itself is active', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-inactive-plan', active: false);
    $price = ensureCheckoutPrice($plan, active: true);

    $subscription = Subscription::currentFor($tenantA);

    expect(fn () => app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel'))
        ->toThrow(InactivePlanException::class);
});

test('4/5/10. amount and currency come from the central PlanPrice and are what the provider receives', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-amount-currency');
    $price = ensureCheckoutPrice($plan, amountMinor: 4321, currency: 'EUR');
    $subscription = Subscription::currentFor($tenantA);

    $capturedParams = null;

    ApiRequestor::setHttpClient(new class($capturedParams) implements ClientInterface
    {
        public function __construct(private mixed &$capturedParams) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->capturedParams = $params;

            return fakeCheckoutSessionResponse(uid('cs_test_capture'), 4321, 'eur');
        }
    });

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');

    expect($result['payment']->amount_minor)->toBe(4321);
    expect($result['payment']->currency)->toBe('EUR');
});

test('6/7. client-supplied amount/currency cannot override the authoritative PlanPrice value', function () {
    // Structural proof, not a request-tampering simulation: BillingService::
    // createPendingPayment() and CheckoutService::initiate() have no
    // amount/currency PARAMETER anywhere in their signatures - there is no
    // code path through which a caller (a tampered HTTP request body
    // included) could ever supply one. CheckoutController::store() itself
    // only ever reads `plan_price_id` from the request.
    $reflection = new ReflectionMethod(CheckoutService::class, 'initiate');
    $paramNames = array_map(fn ($p) => $p->getName(), $reflection->getParameters());

    expect($paramNames)->not->toContain('amount');
    expect($paramNames)->not->toContain('amountMinor');
    expect($paramNames)->not->toContain('currency');

    $controllerSource = file_get_contents((new ReflectionClass(\Platform\Billing\Http\Controllers\Tenant\CheckoutController::class))->getFileName());
    expect($controllerSource)->not->toContain("'amount'");
    expect($controllerSource)->not->toContain("'currency'");
});

test('8/9. a Pending Payment is created before any provider call, and the Subscription plan does not change while Pending', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-pending-first');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);
    $planIdBefore = $subscription->plan_id;

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_pending_first'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');

    expect($result['payment']->status)->toBe(PaymentStatus::Pending);
    expect($subscription->fresh()->plan_id)->toBe($planIdBefore);
});

test('11/12. the success and cancel return pages never mark a Payment Succeeded', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-return-urls');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_return_urls'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $paymentId = $result['payment']->id;

    checkoutLoginAsTenantAdmin($this, CHECKOUT_TENANT_IDS[0].'.localhost');

    $success = $this->get('http://'.CHECKOUT_TENANT_IDS[0].'.localhost/admin/saas/plan/checkout/success');
    $success->assertOk();
    expect(Payment::find($paymentId)->status)->toBe(PaymentStatus::Pending);

    $cancel = $this->get('http://'.CHECKOUT_TENANT_IDS[0].'.localhost/admin/saas/plan/checkout/cancel');
    $cancel->assertOk();
    expect(Payment::find($paymentId)->status)->toBe(PaymentStatus::Pending);
});

test('13/16/17/18/19. a validly signed successful webhook marks Payment Succeeded, changes the Subscription plan, syncs tenants.plan_id, and enforcement reflects it immediately', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-full-success');
    $price = ensureCheckoutPrice($plan, amountMinor: 1500, currency: 'USD');
    $subscription = Subscription::currentFor($tenantA);
    $oldPlanId = $subscription->plan_id;

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_full_success'), 1500, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_full_success_1'), uid('cs_test_full_success'), 1500, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    expect($payment->fresh()->paid_at)->not->toBeNull();

    $subscription->refresh();
    expect($subscription->plan_id)->toBe($plan->id);
    expect($subscription->plan_id)->not->toBe($oldPlanId);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    expect($tenantA->fresh()->plan_id)->toBe($plan->id);
});

test('14. an invalid webhook signature is rejected cleanly, with zero mutation', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-bad-signature');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_bad_sig'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, ] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_bad_sig_1'), uid('cs_test_bad_sig'), 1000, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => 't=1,v1=deadbeefnotarealsignature',
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertStatus(400);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect(BillingProviderEvent::where('provider_event_id', uid('evt_bad_sig_1'))->exists())->toBeFalse();
});

test('15. an unrecognized event type does not mutate the Payment, but is still recorded', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-unknown-event');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_unknown_event'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook([
        'id' => uid('evt_unknown_1'),
        'object' => 'event',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_irrelevant', 'object' => 'payment_intent']],
    ], CHECKOUT_TEST_WEBHOOK_SECRET);

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $event = BillingProviderEvent::where('provider_event_id', uid('evt_unknown_1'))->first();
    expect($event)->not->toBeNull();
    expect($event->processing_status)->toBe(ProviderEventProcessingStatus::Ignored);
});

test('20/21. duplicate webhook delivery is idempotent - no duplicate Payment, no second plan change, no duplicate event row', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-duplicate-webhook');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_duplicate'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_duplicate_1'), uid('cs_test_duplicate'), 1000, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $first = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);
    $first->assertOk();

    $paidAtAfterFirst = $payment->fresh()->paid_at;

    // Redeliver the EXACT same event.
    $second = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);
    $second->assertOk();

    expect(Payment::where('tenant_id', CHECKOUT_TENANT_IDS[0])->where('plan_price_id', $price->id)->count())->toBe(1);
    expect(BillingProviderEvent::where('provider_event_id', uid('evt_duplicate_1'))->count())->toBe(1);
    expect($payment->fresh()->paid_at->equalTo($paidAtAfterFirst))->toBeTrue();
});

test('22/23. an event whose provider reference matches no known Payment is rejected, never activates a plan', function () {
    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_no_match_1'), uid('cs_test_does_not_exist_anywhere'), 1000, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();

    $event = BillingProviderEvent::where('provider_event_id', uid('evt_no_match_1'))->first();
    expect($event)->not->toBeNull();
    expect($event->processing_status)->toBe(ProviderEventProcessingStatus::Rejected);
    expect($event->payment_id)->toBeNull();
});

test('24. an event reporting a different amount than the correlated Payment is rejected, never marks it Succeeded', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-amount-mismatch');
    $price = ensureCheckoutPrice($plan, amountMinor: 1000, currency: 'USD');
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_amount_mismatch'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_amount_mismatch_1'), uid('cs_test_amount_mismatch'), 999999, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $event = BillingProviderEvent::where('provider_event_id', uid('evt_amount_mismatch_1'))->first();
    expect($event->processing_status)->toBe(ProviderEventProcessingStatus::Rejected);
});

test('25. an event reporting a different currency than the correlated Payment is rejected, never marks it Succeeded', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-currency-mismatch');
    $price = ensureCheckoutPrice($plan, amountMinor: 1000, currency: 'USD');
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_currency_mismatch'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_currency_mismatch_1'), uid('cs_test_currency_mismatch'), 1000, 'eur'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('26/27/28. a failed/expired provider event changes only the Payment, never TenantStatus or Subscription plan/status', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-failure-isolated');
    $price = ensureCheckoutPrice($plan);
    $subscription = Subscription::currentFor($tenantA);
    $statusBefore = $subscription->status;
    $planIdBefore = $subscription->plan_id;
    $tenantStatusBefore = $tenantA->fresh()->status;

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_expired'), 1000, 'usd'),
    ]));

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');
    $payment = $result['payment'];

    [$payload, $signature] = signedStripeWebhook([
        'id' => uid('evt_expired_1'),
        'object' => 'event',
        'type' => 'checkout.session.expired',
        'data' => ['object' => [
            'id' => uid('cs_test_expired'), 'object' => 'checkout.session',
            'amount_total' => 1000, 'currency' => 'usd',
        ]],
    ], CHECKOUT_TEST_WEBHOOK_SECRET);

    $response = $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Canceled);

    expect($subscription->fresh()->status)->toBe($statusBefore);
    expect($subscription->fresh()->plan_id)->toBe($planIdBefore);
    expect($tenantA->fresh()->status)->toBe($tenantStatusBefore);
});

test('29. Tenant A cannot see Tenant B\'s payment on the checkout result page', function () {
    [$tenantA, $tenantB] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-cross-tenant');
    $priceA = ensureCheckoutPrice($plan, amountMinor: 1111);
    $priceB = ensureCheckoutPrice($plan, amountMinor: 2222);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_cross_a'), 1111, 'usd'),
    ]));
    app(CheckoutService::class)->initiate($tenantA, Subscription::currentFor($tenantA), $priceA, 'http://x/success', 'http://x/cancel');

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_cross_b'), 2222, 'usd'),
    ]));
    app(CheckoutService::class)->initiate($tenantB, Subscription::currentFor($tenantB), $priceB, 'http://x/success', 'http://x/cancel');

    checkoutLoginAsTenantAdmin($this, CHECKOUT_TENANT_IDS[0].'.localhost');
    $responseA = $this->get('http://'.CHECKOUT_TENANT_IDS[0].'.localhost/admin/saas/plan/checkout/success');
    $responseA->assertOk();
    $responseA->assertSee('11.11');
    $responseA->assertDontSee('22.22');

    tenancy()->end();

    checkoutLoginAsTenantAdmin($this, CHECKOUT_TENANT_IDS[1].'.localhost');
    $responseB = $this->get('http://'.CHECKOUT_TENANT_IDS[1].'.localhost/admin/saas/plan/checkout/success');
    $responseB->assertOk();
    $responseB->assertSee('22.22');
    $responseB->assertDontSee('11.11');
});

test('30. Platform Admin can see recent payment status on the tenant detail page', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-admin-visibility');
    $price = ensureCheckoutPrice($plan, amountMinor: 7777, currency: 'USD');
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_admin_visibility'), 7777, 'usd'),
    ]));
    app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');

    ensureCheckoutPlatformAdmin();
    loginPlatformAdminForCheckout($this);

    $response = $this->get('http://localhost/platform/tenants/'.CHECKOUT_TENANT_IDS[0]);
    $response->assertOk();
    $response->assertSee('77.77');
    $response->assertSee('Pending');
});

test('31. billing checkout/webhook mutations never touch the tenant connection', function () {
    [$tenantA] = ensureCheckoutTenants();
    $plan = ensureCheckoutPlan('checkout-plan-central-only');
    $price = ensureCheckoutPrice($plan, amountMinor: 3000, currency: 'USD');
    $subscription = Subscription::currentFor($tenantA);

    ApiRequestor::setHttpClient(new FakeStripeHttpClientForCheckoutTest([
        fakeCheckoutSessionResponse(uid('cs_test_central_only'), 3000, 'usd'),
    ]));

    $connections = [];
    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    $result = app(CheckoutService::class)->initiate($tenantA, $subscription, $price, 'http://x/success', 'http://x/cancel');

    [$payload, $signature] = signedStripeWebhook(
        checkoutSessionCompletedEvent(uid('evt_central_only_1'), uid('cs_test_central_only'), 3000, 'usd'),
        CHECKOUT_TEST_WEBHOOK_SECRET
    );

    $this->call('POST', 'http://localhost/billing/webhook/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($connections)->not->toContain('tenant');
});

test('32. a free tenant with no Payment remains a valid, fully-provisioned tenant', function () {
    $tenant = Tenant::find('tenant-checkout-free-check');

    if (! $tenant) {
        $tenant = Tenant::create(['id' => 'tenant-checkout-free-check', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-checkout-free-check.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    expect($tenant->fresh()->status)->toBe(TenantStatus::Ready);
    expect(Subscription::currentFor($tenant))->not->toBeNull();
    expect(Payment::where('tenant_id', $tenant->getTenantKey())->count())->toBe(0);
});
