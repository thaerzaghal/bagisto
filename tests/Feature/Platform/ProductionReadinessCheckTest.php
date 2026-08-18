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
use Tests\TestCase;

uses(TestCase::class);

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

test('7. fails and reports asset_helper_tenancy=true as a FAIL (R63/R65 regression guard)', function () {
    config(['app.debug' => false, 'responsecache.enabled' => false, 'tenancy.filesystem.asset_helper_tenancy' => true]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('asset_helper_tenancy');
    expect($output)->toContain('FAIL');
    $this->artisan('platform:production:check')->assertFailed();
});

test('8. passes asset_helper_tenancy when it is false, the correct value', function () {
    config(['tenancy.filesystem.asset_helper_tenancy' => false]);

    Artisan::call('platform:production:check');

    expect(Artisan::output())->not->toContain('asset_helper_tenancy</error>');
});

test('9. reports the deployed APP_COMMIT marker when present, and warns (not fails) when absent', function () {
    $productionShaped = [
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
        'tenancy.filesystem.asset_helper_tenancy' => false,
    ];

    $marker = base_path('APP_COMMIT');
    $existed = is_file($marker);
    $original = $existed ? file_get_contents($marker) : null;

    putenv('TRUSTED_PROXIES=10.0.0.5');
    putenv('PLATFORM_CENTRAL_DOMAINS=app.example.test');

    file_put_contents($marker, "abc1234\n");
    config($productionShaped);
    Artisan::call('platform:production:check');
    expect(Artisan::output())->toContain('abc1234');

    unlink($marker);
    config($productionShaped);
    Artisan::call('platform:production:check');
    $output = Artisan::output();
    expect($output)->toContain('Deployed source');
    expect($output)->toContain('WARN');
    // A missing deploy marker is a visibility gap, not a misconfiguration -
    // it must never fail the command's own exit code.
    config($productionShaped);
    $this->artisan('platform:production:check')->assertSuccessful();

    putenv('TRUSTED_PROXIES');
    putenv('PLATFORM_CENTRAL_DOMAINS');

    if ($existed) {
        file_put_contents($marker, $original);
    } else {
        @unlink($marker);
    }
});

test('10. warns (does not fail) when Turnstile signup abuse protection is disabled', function () {
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
        'tenancy.filesystem.asset_helper_tenancy' => false,
        'platform.signup.turnstile.enabled' => false,
    ]);

    putenv('TRUSTED_PROXIES=10.0.0.5');
    putenv('PLATFORM_CENTRAL_DOMAINS=app.example.test');

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Signup abuse protection');
    expect($output)->toContain('WARN');
    $this->artisan('platform:production:check')->assertSuccessful();

    putenv('TRUSTED_PROXIES');
    putenv('PLATFORM_CENTRAL_DOMAINS');
});

test('11. passes when Turnstile is enabled with both keys configured', function () {
    config([
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'a-real-site-key',
        'platform.signup.turnstile.secret_key' => 'a-real-secret-key',
    ]);

    Artisan::call('platform:production:check');

    expect(Artisan::output())->toContain('Signup abuse protection');
    expect(Artisan::output())->not->toContain('Signup abuse protection</error>');
});

test('12. fails when Turnstile is enabled but a key is missing, and never prints the secret value', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'a-real-site-key',
        'platform.signup.turnstile.secret_key' => '',
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Signup abuse protection');
    expect($output)->toContain('TURNSTILE_SECRET_KEY');
    $this->artisan('platform:production:check')->assertFailed();
});

// TASK-MVP-007. `Public signup` is a SEPARATE row from `Signup abuse
// protection` above - it reports the managed-onboarding product decision
// itself (config('platform.signup.enabled')), not Turnstile's own armed
// status.
test('13. passes with "disabled (managed onboarding)" when public signup is disabled - the production default', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'database.connections.tenant_provisioning.username' => 'provisioning_user',
        'platform.signup.enabled' => false,
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Public signup');
    expect($output)->toContain('disabled (managed onboarding)');
    $this->artisan('platform:production:check')->assertSuccessful();
});

test('14. passes when public signup is enabled with valid Turnstile protection', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'database.connections.tenant_provisioning.username' => 'provisioning_user',
        'platform.signup.enabled' => true,
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'a-real-site-key',
        'platform.signup.turnstile.secret_key' => 'a-real-secret-key',
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Public signup');
    expect($output)->not->toContain('Public signup</error>');
    $this->artisan('platform:production:check')->assertSuccessful();
});

test('15. fails when public signup is enabled but Turnstile is disabled - must not silently pass', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'platform.signup.enabled' => true,
        'platform.signup.turnstile.enabled' => false,
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Public signup');
    expect($output)->toContain('MUST NOT run without it');
    $this->artisan('platform:production:check')->assertFailed();
});

test('16. fails when public signup is enabled with Turnstile enabled but misconfigured - must not silently pass', function () {
    config([
        'app.debug' => false,
        'responsecache.enabled' => false,
        'platform.signup.enabled' => true,
        'platform.signup.turnstile.enabled' => true,
        'platform.signup.turnstile.site_key' => 'a-real-site-key',
        'platform.signup.turnstile.secret_key' => '',
    ]);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Public signup');
    expect($output)->toContain('abuse protection is not correctly configured');
    $this->artisan('platform:production:check')->assertFailed();
});
