# Storefront Shopper Order Flow

**Status: VERIFIED (TASK-MVP-002, 2026-08-16).** This document records the real, unmodified Bagisto shopper checkout flow as it actually exists in `packages/Webkul/*`, and confirms it works correctly, end-to-end, against a genuinely provisioned multi-tenant store with **zero Platform-owned defects found and zero `packages/Webkul` changes made**. See `tests/Feature/Platform/StorefrontOrderEndToEndTest.php` for the full, real-HTTP proof.

## Platform SaaS Billing vs. Shop order payment - two separate domains

These are frequently confused and must not be. This project has two entirely independent payment concepts:

1. **`Platform\Billing`** (TASK-ARCH-018/019) - the **merchant** pays **the platform** for their SaaS subscription (a `Plan`/`PlanPrice`). Stripe sandbox checkout, `Payment`/`Subscription` models, central-DB-only. See [billing.md](billing.md).
2. **Bagisto Shop order payment** (this document) - a **shopper** pays **the merchant** for a store order (Cash On Delivery, Money Transfer, or any other configured storefront gateway). `Webkul\Payment\Payment\*` classes, `orders`/`order_payment` tables, tenant-DB-only.

No code path in this codebase confuses the two: the shopper checkout flow below never touches `Platform\Billing`, never creates a `Platform\Billing\Models\Payment` row, never calls Stripe, and never mutates a tenant's `Subscription`. Conversely, `Platform\Billing`'s checkout/webhook flow never touches `orders`/`cart`/`Webkul\Payment`.

## The real flow (as verified)

```
GET  /{url_key}                              storefront product detail (fallback route,
                                               Webkul\Shop\Http\Controllers\ProductsCategoriesProxyController)
POST /api/checkout/cart                       add to cart (Webkul\Shop\Http\Controllers\API\CartController::store())
POST /api/checkout/onepage/addresses          billing/shipping address (API\OnepageController::storeAddress())
POST /api/checkout/onepage/shipping-methods   shipping method selection (API\OnepageController::storeShippingMethod())
POST /api/checkout/onepage/payment-methods    payment method selection (API\OnepageController::storePaymentMethod())
POST /api/checkout/onepage/orders             order placement (API\OnepageController::storeOrder()
                                               -> Webkul\Sales\Repositories\OrderRepository::create())
```

All routes live under `packages/Webkul/Shop/src/Routes/{store-front-routes,api}.php`, registered with the `web`/`shop` middleware groups Bagisto already uses - **no route, controller, or middleware change was needed anywhere in this flow**. The existing tenancy wiring (`InitializeTenancyByDomain` prepended to the `web` group since TASK-ARCH-003, `TenantAccessGate` ahead of it since TASK-ARCH-014) already covers every route above with zero additional work - a suspended/non-Ready tenant's checkout routes are blocked exactly like every other tenant route (verified live, test 12).

## Guest checkout works out of the box

`sales.checkout.shopping_cart.allow_guest_checkout` defaults to `'1'`, seeded unconditionally by Bagisto's own `Webkul\Installer\Database\Seeders\Core\ConfigTableSeeder` for every tenant (no Platform-owned seeding involved). The per-product `guest_checkout` attribute also defaults `true`. No login is required anywhere in the verified flow - a guest can browse, cart, address, ship, pay, and place an order with no customer account. (A logged-in `Webkul\Customer` account also works identically and was not what this task chose to exercise in the primary path, since guest checkout is the strictly harder case - it exercises session-based guest cart association, which customer checkout does not need.)

## Minimum purchasable-product requirements (verified empirically)

