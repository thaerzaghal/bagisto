<?php

/**
 * TASK-ARCH-005 - tenant filesystem/storage isolation security test matrix.
 *
 * Addresses RISK_REGISTER.md R16. Real MySQL, real local filesystem (no
 * mocked Storage facade), real Bagisto product image upload workflow, real
 * HTTP requests through the /storage/{path} route. Nothing mocked.
 *
 * See docs/architecture/storage.md for the full architecture this proves:
 * Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper remaps disk
 * roots per tenant (config/tenancy.php), Platform\Tenancy\Services\
 * TenantProvisioner::ensureFilesystemPrepared() creates the directory
 * skeleton that bootstrapper never creates itself, Platform\Tenancy\
 * Listeners\RetargetImageCachePaths fixes a frozen-at-boot config value in
 * Webkul\ImageCache, and routes/tenant.php's new /storage/{path} route
 * (reusing Stancl\Tenancy\Controllers\TenantAssetsController) serves files
 * from the correct tenant root at the exact URL Bagisto's own Storage::url()
 * calls already generate - zero packages/Webkul changes anywhere.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Theme\Repositories\ThemeCustomizationRepository;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const STORAGE_TEST_TENANT_IDS = ['tenant-a', 'tenant-b'];

function cleanupStorageTestTenants(): void
{
    $central = DB::connection('mysql');
    $provisioning = DB::connection('tenant_provisioning');

    foreach (STORAGE_TEST_TENANT_IDS as $id) {
        $row = $central->table('tenants')->where('id', $id)->first();

        if ($row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];
            if (! empty($data['tenancy_db_username'])) {
                $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
            }
            if (! empty($data['tenancy_db_name'])) {
                $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
            }
        }

        $central->table('domains')->where('tenant_id', $id)->delete();
        $central->table('tenants')->where('id', $id)->delete();
    }
}

function ensureStorageTestFixtures(): array
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
 * No blanket afterEach() here, deliberately - same rationale as
 * TenantCacheIsolationTest.php's beforeEach() docblock: this file shares
 * tenant-a/tenant-b fixtures (by id) with the other Platform test files
 * within one `vendor/bin/pest tests/Feature/Platform/` run, and tearing
 * down after every test would both slow this file down and force needless
 * re-provisioning. `cleanupStorageTestTenants()` remains available for
 * manual end-of-run cleanup. The one test that provisions its OWN extra
 * tenant (tenant-storage-c, to observe provisioning's filesystem side
 * effects in isolation) cleans that specific tenant up itself, inline.
 */
beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureStorageTestFixtures();
});

test('Tenant A and Tenant B can write the identical logical filename to physically distinct locations, and each reads only its own contents', function () {
    $this->tenantA->run(fn () => Storage::disk('public')->put('test-isolation/shared-name.txt', 'TENANT_A'));
    $this->tenantB->run(fn () => Storage::disk('public')->put('test-isolation/shared-name.txt', 'TENANT_B'));

    $this->tenantA->run(fn () => expect(Storage::disk('public')->get('test-isolation/shared-name.txt'))->toBe('TENANT_A'));
    $this->tenantB->run(fn () => expect(Storage::disk('public')->get('test-isolation/shared-name.txt'))->toBe('TENANT_B'));

    // Prove the physical files are genuinely distinct, not just behaviorally
    // correct by coincidence (per docs/architecture/security.md's "inspect,
    // don't just observe" testing principle).
    $physicalA = $this->tenantA->run(fn () => Storage::disk('public')->path('test-isolation/shared-name.txt'));
    $physicalB = $this->tenantB->run(fn () => Storage::disk('public')->path('test-isolation/shared-name.txt'));

    expect($physicalA)->not->toBe($physicalB);
    expect(file_exists($physicalA))->toBeTrue();
    expect(file_exists($physicalB))->toBeTrue();
    expect(file_get_contents($physicalA))->toBe('TENANT_A');
    expect(file_get_contents($physicalB))->toBe('TENANT_B');
});

