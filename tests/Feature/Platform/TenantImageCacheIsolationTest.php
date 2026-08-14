<?php

/**
 * TASK-ARCH-005 finalization - end-to-end verification of R24/R25
 * (RISK_REGISTER.md) against the ACTUAL, unmodified Webkul\ImageCache route
 * and controller (packages/Webkul/ImageCache/src/Http/Controllers/
 * ImageCacheController.php, route name "imagecache", registered by
 * Webkul\ImageCache\Providers\ImageCacheServiceProvider::bootImageCache()).
 * No mocking of ImageCache, no replacement route - every request below hits
 * the real `cache/{template}/{filename}` route Bagisto itself registers.
 *
 * R25 (route never initialized tenancy at all) is fixed by
 * Platform\Tenancy\Providers\TenancyServiceProvider::attachTenancyToImageCacheRoute()
 * - it finds the already-registered 'imagecache' named route, inside an
 * $this->app->booted(...) callback (guaranteed to run after EVERY service
 * provider, including Webkul\ImageCache's, has finished boot()), and calls
 * the route's own ->middleware() method to attach
 * Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains and
 * Stancl\Tenancy\Middleware\InitializeTenancyByDomain. Zero
 * packages/Webkul changes; zero global middleware change (the 'web' group
 * and every other route's middleware list are untouched) - only this one
 * named route is affected.
 *
 * A note on "cached resized output" (test matrix items 6-8 in the task):
 * Webkul\ImageCache\Http\Controllers\ImageCacheController never writes a
 * resized file to disk anywhere - getImage() reads the source, resizes
 * in-memory, and returns it with HTTP Cache-Control/ETag headers only (see
 * buildResponse()). There IS a separate Webkul\ImageCache\ImageCache class
 * that uses Laravel's Cache repository to persist encoded bytes keyed by a
 * checksum - but it is dead code, never instantiated anywhere in
 * packages/ (confirmed by a full-repo grep during this task). So "physical
 * cache file isolation" has no real artifact to test for the ACTUAL route -
 * fabricating one would violate "use the actual installed route, don't mock
 * ImageCache". What IS tested instead, honestly reflecting the real code:
 * that repeated requests never leak or go stale across tenants, and that a
 * tenant can never retrieve content that only exists for the other tenant.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

function ensureImageCacheTestFixtures(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenantA = Tenant::find('tenant-a');
    if (! $tenantA || $tenantA->status !== TenantStatus::Ready) {
        if (! $tenantA) {
            $tenantA = Tenant::create(['id' => 'tenant-a', 'status' => TenantStatus::Pending]);
            $tenantA->domains()->create(['domain' => 'tenant-a.localhost']);
        }
        $provisioner->provision($tenantA);
    }

    $tenantB = Tenant::find('tenant-b');
    if (! $tenantB || $tenantB->status !== TenantStatus::Ready) {
        if (! $tenantB) {
            $tenantB = Tenant::create(['id' => 'tenant-b', 'status' => TenantStatus::Pending]);
            $tenantB->domains()->create(['domain' => 'tenant-b.localhost']);
        }
        $provisioner->provision($tenantB);
    }

    return [$tenantA, $tenantB];
}

/**
 * Real, decodable PNG bytes (not placeholder text) - the actual
 * ImageCacheController pipeline calls image_manager()->read($path) and
 * ->encodeByMediaType(), which requires genuinely valid image data, not a
 * mocked file. Two different solid colors so served output is distinguishable
 * by content, not just by byte-length.
 */
function makeSolidPng(int $r, int $g, int $b): string
{
    $image = imagecreatetruecolor(20, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/**
 * The actual resize route re-encodes the source (Intervention Image), so
 * the response bytes are never byte-identical to the uploaded source PNG -
 * decode both and compare a pixel instead, which is what proves "this
 * response really was derived from THIS tenant's source", not just "the
 * response is non-empty".
 */
function topLeftPixel(string $imageBytes): array
{
    $image = imagecreatefromstring($imageBytes);
    $rgb = imagecolorat($image, 0, 0);
    $colors = imagecolorsforindex($image, $rgb);
    imagedestroy($image);

    return [$colors['red'], $colors['green'], $colors['blue']];
}

/**
 * No blanket afterEach() - same rationale as the sibling Platform test files
 * (see TenantStorageIsolationTest.php's beforeEach() docblock): tenant-a/
 * tenant-b are shared fixtures reused across the whole Platform suite.
 */
beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureImageCacheTestFixtures();
});

afterEach(function () {
    $this->tenantA->run(fn () => Storage::disk('public')->deleteDirectory('imgcache-test'));
    $this->tenantB->run(fn () => Storage::disk('public')->deleteDirectory('imgcache-test'));
    Storage::disk('public')->delete('imgcache-test/central-only.png');
    Storage::disk('public')->deleteDirectory('imgcache-test');
});

test('the RetargetImageCachePaths listener actually retargets imagecache.paths to each tenant\'s own storage_path() after tenancy initializes', function () {
    // This isolates the ONE thing Platform\Tenancy\Listeners\
    // RetargetImageCachePaths is actually responsible for, independent of
    // routing (see the tests below) - proof that the listener itself does
    // its job when tenancy initializes.
    $pathsA = $this->tenantA->run(fn () => config('imagecache.paths'));
    $pathsB = $this->tenantB->run(fn () => config('imagecache.paths'));

    $expectedA = $this->tenantA->run(fn () => [storage_path('app/public'), public_path('storage')]);
    $expectedB = $this->tenantB->run(fn () => [storage_path('app/public'), public_path('storage')]);

    expect($pathsA)->toBe($expectedA);
    expect($pathsB)->toBe($expectedB);

    // The two tenants' resolved paths are genuinely distinct directories,
    // not the same central path returned twice.
    expect($pathsA[0])->not->toBe($pathsB[0]);
    expect($pathsA[0])->toContain('tenant-a');
    expect($pathsB[0])->toContain('tenant-b');
});

