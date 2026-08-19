<?php

declare(strict_types=1);

namespace Platform\Enforcement\Providers;

use Illuminate\Support\ServiceProvider;
use Platform\Enforcement\Importers\EnforcingProductImporter;
use Platform\Enforcement\Listeners\EnforceProductCreationLimit;
use Webkul\Product\Models\Product;

/**
 * TASK-ARCH-012. Package boundary: Platform\Enforcement depends on
 * Platform\Plans (TenantLimits/FeatureCode/exceptions) AND Webkul\Product
 * (the real Product model, to hook its `creating` event) - the one
 * Platform package in this codebase allowed to know about a specific
 * Webkul package, since its entire job is wiring Bagisto's real
 * extension points to Plans' feature-agnostic entitlement/limit engine.
 * Nothing depends on Platform\Enforcement in return (a leaf package).
 * Platform\Plans itself stays completely free of any Webkul dependency -
 * see docs/architecture/feature-limits.md "Package boundary" and
 * DECISION_LOG.md C23.
 */
class EnforcementServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        Product::creating(function (Product $product) {
            app(EnforceProductCreationLimit::class)->handle($product);
        });

        $this->registerEnforcingProductImporter();
    }

    /**
     * TASK-ARCH-012A (RISK_REGISTER.md R39). Swaps Bagisto's product CSV/
     * XLS/XML importer for Platform\Enforcement\Importers\
     * EnforcingProductImporter - a plain subclass that calls parent::
     * validateData() for everything Bagisto normally does, then adds one
     * additional, whole-file check (see that class's own docblock for the
     * full reasoning: why the validation phase, not the per-batch import
     * phase, and why counting only new root products).
     *
     * Must run in boot(), not register() - `Webkul\DataTransfer\Providers\
     * DataTransferServiceProvider::register()`'s own `mergeConfigFrom()`
     * call is what first populates `config('importers.products')` at all
     * (title, sample paths, the original importer class); every
     * ServiceProvider's register() phase completes before ANY provider's
     * boot() phase begins, so by the time this runs, that base config is
     * guaranteed to already exist. A plain dot-notation `config(['importers.
     * products.importer' => ...])` set only replaces that one nested key -
     * 'title'/'sample_paths'/'sample_images_zip_path' are left completely
     * untouched, and every OTHER importer type (customers, tax_rates) is
     * unaffected, since this only ever touches the 'products' key.
     */
    protected function registerEnforcingProductImporter(): void
    {
        config(['importers.products.importer' => EnforcingProductImporter::class]);
    }
}
