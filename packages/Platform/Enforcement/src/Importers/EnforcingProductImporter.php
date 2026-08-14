<?php

declare(strict_types=1);

namespace Platform\Enforcement\Importers;

use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Exceptions\EntitlementException;
use Platform\Plans\Exceptions\LimitExceededException;
use Platform\Plans\Services\TenantLimits;
use Throwable;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\Product\Importer as BaseProductImporter;
use Webkul\DataTransfer\Repositories\ImportBatchRepository;
use Webkul\Product\Models\Product;

/**
 * TASK-ARCH-012A. Closes RISK_REGISTER.md R39 - the ONE product-creation
 * path TASK-ARCH-012 found unenforced (`Webkul\DataTransfer\Helpers\
 * Importers\Product\Importer::saveProducts()` uses a raw, multi-row
 * `insert()` that bypasses Eloquent's `creating` event entirely, the
 * boundary `Platform\Enforcement\Listeners\EnforceProductCreationLimit`
 * depends on).
 *
 * EXTENSION MECHANISM: a plain subclass of Bagisto's own product
 * importer, swapped in via `config('importers.products.importer')` (see
 * `Platform\Enforcement\Providers\EnforcementServiceProvider::boot()`,
 * which sets this AFTER `Webkul\DataTransfer\Providers\
 * DataTransferServiceProvider::register()`'s own `mergeConfigFrom()` has
 * already populated the base config - a targeted `config(['importers.
 * products.importer' => ...])` dot-notation set only replaces THAT one
 * nested key, leaving 'title'/'sample_paths'/etc. from the original
 * config untouched). `Webkul\DataTransfer\Helpers\Import::getTypeImporter
 * ()` resolves the importer purely by class name from this config
 * (`app()->make($importerConfig['importer'])`), so nothing else in
 * `packages/Webkul` needs to know this subclass exists at all - zero
 * `packages/Webkul` modification, zero core behavior change for every
 * OTHER importer type (customers, tax rates) or if this subclass is ever
 * removed (config reverts to the original class automatically).
 *
 * WHY THE VALIDATION PHASE, NOT THE PER-BATCH IMPORT PHASE: product
 * imports are processed as MULTIPLE, INDEPENDENT QUEUED JOBS - one
 * `Webkul\DataTransfer\Jobs\Import\ImportBatch` per `import_batches` row,
 * dispatched via `Illuminate\Support\Facades\Bus::batch()`, which (per
 * that job class's own docblock) "run side by side across the fleet" -
 * i.e. genuinely concurrently, across however many queue workers are
 * running. A per-batch check (mirroring TASK-ARCH-012's `Product::
 * creating` boundary) would reproduce R38's race N-ways instead of
 * closing anything - two batches of the SAME import could both observe
 * "usage still under the limit" and both proceed. Hooking `validateData()`
 * instead - which ALWAYS runs once, synchronously, entirely BEFORE any
 * batch job is ever dispatched (confirmed live: `Webkul\DataTransfer\
 * Helpers\Importers\Product\Importer` does not opt into
 * `Concerns\ValidatesInChunks`'s `$chunkedValidationSupported = true`,
 * so `Webkul\DataTransfer\Helpers\Import::validate()`'s plain,
 * synchronous path is the ONLY path a product import ever takes) - gives
 * TRUE atomic, all-or-nothing rejection: if this check fails, `Import::
 * isValid()` returns false, and `Webkul\Admin\Http\Controllers\Settings\
 * DataTransfer\ImportController::start()` (confirmed live, line ~420:
 * `if (! $this->importHelper->isValid()) { return ...400... }`) never
 * calls `claimForProcessing()`/`start()` - no batch is EVER dispatched
 * for an over-limit import, so there is nothing left to race.
 *
 * COUNTING SEMANTICS (matching TASK-ARCH-012 exactly): only genuinely
 * NEW root/aggregate products consume `products.limit`. By the time
 * `Webkul\DataTransfer\Helpers\Importers\AbstractImporter::validateData()`
 * has run (called via `parent::validateData()` first), EVERY row that
 * passed Bagisto's own per-row validation is already sitting in this
 * import's `import_batches` rows (`AbstractImporter::saveValidatedBatches()`)
 * - giving this class full, already-materialized visibility into the
 * WHOLE file's row set before persistence, with zero re-parsing of the
 * source file needed. From that full row set:
 * - a row is a VARIANT, not a root product, if its `sku` is referenced in
 *   ANY row's `configurable_variants` column - parsed with the EXACT same
 *   `explode('|')` + `parse_str(str_replace(',', '&', ...))` logic
 *   `Webkul\DataTransfer\Helpers\Importers\Product\Importer::
 *   prepareConfigurableVariants()` itself uses (confirmed live: a
 *   variant's own row, when present in the file, is a plain 'simple'-type
 *   row created independently and only linked to its parent via
 *   `parent_id` LATER, in the separate linking phase - see
 *   `saveConfigurableVariants()`).
 * - a row is an UPDATE, not a create, if `isSKUExist()` (backed by
 *   `SKUStorage`, preloaded with EVERY existing SKU in `prepareForValidation()`,
 *   which runs before any row is validated) already knows its SKU.
 * - everything else is a genuinely NEW root product, consuming one unit
 *   of `products.limit`.
 *
 * Deliberately holds the WHOLE import's row set in memory once, during
 * this one validation pass, rather than optimizing for very large files -
 * proportionate for this task's scope (Bagisto's own `saveValidatedBatches()`
 * already persisted this exact data to build the very batches this reads
 * back), not a general import-pipeline redesign.
 */