A product is purchasable through the storefront the moment it has:
- `status = true` and `visible_individually = true` (both channel-scoped attributes) - required for the product detail page to render at all (404 otherwise) and for add-to-cart to succeed.
- A non-empty `url_key` (the product's entire storefront URL, via the single catch-all `Route::fallback()` in Bagisto - there is no dedicated `/product/{id}` route).
- A channel association (`product_channels` pivot) - auto-synced to the default channel by `Webkul\Product\Type\AbstractType::create()` for any product created through the normal domain path.
- A `ProductInventory` row (`product_inventories` table) on an inventory source the product's channel is linked to, with `qty > 0` (or `manage_stock = false`, which allows any quantity).
- `guest_checkout = true` (product attribute) if the shopper is not logging in.

No category association is required for a purchasable product (categories affect browsing/listing, not purchasability).

## Minimum configuration a freshly provisioned tenant already has - no provisioning gap found

This was the primary open question this task set out to answer, and the answer is **no, there is no gap**: a tenant provisioned purely through the existing `TenantProvisioner` pipeline (unchanged since TASK-ARCH-002, including TASK-MVP-001's self-service signup path) already has everything the checkout flow needs, with zero manual database work:

- **Default channel** (`id=1`, `code='default'`) with countries, states, currency, and locale all attached - seeded unconditionally by `Webkul\Installer\Database\Seeders\Core\{ChannelTableSeeder,CountriesTableSeeder,StatesTableSeeder}`.
- **Default inventory source** (`id=1`, `code='default'`) already linked to the default channel via `channel_inventory_sources` - seeded by `Core\ChannelTableSeeder` + `Inventory\InventorySourceTableSeeder`.
- **Shipping methods active**: `free` and `flatrate` carriers (`packages/Webkul/Shipping/src/Config/carriers.php`, `active => true`) - no `core_config` row exists for a fresh tenant to override this, so the package-config default (`true`) is what actually governs availability. Confirmed live: both appear as available shipping methods on the very first address-store request against a freshly provisioned tenant, with no admin action.
- **Payment methods active**: `cashondelivery` and `moneytransfer` (`packages/Webkul/Payment/src/Config/payment-methods.php`, `active => true`) - same mechanism, same result.
- **Guest checkout allowed** (see above).

The only thing genuinely missing after provisioning is what a real merchant is always expected to supply themselves: **products**. This is correctly classified as normal merchant setup work (task section 9's "category A"), not a Platform provisioning defect ("category B") - no fix was needed or made.

## Order placement result (verified)

`Webkul\Sales\Repositories\OrderRepository::create()` (wrapped in its own `DB::transaction()`) creates `orders` + `order_items` + `order_payment` + two `addresses` rows (`address_type` = `order_billing`/`order_shipping`) in one atomic operation, entirely inside the tenant's own database. Verified: correct customer/guest identity, product/quantity, price/total consistency (`grand_total == sub_total` for a free-shipping, no-tax order), selected shipping method (`shipping_method` column), and selected payment method (`order_payment.method`) are all persisted exactly as submitted. Confirmed absent from the central database (`orders` table does not exist there at all - the same R17/R30 invariant this whole engagement already established) and absent from an unrelated tenant's own database.

## Inventory side effect (verified, not assumed)

Order placement does **not** decrement the physical inventory-source quantity (`product_inventories.qty`). It instead writes a *reservation* to `product_ordered_inventories` (`Webkul\Sales\Repositories\OrderItemRepository::manageInventory()`), which is what the storefront-visible `product_inventory_indices.qty` (physical minus reserved) actually reflects. **Physical stock is only decremented at shipment creation** (`Webkul\Sales\Repositories\ShipmentItemRepository::updateProductInventory()`) - out of scope for this task, not exercised. Confirmed live: after placing an order for 3 units, `product_inventories.qty` is byte-for-byte unchanged, `product_ordered_inventories.qty` is `3`, and `product_inventory_indices.qty` has dropped by exactly `3`.

## Tenant isolation (verified, not assumed)

- A product created in Tenant A is invisible through Tenant B's own storefront at the identical relative URL (404, not a cross-tenant leak - the product genuinely does not exist in Tenant B's own database).
- An order placed in Tenant A does not exist in the central database (no `orders` table there at all) or in Tenant B's database.
- The shopper's session for a Tenant A checkout lands in Tenant A's own `sessions` table, never centrally (same mechanism TASK-ARCH-010/R33 already established for the homepage, now confirmed for the full checkout flow too).
- A `DB::listen()` connection-probe across the full add-to-cart request shows only two connections ever touched: `mysql` (central, for tenant/domain resolution) and `tenant` (the dynamically-bound tenant connection) - never a third, cross-tenant connection.

## Negative paths verified

- An inactive product (`status = false` for the channel) cannot be added to cart - `API\CartController::store()`'s own pre-check rejects it with a clean 400, zero `cart_items` row created.
- Requesting more quantity than available inventory is rejected (`Webkul\Product\Exceptions\InsufficientProductInventoryException`, 400), zero `cart_items` row created.
- A suspended tenant's checkout routes remain blocked by the existing `TenantAccessGate` (423), exactly like every other tenant route - confirmed this applies to the Shop API routes specifically, not just the storefront homepage/Admin routes already covered by `TenantAccessGateTest.php`.

## What this task did NOT need to change

No `packages/Webkul` file was modified. No new Platform-owned seeding/configuration step was added to `TenantProvisioner` - the investigation in this document is exactly what confirms none was needed. The only code this task added is the verification test itself (`tests/Feature/Platform/StorefrontOrderEndToEndTest.php`) and this document.
