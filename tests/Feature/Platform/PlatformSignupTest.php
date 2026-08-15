<?php

/**
 * TASK-MVP-001 - Merchant Self-Service Signup & Automatic Provisioning.
 *
 * Real HTTP requests against the central domain (`http://localhost`,
 * matching `config('tenancy.central_domains')`), real MySQL tenant
 * database provisioning, real Bagisto tenant Admin logins - nothing
 * mocked, matching every other Platform integration test file's own
 * evidentiary standard.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

// R40-class discipline: a dedicated fixture prefix, never tenant-a/tenant-b
// or any other file's shared fixtures.
const SIGNUP_TEST_IDS = [
    'signup-mvp-a',
    'signup-mvp-b',
    'signup-mvp-dup',
    'signup-mvp-retry',
    'signup-mvp-bad1',
];

function cleanupSignupTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (SIGNUP_TEST_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            $dbName = $data['tenancy_db_name'] ?? null;
            $dbUsername = $data['tenancy_db_username'] ?? null;

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

function signupPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'owner_name' => 'Merchant '.$slug,
        'owner_email' => $slug.'@example.test',
        'slug' => $slug,
        'password' => 'correct-horse-1',
        'password_confirmation' => 'correct-horse-1',
    ], $overrides);
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array<int, string|null>}
 */
function captureQueriedConnectionsDuringSignup(callable $callback): array
{
    $connections = [];

    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    return [$callback(), $connections];
}

beforeEach(function () {
    cleanupSignupTestTenants();
    Cache::flush(); // isolates the rate-limiting test from every other test's own /join hits.
    config(['platform.plans.default_code' => 'free']);
});

afterEach(fn () => cleanupSignupTestTenants());

test('1. the signup form is reachable on the central domain', function () {
    $response = $this->get('http://localhost/join');

    $response->assertOk();
    $response->assertSee('Create your store');
});

test('2-4, 7, 14. a fresh merchant can self-register: Tenant+Domain created centrally, real tenant database provisioned, default plan + active subscription established, redirected to the real tenant admin login', function () {
    $response = $this->post('http://localhost/join', signupPayload('signup-mvp-a'));

    $tenant = Tenant::find('signup-mvp-a');
    expect($tenant)->not->toBeNull();
    expect($tenant->status)->toBe(TenantStatus::Ready);

    // Central Tenant + Domain rows.
    expect($tenant->owner_name)->toBe('Merchant signup-mvp-a');
    expect($tenant->owner_email)->toBe('signup-mvp-a@example.test');
    expect(Domain::where('domain', 'signup-mvp-a.platform.test')->exists())->toBeTrue();

    // Real, physically separate tenant database - not just a central flag.
    expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))->toBeTrue();
    $tenant->run(function () {
        expect(DB::connection()->getDatabaseName())->not->toBe('bagisto_central');
        expect(\Illuminate\Support\Facades\Schema::hasTable('products'))->toBeTrue();
        expect(\Illuminate\Support\Facades\Schema::hasTable('admins'))->toBeTrue();
    });

    // Default plan + Active subscription, through the existing,
    // unmodified pipeline (TenantProvisioner::ensureInitialSubscriptionStarted()).
    $defaultPlan = Plan::where('code', 'free')->first();
    expect($tenant->plan_id)->toBe($defaultPlan->id);
    $subscription = Subscription::currentFor($tenant);
    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->plan_id)->toBe($defaultPlan->id);

    // Redirects to the merchant's OWN tenant admin login - never an
    // auto-authenticated session.
    $response->assertRedirect('http://signup-mvp-a.platform.test/admin/login?welcome=1');
});

test('5-6. the merchant-selected admin identity replaces the generic seeded admin, and the merchant can perform a real login with it', function () {
    $this->post('http://localhost/join', signupPayload('signup-mvp-a', [
        'owner_name' => 'Amina Merchant',
        'owner_email' => 'amina@example.test',
        'password' => 'a-real-password-1',
        'password_confirmation' => 'a-real-password-1',
    ]));

    $tenant = Tenant::find('signup-mvp-a');

    $tenant->run(function () {
        $admin = DB::table('admins')->where('id', 1)->first();
        expect($admin->name)->toBe('Amina Merchant');
        expect($admin->email)->toBe('amina@example.test');
        // 11. No plaintext password anywhere - a real bcrypt hash only.
        expect($admin->password)->toStartWith('$2y$');
        expect($admin->email)->not->toBe('admin@example.com');
    });

    // A REAL login through the unmodified Bagisto tenant Admin login flow -
    // matching TenantAdminPlanPageTest.php's own established convention:
    // the real proof of successful authentication is that a subsequent
    // request to a protected admin page succeeds, not the login
    // response's own redirect shape.
    $this->post('http://signup-mvp-a.platform.test/admin/login', [
        'email' => 'amina@example.test',
        'password' => 'a-real-password-1',
    ]);

    $protected = $this->get('http://signup-mvp-a.platform.test/admin/catalog/products');
    $protected->assertOk();
});

test('8a. a duplicate store address (slug) is rejected before any provisioning side effect', function () {
    $this->post('http://localhost/join', signupPayload('signup-mvp-dup'));
    expect(Tenant::find('signup-mvp-dup'))->not->toBeNull();

    $before = DB::connection('mysql')->table('tenants')->count();

    $second = $this->post('http://localhost/join', signupPayload('signup-mvp-dup', [
        'owner_email' => 'someone-else@example.test',
    ]));

    $second->assertSessionHasErrors('slug');
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);
});

