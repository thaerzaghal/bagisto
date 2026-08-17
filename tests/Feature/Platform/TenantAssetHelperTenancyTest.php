<?php

/**
 * TASK-MVP-004 - regression coverage for a real production defect found
 * during a real human browser test of the pilot tenant Admin/Storefront.
 *
 * Root cause: config/tenancy.php's `filesystem.asset_helper_tenancy` was left
 * at stancl/tenancy's own package default (`true`). That makes
 * Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper rebind Laravel's
 * global asset() URL root to stancl's `/tenancy/assets/{path}` route (backed
 * by Stancl\Tenancy\Controllers\TenantAssetsController, which only serves
 * files from that tenant's own storage_path('app/public/...')) for the
 * entire duration of any tenant-context request. Bagisto's own Blade views
 * (unmodified) reference compiled Vite build CSS/JS/images via the plain
 * asset() helper - under tenant context those calls resolved to a URL that
 * 404s (compiled build assets never live in per-tenant storage), so the
 * Admin's Vue app never mounted and Login/Reset Password became inert; the
 * same happened on the tenant storefront.
 *
 * Fix: `asset_helper_tenancy` is now `false`. Nothing in packages/Webkul or
 * packages/Platform calls `tenant_asset()`, so nothing relies on the feature
 * being on. Tenant-uploaded media (product images, avatars, theme uploads)
 * is untouched by this flag either way - it is served through a completely
 * separate, deliberately-registered route (routes/tenant.php's
 * `/storage/{path}` -> the same TenantAssetsController, TASK-ARCH-005/R16),
 * which this file also re-proves still works under the new config.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const ASSET_HELPER_TEST_TENANT_IDS = ['tenant-asset-a', 'tenant-asset-b'];

function ensureAssetHelperTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-asset-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-asset-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-asset-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-asset-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-asset-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-asset-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }

    return [$tenantA->fresh(), $tenantB->fresh()];
}

/**
 * Pulls every stylesheet/module-script URL an actual rendered page
 * references, exactly as a real browser would discover them.
 */
function extractBuildAssetUrls(string $html): array
{
    $urls = [];

    preg_match_all('/<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"/', $html, $cssMatches);
    preg_match_all('/<script[^>]+type="module"[^>]+src="([^"]+)"/', $html, $jsMatches);

    return array_merge($cssMatches[1] ?? [], $jsMatches[1] ?? []);
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureAssetHelperTestFixtures();
});

test('the config fix is actually applied', function () {
    expect(config('tenancy.filesystem.asset_helper_tenancy'))->toBeFalse();
});

test('a real tenant Admin login page generates build asset URLs without /tenancy/assets/', function () {
    $response = $this->get('http://tenant-asset-a.localhost/admin/login');
    $response->assertOk();

    $urls = extractBuildAssetUrls($response->getContent());

    expect($urls)->not->toBeEmpty('the real Admin login page must reference at least one compiled build asset');

    foreach ($urls as $url) {
        expect($url)->not->toContain('/tenancy/assets/', "Admin build asset URL still tenant-rewritten: {$url}");
    }
});

test('a real tenant Storefront homepage generates build asset URLs without /tenancy/assets/', function () {
    $response = $this->get('http://tenant-asset-a.localhost/');
    $response->assertOk();

    $urls = extractBuildAssetUrls($response->getContent());

    expect($urls)->not->toBeEmpty('the real Storefront homepage must reference at least one compiled build asset');

    foreach ($urls as $url) {
        expect($url)->not->toContain('/tenancy/assets/', "Storefront build asset URL still tenant-rewritten: {$url}");
    }
});

/**
 * Pest's $this->get() drives requests straight into the Laravel Kernel -
 * there is no webserver in front of it, so a real production nginx serving
 * public/themes/.../build/assets/*.css directly (never touching PHP) cannot
 * be reproduced in-process; requesting that path here would 404 through
 * Laravel's own router instead (a different, unrelated failure mode). The
 * physically-correct check achievable here is that the URL asset() actually
 * generated resolves to a real file under public_path() - i.e. the asset
 * pipeline and the generated URL genuinely agree. The corresponding real,
 * live HTTP 200 + correct Content-Type proof against the actual production
 * webserver is documented separately (see this task's own final report /
 * docs/implementation/email-delivery.md-adjacent production notes) - not
 * something Mail::fake()-style faking would prove, and not something Pest's
 * in-process client can prove either.
 */