class EnforcingProductImporter extends BaseProductImporter
{
    public const ERROR_CODE_PRODUCTS_LIMIT_EXCEEDED = 'products_limit_exceeded';

    /**
     * Populated as a side effect of validateRow() (called once per row by
     * AbstractImporter::saveValidatedBatches()) so the bulk check below
     * can attach a REAL row number to each error it adds - without this,
     * Platform\DataTransfer\Helpers\Error::addError()'s row-number
     * parameter would have to be null for every row, which would make
     * addRowToInvalid() a no-op (see that method: it only records a row
     * when given a real, non-null number) and defeat the whole mechanism
     * this class relies on to force Import::isValid() to false (see
     * rejectImportForLimitExceeded()'s own docblock).
     *
     * @var array<string, int>
     */
    protected array $rowNumbersBySku = [];

    public function validateRow(array $rowData, int $rowNumber): bool
    {
        $result = parent::validateRow($rowData, $rowNumber);

        if (! empty($rowData['sku'])) {
            $this->rowNumbersBySku[$rowData['sku']] = $rowNumber;
        }

        return $result;
    }

    public function validateData(): void
    {
        parent::validateData();

        $this->enforceProductsLimit();
    }

    /**
     * Clears the per-import state this class accumulates - matches
     * AbstractImporter::releaseBatchMemory()'s own existing reasoning
     * (a queue worker keeps its container, and this importer instance,
     * alive between imports; per-import state left behind would otherwise
     * leak into the next one this same worker validates).
     */
    public function releaseBatchMemory(): void
    {
        parent::releaseBatchMemory();

        $this->rowNumbersBySku = [];
    }

