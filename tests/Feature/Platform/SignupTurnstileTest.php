<?php

/**
 * TASK-MVP-006 - Public Signup Abuse Protection (Cloudflare Turnstile).
 *
 * Real HTTP requests against the central domain, real MySQL tenant
 * provisioning, real `Platform\Signup\Services\TurnstileVerifier` -
 * nothing mocked EXCEPT the one external network boundary
 * (`Http::fake()` for Cloudflare's own `siteverify` endpoint), matching
 * this project's own accepted exception for external infrastructure
 * (the same pattern already used for the Stripe SDK's official test
 * seam). `MerchantOnboarding`/`TenantProvisioner` are deliberately never
 * mocked here - the whole point of the ordering tests is to prove real
 * provisioning genuinely never runs, not to assert a mock wasn't called.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

const TURNSTILE_TEST_IDS = [
    'turnstile-a',
    'turnstile-b',
    'turnstile-dup-slug',
    'turnstile-dup-email',
    'turnstile-retry',
];

function cleanupTurnstileTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (TURNSTILE_TEST_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbUsername = $data['tenancy_db_username'] ?? null;
            $dbName = $data['tenancy_db_name'] ?? null;

            if ($dbUsername) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $dbUsername).'`');
            }

            if ($dbName) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $dbName).'`');
            }
        }

        $central->table('domains')->where('tenant_id', $id)->delete();
        $central->table('tenants')->where('id', $id)->delete();
    }
}

function turnstilePayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'owner_name' => 'Merchant '.$slug,
        'owner_email' => $slug.'@example.test',
        'slug' => $slug,
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ], $overrides);
}

function assertZeroSignupSideEffects(string $slug, string $domain): void
{
    expect(Tenant::find($slug))->toBeNull();
    expect(Domain::where('domain', $domain)->exists())->toBeFalse();
}

beforeEach(function () {
    cleanupTurnstileTestTenants();
    Cache::flush(); // isolates the rate-limit test from every other test's own /join hits.
    config(['platform.plans.default_code' => 'free']);
    config(['platform.signup.turnstile.enabled' => false]);
    // TASK-MVP-007. Production now defaults PUBLIC_SIGNUP_ENABLED to false
    // (managed-only onboarding) - this whole file exists to prove
    // Turnstile's own behavior WHEN public signup is reachable, so it
    // explicitly opts back in. See PublicSignupFlagTest.php for the
    // disabled-by-default behavior itself.
    config(['platform.signup.enabled' => true]);
});

afterEach(fn () => cleanupTurnstileTestTenants());

test('1. Turnstile disabled: signup behaves exactly as before, no Cloudflare call made', function () {
    config(['platform.signup.turnstile.enabled' => false]);
    Http::fake(); // if TurnstileVerifier called out, this would record it - assert it never did

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-a'));

    $response->assertRedirect('http://turnstile-a.platform.test/admin/login?welcome=1');
    expect(Tenant::find('turnstile-a')->status->value)->toBe('ready');
    Http::assertNothingSent();
});

test('2. Turnstile enabled + Cloudflare success=true: real signup and provisioning succeed', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-b', ['cf-turnstile-response' => 'a-real-looking-token']));

    $response->assertRedirect('http://turnstile-b.platform.test/admin/login?welcome=1');
    $tenant = Tenant::find('turnstile-b');
    expect($tenant)->not->toBeNull();
    expect($tenant->status->value)->toBe('ready');
    expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeTrue();
});

test('3. Turnstile enabled + missing token: rejected before any side effect, no Cloudflare call made', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake();

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-a')); // no cf-turnstile-response field at all

    $response->assertSessionHasErrors('turnstile');
    assertZeroSignupSideEffects('turnstile-a', 'turnstile-a.platform.test');
    Http::assertNothingSent(); // missing token is rejected before ever calling Cloudflare
});

test('4. Turnstile enabled + Cloudflare success=false (invalid/expired token): rejected before any side effect', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200)]);

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-a', ['cf-turnstile-response' => 'an-expired-or-invalid-token']));

    $response->assertSessionHasErrors('turnstile');
    assertZeroSignupSideEffects('turnstile-a', 'turnstile-a.platform.test');
});

test('5. Turnstile enabled + Cloudflare network failure: fails closed, zero provisioning', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(function () {
        throw new ConnectionException('simulated Cloudflare network outage');
    });

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-a', ['cf-turnstile-response' => 'some-token']));

    $response->assertSessionHasErrors('turnstile');
    assertZeroSignupSideEffects('turnstile-a', 'turnstile-a.platform.test');
});

test('6. Turnstile enabled + malformed/non-2xx verification response: fails closed', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response('not json at all', 500)]);

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-a', ['cf-turnstile-response' => 'some-token']));

    $response->assertSessionHasErrors('turnstile');
    assertZeroSignupSideEffects('turnstile-a', 'turnstile-a.platform.test');
});

test('7. a direct POST without ever loading the widget/JS still cannot bypass Turnstile', function () {
    // This IS the whole test suite's own proof of "the browser widget is
    // never the security boundary" - every test above already posts
    // directly (no JS/browser involved at all) and is still correctly
    // rejected when the field is absent/invalid. This test just makes the
    // claim explicit for a fully bare payload (no cf-turnstile-response
    // key present in the request body whatsoever).
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake();

    $payload = turnstilePayload('turnstile-a');
    expect($payload)->not->toHaveKey('cf-turnstile-response');

    $response = $this->post('http://localhost/join', $payload);

    $response->assertSessionHasErrors('turnstile');
    assertZeroSignupSideEffects('turnstile-a', 'turnstile-a.platform.test');
});

test('8. duplicate slug is still rejected before Turnstile is ever consulted', function () {
    config(['platform.signup.turnstile.enabled' => false]);
    $this->post('http://localhost/join', turnstilePayload('turnstile-dup-slug'))->assertRedirect();

    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake();

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-dup-slug', [
        'owner_email' => 'different-turnstile-dup-slug@example.test',
        'cf-turnstile-response' => 'irrelevant',
    ]));

    $response->assertSessionHasErrors('slug');
    Http::assertNothingSent(); // field validation fails first, Turnstile never called
});

test('9. duplicate owner email is still rejected before Turnstile is ever consulted', function () {
    config(['platform.signup.turnstile.enabled' => false]);
    $this->post('http://localhost/join', turnstilePayload('turnstile-dup-email'))->assertRedirect();

    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake();

    $response = $this->post('http://localhost/join', turnstilePayload('turnstile-dup-email-2', [
        'owner_email' => 'turnstile-dup-email@example.test',
        'cf-turnstile-response' => 'irrelevant',
    ]));

    $response->assertSessionHasErrors('owner_email');
    Http::assertNothingSent();
});

test('10. the signed retry flow remains intact and is never routed through Turnstile', function () {
    config(['platform.signup.turnstile.enabled' => false]);

    // Force the initial attempt to fail via a real, deterministic
    // provisioning failure (no default plan exists under this bogus code) -
    // the exact same established technique PlatformSignupTest.php's own
    // "retry after a forced provisioning failure" test already uses.
    config(['platform.plans.default_code' => 'turnstile-retry-missing-plan']);
    $this->post('http://localhost/join', turnstilePayload('turnstile-retry'));
    config(['platform.plans.default_code' => 'free']);

    $tenant = Tenant::find('turnstile-retry');
    expect($tenant)->not->toBeNull();
    expect($tenant->status->value)->toBe('failed');

    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(); // if retry called Cloudflare, this would record it

    $signedUrl = URL::temporarySignedRoute(
        'signup.retry.store',
        now()->addHours(24),
        ['tenant' => $tenant->getKey()]
    );

    $response = $this->post($signedUrl, [
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ]);

    $response->assertRedirect('http://turnstile-retry.platform.test/admin/login?welcome=1');
    Http::assertNothingSent(); // retry never consults Turnstile, regardless of its enabled state
});

test('11. the signup POST throttle is now 3 per minute per IP', function () {
    $last = null;

    for ($i = 0; $i < 4; $i++) {
        $last = $this->post('http://localhost/join', ['slug' => '']); // deliberately invalid, no side effects
    }

    expect($last->getStatusCode())->toBe(429);
});

test('12. the Turnstile secret key never appears in the rendered signup HTML', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'public-site-key-visible',
        'platform.signup.turnstile.secret_key' => 'super-secret-value-must-never-render',
    ]);

    $response = $this->get('http://localhost/join');

    $response->assertOk();
    $response->assertSee('public-site-key-visible');
    $response->assertDontSee('super-secret-value-must-never-render');
});

test('13. the Turnstile widget/site key is rendered only when enabled, absent when disabled', function () {
    config(['platform.signup.turnstile.enabled' => false]);
    $disabled = $this->get('http://localhost/join');
    $disabled->assertOk();
    $disabled->assertDontSee('cf-turnstile');
    $disabled->assertDontSee('challenges.cloudflare.com');

    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key-visible',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    $enabled = $this->get('http://localhost/join');
    $enabled->assertOk();
    $enabled->assertSee('cf-turnstile', false);
    $enabled->assertSee('challenges.cloudflare.com', false);
    $enabled->assertSee('test-site-key-visible');
});

test('14. a failed verification leaves zero Tenant, zero Domain, zero tenant database, zero Subscription, zero Admin artifacts', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);

    $this->post('http://localhost/join', turnstilePayload('turnstile-a', ['cf-turnstile-response' => 'bad-token']));

    // Central: zero Tenant, zero Domain.
    expect(Tenant::find('turnstile-a'))->toBeNull();
    expect(Domain::where('domain', 'turnstile-a.platform.test')->exists())->toBeFalse();

    // No physical database was ever created for this slug.
    $provisioning = DB::connection('tenant_provisioning');
    $stillNoDatabase = $provisioning->select("SHOW DATABASES LIKE 'tenantturnstile-a'");
    expect($stillNoDatabase)->toBeEmpty();

    // No subscription row (would require a real tenant id foreign key that
    // was never created).
    expect(DB::connection('mysql')->table('subscriptions')->where('tenant_id', 'turnstile-a')->exists())->toBeFalse();
});
