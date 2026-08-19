<?php

/**
 * TASK-MVP-013 (RISK_REGISTER.md R72). Proves `Platform\Plans\Exceptions\
 * Concerns\RendersAsEntitlementFailure` (the replacement for
 * `Platform\Enforcement\Providers\EnforcementServiceProvider::
 * registerExceptionRenderer()`'s now-broken `Container::afterResolving()`
 * registration): every `EntitlementException` implementor renders a clean
 * 422 with the exact historical R36 message contract, via real HTTP
 * requests through the actual, unmodified Bagisto Admin product-creation
 * endpoint - not by calling `->render()` directly except where a real
 * HTTP path genuinely cannot reach a specific case (see test 4).
 *
 * This file deliberately covers only the GAPS `ProductLimitEnforcementTest.php`
 * does not already close (LimitExceededException+products.limit,
 * FeatureNotConfiguredException, FeatureTypeMismatchException, and the
 * "blocked creation persists nothing" proof are all already covered there
 * and are re-run unmodified as part of this same task's own verification -
 * see the task's final report): `NoPlanAssignedException` via a real HTTP
 * request (never exercised end-to-end anywhere in this codebase before),
 * proof that `MyPlanController`'s own local catch remains completely
 * unaffected by `render()` now existing on the exception class, and a
 * direct proof that `EntitlementException`/concrete-type catching still
 * works normally outside HTTP.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Exceptions\EntitlementException;
use Platform\Plans\Exceptions\LimitExceededException;
use Platform\Plans\Exceptions\NoPlanAssignedException;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Tests\TestCase;

uses(PlatformIntegrationTestCase::class);

const EER_TENANT_ID = 'tenant-eer-a';

function ensureEerTenant(): Tenant
{
    $tenant = Tenant::find(EER_TENANT_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => EER_TENANT_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => EER_TENANT_ID.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    return $tenant->fresh();
}

function eerAttributeFamilyId(Tenant $tenant): int
{
    return $tenant->run(fn () => DB::table('attribute_families')->value('id'));
}

function eerCreateProductViaAdmin(TestCase $test, string $domain, string $sku): TestResponse
{
    return $test->postJson('http://'.$domain.'/admin/catalog/products/create', [
        'type' => 'simple',
        'attribute_family_id' => eerAttributeFamilyId(Tenant::find(explode('.', $domain)[0])),
        'sku' => $sku,
    ]);
}

function eerLoginAsTenantAdmin(TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

beforeEach(function () {
    $this->tenant = ensureEerTenant();
    $this->tenant->run(fn () => DB::table('products')->delete());
});

test('1. NoPlanAssignedException (a real, never-before-exercised gap) renders a clean 422 via a real HTTP product-creation request, never a 500', function () {
    // Bypasses TenantProvisioner::ensureInitialSubscriptionStarted()'s own
    // guarantee deliberately, to reach the exact state that class's own
    // docblock says "should never happen but fails loud if it does".
    $this->tenant->forceFill(['plan_id' => null])->save();

    eerLoginAsTenantAdmin($this, EER_TENANT_ID.'.localhost');

    $response = eerCreateProductViaAdmin($this, EER_TENANT_ID.'.localhost', 'eer-no-plan');

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Your current plan does not allow this action.');
    expect($response->getContent())->not->toContain('SQLSTATE');
    expect($response->getContent())->not->toContain('Stack trace');

    $exists = $this->tenant->run(fn () => DB::table('products')->where('sku', 'eer-no-plan')->exists());
    expect($exists)->toBeFalse();
});

test('2. MyPlanController\'s own local NoPlanAssignedException catch remains completely unaffected - still the existing fallback view, never a 422 JSON response', function () {
    $this->tenant->forceFill(['plan_id' => null])->save();

    eerLoginAsTenantAdmin($this, EER_TENANT_ID.'.localhost');

    $response = $this->get('http://'.EER_TENANT_ID.'.localhost/admin/saas/plan');

    // If render() being added had somehow changed anything about local
    // try/catch behavior, this would now incorrectly be a 422 JSON
    // response instead of the real Blade view MyPlanController's own
    // catch block already returns.
    $response->assertOk();
    $response->assertSee('My Plan');
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});

test('3. catch(EntitlementException)/catch(NoPlanAssignedException) still catch the original concrete exception type normally, outside HTTP', function () {
    try {
        throw new LimitExceededException(FeatureCode::ProductsLimit->value, 5, 5);
    } catch (EntitlementException $e) {
        expect($e)->toBeInstanceOf(LimitExceededException::class);
        expect($e->feature)->toBe(FeatureCode::ProductsLimit->value);
        expect($e->limit)->toBe(5);
    }

    try {
        throw new NoPlanAssignedException('no plan for test tenant');
    } catch (NoPlanAssignedException $e) {
        expect($e->getMessage())->toBe('no plan for test tenant');
    }
});

test('5. the 422 rendering path is identical under APP_DEBUG=false - a real, disclosed in-process proof, not a full real-subprocess one', function () {
    // Unlike R68's own CentralSafeExceptionHandlerRealHandlerTest.php (which
    // genuinely NEEDS a real subprocess, because Webkul\Core\Exceptions\
    // Handler::register()'s OWN renderable registrations only activate
    // under a real APP_DEBUG=false process), THIS fix's correctness is
    // structurally independent of config('app.debug') entirely - confirmed
    // by direct source reading (TASK-MVP-013's own design checkpoint):
    // `method_exists($e, 'render')` is the FIRST, unconditional check in
    // BOTH CentralSafeExceptionHandler::render() and the base
    // Illuminate\Foundation\Exceptions\Handler::render(), evaluated before
    // ANY debug-gated branch, and RendersAsEntitlementFailure::render()
    // itself never reads config('app.debug') at all. A real in-process
    // config mutation genuinely exercises the same code path a real
    // process would, for this specific fix - disclosed explicitly here
    // rather than silently assumed, matching this project's own established
    // practice (see CentralSafeExceptionHandlerTest.php's own docblock for
    // the same kind of disclosure). CentralSafeExceptionHandlerRealHandlerTest.php
    // is re-run unmodified as corroborating real-subprocess evidence that
    // the surrounding exception pipeline is genuinely unaffected.
    config(['app.debug' => false]);

    try {
        $this->tenant->forceFill(['plan_id' => null])->save();

        eerLoginAsTenantAdmin($this, EER_TENANT_ID.'.localhost');

        $response = eerCreateProductViaAdmin($this, EER_TENANT_ID.'.localhost', 'eer-debug-false');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Your current plan does not allow this action.');
        expect($response->getContent())->not->toContain('SQLSTATE');
        expect($response->getContent())->not->toContain('Stack trace');
    } finally {
        config(['app.debug' => true]);
    }
});

test('4. LimitExceededException for a feature other than products.limit renders the generic message, not the products-specific one', function () {
    // No real HTTP path in this codebase throws LimitExceededException for
    // any feature other than products.limit today (confirmed by a full
    // grep of every assertWithinLimit() call site) - this proves the
    // trait's own branch logic directly, the same narrow, disclosed
    // technique already used elsewhere in this project when no real
    // request path can reach a specific code branch.
    $exception = new LimitExceededException(FeatureCode::StaffLimit->value, 3, 3);

    $response = $exception->render(request());

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'])->toBe('Your current plan does not allow this action.');
});
