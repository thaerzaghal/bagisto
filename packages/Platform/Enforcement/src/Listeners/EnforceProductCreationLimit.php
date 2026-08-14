<?php

declare(strict_types=1);

namespace Platform\Enforcement\Listeners;

use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Services\TenantLimits;
use Webkul\Product\Models\Product;

/**
 * TASK-ARCH-012. The first real consumer of Platform\Plans\Services\
 * TenantLimits - enforces `products.limit` for every product-creation
 * path that goes through Bagisto's own type-class hierarchy
 * (Webkul\Product\Type\AbstractType::create(), which every product type
 * - Simple, Virtual, Downloadable, Grouped, Bundle, Booking, and
 * Configurable's own root-product step - either uses directly or calls
 * via `parent::create()`). Registered against Eloquent's static
 * `Product::creating` model event (Platform\Enforcement\Providers\
 * EnforcementServiceProvider::boot()), NOT against any single
 * controller - this is deliberately the NARROWEST point every one of
 * those paths is structurally guaranteed to pass through, since they all
 * end in `$this->productRepository->getModel()->create($data)`, i.e. a
 * real `Illuminate\Database\Eloquent\Model::create()` call, which always
 * fires `creating` before the row is physically inserted. See
 * docs/architecture/feature-limits.md "Enforcement boundary" for the
 * full audit of every product-creation call site found in this codebase,
 * including the one real gap this boundary does NOT close
 * (Webkul\DataTransfer's bulk import, which bypasses Eloquent entirely
 * via a raw `insert()` - documented there as a known, explicit, approved
 * bypass, not silently ignored).
 *
 * COUNTING SEMANTICS: only ROOT/aggregate products count toward the
 * limit - a product with `parent_id` set is a configurable product's
 * variant (Webkul\Product\Type\Configurable::createVariant()), an
 * internal implementation detail of ONE merchant-created catalog entry,
 * not a separate one. `parent_id` is reliably readable at `creating`
 * time because Eloquent's `create()` fills the model's attributes from
 * `$data` in its constructor, before `save()` (and therefore `creating`)
 * ever runs - confirmed live: root products are created with no
 * `parent_id` key in `$data` at all (null), variants always pass
 * `'parent_id' => $product->id` explicitly.
 *
 * THROWING vs RETURNING FALSE: this listener THROWS Platform\Plans\
 * Exceptions\LimitExceededException (via TenantLimits::assertWithinLimit)
 * rather than returning `false` (Eloquent's other supported way to abort
 * a `creating` listener) - a thrown exception carries the actual reason
 * (which feature, what limit, current usage) all the way up through
 * AbstractType::create() / ProductRepository::create() to whichever
 * controller called it, letting EnforcementServiceProvider's exception
 * renderer turn it into a clean, specific user-facing message instead of
 * a bare, silent "nothing happened".
 */
class EnforceProductCreationLimit
{
    public function handle(Product $product): void
    {
        if ($product->parent_id !== null) {
            return;
        }

        if (! tenancy()->initialized) {
            // Defensive only, not a real code path in this application:
            // the `products` table only exists inside a tenant database
            // (see docs/architecture/database-per-tenant.md), so nothing
            // should ever be able to construct a Product model at all
            // outside an active tenancy.
            return;
        }

        $currentCount = Product::whereNull('parent_id')->count();

        TenantLimits::current()->assertWithinLimit(FeatureCode::ProductsLimit, $currentCount);
    }
}