test('a real HTTP request to the actual installed cache/{template}/{filename} route returns 200 and each tenant\'s resized output is genuinely derived from its own source image, even at the identical relative path', function () {
    $relativePath = 'imgcache-test/photo.png';

    // Deliberately the SAME relative path in both tenants - proves isolation
    // is structural (disk-root-based), not a coincidence of different paths.
    $this->tenantA->run(fn () => Storage::disk('public')->put($relativePath, makeSolidPng(255, 0, 0)));
    $this->tenantB->run(fn () => Storage::disk('public')->put($relativePath, makeSolidPng(0, 0, 255)));

    $responseA = $this->get('http://tenant-a.localhost/cache/small/'.$relativePath);
    $responseB = $this->get('http://tenant-b.localhost/cache/small/'.$relativePath);

    // 1 & 2: both real requests succeed.
    $responseA->assertOk();
    $responseB->assertOk();

    // 3 & 4: each tenant's output is genuinely derived from ITS OWN source
    // (red for A, blue for B), not the other tenant's, and not some
    // central/default fallback image.
    [$rA, $gA, $bA] = topLeftPixel($responseA->getContent());
    [$rB, $gB, $bB] = topLeftPixel($responseB->getContent());

    expect($rA)->toBeGreaterThan(200);
    expect($bA)->toBeLessThan(50);
    expect($bB)->toBeGreaterThan(200);
    expect($rB)->toBeLessThan(50);

    // 5: the identical relative path, requested through two different
    // tenant domains, produces two DIFFERENT response bodies - no collision.
    expect($responseA->getContent())->not->toBe($responseB->getContent());
});

test('repeated requests never go stale or leak across tenants, even though the real route persists no server-side resize cache file', function () {
    // See this file's top docblock: Webkul\ImageCache\ImageCache (the only
    // class with any file/Cache-backed persistence) is dead code, never
    // instantiated by the real route - ImageCacheController re-derives the
    // response from the live source file on every single request. This test
    // proves that property holds under tenancy: changing tenant A's source
    // and re-requesting immediately reflects the change (nothing stale or
    // cached-from-a-different-tenant is being served), which is the
    // meaningful, real-code-path version of "no cache collision".
    $relativePath = 'imgcache-test/repeat.png';

    $this->tenantA->run(fn () => Storage::disk('public')->put($relativePath, makeSolidPng(255, 0, 0)));
    $first = $this->get('http://tenant-a.localhost/cache/small/'.$relativePath);
    $first->assertOk();
    [$r1] = topLeftPixel($first->getContent());
    expect($r1)->toBeGreaterThan(200);

    $this->tenantA->run(fn () => Storage::disk('public')->put($relativePath, makeSolidPng(0, 255, 0)));
    $second = $this->get('http://tenant-a.localhost/cache/small/'.$relativePath);
    $second->assertOk();
    [, $g2] = topLeftPixel($second->getContent());
    expect($g2)->toBeGreaterThan(200);

    // 7 & 8: a tenant can never retrieve content that only ever existed for
    // the OTHER tenant - request a filename unique to tenant B from tenant
    // A's domain, and vice versa.
    $uniqueToB = 'imgcache-test/only-b-'.uniqid().'.png';
    $this->tenantB->run(fn () => Storage::disk('public')->put($uniqueToB, makeSolidPng(0, 0, 255)));

    $crossRequest = $this->get('http://tenant-a.localhost/cache/small/'.$uniqueToB);
    expect($crossRequest->status())->toBe(404);

    $legitRequest = $this->get('http://tenant-b.localhost/cache/small/'.$uniqueToB);
    $legitRequest->assertOk();

    $this->tenantB->run(fn () => Storage::disk('public')->delete($uniqueToB));
});

test('an unknown domain cannot reach the real ImageCache route', function () {
    Storage::disk('public')->put('imgcache-test/central-only.png', makeSolidPng(128, 128, 128));

    $response = $this->get('http://unknown-tenant.localhost/cache/small/imgcache-test/central-only.png');

    expect($response->status())->toBe(404);
});

test('a central/platform domain cannot reach the real ImageCache route (no legitimate central use case exists today)', function () {
    // Policy decision (see Platform\Tenancy\Providers\TenancyServiceProvider
    // ::attachTenancyToImageCacheRoute() docblock and docs/architecture/
    // storage.md): ImageCache serves tenant-owned media, no real central/
    // platform route consumes it today, so PreventAccessFromCentralDomains
    // is attached alongside InitializeTenancyByDomain - central domains get
    // a controlled 404, the same as an unresolved tenant, rather than ever
    // falling back to serving central storage content.
    Storage::disk('public')->put('imgcache-test/central-only.png', makeSolidPng(128, 128, 128));

    foreach (['http://localhost', 'http://127.0.0.1'] as $centralDomain) {
        $response = $this->get($centralDomain.'/cache/small/imgcache-test/central-only.png');

        expect($response->status())
            ->toBe(404, "Expected the central domain [{$centralDomain}] to be denied access to the tenant-owned ImageCache route.");
    }
});