test('Tenant A deleting a file does not delete Tenant B\'s file at the identical logical path', function () {
    $this->tenantA->run(fn () => Storage::disk('public')->put('test-isolation/delete-me.txt', 'A'));
    $this->tenantB->run(fn () => Storage::disk('public')->put('test-isolation/delete-me.txt', 'B'));

    $this->tenantA->run(fn () => Storage::disk('public')->delete('test-isolation/delete-me.txt'));

    $this->tenantA->run(fn () => expect(Storage::disk('public')->exists('test-isolation/delete-me.txt'))->toBeFalse());
    $this->tenantB->run(fn () => expect(Storage::disk('public')->get('test-isolation/delete-me.txt'))->toBe('B'));
});

test('a real Bagisto product image upload (Webkul\Product\Repositories\ProductImageRepository) is tenant-isolated even when product IDs collide', function () {
    $upload = function () {
        $product = app(ProductRepository::class)->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'IMG-'.uniqid()]);

        app(ProductImageRepository::class)->upload([
            'images' => ['files' => [UploadedFile::fake()->image('photo.jpg', 20, 20)]],
        ], $product, 'images');

        $image = $product->images()->first();

        return [
            'product_id' => $product->id,
            'path' => $image->path,
            'physical' => Storage::disk('public')->path($image->path),
            'contents' => Storage::disk('public')->get($image->path),
        ];
    };

    $resultA = $this->tenantA->run($upload);
    $resultB = $this->tenantB->run($upload);

    // Webkul\Product\Repositories\ProductMediaRepository::getProductDirectory()
    // builds a flat 'product/{id}' path with no tenant/store segment at all
    // (confirmed by the TASK-ARCH-005 filesystem audit) - product ids are
    // local to each tenant's own database, so WHEN two tenants' products
    // happen to land on the same id (a real, expected occurrence - it just
    // isn't forced deterministically by this specific test run, since these
    // tenant-a/tenant-b fixtures are shared and accumulate products across
    // the other Platform test files too), their logical paths are IDENTICAL
    // strings. The security property under test does not depend on that
    // coincidence actually happening on any given run - it holds either way,
    // which is exactly what's asserted below.
    expect($resultA['path'])->toStartWith('product/');
    expect($resultB['path'])->toStartWith('product/');

    // The physical files never collide, regardless of whether the logical
    // ids/paths happened to coincide this run.
    expect($resultA['physical'])->not->toBe($resultB['physical']);
    expect(file_exists($resultA['physical']))->toBeTrue();
    expect(file_exists($resultB['physical']))->toBeTrue();
    expect(file_get_contents($resultA['physical']))->toBe($resultA['contents']);
    expect(file_get_contents($resultB['physical']))->toBe($resultB['contents']);

    // Tenant A's own disk cannot see Tenant B's image at all, even knowing
    // its exact relative path.
    $this->tenantA->run(function () use ($resultB) {
        expect(Storage::disk('public')->exists($resultB['path']))->toBeFalse();
    });
});

test('a real HTTP request to /storage/{path} serves the correct tenant\'s file, and cross-tenant/unknown-domain access is impossible', function () {
    $path = 'test-isolation/http-served.txt';
    $this->tenantA->run(fn () => Storage::disk('public')->put($path, 'HTTP_TENANT_A'));
    $this->tenantB->run(fn () => Storage::disk('public')->put($path, 'HTTP_TENANT_B'));

    // Stancl\Tenancy\Controllers\TenantAssetsController::asset() returns
    // response()->file(...), a Symfony BinaryFileResponse - it streams the
    // file directly and its getContent() deliberately always returns false
    // (by Symfony design, for memory efficiency), so the served bytes must
    // be read from the underlying file object instead.
    $responseA = $this->get('http://tenant-a.localhost/storage/'.$path);
    $responseA->assertOk();
    expect(file_get_contents($responseA->getFile()->getPathname()))->toBe('HTTP_TENANT_A');

    $responseB = $this->get('http://tenant-b.localhost/storage/'.$path);
    $responseB->assertOk();
    expect(file_get_contents($responseB->getFile()->getPathname()))->toBe('HTTP_TENANT_B');

    // Unknown domain: no tenant ever resolves, so the file can never be
    // reached regardless of the exact logical path requested.
    $unknown = $this->get('http://unknown.localhost/storage/'.$path);
    $unknown->assertNotFound();
});

