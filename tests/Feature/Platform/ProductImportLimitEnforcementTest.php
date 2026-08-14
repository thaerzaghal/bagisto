<?php

/**
 * TASK-ARCH-012A - closes RISK_REGISTER.md R39 (the one product-creation
 * path TASK-ARCH-012 found unenforced: Webkul\DataTransfer's bulk
 * product import, which bypasses Eloquent's `creating` event via a raw
 * `insert()`).
 *
 * Real MySQL, real CSV files written to the tenant's own private disk,
 * real Webkul\DataTransfer\Helpers\Import/Importer classes (via
 * Platform\Enforcement\Importers\EnforcingProductImporter, swapped in by
 * config exactly as production does - no test-only shortcut), real
 * TenantLimits/TenantEntitlements/Plan resolution. Nothing mocked.
 *
 * `session.driver` is NOT forced here (unlike the HTTP-heavy test files)
 * because most of these tests call the Import/Importer SERVICE layer
 * directly, not through a browser session - test 11 (the one real HTTP
 * round-trip) reuses the already-authenticated-admin pattern established
 * elsewhere in this suite, which does not depend on the session driver
 * either way for what it asserts.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Enums\FeatureType;
use Platform\Plans\Models\Plan;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Webkul\DataTransfer\Helpers\Import as ImportHelper;
use Webkul\DataTransfer\Models\Import;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const IMPORT_TEST_TENANT_IDS = ['tenant-import-a', 'tenant-import-b'];

function ensureImportTestTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (IMPORT_TEST_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

function ensureImportLimitPlan(int $limit): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'import-limit-test-'.$limit],
        ['name' => "Import Limit Test ({$limit})", 'is_active' => true, 'sort_order' => 95]
    );

    $plan->features()->updateOrCreate(
        ['feature_code' => FeatureCode::ProductsLimit->value],
        ['type' => FeatureType::Numeric, 'value' => $limit]
    );

    return $plan;
}

function ensureImportUnlimitedPlan(): Plan
{
    $plan = Plan::updateOrCreate(
        ['code' => 'import-limit-test-unlimited'],
        ['name' => 'Import Limit Test (unlimited)', 'is_active' => true, 'sort_order' => 96]
    );

    $plan->features()->updateOrCreate(
        ['feature_code' => FeatureCode::ProductsLimit->value],
        ['type' => FeatureType::Unlimited, 'value' => null]
    );

    return $plan;
}

/**
 * The real header Bagisto's own sample products.csv uses
 * (storage/app/public/data-transfer/samples/csv/products.csv) - every
 * column the Product importer's validation rules can require.
 */
function csvHeader(): array
{
    return ['sku', 'parent_sku', 'locale', 'attribute_family_code', 'type', 'categories', 'images', 'name', 'description', 'short_description', 'status', 'visible_individually', 'new', 'featured', 'guest_checkout', 'length', 'width', 'height', 'weight', 'tax_category_name', 'price', 'cost', 'special_price', 'special_price_from', 'special_price_to', 'customer_group_prices', 'url_key', 'meta_title', 'meta_keywords', 'meta_description', 'manage_stock', 'inventories', 'related_skus', 'cross_sell_skus', 'up_sell_skus', 'configurable_variants', 'bundle_options', 'associated_skus', 'booking_options'];
}

/**
 * A minimal, real, fully-valid 'simple' product row - every field
 * Bagisto's own validation rules for the default attribute family
 * actually require populated (confirmed live while writing these tests:
 * an earlier draft omitting description/short_description/weight failed
 * Bagisto's own validation before this task's own check ever ran).
 */
function csvRow(string $sku, string $type = 'simple', array $overrides = []): array
{
    $row = [
        'sku' => $sku,
        'parent_sku' => '',
        'locale' => 'en',
        'attribute_family_code' => 'default',
        'type' => $type,
        'categories' => '',
        'images' => '',
        'name' => 'Product '.$sku,
        'description' => 'A real description.',
        'short_description' => 'A real short description.',
        'status' => '1',
        'visible_individually' => '1',
        'new' => '0',
        'featured' => '0',
        'guest_checkout' => '0',
        'length' => '',
        'width' => '',
        'height' => '',
        'weight' => '1.5',
        'tax_category_name' => '',
        'price' => '10.00',
        'cost' => '',
        'special_price' => '',
        'special_price_from' => '',
        'special_price_to' => '',
        'customer_group_prices' => '',
        'url_key' => 'url-'.strtolower($sku),
        'meta_title' => '',
        'meta_keywords' => '',
        'meta_description' => '',
        'manage_stock' => '1',
        'inventories' => 'default=100',
        'related_skus' => '',
        'cross_sell_skus' => '',
        'up_sell_skus' => '',
        'configurable_variants' => '',
        'bundle_options' => '',
        'associated_skus' => '',
        'booking_options' => '',
    ];

    return array_values(array_merge($row, $overrides));
}

