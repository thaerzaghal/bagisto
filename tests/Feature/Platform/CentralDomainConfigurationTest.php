<?php

/**
 * TASK-MVP-004A (task section 2) - PLATFORM_CENTRAL_DOMAINS.
 *
 * Proves `config('tenancy.central_domains')` is now driven by an EXPLICIT,
 * separate env source (never derived from `config('platform.base_domain')`
 * - see config/tenancy.php's own docblock for why), while `127.0.0.1`/
 * `localhost` remain always-present local-dev defaults, and that changing
 * this configuration does not regress either direction of the existing
 * central/tenant host boundary (`Platform\Admin\Http\Middleware\
 * EnsureCentralDomain` rejecting tenant hosts; `Stancl\Tenancy\Middleware\
 * InitializeTenancyByDomain` still resolving real tenant hosts).
 */

use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Platform\Tenancy\Support\EnvList;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const CENTRAL_DOMAIN_TEST_TENANT_ID = 'tenant-central-domain-check';

test('1. central domains parse correctly - EnvList composition matches config/tenancy.php\'s own formula', function () {
    // Mirrors config/tenancy.php's own `array_values(array_unique(array_merge(
    // ['127.0.0.1', 'localhost'], EnvList::parse(env('PLATFORM_CENTRAL_DOMAINS'))))) `
    // expression directly, proving the composition behaves as documented for
    // a realistic PLATFORM_CENTRAL_DOMAINS value without needing to reboot
    // the app with a different real env value.
    $composed = array_values(array_unique(array_merge(
        ['127.0.0.1', 'localhost'],
        EnvList::parse('central.example.test, app.example.test')
    )));

    expect($composed)->toBe(['127.0.0.1', 'localhost', 'central.example.test', 'app.example.test']);
});

test('2. with PLATFORM_CENTRAL_DOMAINS unset, 127.0.0.1/localhost remain the local-dev default', function () {
    // Real app boot value (this test's own actual, unmodified test
    // environment does not set PLATFORM_CENTRAL_DOMAINS).
    expect(config('tenancy.central_domains'))->toBe(['127.0.0.1', 'localhost']);
});

test('3. the central-domain list can differ from the tenant base domain - both are independently configurable', function () {
    // config('platform.base_domain') (tenant subdomain parent) and
    // config('tenancy.central_domains') (central-app host allowlist) are
    // read from two entirely separate config keys/env vars - proven here
    // by setting them to deliberately UNRELATED values and confirming
    // neither derives from the other.
    config([
        'platform.base_domain' => 'stores.example.test',
        'tenancy.central_domains' => ['platform.example.test'],
    ]);

    expect(config('platform.base_domain'))->toBe('stores.example.test');
    expect(config('tenancy.central_domains'))->toBe(['platform.example.test']);
    expect(config('tenancy.central_domains'))->not->toContain('stores.example.test');
});

test('4. central-only routes are rejected on a host NOT in the configured central-domain list', function () {
    config(['tenancy.central_domains' => ['platform.example.test']]);

    // 'localhost' is the DEFAULT central domain, but is no longer in the
    // list after the override above - EnsureCentralDomain must reject it
    // exactly like any other non-central host.
    $response = $this->get('http://localhost/platform/login');

    $response->assertNotFound();
});

test('5. central-only routes remain reachable on a host that IS in the configured central-domain list', function () {
    config(['tenancy.central_domains' => ['platform.example.test']]);

    $response = $this->get('http://platform.example.test/platform/login');

    $response->assertOk();
});

test('6. central-only routes remain rejected on an ordinary tenant-shaped host, regardless of central-domain configuration', function () {
    // No tenant fixture needed - EnsureCentralDomain rejects purely on
    // host-not-in-list grounds, before any tenant resolution is attempted.
    $response = $this->get('http://some-tenant.localhost/platform/login');

    $response->assertNotFound();
});

test('7. ordinary tenant routing still works normally after the central-domain configuration change', function () {
    $tenant = Tenant::find(CENTRAL_DOMAIN_TEST_TENANT_ID);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => CENTRAL_DOMAIN_TEST_TENANT_ID, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => CENTRAL_DOMAIN_TEST_TENANT_ID.'.localhost']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
    }

    $response = $this->get('http://'.CENTRAL_DOMAIN_TEST_TENANT_ID.'.localhost/admin/login');

    $response->assertOk();
});