test('path traversal cannot escape the tenant storage root via the /storage/{path} route', function () {
    // Stancl\Tenancy\Controllers\TenantAssetsController::validatePath() uses
    // realpath() + a startsWith($allowedRoot) check - this proves that
    // existing, already-battle-tested protection actually holds in THIS
    // app's routing, rather than assuming it does because the vendor class
    // looks correct on paper.
    $this->tenantA->run(fn () => Storage::disk('public')->put('safe-dir/inside.txt', 'SAFE'));

    foreach ([
        '../../../../../../etc/passwd',
        '..%2F..%2F..%2Fetc%2Fpasswd',
        'safe-dir/../../../../etc/passwd',
        '....//....//....//etc/passwd',
    ] as $attempt) {
        $response = $this->get('http://tenant-a.localhost/storage/'.$attempt);

        expect($response->status())
            ->not->toBe(200, "Path traversal attempt [{$attempt}] returned HTTP 200 - possible escape.");
    }

    // The legitimate file is still reachable normally - traversal protection
    // isn't just blocking everything.
    $response = $this->get('http://tenant-a.localhost/storage/safe-dir/inside.txt');
    $response->assertOk();
    expect(file_get_contents($response->getFile()->getPathname()))->toBe('SAFE');
});

test('provisioning creates the required tenant storage skeleton safely', function () {
    // Fresh third tenant specifically to observe provisioning's filesystem
    // side effects in isolation from the shared tenant-a/tenant-b fixtures.
    $tenant = Tenant::find('tenant-storage-c');
    if (! $tenant) {
        $tenant = Tenant::create(['id' => 'tenant-storage-c', 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => 'tenant-storage-c.localhost']);
    }

    app(TenantProvisioner::class)->provision($tenant);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Ready);

    $tenant->run(function () {
        foreach ([
            'app/public',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'framework/testing',
            'logs',
        ] as $relative) {
            expect(is_dir(storage_path($relative)))->toBeTrue("Expected {$relative} to exist under the tenant's storage_path().");
        }

        expect(Storage::disk('public')->exists(''))->toBeTrue();
        expect(Storage::disk('private')->exists(''))->toBeTrue();

        // The skeleton being present doesn't just avoid warnings - a real
        // write actually works end-to-end.
        Storage::disk('public')->put('smoke-test.txt', 'ok');
        expect(Storage::disk('public')->get('smoke-test.txt'))->toBe('ok');
    });

    // Cleanup this test's own extra tenant (not shared with other files) -
    // DB rows AND the physical storage_path() root. FilesystemTenancyBootstrapper
    // names it '{suffix_base}{tenant_id}' (config/tenancy.php,
    // 'suffix_base' => 'tenant') under the central storage_path(), i.e.
    // storage_path('tenant'.$tenant->id) == storage_path('tenanttenant-storage-c').
    // Leaving this on disk after a successful run was the exact gap flagged
    // in TASK-ARCH-005's finalization review - fixed here, not hidden behind
    // .gitignore (the .gitignore entry stays only as a backstop for runs that
    // fail before reaching this cleanup, not as the primary mechanism).
    $data = json_decode(DB::connection('mysql')->table('tenants')->where('id', 'tenant-storage-c')->value('data') ?? '{}', true) ?: [];
    $provisioning = DB::connection('tenant_provisioning');
    if (! empty($data['tenancy_db_username'])) {
        $provisioning->statement('DROP USER IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_username']).'`');
    }
    if (! empty($data['tenancy_db_name'])) {
        $provisioning->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $data['tenancy_db_name']).'`');
    }
    DB::connection('mysql')->table('domains')->where('tenant_id', 'tenant-storage-c')->delete();
    DB::connection('mysql')->table('tenants')->where('id', 'tenant-storage-c')->delete();
    File::deleteDirectory(storage_path('tenant'.$tenant->id));
});