function writeProductsCsv(string $relPath, array $rows): void
{
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, csvHeader());
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    rewind($fh);
    $content = stream_get_contents($fh);
    fclose($fh);

    Storage::disk('private')->put($relPath, $content);
}

/**
 * Creates and validates a real import against the CURRENT tenant context
 * (caller must already be inside a $tenant->run() closure) - returns
 * plain, connection-independent data only (never the Importer/Import
 * helper objects themselves), since those hold state bound to whatever
 * tenant connection was active when they were built.
 */
function runImport(string $relPath, array $overrides = []): array
{
    $import = Import::create(array_merge([
        'type' => 'products',
        'action' => 'append',
        'process_in_queue' => false,
        'validation_strategy' => 'stop-on-errors',
        'allowed_errors' => 0,
        'field_separator' => ',',
        'file_path' => $relPath,
    ], $overrides));

    $helper = app(ImportHelper::class)->setImport($import);

    $isValid = $helper->validate();

    $fresh = $helper->getImport()->fresh();

    return [
        'id' => $fresh->id,
        'isValid' => $isValid,
        'processed' => $fresh->processed_rows_count,
        'invalid' => $fresh->invalid_rows_count,
        'errors' => $fresh->errors,
    ];
}

/**
 * Runs every pending batch for an already-validated import (process_in_queue
 * = false path, mirroring exactly what Webkul\Admin\Http\Controllers\
 * Settings\DataTransfer\ImportController::start() does per poll).
 */
function processImport(int $importId): void
{
    $import = Import::findOrFail($importId);

    $helper = app(ImportHelper::class)->setImport($import);
    $helper->started();

    foreach ($import->batches as $batch) {
        $helper->start($batch);
    }
}

function importRootProductCount(): int
{
    return DB::table('products')->whereNull('parent_id')->count();
}

beforeEach(function () {
    [$this->tenantA, $this->tenantB] = ensureImportTestTenants();

    $this->tenantA->run(function () {
        DB::table('products')->delete();
        DB::table('import_batches')->delete();
        DB::table('imports')->delete();
    });
    $this->tenantB->run(function () {
        DB::table('products')->delete();
        DB::table('import_batches')->delete();
        DB::table('imports')->delete();
    });
});

test('1. an import below the remaining limit succeeds', function () {
    $plan = ensureImportLimitPlan(5);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t1/products.csv', [
            csvRow('IMP-1-1'),
            csvRow('IMP-1-2'),
        ]);

        $result = runImport('imports/t1/products.csv');

        expect($result['isValid'])->toBeTrue();
        expect($result['invalid'])->toBe(0);

        processImport($result['id']);

        expect(importRootProductCount())->toBe(2);
    });
});

test('2. an import that lands exactly on the limit succeeds', function () {
    $plan = ensureImportLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t2/products.csv', [
            csvRow('IMP-2-1'),
            csvRow('IMP-2-2'),
        ]);

        $result = runImport('imports/t2/products.csv');

        expect($result['isValid'])->toBeTrue();

        processImport($result['id']);

        expect(importRootProductCount())->toBe(2);
    });
});

test('3. an import exceeding the limit is rejected', function () {
    $plan = ensureImportLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t3/products.csv', [
            csvRow('IMP-3-1'),
            csvRow('IMP-3-2'),
            csvRow('IMP-3-3'),
        ]);

        $result = runImport('imports/t3/products.csv');

        expect($result['isValid'])->toBeFalse();
        expect($result['invalid'])->toBe($result['processed']);

        $errors = implode(' ', $result['errors']);
        expect($errors)->toContain('your current plan allows up to 2 products');
        expect($errors)->not->toContain('LimitExceededException');
        expect($errors)->not->toContain('Exception');
    });
});

test('4. a rejected import persists no extra root products', function () {
    $plan = ensureImportLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t4/products.csv', [
            csvRow('IMP-4-1'),
            csvRow('IMP-4-2'),
            csvRow('IMP-4-3'),
        ]);

        $result = runImport('imports/t4/products.csv');
        expect($result['isValid'])->toBeFalse();

        // Nothing was ever inserted - validation, not processing, blocked
        // it, so there is nothing to "roll back".
        expect(importRootProductCount())->toBe(0);
        expect(DB::table('products')->where('sku', 'like', 'IMP-4-%')->exists())->toBeFalse();
    });
});

