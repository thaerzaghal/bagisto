<?php

declare(strict_types=1);

namespace Platform\Enforcement\Providers;

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use Platform\Enforcement\Importers\EnforcingProductImporter;
use Platform\Enforcement\Listeners\EnforceProductCreationLimit;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Exceptions\EntitlementException;
use Platform\Plans\Exceptions\LimitExceededException;
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

        $this->registerExceptionRenderer();
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

    /**
     * TASK-ARCH-012 (section 7: "no HTTP 500... show a useful validation/
     * business message... do not expose internal exception names").
     *
     * REAL FINDING, not the textbook Laravel approach: a plain, one-time
     * `app(ExceptionHandler::class)->renderable(...)` call from THIS
     * provider's boot() does NOT work in this application - confirmed
     * live (a first draft this way rendered a raw 500/debug page, not
     * the intended 422, for every enforcement test). Root cause:
     * `Webkul\Core\Providers\CoreServiceProvider::register()` rebinds
     * `Illuminate\Contracts\Debug\ExceptionHandler::class` via
     * `$this->app->bind(...)` (NOT `singleton()`) to its own
     * `Webkul\Core\Exceptions\Handler` - every `app(ExceptionHandler::
     * class)` call therefore constructs a BRAND NEW instance, so a
     * renderable callback registered on the one instance THIS provider
     * happened to resolve is immediately orphaned and never touches the
     * instance actually used to render a later request's exception.
     *
     * The fix - and the SAME mechanism `bootstrap/app.php`'s own
     * `withExceptions()` closure already relies on for
     * TenantCouldNotBeIdentifiedException (TASK-ARCH-003), proven
     * reliable throughout this whole engagement despite the same `bind()`
     * quirk - is `Container::afterResolving()`, which (confirmed by
     * reading `Illuminate\Container\Container::fireAfterResolvingCallbacks
     * ()`) fires on EVERY resolution of a matching type, not just the
     * first. Registering the renderable callback inside an
     * `afterResolving()` hook re-attaches it to each fresh
     * `bind()`-created Handler instance as it's constructed, which is
     * exactly what's needed here. Registered against the concrete
     * `Illuminate\Foundation\Exceptions\Handler::class` (the class
     * `Webkul\Core\Exceptions\Handler` extends) rather than the
     * interface, matching `withExceptions()`'s own target and Laravel's
     * container walking the resolved object's parent classes when
     * matching `afterResolving()` callbacks.
     *
     * Not a `packages/Webkul` change - the `bind()` vs `singleton()`
     * choice in CoreServiceProvider is left exactly as-is; this works
     * around it entirely from within Platform\Enforcement. See
     * RISK_REGISTER.md for this finding recorded in full.
     *
     * Catches the EntitlementException MARKER INTERFACE, not just
     * LimitExceededException - every TASK-ARCH-008 entitlement-resolution
     * failure (no plan assigned, feature not configured, wrong feature
     * type) is an equally real way product creation can be blocked, and
     * every one of them must get the same "no 500, no internal exception
     * name" treatment, not just the expected/common over-limit case.
     *
     * A 422 (Unprocessable Content) with a top-level `message` key
     * mirrors exactly the JSON shape Laravel's own ValidationException
     * already renders for this same `admin.catalog.products.store`
     * endpoint (confirmed live: Bagisto's Admin Vue layer reads
     * `error.response.data.message` broadly, e.g.
     * packages/Webkul/Admin/src/Resources/views/customers/customers/
     * index/create.blade.php and many other create/edit forms) - so the
     * existing, unmodified admin product-create form already displays
     * this message correctly with zero packages/Webkul change.
     */
    protected function registerExceptionRenderer(): void
    {
        $this->app->afterResolving(Handler::class, function (Handler $handler) {
            $handler->renderable(function (EntitlementException $e, $request) {
                $message = $e instanceof LimitExceededException && $e->feature === FeatureCode::ProductsLimit->value
                    ? "Your current plan allows up to {$e->limit} products."
                    : 'Your current plan does not allow this action.';

                return response()->json(['message' => $message], 422);
            });
        });
    }
}