test('8b. a duplicate owner email is rejected before any provisioning side effect', function () {
    $this->post('http://localhost/join', signupPayload('signup-mvp-a', [
        'owner_email' => 'shared@example.test',
    ]));

    $before = DB::connection('mysql')->table('tenants')->count();

    $second = $this->post('http://localhost/join', signupPayload('signup-mvp-b', [
        'owner_email' => 'shared@example.test',
    ]));

    $second->assertSessionHasErrors('owner_email');
    expect(Tenant::find('signup-mvp-b'))->toBeNull();
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);
});

test('9. malicious/invalid slugs, including a backtick, are rejected and never reach provisioning', function (string $badSlug) {
    $before = DB::connection('mysql')->table('tenants')->count();

    $response = $this->post('http://localhost/join', signupPayload('signup-mvp-bad1', ['slug' => $badSlug]));

    $response->assertSessionHasErrors('slug');
    expect(DB::connection('mysql')->table('tenants')->count())->toBe($before);
})->with([
    'backtick' => ['tenant`evil'],
    'uppercase' => ['SignupMvp'],
    'spaces' => ['signup mvp'],
    'sql-ish' => ["a'; DROP TABLE tenants;--"],
    'too short' => ['ab'],
    'reserved word' => ['admin'],
    'leading hyphen' => ['-signupmvp'],
]);

test('10. retry after a forced provisioning failure is authorized only via the signed link and succeeds idempotently', function () {
    // Force a real, deterministic provisioning failure - no default plan
    // exists under this bogus code - WITHOUT the malformed input R19
    // already blocks at the validation layer.
    config(['platform.plans.default_code' => 'signup-mvp-retry-missing-plan']);

    $failed = $this->post('http://localhost/join', signupPayload('signup-mvp-retry', [
        'password' => 'first-attempt-pw',
        'password_confirmation' => 'first-attempt-pw',
    ]));

    $failed->assertOk();
    $failed->assertSee('We hit a problem');

    $tenant = Tenant::find('signup-mvp-retry');
    expect($tenant->status)->toBe(TenantStatus::Failed);
    expect($tenant->last_error)->toContain('signup-mvp-retry-missing-plan');

    // A raw, unsigned retry URL (mere knowledge of the tenant id/slug -
    // public information) is rejected - the REQUIRED security adjustment.
    $unsigned = $this->get(route('signup.retry.show', ['tenant' => 'signup-mvp-retry']));
    $unsigned->assertStatus(403);

    // Recover: fix the misconfiguration, then follow the ACTUAL signed
    // link the failure page rendered (extracted from its own HTML, not a
    // freshly-minted equivalent) - proving the exact link handed to the
    // merchant is the one that works.
    config(['platform.plans.default_code' => 'free']);

    preg_match('/<a href="([^"]+)">Try again<\/a>/', $failed->getContent(), $m);
    $retryUrl = html_entity_decode($m[1]);

    $retryForm = $this->get($retryUrl);
    $retryForm->assertOk();
    $retryForm->assertSee('Finish setting up');

    $submitUrl = extractSignedFormAction($retryForm->getContent());

    $retried = $this->post($submitUrl, [
        'password' => 'second-attempt-pw',
        'password_confirmation' => 'second-attempt-pw',
    ]);

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready);
    $retried->assertRedirect('http://signup-mvp-retry.platform.test/admin/login?welcome=1');

    // Idempotency: the retried admin identity (owner_name/owner_email
    // persisted from the ORIGINAL attempt, password from THIS retry) is
    // what actually works to log in - not the first attempt's password.
    $tenant->run(function () {
        expect(DB::table('admins')->where('id', 1)->value('email'))->toBe('signup-mvp-retry@example.test');
    });

    $this->post('http://signup-mvp-retry.platform.test/admin/login', [
        'email' => 'signup-mvp-retry@example.test',
        'password' => 'second-attempt-pw',
    ]);

    $protected = $this->get('http://signup-mvp-retry.platform.test/admin/catalog/products');
    $protected->assertOk();
});

function extractSignedFormAction(string $html): string
{
    preg_match('/<form method="POST" action="([^"]+)"/', $html, $m);

    return html_entity_decode($m[1]);
}

test('11. no plaintext password is persisted anywhere centrally', function () {
    $this->post('http://localhost/join', signupPayload('signup-mvp-a', [
        'password' => 'never-store-me-1',
        'password_confirmation' => 'never-store-me-1',
    ]));

    $tenantRow = DB::connection('mysql')->table('tenants')->where('id', 'signup-mvp-a')->first();
    $raw = json_encode($tenantRow);
    expect($raw)->not->toContain('never-store-me-1');

    $domainRow = DB::connection('mysql')->table('domains')->where('tenant_id', 'signup-mvp-a')->first();
    expect(json_encode($domainRow))->not->toContain('never-store-me-1');
});

test('12. signup never initializes tenancy or queries any tenant database connection for a request that does not touch its own tenant', function () {
    [$response, $connections] = captureQueriedConnectionsDuringSignup(
        fn () => $this->get('http://localhost/join')
    );

    $response->assertOk();
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();
});

test('13. rate limiting engages on repeated signup submissions', function () {
    $last = null;

    for ($i = 0; $i < 7; $i++) {
        $last = $this->post('http://localhost/join', ['slug' => '']); // deliberately invalid, no side effects
    }

    expect($last->getStatusCode())->toBe(429);
});