test('a real known Admin build asset (CSS and JS) resolves to a real file on disk at the generated URL', function () {
    $page = $this->get('http://tenant-asset-a.localhost/admin/login');
    $page->assertOk();

    $urls = extractBuildAssetUrls($page->getContent());
    $cssUrl = collect($urls)->first(fn ($u) => str_ends_with(parse_url($u, PHP_URL_PATH), '.css'));
    $jsUrl = collect($urls)->first(fn ($u) => str_ends_with(parse_url($u, PHP_URL_PATH), '.js'));

    expect($cssUrl)->not->toBeNull();
    expect($jsUrl)->not->toBeNull();

    expect(file_exists(public_path(ltrim(parse_url($cssUrl, PHP_URL_PATH), '/'))))->toBeTrue("No real file on disk for {$cssUrl}");
    expect(file_exists(public_path(ltrim(parse_url($jsUrl, PHP_URL_PATH), '/'))))->toBeTrue("No real file on disk for {$jsUrl}");
});

test('a real known Storefront build asset (CSS and JS) resolves to a real file on disk at the generated URL', function () {
    $page = $this->get('http://tenant-asset-a.localhost/');
    $page->assertOk();

    $urls = extractBuildAssetUrls($page->getContent());
    $cssUrl = collect($urls)->first(fn ($u) => str_ends_with(parse_url($u, PHP_URL_PATH), '.css'));
    $jsUrl = collect($urls)->first(fn ($u) => str_ends_with(parse_url($u, PHP_URL_PATH), '.js'));

    expect($cssUrl)->not->toBeNull();
    expect($jsUrl)->not->toBeNull();

    expect(file_exists(public_path(ltrim(parse_url($cssUrl, PHP_URL_PATH), '/'))))->toBeTrue("No real file on disk for {$cssUrl}");
    expect(file_exists(public_path(ltrim(parse_url($jsUrl, PHP_URL_PATH), '/'))))->toBeTrue("No real file on disk for {$jsUrl}");
});

test('build asset URLs are identical across two different tenants (shared, non-tenant-specific static assets)', function () {
    $pageA = $this->get('http://tenant-asset-a.localhost/admin/login');
    $pageB = $this->get('http://tenant-asset-b.localhost/admin/login');
    $pageA->assertOk();
    $pageB->assertOk();

    $urlsA = extractBuildAssetUrls($pageA->getContent());
    $urlsB = extractBuildAssetUrls($pageB->getContent());

    // Same build, same shared static assets - only the host differs.
    $pathsA = array_map(fn ($u) => parse_url($u, PHP_URL_PATH), $urlsA);
    $pathsB = array_map(fn ($u) => parse_url($u, PHP_URL_PATH), $urlsB);

    expect($pathsA)->toBe($pathsB);
});

test('tenant-uploaded media still serves correctly via the separate, untouched /storage/{path} route', function () {
    // This is the mechanism TASK-ARCH-005/R16 built and TenantStorageIsolationTest
    // already covers in depth - re-proven narrowly here to show this fix did not
    // regress it, and that it is genuinely independent of asset_helper_tenancy.
    $path = 'asset-helper-regression/media.txt';
    $this->tenantA->run(fn () => Storage::disk('public')->put($path, 'TENANT_A_MEDIA'));

    $response = $this->get('http://tenant-asset-a.localhost/storage/'.$path);
    $response->assertOk();
    expect(file_get_contents($response->getFile()->getPathname()))->toBe('TENANT_A_MEDIA');

    // Tenant B cannot see tenant A's file via its own domain. Matches the
    // established convention in TenantStorageIsolationTest's own path-traversal
    // test: TenantAssetsController::abortIf() deliberately throws a raw
    // Exception (not a clean 404) when app()->runningUnitTests() is true
    // ("makes testing the cause of the failure easier", per the vendor
    // class's own comment) - so this only asserts "not a successful 200",
    // not a specific status code.
    $crossTenant = $this->get('http://tenant-asset-b.localhost/storage/'.$path);
    expect($crossTenant->status())->not->toBe(200);
});