    protected function enforceProductsLimit(): void
    {
        // Deletions only ever reduce usage - never a limit concern.
        if ($this->import->action === Import::ACTION_DELETE) {
            return;
        }

        $rows = $this->collectBatchRows();

        if (empty($rows)) {
            // Nothing survived Bagisto's own validation (e.g. a bad
            // header aborted before any batch was built) - nothing of
            // ours to check either.
            return;
        }

        $variantSkus = $this->collectVariantSkus($rows);

        $newRootSkus = [];

        foreach ($rows as $rowData) {
            $sku = $rowData['sku'] ?? null;

            if (
                ! $sku
                || isset($variantSkus[$sku])
                || isset($newRootSkus[$sku])
            ) {
                continue;
            }

            if ($this->isSKUExist($sku)) {
                // An update to an existing product - does not consume
                // any additional quota, regardless of what else in the
                // row changes.
                continue;
            }

            $newRootSkus[$sku] = true;
        }

        $newRootCount = count($newRootSkus);

        if ($newRootCount === 0) {
            // Every row is either an update or a variant - always
            // allowed, matching TASK-ARCH-012's own "updates never
            // consume quota" rule.
            return;
        }

        if (! tenancy()->initialized) {
            // Defensive only - imports only ever run inside an active
            // tenant context in this application (there is no central
            // `products` table to import into at all).
            return;
        }

        $currentRootCount = Product::whereNull('parent_id')->count();

        try {
            // Reuses Platform\Plans\Services\TenantLimits::assertWithinLimit()
            // exactly as TASK-ARCH-012's single-creation boundary does -
            // no entitlement logic duplicated here. Its "may I add ONE
            // more" semantics (blocks when currentUsage >= limit) is
            // asked about the LAST of the N new root products this
            // import would create: passing (currentRootCount + newRootCount
            // - 1) asks "would creating the Nth new root product push
            // this tenant over the limit" - true if and only if
            // (currentRootCount + newRootCount) > limit, which is exactly
            // the bulk question this method needs answered.
            TenantLimits::current()->assertWithinLimit(
                FeatureCode::ProductsLimit,
                $currentRootCount + $newRootCount - 1
            );
        } catch (EntitlementException $e) {
            $this->rejectImportForLimitExceeded($rows, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function collectBatchRows(): array
    {
        $rows = [];

        foreach (app(ImportBatchRepository::class)->findWhere(['import_id' => $this->import->id]) as $batch) {
            foreach ($batch->data as $rowData) {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, true>
     */
    protected function collectVariantSkus(array $rows): array
    {
        $variantSkus = [];

        foreach ($rows as $rowData) {
            if (
                ($rowData['type'] ?? null) !== self::PRODUCT_TYPE_CONFIGURABLE
                || empty($rowData['configurable_variants'])
            ) {
                continue;
            }

            foreach (explode('|', $rowData['configurable_variants']) as $variant) {
                parse_str(str_replace(',', '&', $variant), $variantAttributes);

                if (! empty($variantAttributes['sku'])) {
                    $variantSkus[$variantAttributes['sku']] = true;
                }
            }
        }

        return $variantSkus;
    }

    /**
     * Marks EVERY row that survived Bagisto's own validation as ALSO
     * invalid, with a clear, non-internal message - deliberately, not a
     * partial/best-effort rejection. `Webkul\DataTransfer\Helpers\Import::
     * isValid()` has exactly one validation-strategy-INDEPENDENT block
     * condition (`processed_rows_count <= invalid_rows_count` - true only
     * when EVERY processed row is invalid); its other condition
     * (`errors_count > allowed_errors`) only applies when the merchant
     * chose the 'stop-on-errors' strategy, which a per-row-only error
     * would silently skip past under 'skip-errors' instead of blocking
     * the whole import. A plan-capacity violation is a whole-import
     * business constraint, not a per-row data problem "skip-errors" is
     * meant to tolerate, so it must block unconditionally - marking every
     * surviving row invalid is what reliably achieves that via Bagisto's
     * own, unmodified `isValid()` logic, and produces a real, visible,
     * per-row explanation in the same generated error report/summary
     * every other validation failure uses (Import::getFormattedErrors()/
     * uploadErrorReport()) - not a special, differently-behaved case.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function rejectImportForLimitExceeded(array $rows, Throwable $e): void
    {
        $message = $e instanceof LimitExceededException && $e->feature === FeatureCode::ProductsLimit->value
            ? "This import was not processed: your current plan allows up to {$e->limit} products, and this file would exceed that limit. Upgrade your plan or reduce the number of new products in this file."
            : 'This import was not processed: your current plan does not allow it.';

        foreach ($rows as $rowData) {
            $sku = $rowData['sku'] ?? null;

            $rowNumber = $sku !== null ? ($this->rowNumbersBySku[$sku] ?? null) : null;

            $this->errorHelper->addError(
                self::ERROR_CODE_PRODUCTS_LIMIT_EXCEEDED,
                $rowNumber,
                null,
                $message
            );
        }
    }
}
