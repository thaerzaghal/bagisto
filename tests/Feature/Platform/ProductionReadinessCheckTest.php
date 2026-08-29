<?php

/**
 * TASK-MVP-004A (task section 6) - `php artisan platform:production:check`.
 *
 * Read-only - proves the command mutates nothing (no config/env/DB writes -
 * every check is a plain config() read or, for Redis, a real, harmless
 * PING) and never leaks a secret VALUE into its own output, while still
 * genuinely reporting misconfiguration (APP_DEBUG=true, non-redis
 * CACHE_STORE, RESPONSE_CACHE_ENABLED=true, DB_PROVISION_USERNAME=root).
 *
 * TASK-MVP-017 (RISK_REGISTER.md R76): also covers the new Admin/Shop
 * static-asset consistency checks. `Http::fake()` is used for their one
 * genuinely external-to-this-process boundary (a real, live HTTP request
 * to a second Docker container, `web`) - the same established pattern
 * already used for Cloudflare Turnstile (`SignupTurnstileTest.php`), never
 * a mock of this project's own database/filesystem/cache/queue. A global
 * `beforeEach()` fake (PASS-shaped, for `http://web/*`) keeps every
 * EXISTING test in this file fast/deterministic despite this local
 * environment genuinely having real Admin/Shop `manifest.json` files on
 * disk (confirmed directly) - without it, every test running the full
 * command would otherwise attempt a real, doomed-to-fail connection to a
 * host that does not exist in this local topology.
 *
 * The `beforeEach()` fake is registered as a SINGLE closure that reads
 * `$this->webFakeResponder` on every call, rather than re-calling
 * `Http::fake([...])` per test to override it. This is deliberate: Laravel's
 * HTTP client resolves a faked response via `stubCallbacks->map(...)->
 * filter()->first()` (`PendingRequest::buildStubHandler()`) - i.e. the
 * FIRST-registered matching stub wins, not the most recently registered
 * one. A per-test `Http::fake(['http://web/*' => ...])` call therefore
 * would NOT override this file's own `beforeEach()` stub (confirmed
 * directly: an earlier draft of test 18 silently kept getting the
 * `beforeEach()`'s 200 response instead of its own intended 404).
 * Mutating `$this->webFakeResponder` instead sidesteps that ordering
 * entirely, since only one stub is ever registered for the lifetime of a
 * given test.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Platform\Tenancy\Console\Commands\ProductionReadinessCheck;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Content-Type must be set explicitly here: Http::response()'s own
    // default (Illuminate\Http\Client\Factory::psr7Response()) only ever
    // sets Content-Type for an array body (as 'application/json') - a
    // plain string body like this one gets NO Content-Type header at all,
    // which would otherwise false-fail the new Content-Type check below
    // (added per the R76 correction) for every pre-existing test in this
    // file that doesn't override $this->webFakeResponder itself.
    $this->webFakeResponder = fn ($request) => Http::response(
        '/* fake asset content */',
        200,
        ['Content-Type' => Str::contains($request->url(), '.css') ? 'text/css' : 'application/javascript']
    );

    Http::fake(function ($request) {
        return Str::is('http://web/*', $request->url())
            ? ($this->webFakeResponder)($request)
            : null;
    });
});

function productionShapedConfigForAssetChecks(): void
{
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
}

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

test('17. passes Admin/Shop static asset checks when web genuinely serves the current manifest asset', function () {
    productionShapedConfigForAssetChecks();

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Admin static assets');
    expect($output)->toContain('Shop static assets');
    expect($output)->toContain('verified being served by web');
    $this->artisan('platform:production:check')->assertSuccessful();
});

test('18. fails (and fails the command exit code) when web returns a non-200 for the current manifest asset - the exact R76 incident condition', function () {
    productionShapedConfigForAssetChecks();
    $this->webFakeResponder = fn () => Http::response('Not Found', 404);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('HTTP 404');
    expect($output)->toContain('R76');
    $this->artisan('platform:production:check')->assertFailed();
});

test('19. never hardcodes a generated asset filename - the exact URL requested matches whatever the real manifest currently contains', function () {
    productionShapedConfigForAssetChecks();

    $this->artisan('platform:production:check')->assertSuccessful();

    // Http::recorded() lists every request that actually passed through the
    // fake, regardless of which registered stub's response ended up being
    // used - more robust here than a custom tracking closure, and avoids
    // the stub-ordering gotcha documented above the beforeEach() block.
    $requestedUrls = collect(Http::recorded())
        ->map(fn ($pair) => (string) $pair[0]->url())
        ->all();

    $manifest = json_decode((string) file_get_contents(public_path('themes/admin/default/build/manifest.json')), true);
    $expectedJsFile = $manifest['src/Resources/assets/js/app.js']['file'];
    $expectedCssFile = $manifest['src/Resources/assets/css/app.css']['file'];

    expect($requestedUrls)->toContain('http://web/themes/admin/default/build/'.$expectedJsFile);
    expect($requestedUrls)->toContain('http://web/themes/admin/default/build/'.$expectedCssFile);
});