test('5. updating existing products does not consume quota', function () {
    $plan = ensureImportLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
        $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'IMP-5-EXISTING']);

        expect(importRootProductCount())->toBe(1);

        // An import that ONLY updates the one existing product, already
        // at the cap (limit=1, current=1) - must still succeed, since
        // zero NEW root products are being requested.
        writeProductsCsv('imports/t5/products.csv', [
            csvRow('IMP-5-EXISTING', overrides: ['name' => 'Renamed']),
        ]);

        $result = runImport('imports/t5/products.csv');

        expect($result['isValid'])->toBeTrue();

        processImport($result['id']);

        expect(importRootProductCount())->toBe(1);
    });
});

test('6. configurable variants do not consume quota', function () {
    $plan = ensureImportLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        // One configurable root (consumes 1 unit) referencing one variant
        // row (must NOT consume a second unit) - limit=1, current=0, so
        // this is only allowed at all if the variant is correctly excluded.
        writeProductsCsv('imports/t6/products.csv', [
            csvRow('IMP-6-VARIANT', overrides: [
                'name' => 'Variant Row',
                'price' => '9.00',
            ]),
            csvRow('IMP-6-CONFIG', 'configurable', [
                'name' => 'Configurable Root',
                'price' => '',
                'configurable_variants' => 'sku=IMP-6-VARIANT,color=1',
            ]),
        ]);

        $result = runImport('imports/t6/products.csv');

        expect($result['isValid'])->toBeTrue();
    });
});

test('7. a mixed update + create import calculates additional usage correctly', function () {
    $plan = ensureImportLimitPlan(2);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
        $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'IMP-7-EXISTING']);

        // limit=2, current=1: 1 update (free) + 1 new root (consumes the
        // last remaining unit) = allowed.
        writeProductsCsv('imports/t7/products.csv', [
            csvRow('IMP-7-EXISTING', overrides: ['name' => 'Updated']),
            csvRow('IMP-7-NEW'),
        ]);

        $result = runImport('imports/t7/products.csv');
        expect($result['isValid'])->toBeTrue();

        processImport($result['id']);
        expect(importRootProductCount())->toBe(2);
    });

    // A SECOND, separate import (fresh Import record) now at the cap
    // (current=2, limit=2): 1 more update (free) + 1 more new root
    // (would make 3) = blocked.
    $this->tenantA->run(function () {
        writeProductsCsv('imports/t7b/products.csv', [
            csvRow('IMP-7-EXISTING', overrides: ['name' => 'Updated Again']),
            csvRow('IMP-7-NEW-2'),
        ]);

        $result = runImport('imports/t7b/products.csv');
        expect($result['isValid'])->toBeFalse();
        expect(importRootProductCount())->toBe(2);
    });
});

test('8. an unlimited plan allows import regardless of count', function () {
    $plan = ensureImportUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t8/products.csv', [
            csvRow('IMP-8-1'),
            csvRow('IMP-8-2'),
            csvRow('IMP-8-3'),
            csvRow('IMP-8-4'),
        ]);

        $result = runImport('imports/t8/products.csv');
        expect($result['isValid'])->toBeTrue();

        processImport($result['id']);
        expect(importRootProductCount())->toBe(4);
    });
});

test('9. Tenant A\'s import usage does not affect Tenant B', function () {
    $planA = ensureImportLimitPlan(1);
    $planB = ensureImportUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $planA->id])->save();
    $this->tenantB->forceFill(['plan_id' => $planB->id])->save();

    $this->tenantA->run(function () {
        writeProductsCsv('imports/t9a/products.csv', [csvRow('IMP-9-A-1')]);
        $result = runImport('imports/t9a/products.csv');
        expect($result['isValid'])->toBeTrue();
        processImport($result['id']);
        expect(importRootProductCount())->toBe(1);
    });

    $this->tenantB->run(function () {
        writeProductsCsv('imports/t9b/products.csv', [
            csvRow('IMP-9-B-1'),
            csvRow('IMP-9-B-2'),
            csvRow('IMP-9-B-3'),
        ]);
        $result = runImport('imports/t9b/products.csv');
        expect($result['isValid'])->toBeTrue();
        processImport($result['id']);
        expect(importRootProductCount())->toBe(3);
    });

    // Tenant A remains at its own cap, unaffected by Tenant B's import.
    $this->tenantA->run(function () {
        expect(importRootProductCount())->toBe(1);
    });
});