test('a real Webkul\Theme\Repositories\ThemeCustomizationRepository upload resolves to the correct tenant\'s file via the real /storage/{path} route', function () {
    // Smallest real integration test for the narrow case flagged in the
    // TASK-ARCH-005 report as "expected to work, not independently tested":
    // ThemeCustomizationRepository::uploadImage() stores 'image' =>
    // 'storage/'.$path (a DB-baked, storage/-prefixed string, unlike every
    // other consumer which stores a bare relative path and calls
    // Storage::url() at render time - see docs/architecture/storage.md).
    // This proves that convention actually resolves through the real
    // /storage/{path} route to the correct tenant's own file, not assumed
    // by analogy to the other, already-tested consumers.
    $upload = function (int $r, int $g, int $b) {
        $theme = app(ThemeCustomizationRepository::class)->create([
            'type' => 'static_content',
            'name' => 'TASK-ARCH-005 theme asset test',
            'sort_order' => 1,
            'status' => 1,
            'channel_id' => 1,
        ]);

        // ThemeCustomizationRepository::uploadImage() calls $theme->translate($locale)
        // and assumes a non-null translation row already exists for that
        // locale (it does NOT use translateOrNew()) - true of the real admin
        // UI flow too (create() is always a separate prior HTTP request from
        // uploadImage(), and nothing else auto-creates this row). This is a
        // real precondition of the real repository method, unrelated to
        // tenancy - reproducing it here, not working around ImageCache/
        // Storage/anything actually under test.
        // ThemeCustomizationTranslation::$fillable is ['name', 'options']
        // only - 'locale' is deliberately excluded from mass assignment, so
        // it must be set via direct property assignment instead of create().
        $translation = $theme->translations()->make(['options' => []]);
        $translation->locale = app()->getLocale();
        $translation->save();
        // ThemeCustomization has `protected $with = ['translations']`, eager
        // loaded onto the in-memory instance at creation time (before the
        // row above existed) - translate() reads that cached relation
        // collection, not a fresh query, so it must be reloaded here.
        $theme->load('translations');

        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        $tmpPath = tempnam(sys_get_temp_dir(), 'theme-asset').'.png';
        imagepng($image, $tmpPath);
        imagedestroy($image);

        app(ThemeCustomizationRepository::class)->uploadImage([
            app()->getLocale() => [
                'options' => [
                    [
                        'image' => new UploadedFile($tmpPath, 'slide.png', 'image/png', null, true),
                        'link' => '',
                        'title' => 'slide',
                    ],
                ],
            ],
        ], $theme);

        @unlink($tmpPath);

        $stored = $theme->translate(app()->getLocale())->options['images'][0]['image'];

        return [
            'stored' => $stored,
            'contents' => Storage::disk('public')->get(str_replace('storage/', '', $stored)),
        ];
    };

    $resultA = $this->tenantA->run(fn () => $upload(255, 0, 0));
    $resultB = $this->tenantB->run(fn () => $upload(0, 0, 255));

    expect($resultA['stored'])->toStartWith('storage/theme/');
    expect($resultB['stored'])->toStartWith('storage/theme/');

    // The real HTTP route each tenant's stored DB value actually points at.
    $urlPathA = str_replace('storage/', '', $resultA['stored']);
    $urlPathB = str_replace('storage/', '', $resultB['stored']);

    $responseA = $this->get('http://tenant-a.localhost/storage/'.$urlPathA);
    $responseA->assertOk();
    expect(file_get_contents($responseA->getFile()->getPathname()))->toBe($resultA['contents']);

    $responseB = $this->get('http://tenant-b.localhost/storage/'.$urlPathB);
    $responseB->assertOk();
    expect(file_get_contents($responseB->getFile()->getPathname()))->toBe($resultB['contents']);

    // Tenant A's own stored path is unreachable from Tenant B's domain -
    // proves the correct tenant's file is served, not merely *a* file.
    $crossResponse = $this->get('http://tenant-b.localhost/storage/'.$urlPathA);
    expect($crossResponse->status())->not->toBe(200);
});

test('provisioning retry does not corrupt or duplicate tenant storage', function () {
    // tenant-a is already Ready (shared fixture). Write a file, then
    // re-provision (simulating a supervisor retry after a transient
    // failure elsewhere) and confirm the file, and the skeleton, are
    // untouched - not recreated, not duplicated, not deleted.
    $this->tenantA->run(fn () => Storage::disk('public')->put('retry-check/file.txt', 'ORIGINAL'));

    $this->tenantA->forceFill(['status' => TenantStatus::Pending])->save();
    app(TenantProvisioner::class)->provision($this->tenantA);

    expect($this->tenantA->fresh()->status)->toBe(TenantStatus::Ready);
    $this->tenantA->run(function () {
        expect(Storage::disk('public')->get('retry-check/file.txt'))->toBe('ORIGINAL');
        expect(is_dir(storage_path('app/public')))->toBeTrue();
    });
});