test('20. fails (and fails the command exit code) when web is unreachable via connection refused - a manifest already proves this is a real production-shaped build', function () {
    productionShapedConfigForAssetChecks();
    $this->webFakeResponder = function () {
        throw new ConnectionException('Connection refused');
    };

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Admin static assets');
    expect($output)->toContain('could not reach the internal web service');
    expect($output)->toContain('R76');
    // A real FAIL - the command must exit non-zero. Revised per explicit
    // product-owner correction after the initial implementation: a
    // genuinely unreachable `web`, once a real manifest already proves
    // this environment has a real production-shaped build, is exactly
    // the false-green state this check exists to eliminate - it must
    // never be treated as merely "no app/web split here."
    $this->artisan('platform:production:check')->assertFailed();
});

test('20b. fails (and fails the command exit code) when the internal request times out - any transport-level exception, not just connection-refused', function () {
    productionShapedConfigForAssetChecks();
    $this->webFakeResponder = function () {
        throw new ConnectionException('Connection timed out after 3 seconds');
    };

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Admin static assets');
    expect($output)->toContain('could not reach the internal web service');
    expect($output)->toContain('R76');
    $this->artisan('platform:production:check')->assertFailed();
});

test('20c. fails when web returns HTTP 200 but with a Content-Type that does not look like the expected asset type - an error/fallback page masquerading as success', function () {
    productionShapedConfigForAssetChecks();
    $this->webFakeResponder = fn () => Http::response('<html>not the real asset</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('Admin static assets');
    expect($output)->toContain('does not look like');
    expect($output)->toContain('R76');
    $this->artisan('platform:production:check')->assertFailed();
});

test('20d. still passes with a Content-Type that legitimately includes a charset/synonym suffix, not just an exact bare mime type', function () {
    productionShapedConfigForAssetChecks();
    $this->webFakeResponder = fn ($request) => Str::contains($request->url(), '.css')
        ? Http::response('/* css */', 200, ['Content-Type' => 'text/css; charset=UTF-8'])
        : Http::response('// js', 200, ['Content-Type' => 'text/javascript; charset=UTF-8']);

    Artisan::call('platform:production:check');
    $output = Artisan::output();

    expect($output)->toContain('verified being served by web');
    $this->artisan('platform:production:check')->assertSuccessful();
});

test('21. reports INFO, not WARN/FAIL, when no Vite manifest exists at all for a theme', function () {
    $command = app(ProductionReadinessCheck::class);
    $method = new ReflectionMethod($command, 'checkThemeStaticAssets');
    $method->setAccessible(true);

    $result = $method->invoke($command, 'Test theme', 'themes/tor-r76-nonexistent-fixture/build');

    expect($result[0])->toBe('Test theme');
    expect($result[1])->toBe('INFO');
    expect($result[2])->toContain('not applicable in this environment');
});

test('22. reports a WARN when a manifest exists but is missing an expected entry - a build-config concern, not asset drift', function () {
    $fixtureDir = public_path('themes/tor-r76-incomplete-fixture/build');
    mkdir($fixtureDir, 0755, true);
    file_put_contents($fixtureDir.'/manifest.json', json_encode([
        'src/Resources/assets/css/app.css' => ['file' => 'assets/app-fake.css'],
        // Deliberately missing the js entry.
    ]));

    try {
        $command = app(ProductionReadinessCheck::class);
        $method = new ReflectionMethod($command, 'checkThemeStaticAssets');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'Test theme', 'themes/tor-r76-incomplete-fixture/build');

        // resultWarn() wraps the status in a console formatting tag (same
        // convention as resultPass()'s '<info>PASS</info>' and
        // resultFail()'s '<error>FAIL</error>') - only resultInfo() returns
        // a bare string. Assert via toContain() rather than a brittle
        // hardcoded tag string, matching this test's own "structural check,
        // not hardcoded output" spirit.
        expect($result[1])->toContain('WARN');
        expect($result[2])->toContain('unexpected build structure');
    } finally {
        unlink($fixtureDir.'/manifest.json');
        rmdir($fixtureDir);
        rmdir(public_path('themes/tor-r76-incomplete-fixture'));
    }
});