test('10. a multi-batch import cannot bypass the limit across batches', function () {
    // AbstractImporter::BATCH_SIZE = 100 - 120 new root rows guarantees
    // this import spans two import_batches rows, while this task's check
    // operates on the WHOLE FILE (every batch already built and visible
    // before any of them run), not per-batch - the real proof this test
    // exists for.
    $plan = ensureImportLimitPlan(100);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = csvRow('IMP-10-'.$i);
        }
        writeProductsCsv('imports/t10/products.csv', $rows);

        $result = runImport('imports/t10/products.csv');

        $batchCount = DB::table('import_batches')->where('import_id', $result['id'])->count();
        expect($batchCount)->toBeGreaterThan(1);

        expect($result['isValid'])->toBeFalse();
        expect(importRootProductCount())->toBe(0);
    });
});

test('11. a real Bagisto DataTransfer HTTP path is exercised end-to-end', function () {
    $plan = ensureImportLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->post('http://tenant-import-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    expect(\Illuminate\Support\Facades\Route::has('admin.settings.data_transfer.imports.store'))->toBeTrue();

    $csv = "sku,parent_sku,locale,attribute_family_code,type,categories,images,name,description,short_description,status,visible_individually,new,featured,guest_checkout,length,width,height,weight,tax_category_name,price,cost,special_price,special_price_from,special_price_to,customer_group_prices,url_key,meta_title,meta_keywords,meta_description,manage_stock,inventories,related_skus,cross_sell_skus,up_sell_skus,configurable_variants,bundle_options,associated_skus,booking_options\n"
        ."IMP-11-1,,en,default,simple,,,Product IMP-11-1,A real description.,A real short description.,1,1,0,0,0,,,,1.5,,10.00,,,,,,url-imp-11-1,,,,1,default=100,,,,,,,\n"
        ."IMP-11-2,,en,default,simple,,,Product IMP-11-2,A real description.,A real short description.,1,1,0,0,0,,,,1.5,,10.00,,,,,,url-imp-11-2,,,,1,default=100,,,,,,,\n";

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('products.csv', $csv);

    $store = $this->post('http://tenant-import-a.localhost/admin/settings/data-transfer/imports/create', [
        'type' => 'products',
        'action' => 'append',
        'process_in_queue' => '0',
        'validation_strategy' => 'stop-on-errors',
        'allowed_errors' => '0',
        'field_separator' => ',',
        'file' => $file,
    ]);
    $store->assertRedirect();

    $importId = $this->tenantA->run(fn () => DB::table('imports')->orderByDesc('id')->value('id'));
    expect($importId)->not->toBeNull();

    $validate = $this->getJson('http://tenant-import-a.localhost/admin/settings/data-transfer/imports/validate/'.$importId);
    $validate->assertOk();
    $validate->assertJsonPath('is_valid', false);

    $errors = $this->tenantA->run(fn () => DB::table('imports')->where('id', $importId)->value('errors'));
    expect($errors)->toContain('your current plan allows up to 1 products');

    $this->tenantA->run(function () {
        expect(importRootProductCount())->toBe(0);
    });
});

test('12. existing Product::creating (single-creation) enforcement remains green alongside import enforcement', function () {
    $plan = ensureImportLimitPlan(1);
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->tenantA->run(function () {
        $repo = app(\Webkul\Product\Repositories\ProductRepository::class);
        $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'IMP-12-VIA-ADMIN']);

        expect(importRootProductCount())->toBe(1);

        expect(fn () => $repo->create(['type' => 'simple', 'attribute_family_id' => 1, 'sku' => 'IMP-12-BLOCKED']))
            ->toThrow(\Platform\Plans\Exceptions\LimitExceededException::class);

        expect(importRootProductCount())->toBe(1);

        // The import path, checking the SAME live usage, is consistent
        // with the single-creation path above: already at cap, so even
        // one new root product via import is also blocked.
        writeProductsCsv('imports/t12/products.csv', [csvRow('IMP-12-VIA-IMPORT')]);
        $result = runImport('imports/t12/products.csv');
        expect($result['isValid'])->toBeFalse();
    });
});

test('13. the existing full Platform suite is unaffected (spot check: TASK-ARCH-009 My Plan page still works)', function () {
    $plan = ensureImportUnlimitedPlan();
    $this->tenantA->forceFill(['plan_id' => $plan->id])->save();

    $this->post('http://tenant-import-a.localhost/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();

    $response = $this->get('http://tenant-import-a.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('My Plan');
});
