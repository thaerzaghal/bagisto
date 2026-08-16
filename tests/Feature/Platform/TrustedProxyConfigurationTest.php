<?php

/**
 * TASK-MVP-004A (task section 1) - TRUSTED_PROXIES / R20.
 *
 * `Platform\Tenancy\Support\EnvList::parse()` is pure and unit-testable
 * directly (requirements A/B below). Requirements C/D need a REAL request
 * to actually exercise `Illuminate\Http\Middleware\TrustProxies` (global
 * middleware - bootstrap/app.php - runs before routing, so it applies to
 * any route, including the ad-hoc probe route this file registers under
 * the 'platform' middleware group to avoid tenant-domain resolution
 * entirely). `TrustProxies::at()`/`flushState()` are Laravel's OWN
 * supported static configuration points (see vendor source) - not a hack -
 * this is the same mechanism `bootstrap/app.php` itself calls at boot.
 */

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Platform\Tenancy\Support\EnvList;

uses(Tests\TestCase::class);

beforeEach(function () {
    Route::middleware(['platform'])->get('/__test/trusted-proxy-probe', function (Request $request) {
        return response()->json([
            'host' => $request->getHost(),
            'secure' => $request->isSecure(),
        ]);
    });
});

afterEach(fn () => TrustProxies::flushState());

// A: EnvList::parse() on empty/unset input - the "local/default behavior
// remains functional" case, since bootstrap/app.php falls back to '*'
// exactly when this returns [].
test('A. EnvList::parse returns an empty list for null/blank input (falls back to trust-all locally)', function () {
    expect(EnvList::parse(null))->toBe([]);
    expect(EnvList::parse(''))->toBe([]);
    expect(EnvList::parse('   '))->toBe([]);
});

// B: explicit proxy configuration is parsed correctly.
test('B. EnvList::parse splits, trims, and drops empty entries from a comma-separated list', function () {
    expect(EnvList::parse('203.0.113.5'))->toBe(['203.0.113.5']);
    expect(EnvList::parse('203.0.113.5,198.51.100.9'))->toBe(['203.0.113.5', '198.51.100.9']);
    expect(EnvList::parse(' 203.0.113.5 , 198.51.100.9 '))->toBe(['203.0.113.5', '198.51.100.9']);
    expect(EnvList::parse('203.0.113.5,,198.51.100.9,'))->toBe(['203.0.113.5', '198.51.100.9']);
    // CIDR ranges pass through untouched - Symfony's trusted-proxy IP
    // matching (Request::setTrustedProxies(), which TrustProxies delegates
    // to) natively understands CIDR notation, so no extra parsing is
    // needed for this shape.
    expect(EnvList::parse('10.0.0.0/8, 172.16.0.0/12'))->toBe(['10.0.0.0/8', '172.16.0.0/12']);
});

// C: forwarded HTTPS/host behavior works through a trusted proxy.
test('C. forwarded proto/host from a TRUSTED proxy IP is honored', function () {
    TrustProxies::at(['203.0.113.5']);

    $response = $this->call('GET', 'http://localhost/__test/trusted-proxy-probe', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.5',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'app.example.test',
    ]);

    $response->assertOk();
    expect($response->json('secure'))->toBeTrue();
    expect($response->json('host'))->toBe('app.example.test');
});

// D: spoofed forwarded headers from an untrusted source are not honored.
test('D. forwarded proto/host from an UNTRUSTED IP are ignored - real connection values are used instead', function () {
    TrustProxies::at(['203.0.113.5']);

    $response = $this->call('GET', 'http://localhost/__test/trusted-proxy-probe', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'evil.example.test',
    ]);

    $response->assertOk();
    expect($response->json('secure'))->toBeFalse();
    expect($response->json('host'))->toBe('localhost');
});

test('A(behavioral). with the app booted under its actual (unset TRUSTED_PROXIES) local test config, forwarded headers are honored from any IP - matching today\'s "*" behavior', function () {
    // Deliberately does NOT call TrustProxies::at() - exercises whatever
    // bootstrap/app.php itself configured at real application boot from
    // the actual (TRUSTED_PROXIES-unset) test environment, proving local
    // dev needs zero additional setup.
    $response = $this->call('GET', 'http://localhost/__test/trusted-proxy-probe', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'anything.example.test',
    ]);

    $response->assertOk();
    expect($response->json('secure'))->toBeTrue();
    expect($response->json('host'))->toBe('anything.example.test');
});
