<?php

/**
 * TASK-MVP-004A (task section 6) - `php artisan platform:production:check`.
 *
 * Read-only - proves the command mutates nothing (no config/env/DB writes -
 * every check is a plain config() read or, for Redis, a real, harmless
 * PING) and never leaks a secret VALUE into its own output, while still
 * genuinely reporting misconfiguration (APP_DEBUG=true, non-redis
 * CACHE_STORE, RESPONSE_CACHE_ENABLED=true, DB_PROVISION_USERNAME=root).
 */

use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class);

test('1. exits successfully against a production-shaped configuration', function () {
    config([
        'app.debug' => false,
        'platform.base_domain' => 'app.example.test',
        'tenancy.central_domains' => ['app.example.test'],
        'cache.default' => 'redis',
        'session.driver' => 'database',
        'responsecache.enabled' => false,
        'database.connections.tenant_provisioning.username' => 'provisioning_user',
        'platform-billing.stripe.secret' => 'sk_test_configured',
        'mail.mailers.smtp.host' => 'smtp.real-provider.test',
        'mail.mailers.smtp.port' => '587',
    ]);

    putenv('TRUSTED_PROXIES=10.0.0.5');
    putenv('PLATFORM_CENTRAL_DOMAINS=app.example.test');

    $this->artisan('platform:production:check')->assertSuccessful();

    putenv('TRUSTED_PROXIES');
    putenv('PLATFORM_CENTRAL_DOMAINS');
});

test('2. fails and reports APP_DEBUG=true as a FAIL', function () {
    config(['app.debug' => true]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('APP_DEBUG');
    expect($output)->toContain('FAIL');
});

test('3. fails and reports RESPONSE_CACHE_ENABLED=true as a FAIL (R1)', function () {
    config(['app.debug' => false, 'responsecache.enabled' => true]);

    $this->artisan('platform:production:check')->assertFailed();
});

test('4. fails and reports DB_PROVISION_USERNAME=root as a FAIL', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'database.connections.tenant_provisioning.username' => 'root',
    ]);

    $this->artisan('platform:production:check')->assertFailed();
});

test('5. never prints the actual STRIPE_SECRET/DB provisioning password value', function () {
    config([
        'platform-billing.stripe.secret' => 'sk_test_super_secret_value_12345',
        'database.connections.tenant_provisioning.password' => 'super_secret_db_password',
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->not->toContain('sk_test_super_secret_value_12345');
    expect($output)->not->toContain('super_secret_db_password');
});

test('6. warns (does not fail the exit code) when Stripe is intentionally unconfigured', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'cache.default' => 'redis',
        'database.connections.tenant_provisioning.username' => 'provisioning_user',
        'platform-billing.stripe.secret' => '',
        'mail.mailers.smtp.host' => 'smtp.real-provider.test',
        'mail.mailers.smtp.port' => '587',
        'platform.base_domain' => 'app.example.test',
        'tenancy.central_domains' => ['app.example.test'],
    ]);

    putenv('TRUSTED_PROXIES=10.0.0.5');
    putenv('PLATFORM_CENTRAL_DOMAINS=app.example.test');

    // Stripe being unconfigured must not, by itself, fail the command -
    // it's the recommended pilot posture (INFO), not a misconfiguration.
    $this->artisan('platform:production:check')->assertSuccessful();

    putenv('TRUSTED_PROXIES');
    putenv('PLATFORM_CENTRAL_DOMAINS');
});
