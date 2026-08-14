# Feature / Limit System

**Status: `products.limit` FULLY ENFORCED (TASK-ARCH-012 + TASK-ARCH-012A, 2026-08-14).** The plan/feature-definition/tenant-assignment/entitlement-resolution domain (TASK-ARCH-008) is built and tested; TASK-ARCH-012 added the first real, working enforcement of a numeric limit (`products.limit`) for the Admin UI/type-class product-creation path; TASK-ARCH-012A closed the one bypass TASK-ARCH-012 found and left open (bulk CSV/XLS/XML product import). Usage tracking beyond a live count and enforcement of any OTHER feature (`staff.limit`, `domains.custom`, `reports.advanced`) remain deliberately unbuilt - see "What TASK-ARCH-012 deliberately does not include" below.

## Why this was pulled ahead of Phase 7

Originally Phase 9 (this document) followed Phase 7 (Tenant Admin Integration) in the roadmap. Phase 7's acceptance criteria ("tenant admin sees their plan/usage/domain") needs real plan data to exist first - building it earlier would mean placeholder UI, which the product owner explicitly rejected. Rather than renumber phases, this task pulled the minimal plan/feature/entitlement *domain* (not the whole of Phase 9) ahead, as TASK-ARCH-008. See IMPLEMENTATION_PLAN.md's "RESEQUENCING NOTE" on Phase 7 for the full record.

## Naming: `plans`, not `subscription_plans`

Phase 0's original sketch (this document and [database-per-tenant.md](database-per-tenant.md)) named the table `subscription_plans`. Renamed to `plans` during TASK-ARCH-008: this task explicitly excludes subscriptions ("This task is NOT subscriptions"), and naming the table after a domain concept (subscriptions) that doesn't exist yet read as confusing. See DECISION_LOG.md for the full reasoning. `plan_features` keeps its original name unchanged.

## Domain boundary

