<?php

/**
 * TASK-MVP-007 - Managed Merchant Onboarding / Disable Public Signup.
 *
 * Proves the `PUBLIC_SIGNUP_ENABLED` flag (`config('platform.signup.enabled')`,
 * defaulting to `false` in production - the managed-onboarding posture)
 * behaves exactly as required: both `GET`/`POST /join` genuinely 404 (not a
 * "disabled" page, not 403) with ZERO side effects when disabled, while the
 * signed retry flow (`/join/retry/{tenant}`) remains completely unaffected
 * by the flag either way - it is cryptographically authorized and tied to
 * an already-created tenant, a materially different risk profile from
 * anonymous public registration. Real HTTP requests, real MySQL, nothing
 * mocked except Cloudflare's own external `siteverify` endpoint
 * (`Http::fake()`) where relevant - this project's one accepted mock
 * boundary.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\Feature\Platform\PlatformIntegrationTestCase;

uses(PlatformIntegrationTestCase::class);

const PUBLIC_SIGNUP_FLAG_TEST_IDS = [
    'flag-a',
    'flag-retry',
];

function cleanupPublicSignupFlagTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (PUBLIC_SIGNUP_FLAG_TEST_IDS as $id) {
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

function flagTestPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'owner_name' => 'Merchant '.$slug,
        'owner_email' => $slug.'@example.test',
        'slug' => $slug,
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ], $overrides);
}

beforeEach(function () {
    cleanupPublicSignupFlagTestTenants();
    Cache::flush();
    config(['platform.plans.default_code' => 'free']);
});

afterEach(fn () => cleanupPublicSignupFlagTestTenants());

test('1. GET /join 404s when public signup is disabled', function () {
    config(['platform.signup.enabled' => false]);

    $response = $this->get('http://localhost/join');

    $response->assertNotFound();
});

test('2. POST /join 404s when public signup is disabled, with zero side effects', function () {
    config(['platform.signup.enabled' => false]);

    $before = DB::connection('mysql')->table('tenants')->count();

    $response = $this->post('http://localhost/join', flagTestPayload('flag-a'));

    $response->assertNotFound();
    expect(Tenant::find('flag-a'))->toBeNull();
    expect(Domain::where('domain', 'flag-a.platform.test')->exists())->toBeFalse();
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);

    $provisioning = DB::connection('tenant_provisioning');
    $stillNoDatabase = $provisioning->select("SHOW DATABASES LIKE 'tenantflag-a'");
    expect($stillNoDatabase)->toBeEmpty();
});

test('3. Turnstile is never consulted for a POST /join while public signup is disabled', function () {
    config([
        'platform.signup.enabled' => false,
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'test-site-key',
        'platform.signup.turnstile.secret_key' => 'test-secret-key',
    ]);
    Http::fake(function () {
        throw new ConnectionException('TurnstileVerifier must never be reached - the route itself must 404 first');
    });

    $response = $this->post('http://localhost/join', flagTestPayload('flag-a', ['cf-turnstile-response' => 'irrelevant']));

    $response->assertNotFound();
    Http::assertNothingSent();
});

test('4. enabling the flag restores /join to its normal, unchanged behavior', function () {
    config(['platform.signup.enabled' => true]);

    $get = $this->get('http://localhost/join');
    $get->assertOk();
    $get->assertSee('Create your store');

    $post = $this->post('http://localhost/join', flagTestPayload('flag-a'));
    $post->assertRedirect('http://flag-a.platform.test/admin/login?welcome=1');
    expect(Tenant::find('flag-a')->status)->toBe(TenantStatus::Ready);
});

test('5. the signed retry flow remains fully reachable while public signup is disabled', function () {
    // Force a real, deterministic provisioning failure while the flag is
    // still ON (so /join itself is reachable to create the failed tenant),
    // matching PlatformSignupTest.php's own established technique.
    config(['platform.signup.enabled' => true]);
    config(['platform.plans.default_code' => 'flag-retry-missing-plan']);
    $this->post('http://localhost/join', flagTestPayload('flag-retry'));
    config(['platform.plans.default_code' => 'free']);

    $tenant = Tenant::find('flag-retry');
    expect($tenant)->not->toBeNull();
    expect($tenant->status)->toBe(TenantStatus::Failed);

    // Now disable public signup entirely - the retry flow must still work,
    // completely unaffected by the flag.
    config(['platform.signup.enabled' => false]);

    $joinGet = $this->get('http://localhost/join');
    $joinGet->assertNotFound();

    $signedShow = URL::temporarySignedRoute(
        'signup.retry.show',
        now()->addHours(24),
        ['tenant' => $tenant->getKey()]
    );
    $this->get($signedShow)->assertOk();

    $signedStore = URL::temporarySignedRoute(
        'signup.retry.store',
        now()->addHours(24),
        ['tenant' => $tenant->getKey()]
    );
    $response = $this->post($signedStore, [
        'password' => 'second-attempt-pw',
        'password_confirmation' => 'second-attempt-pw',
    ]);

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);
    $response->assertRedirect('http://flag-retry.platform.test/admin/login?welcome=1');
});

test('6. an unsigned retry URL is still rejected regardless of the public signup flag', function () {
    config(['platform.signup.enabled' => true]);
    config(['platform.plans.default_code' => 'flag-retry-missing-plan']);
    $this->post('http://localhost/join', flagTestPayload('flag-retry'));
    config(['platform.plans.default_code' => 'free']);

    expect(Tenant::find('flag-retry'))->not->toBeNull();

    config(['platform.signup.enabled' => false]);

    $unsigned = $this->get(route('signup.retry.show', ['tenant' => 'flag-retry']));

    $unsigned->assertStatus(403);
});