`plans`/`plan_features` are **central-database-only**. Both `Platform\Plans\Models\Plan` and `Platform\Plans\Models\PlanFeature` use `Stancl\Tenancy\Database\Concerns\CentralConnection` (`getConnectionName()` reads `config('tenancy.database.central_connection')` fresh on every call - the same mechanism `Stancl\Tenancy\Database\Models\Tenant`, the base class our own `Tenant` model extends, already relies on). Every query through these models hits the central connection regardless of what tenant context is (or isn't) currently active - proven live in `tests/Feature/Platform/TenantEntitlementsTest.php` ("tenant databases do not contain plan tables", "entitlements can be queried during an active tenant context...").

**Migration placement is load-bearing, not incidental**: `plans`/`plan_features`/the `tenants.plan_id` column migrations live in `database/migrations/` (root), never registered via a package's own `loadMigrationsFrom()`. `Platform\Tenancy\Services\TenantProvisioner::ensureMigrated()` runs `tenants:migrate --path=app('migrator')->paths()` - `Migrator::paths()` returns every path registered via `loadMigrationsFrom()` from *any* service provider. If Plan's migrations had been registered that way (the normal, self-contained-package convention every Webkul package uses), they would have been swept into every tenant database, violating this exact domain boundary. See RISK_REGISTER.md R30 for how this was nearly gotten wrong live during this task, and how it was caught and fixed.

## Design

Generic, data-driven, plan-configurable - no hardcoded numbers or `if ($plan === 'pro')` checks anywhere in application code. Backed by `plan_features`: `plan_id`, `feature_code`, `type` (`boolean|numeric|unlimited`), `value` (nullable integer - `null` + `type=unlimited` means no cap; `0`/`1` for `boolean`; the cap itself for `numeric`).

```php
Platform\Plans\Enums\FeatureCode::ProductsLimit    // 'products.limit', numeric
Platform\Plans\Enums\FeatureCode::StaffLimit       // 'staff.limit', numeric
Platform\Plans\Enums\FeatureCode::CustomDomain     // 'domains.custom', boolean
Platform\Plans\Enums\FeatureCode::AdvancedReports  // 'reports.advanced', boolean
```

These four are illustrative example identifiers (matching this task's own instructions and this document's original Phase 0 sketch), not a finalized business catalog. `feature_code` is a plain string column on `plan_features` - deliberately **not** cast to the `FeatureCode` enum, so centrally-configured entitlements can use codes not yet enumerated in code; the enum exists for call-site type safety (`Platform\Plans\Services\TenantEntitlements::can()`/`limit()` accept `FeatureCode|string` and normalize an enum instance to `->value`).

`Platform\Plans\Services\TenantEntitlements` resolves `tenant → current plan (tenants.plan_id) → plan_features row for the requested code`:

- `TenantEntitlements::current()` - resolves for the currently initialized tenant (throws if no tenancy is active).
- `TenantEntitlements::for($tenant)` - resolves for an explicit tenant, active tenancy or not.
- `->currentPlan(): Plan`
- `->can(FeatureCode|string $feature): bool` - for `boolean`-type features; throws `FeatureTypeMismatchException` if the feature isn't boolean.
- `->limit(FeatureCode|string $feature): ?int` - for `numeric`/`unlimited`-type features; `null` means unlimited; throws `FeatureTypeMismatchException` if the feature is boolean.

Both `can()`/`limit()` throw `FeatureNotConfiguredException` if the current plan has no row at all for the requested code (fail loud, not "silently denied"), and `currentPlan()` throws `NoPlanAssignedException` if `tenants.plan_id` is null.

## Tenant → plan relationship

`tenants.plan_id` (nullable FK → `plans.id`, `restrictOnDelete()`) - directly on the `tenants` table, not a separate assignment table. See DECISION_LOG.md for the full reasoning and how this evolves once Phase 10 builds real `subscriptions`.

## Default plan assignment

`Platform\Tenancy\Services\TenantProvisioner::ensureDefaultPlanAssigned()` is provisioning step 5 (after seeding, before marking a tenant `Ready`): looks up the plan whose `code` matches `config('platform.plans.default_code')` (default `'free'`, never a raw database id) and assigns it if `plan_id` is still null. **Fails provisioning loudly** if that plan doesn't exist - `php artisan platform:plans:seed` (idempotent, safe to rerun) must be run once per environment before any tenant is provisioned, the same deployment-step category as `platform:mark-installed`.

## Seeding

`Platform\Plans\Services\PlanSeeder::seed()` - central-only, idempotent (`updateOrCreate` throughout), seeds FREE/BASIC/PRO with the four example features above. Exposed both via `php artisan platform:plans:seed` and directly as a service (also called from `Tests\Feature\Platform\PlatformIntegrationTestCase::setUp()`, so every Platform integration test has a default plan available before it provisions a tenant - the test-suite equivalent of the required one-time production deployment step).

## What TASK-ARCH-008 deliberately did not include (superseded in part by TASK-ARCH-012)

- ~~**Enforcement**~~: **done for `products.limit`, TASK-ARCH-012** - see below. No other feature is enforced yet.
- **Usage tracking**: still no `usage_records`/metering infrastructure - TASK-ARCH-012 confirmed the original Phase 0 reasoning was right: a live `COUNT(*)` against the tenant's own database at the moment of the action is both sufficient and simpler than a periodic snapshot, for products specifically. See "Product counting semantics" below.
- **Tenant Admin UI**: still no Subscription/Billing/Usage pages. The existing "My Plan" page (TASK-ARCH-009) was deliberately NOT extended with a live usage display in TASK-ARCH-012 - see "Deferred: My Plan usage display" below.
- **Subscriptions/billing**: unchanged, still out of scope.
- **Subscription overrides**: unchanged, still not built.

## TASK-ARCH-012: `products.limit` enforcement - the first real vertical slice

### Enforcement boundary

Every product-creation call site in this codebase was audited (`grep` for `productRepository->create(` and `ProductRepository::create(` usage across all of `packages/Webkul`):

| Call site | Routes through |
|---|---|
| `Webkul\Admin\Http\Controllers\Catalog\ProductController::store()` (the real Admin UI "Add Product" form/AJAX flow, route `admin.catalog.products.store`) | `ProductRepository::create()` → `AbstractType::create()` → `Product::create($data)` |
| `Webkul\Product\Type\Configurable::create()` (a configurable product's ROOT row) | same, via `parent::create($data)` |
| `Webkul\Product\Type\Configurable::createVariant()` (each variant permutation) | same, via `parent::create(['parent_id' => $product->id, ...])` |
| Every other product type (Simple, Virtual, Downloadable, Grouped, Bundle, Booking - none override `create()`) | `AbstractType::create()` directly |
| `Webkul\DataTransfer\Helpers\Importers\Product\Importer::saveProducts()` (CSV/bulk import) | **raw `$this->productRepository->insert($products['insert'])`/`->upsert(...)` - bypasses Eloquent entirely, see "Known bypass" below** |

Every type-class-based path (everything except bulk import) ultimately calls `Illuminate\Database\Eloquent\Model::create()` on the real `Webkul\Product\Models\Product` model, which always fires the `creating` Eloquent event before the row is physically inserted - **this is the enforcement boundary**: `Platform\Enforcement\Listeners\EnforceProductCreationLimit`, registered against `Product::creating` in `Platform\Enforcement\Providers\EnforcementServiceProvider::boot()`. Zero `packages/Webkul` modification - `Model::creating()` is a normal, supported, public Eloquent extension point, not a hack.

Why this boundary and not `catalog.product.create.before` (the string-event `ProductController::store()` fires): that event is Admin-controller-specific and would NOT cover `Configurable::createVariant()`'s or any future type-class caller's direct `AbstractType::create()` calls. `Product::creating` is strictly narrower/tighter (fires later, right at persistence) and structurally guaranteed for every one of those paths, not just one controller.

### Product counting semantics

`products` has a nullable, self-referencing `parent_id` column (`database/Migrations/2018_07_27_065727_create_products_table.php`) - a configurable product's variants are separate rows in the SAME `products` table with `parent_id` set to the root product's id (`Configurable::createVariant()`). No soft-delete column exists on `products` (no `deleted_at`), and there is no real `status`/`is_active` schema column either (Bagisto's "status" is an EAV custom attribute, not a real column) - so "active only" or "non-deleted only" interpretations don't map cleanly onto the actual schema.

**Decision**: `products.limit` counts `Product::whereNull('parent_id')->count()` - "root/aggregate products", i.e. one unit per merchant-created catalog entry regardless of how many variants it has. A configurable product with 12 variants costs exactly 1 unit of the limit, matching the task's own suggested "simplest stable interpretation" and Bagisto's real schema distinction. Proven live: `tests/Feature/Platform/ProductLimitEnforcementTest.php` test 14 creates a root product (consumes the limit) then a variant-shaped row with `parent_id` set (does NOT consume it, confirmed by a second real root product still being correctly blocked at the same cap).

### Central + tenant query flow

`Platform\Enforcement\Listeners\EnforceProductCreationLimit::handle()`, running INSIDE an active tenant context (the product being created only ever exists inside a tenant database):

1. `Product::whereNull('parent_id')->count()` - a plain query against the CURRENT (tenant) connection, no special handling needed.
2. `Platform\Plans\Services\TenantLimits::current()->assertWithinLimit(FeatureCode::ProductsLimit, $currentCount)` - `TenantLimits::current()` resolves `tenant()` (the active tenant) and delegates to `TenantEntitlements::for($tenant)->limit(...)`, which reads `Plan`/`PlanFeature` via `CentralConnection` (TASK-ARCH-008's already-proven mechanism) - correctly reads central data while the tenant connection remains fully active, with no `tenancy()->end()`/manual connection swap anywhere in this code. Proven live: test 7 asserts `tenancy()->initialized` stays `true` throughout.

### Numeric-limit behavior

`currentUsage >= limit` blocks (the question being asked is "may I create ONE MORE" - at cap means the next one would exceed it); `currentUsage < limit` allows. Proven live for both the boundary-minus-one and boundary-exactly cases (tests 1-2).

### Unlimited behavior

`TenantEntitlements::limit()` returning `null` (an `unlimited`-type feature row) short-circuits `TenantLimits::assertWithinLimit()` unconditionally - no count check even runs. Proven live: test 4 creates three products in a row against an unlimited plan with zero blocking.

### Downgrade behavior

Changing a tenant's plan (`tenants.plan_id`) never touches existing product rows - `TenantLimits`/the enforcement listener only run at CREATE time, never on read or on plan change itself. A tenant downgraded below their current usage keeps every existing product fully accessible; only a NEW creation attempt is blocked, until either usage drops back under the new limit (via the merchant deleting products themselves, not an automated process) or the plan is upgraded again. Proven live: test 9.

### Failure UX

A blocked creation throws `Platform\Plans\Exceptions\LimitExceededException` (or, for the rarer misconfiguration cases - no plan assigned / feature not configured / wrong feature type - one of TASK-ARCH-008's existing `EntitlementException`-implementing exceptions, all of which now implement a new shared `Platform\Plans\Exceptions\EntitlementException` marker interface, TASK-ARCH-012). `Platform\Enforcement\Providers\EnforcementServiceProvider` registers a renderer that turns any `EntitlementException` into a plain `422 {"message": "..."}` response - `"Your current plan allows up to N products."` for the specific, expected over-limit case; a safe, generic `"Your current plan does not allow this action."` for the rarer misconfiguration cases (deliberately not leaking tenant IDs/internal plan names/exception class names). This exact JSON shape mirrors Laravel's own `ValidationException` rendering for the same endpoint, so Bagisto's existing, unmodified Admin Vue layer (which already reads `error.response.data.message` broadly across its create/edit forms) displays it correctly with zero `packages/Webkul` change - confirmed live (test 12: response body contains neither `LimitExceededException` nor `Stack trace`).

**A real, non-obvious finding while wiring this up (RISK_REGISTER.md R36)**: a plain, textbook `app(ExceptionHandler::class)->renderable(...)` call from a ServiceProvider's `boot()` does NOT work in this application, because `Webkul\Core\Providers\CoreServiceProvider` rebinds the exception handler via `bind()` (a fresh instance every resolution) rather than `singleton()`. The fix - registering via `Container::afterResolving(Handler::class, ...)`, which re-fires on every resolution - is the exact same mechanism `bootstrap/app.php`'s own `TenantCouldNotBeIdentifiedException` renderer already (and silently, reliably) depends on. See R36 for the full writeup.

### Concurrency / race condition (OPEN, not implemented)

**The count-then-create sequence is NOT atomic.** Two simultaneous requests each reading `currentUsage = 49` against a `limit = 50` can both pass the check and both insert, landing at 51 - a genuine TOCTOU (time-of-check-to-time-of-use) race. `TenantLimits::assertWithinLimit()` does not hold any lock across the count-and-decide step and the actual, separate `Product::creating` → INSERT that follows it.

**Deliberately left unfixed in TASK-ARCH-012**, per the task's own explicit permission to document rather than force a mechanism ("If Bagisto's product creation architecture makes a robust lock inappropriate at this stage, document the race precisely as an OPEN risk"). Reasoning:

- No evidence this is a live/likely threat: a single tenant's product creation is normally one admin, one product, one request at a time through a UI form - not a documented multi-admin-concurrent-creation scenario anywhere in this project's brief.
- Bagisto's own `Configurable::create()` is ALREADY not transactionally atomic (root product insert, then a separate loop of N variant inserts, then attribute-value saves, with no wrapping `DB::transaction()` anywhere in `packages/Webkul/Product`) - bolting a strict, correctly-scoped lock onto ONLY the count check would be inconsistent with the actual atomicity guarantees the surrounding, unmodified code already provides (or doesn't).
- A CORRECT fix requires holding a lock across BOTH the count-check AND the actual persisted INSERT (releasing it too early, e.g. right after the check, does not close the race - see the worked example below) - achievable only via a `Cache::lock()` acquired in `creating` and released in a paired `created`/`saved` listener (with careful handling of the throw-path release and TTL-bounded self-healing if `created` never fires for some other reason). This is real, non-trivial coordination for a benefit that is currently speculative, not evidenced.

**Worked example of why "hold the lock only during the check" would NOT work**: Request A's `creating` listener acquires a lock, sees `count=49`, decides "allowed" (49<50), releases the lock, then proceeds to its own real INSERT (which has not happened yet). If Request B's `creating` listener acquires the lock immediately after A releases it - which is BEFORE A's INSERT has actually run - B ALSO sees `count=49` (A's row doesn't exist yet), is ALSO allowed, and both proceed to insert: final count 51, limit 50, exceeded. Closing this requires the lock to span the full "check + real persistence" window, not just the check.

**If ever needed**: `Cache::lock("product-limit:{$tenantId}", ttl)` acquired in a `creating` listener and released in a paired `created`/`saved` listener (using the model's own dynamic-property mechanism or a small keyed holder to pass the lock instance between the two), OR a dedicated reserved-slot counter table with a unique-constraint-based "claim" step. Neither is built now.

### `Webkul\DataTransfer` bulk product import - RESOLVED (TASK-ARCH-012A)

**Originally left open in TASK-ARCH-012** (see RISK_REGISTER.md R39's original writeup, retained below for the record): `Webkul\DataTransfer\Helpers\Importers\Product\Importer::saveProducts()` calls `$this->productRepository->insert($products['insert'])` - a raw, multi-row `INSERT` via Eloquent's query-builder-level `insert()`, which does NOT fire any Eloquent model events (`creating` never runs) and does not go through `ProductRepository::create()`/`AbstractType::create()` at all. TASK-ARCH-012's own audit judged closing this "a disproportionate scope increase," reasoning that the `data_transfer.imports.batch.import.before` event (the one hookable point it looked at, fired immediately before that raw insert) could not distinguish root products from configurable variants at that stage.

**TASK-ARCH-012A found a cleaner boundary that TASK-ARCH-012's audit missed**: rather than hooking anything near the per-batch INSERT itself, hook the import's VALIDATION phase instead - `Webkul\DataTransfer\Helpers\Importers\AbstractImporter::validateData()`. By the time it returns, `AbstractImporter::saveValidatedBatches()` has ALREADY built every one of the import's `import_batches` rows from the WHOLE file (every row that passed Bagisto's own per-row validation) - giving full, already-materialized visibility into the entire file's row set, including each row's raw `configurable_variants` column, BEFORE any batch job (and therefore any INSERT) has even been dispatched. Parsing `configurable_variants` the same way `Importer::prepareConfigurableVariants()` itself does turned out to be a handful of lines, not "re-implementing a meaningful part of the importer" - TASK-ARCH-012's original estimate of the effort required was wrong.

**Extension mechanism**: `Platform\Enforcement\Importers\EnforcingProductImporter` - a plain subclass of `Webkul\DataTransfer\Helpers\Importers\Product\Importer`, swapped in via a targeted `config(['importers.products.importer' => EnforcingProductImporter::class])` dot-notation set (`Platform\Enforcement\Providers\EnforcementServiceProvider::boot()`, running after `Webkul\DataTransfer\Providers\DataTransferServiceProvider::register()`'s own `mergeConfigFrom()` has already populated the base config - the dot-notation set replaces only the `importer` key, leaving `title`/`sample_paths`/etc. and every OTHER importer type untouched). Zero `packages/Webkul` modification; `Webkul\DataTransfer\Helpers\Import::getTypeImporter()` resolves purely by class name from config, with no awareness this subclass exists.

**Why the validation phase, not a per-batch hook, matters for correctness, not just convenience**: product imports run as MULTIPLE, INDEPENDENT queued jobs (one `Webkul\DataTransfer\Jobs\Import\ImportBatch` per `import_batches` row, dispatched via `Illuminate\Support\Facades\Bus::batch()`) that - per that job class's own docblock - "run side by side across the fleet," i.e. genuinely concurrently across however many queue workers are running. A per-batch check would have reproduced R38's race N-ways (two batches of the same import both observing "still under the limit" and both proceeding) instead of closing anything. Hooking validation - which always runs once, synchronously, entirely before any batch is ever dispatched (confirmed live: Bagisto's product importer does not opt into `Concerns\ValidatesInChunks`'s chunked/queued validation path, so the plain, synchronous `Import::validate()` is the ONLY path a product import ever takes) - gives TRUE atomic, all-or-nothing rejection: if the check fails, `Import::isValid()` returns false, and `Webkul\Admin\Http\Controllers\Settings\DataTransfer\ImportController::start()` (confirmed live) never calls `claimForProcessing()`/`start()` at all. No batch is ever dispatched for an over-limit import, so there is nothing left to race - this closes the bypass with a STRICTER guarantee than TASK-ARCH-012's own single-creation boundary has (see "Concurrency" below).

**Counting semantics** (identical rules to the single-creation boundary, applied to a whole file at once): a row is a VARIANT (excluded) if its `sku` appears in ANY row's `configurable_variants` column, parsed with the exact logic `Importer::prepareConfigurableVariants()` itself uses; a row is an UPDATE (excluded) if `isSKUExist()` (backed by `SKUStorage`, preloaded with every existing SKU before any row is validated) already knows its SKU; everything else is a genuinely new root product. The bulk question "would creating N new root products, on top of C already existing, exceed the limit" is answered by asking `TenantLimits::assertWithinLimit()` (unchanged, no entitlement logic duplicated) about the LAST of the N: `assertWithinLimit($feature, $C + $N - 1)` - true exactly when `C + N > limit`.

**Failure UX**: uses Bagisto's OWN validation/error-reporting model, not a bespoke response shape. Because a plan-capacity violation is a whole-import business constraint (not a per-row data problem "skip-errors" is meant to tolerate), and `Import::isValid()` has exactly one validation-strategy-*independent* block condition (`processed_rows_count <= invalid_rows_count`, true only when every processed row is invalid), `EnforcingProductImporter` marks EVERY row that survived Bagisto's own validation as ALSO invalid, each with a real row number and the same clear message: *"This import was not processed: your current plan allows up to N products, and this file would exceed that limit. Upgrade your plan or reduce the number of new products in this file."* This surfaces through the exact same generated error summary/report every other validation failure in this system uses (`Import::getFormattedErrors()`/`uploadErrorReport()`) - confirmed live via the real HTTP validate endpoint, with no internal exception name ever appearing in the response.

**Concurrency**: does not touch R38 (still OPEN, unchanged) and does not make it worse. The check's own window is a single synchronous method call at validation time, not held open across the (potentially long, multi-batch, multi-worker) processing phase that follows - narrower than R38's already-accepted single-creation race, not wider. The residual overlap is exactly R38 itself: an admin-UI single-product creation (or a second import) could still interleave with an ALREADY-VALIDATED import's later batch processing and jointly exceed the limit by a small margin - the same accepted, documented race, just via a different pair of creation paths, not a new concurrency surface.

**Tests**: `tests/Feature/Platform/ProductImportLimitEnforcementTest.php` (13 tests, all passing) - below/at/over the limit, rejected imports persist nothing, updates and variants excluded correctly, mixed update+create counted correctly, unlimited plan, Tenant A/B isolation, a genuinely multi-batch (120-row, 2-batch) import still blocked as one unit, a real HTTP round-trip through `admin.settings.data_transfer.imports.store`/`.validate`, and a spot-check that the single-creation (`Product::creating`) boundary from TASK-ARCH-012 still works correctly alongside this one.

### Deferred: My Plan usage display

Section 13 of the task brief allowed (but did not require) showing `current / limit` on the existing "My Plan" admin page (TASK-ARCH-009), IF it could reuse the enforcement/usage calculation cleanly. It was evaluated and deferred: `Platform\Plans\Http\Controllers\Admin\MyPlanController` lives in `Platform\Plans`, which has zero dependency on any `Webkul\*` package by design (see "Domain boundary" above) - showing a live product COUNT would require either (a) `Platform\Plans` gaining a new, narrow dependency on `Webkul\Product` (breaking its current Webkul-free purity), or (b) `Platform\Enforcement` depending back on `Platform\Plans`' UI layer, which would create a package dependency CYCLE (`Platform\Enforcement → Platform\Plans` already exists; the reverse would too). Neither is a clean fit for this task's scope, and the task explicitly permits deferring rather than growing scope into a UI project. Left as a candidate for whichever future task actually needs it, with an explicit note on the layering question to resolve first.

### Package boundary and dependency direction

```
Platform\Enforcement -> Platform\Plans (TenantLimits, FeatureCode, LimitExceededException)
Platform\Enforcement -> Webkul\Product (Product model, to hook its `creating` event)
Platform\Enforcement -> Webkul\DataTransfer (Product\Importer, to subclass it; ImportBatchRepository, to read validated batch rows)
```

`Platform\Enforcement` is a new, leaf package (nothing depends on it) - the ONE Platform package in this codebase allowed to know about specific `Webkul\*` packages, since its entire reason to exist is wiring Bagisto's real extension points to Plans' feature-agnostic entitlement engine. `Platform\Plans` itself gained a new, feature-agnostic `TenantLimits` service and a `LimitExceededException`/`EntitlementException` marker interface, but remains completely free of any `Webkul\*` dependency - see DECISION_LOG.md C23-C24 (TASK-ARCH-012) and C25-C26 (TASK-ARCH-012A).
