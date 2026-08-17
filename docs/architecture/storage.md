# Storage / Filesystem Isolation

**Status: FULLY RESOLVED (TASK-ARCH-005, 2026-08-14, finalized 2026-08-14 across two review rounds).** This document originally recorded Phase 0's prediction from reading Bagisto's source alone. It now records the full, verified architecture: a repository-wide audit of every real filesystem consumer, the exact root cause of R16, the fix, and live proof via 9 tests in `TenantStorageIsolationTest.php` plus 5 in `TenantImageCacheIsolationTest.php` (30/30 across the whole Platform suite, 149 assertions). The `Webkul\ImageCache` resize route (R24/R25) — found not to be tenant-reachable at all during the first finalization round — is now fixed and proven end-to-end; see "The ImageCache route fix (R25)" below for how, without any `packages/Webkul` or global-middleware change.

## Filesystem architecture before

`FilesystemTenancyBootstrapper` was disabled from TASK-ARCH-001 onward (it crashed on missing directories under `CacheableRepository`'s key-tracking file — the original, narrower R16 symptom). All Bagisto filesystem consumers wrote to the single, central `public`/`private`/`local` disks with no tenant awareness at all.

## The audit

A full repository scan classified every meaningful filesystem consumer in `packages/Webkul` (17 total). The overwhelming pattern: **every tenant-owned consumer stores a bare relative path like `product/{id}/...`, `category/{id}/...`, `customer/{id}/...`, `theme/{id}/...`, `rma/{id}/...`, `tinymce/{rand}` — with no store/tenant segment at all.** This is favorable, not a problem: under database-per-tenant, numeric IDs are local to each tenant's own database (fresh auto-increment), so as long as the *disk root itself* is tenant-unique, every one of these flat paths becomes isolated automatically, with zero changes to any of the call sites.

| Consumer | Disk | Path pattern | Class |
|---|---|---|---|
| Product images/videos (`Product/src/{ProductImage,Repositories/ProductMediaRepository}.php`) | public | `product/{id}/{rand}.webp` | Tenant-owned |
| Downloadable link files (`ProductDownloadableLinkRepository.php`) | **private** | `product_downloadable_links/{id}` | Tenant-owned |
| Downloadable sample files (`ProductDownloadableSampleRepository.php`) | public (inconsistent with the above — a pre-existing Bagisto quirk, not introduced here) | `product_downloadable_links/{id}` | Tenant-owned |
| Category logo/banner (`CategoryRepository.php`) | public | `category/{id}/{rand}.webp` | Tenant-owned |
| Customer profile image (`CustomerRepository.php`) | public | `customer/{id}` | Tenant-owned |
| Theme customization images (`ThemeCustomizationRepository.php`) | public | `theme/{id}/{rand}.webp` | Tenant-owned — see "Known narrow exception" below |
| RMA attachments (`RMAImageRepository.php`) | public | `rma/{id}` | Tenant-owned |
| TinyMCE/CMS images (`TinyMCEController.php`) | public | `tinymce/{rand}` — flat, unscoped, shared by every WYSIWYG use in the install | Tenant-owned |
| Channel logo/favicon (`Core/src/Repositories/ChannelRepository.php`) | public | `channel/{id}` | Tenant-owned |
| Sitemap files (`Sitemap/src/Models/Sitemap.php`) | public | `additional.channels[*].sitemaps/index` — **already channel-scoped**, the one existing precedent for this pattern in Bagisto itself | Tenant-owned |
| Import/export processing (`DataTransfer/...`) | **private** | `imports/{import_id}/processed/*` | Temporary/job (self-cleaning) |
| Import-produced product images (`Importers/Product/Importer.php`) | public | `product/{id}/{rand}.webp` (same as #1) | Tenant-owned |
| Seeder-copied product/category/theme images (`Installer/.../*TableSeeder.php`) | public | `product/{id}`, `category/{id}`, `theme/{id}` | Tenant-owned, provisioning-time |
| Admin product export (`Admin/src/Exports/ProductDataGridExport.php`) | n/a — streamed directly to the HTTP response, never persisted | — | Global/transient |
| Image resize/cache route (`Webkul\ImageCache`) | bypasses `Storage::` entirely | see "Root cause" below | **The one real blocker found** |
| Downloadable "external URL" temp copy (`Shop/ProductController.php` etc.) | OS temp dir (`sys_get_temp_dir()`), not a Laravel disk | — | Temporary, low risk (single-request lifecycle, random filename) |
| `.env` writer (`Installer/src/Helpers/EnvironmentManager.php`) | raw filesystem | `base_path('.env')` | Global, install-time only — irrelevant (Installer is never used for tenant provisioning, per ADR-001/C13) |

## Exact root cause of R16

Two distinct, now-fixed problems, not one:

1. **`FilesystemTenancyBootstrapper` never creates directories, only remaps config.** Confirmed by reading its source (`vendor/stancl/tenancy/src/Bootstrappers/FilesystemTenancyBootstrapper.php`): `bootstrap()` computes new values for `storage_path()` and each configured disk's `root`, but never calls `mkdir`. Since `'suffix_storage_path' => true` (config/tenancy.php), `storage_path()` *itself* — not just disk roots — is suffixed per tenant, and Laravel/`prettus/l5-repository` assume `framework/{cache/data,sessions,views,testing}` and `logs` already exist under whatever `storage_path()` resolves to. This is what crashed the original spike attempt in TASK-ARCH-001 (a `CacheKeys` file-write warning), which was worked around by disabling the bootstrapper entirely rather than fixed.
2. **A second, previously-undocumented bug of the exact same shape as R17** (TASK-ARCH-002): `Webkul\ImageCache`'s `config/imagecache.php` ships `'paths' => [storage_path('app/public'), public_path('storage')]` — a plain PHP array, evaluated once when the config file is loaded at application boot, long before tenancy ever initializes. `Webkul\ImageCache\Http\Controllers\ImageCacheController::getImagePath()` (the `/cache/{template}/{filename}` resize route) checks this frozen, always-central value *first*, before its own otherwise-correct `storage_path()`-based fallback.

Problem 1 is fully fixed. Problem 2's *config-timing* fix is proven correct in isolation, but end-to-end testing against the real route (see below) surfaced a third, more fundamental problem that preempts it: **the real resize route never initializes tenancy at all**, regardless of the config fix. All three findings are without a single `packages/Webkul` change — including the still-open one, which was investigated but deliberately not "fixed" by editing `packages/Webkul/ImageCache` or making a broad, unauthorized `bootstrap/app.php` change (see below).

## Filesystem architecture after

1. **`FilesystemTenancyBootstrapper` enabled** (`config/tenancy.php`), for disks `local`, `public`, and **`private`** (added — `DataTransfer` and `ProductDownloadableLinkRepository` both write real tenant data there, confirmed by the audit). `public`/`local` nest under the already-suffixed `storage_path()` (`storage/tenant{id}/app/public`); `private` falls back to the bootstrapper's default (`storage/app/private/tenant{id}`) since no `root_override` template is configured for it — still fully tenant-unique.
2. **`Platform\Tenancy\Services\TenantProvisioner::ensureFilesystemPrepared()`** (new step, between database creation and migration) creates the directory skeleton the bootstrapper itself never does: `app/public`, `framework/{cache/data,sessions,views,testing}`, `logs` under the tenant's `storage_path()`, plus the `public`/`private` disk roots. Idempotent (`is_dir()`/`Storage::exists()` guards), safe to re-run after a partial-failure retry (proven by a dedicated test).
3. **`Platform\Tenancy\Listeners\RetargetImageCachePaths`** — a new listener hooked to `Events\TenancyBootstrapped` (fires *after* every bootstrapper, including Filesystem, has run) and `Events\RevertedToCentralContext`, recomputing `config('imagecache.paths')` fresh each time. Same fix shape as R17: don't touch the frozen config file, recompute the value from our own code once tenancy has actually finished initializing. **Proven correct in isolation** (`TenantImageCacheIsolationTest.php`'s first test: `config('imagecache.paths')` inside `tenant->run()` resolves to each tenant's own, distinct `storage_path('app/public')`) — but see "The ImageCache route blocker" below for why this alone does not make the real resize route tenant-aware.
4. **A new `/storage/{path}` route** (`routes/tenant.php`), reusing `Stancl\Tenancy\Controllers\TenantAssetsController` unmodified (no new controller written) — it already does exactly what's needed: `response()->file(storage_path("app/public/$path"))` with real path-traversal protection (`realpath()` + a `startsWith($allowedRoot)` check), verified live against 4 traversal payloads. This route matters because `Storage::url()` on the `public` disk generates `APP_URL.'/storage/{path}'` (a static URL pattern, `config('filesystems.disks.public.url')` is *not* touched by `FilesystemTenancyBootstrapper`) — in stock Laravel that's served by the OS-level `public/storage` symlink, which points at the *central* `storage/app/public`, not any tenant's suffixed directory. Since tenant files are never written under that central path anymore (all writes go through `Storage::`, which is now correctly tenant-scoped), the symlink target is simply empty of tenant content and a real reverse proxy's static-file check naturally falls through to PHP/this route — see "Production note" below.

## Public file serving strategy

Every consumer's *write* path (`Storage::put`/`store`) was already tenant-safe once the disk root is remapped — no changes needed. The *read*/serve path needed the `/storage/{path}` route above specifically because `Storage::url()`'s output string doesn't reflect the remapped root. Verified live, real HTTP requests: `tenant-a.localhost/storage/{path}` and `tenant-b.localhost/storage/{path}` for the *identical logical path* return each tenant's own, distinct content; `unknown.localhost` and a non-existent tenant subdomain both 404.

## Theme customization asset path — now independently verified

`Webkul\Theme\Repositories\ThemeCustomizationRepository::uploadImage()` stores `'image' => 'storage/'.$path` — a `storage/`-prefixed string baked into the DB at write time, unlike every other consumer (which store a bare relative path and call `Storage::url()` at render time). Originally flagged as "expected to work, not independently tested." Now independently verified: `TenantStorageIsolationTest.php`'s dedicated test calls the real `ThemeCustomizationRepository::uploadImage()` (not a substitute) for two tenants with distinct-colored images at the DB layer, confirms the stored `storage/theme/{id}/{file}` value resolves through the real `/storage/{path}` route to each tenant's own correct file, and confirms tenant B cannot reach tenant A's exact stored path. One real, pre-existing Bagisto precondition surfaced while building this test (unrelated to tenancy): `uploadImage()` calls `$theme->translate($locale)`, which returns `null` unless a translation row already exists for that locale (it does not use `translateOrNew()`) — true of the real admin UI flow as well, since `create()` and `uploadImage()` are always two separate HTTP requests and nothing else creates that row first. The test reproduces this precondition explicitly (creating a translation row via direct property assignment, since `ThemeCustomizationTranslation::$fillable` deliberately excludes `locale` from mass assignment) rather than working around it.

## The ImageCache route fix (R25) — resolved, narrowest available mechanism

Building the required end-to-end test for R24 (two tenants, real distinguishable images at the identical relative path, real HTTP requests to the actual `cache/{template}/{filename}` route on `tenant-a.localhost`/`tenant-b.localhost`) originally surfaced a concrete blocker, confirmed two independent ways:

1. **Direct route inspection** (`php artisan tinker`, reading the live `Illuminate\Routing\Route` object registered under the name `imagecache`): `->middleware()` returned `[]`. `Webkul\ImageCache\Providers\ImageCacheServiceProvider::bootImageCache()` registers this route directly on the router (`$this->app['router']->get(...)`) from inside a service provider's `boot()`, entirely outside `routes/web.php` and outside any `Route::group()`. This app's tenant resolution (`Stancl\Tenancy\Middleware\InitializeTenancyByDomain`) is only prepended to the `'web'` middleware **group** (`bootstrap/app.php`), not appended globally — so this specific route never ran it, for any Host header.
2. **Live HTTP proof**: two tenants each write a distinct, real, decodable PNG to the identical relative path. A real HTTP GET to `tenant-a.localhost/cache/small/{path}` and `tenant-b.localhost/cache/small/{path}` **both returned 404** — neither tenant's image was reachable. As a control, writing the same file to the CENTRAL (non-tenant) disk path and repeating the identical request returned 200 — proving the route/controller mechanism itself was sound, and the only missing ingredient was tenant context.

This was **not a cross-tenant leak** (no tenant could see another tenant's image through this route either) — it was a **total functional failure of the real resize route under tenancy**. Two candidate fixes were identified; the broad one (making tenancy middleware truly global, `bootstrap/app.php`, `$middleware->append(...)` instead of `prependToGroup('web', ...)`) would also have changed behavior for `/up` (the health check) and every other package registering routes the same direct-router way — an unaudited blast radius. Per explicit product-owner direction, this was rejected in favor of the narrow option:

### The fix: attach middleware to one named route, after every provider has booted

`Platform\Tenancy\Providers\TenancyServiceProvider::attachTenancyToImageCacheRoute()` (new method, called from `boot()`):

```php
protected function attachTenancyToImageCacheRoute(): void
{
    $this->app->booted(function () {
        $route = $this->app['router']->getRoutes()->getByName('imagecache');

        if (! $route) {
            return;
        }

        $route->middleware([
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
        ]);
    });
}
```

Two things make this the narrowest possible fix, not a workaround:

- **`$this->app->booted(...)` is the correct, already-precedented extension point.** This file's own `mapRoutes()` method (registering `routes/tenant.php`) already uses the identical mechanism. Laravel boots every service provider's `register()`, then every provider's `boot()` — in registration order — and only *then* fires `booted` callbacks. `TenancyServiceProvider` is registered before `Webkul\ImageCache`'s provider (per R9's ordering requirement), so if this ran directly inside `boot()` instead, the `imagecache` route would not exist yet and the lookup would silently no-op. Wrapping it in `booted()` guarantees every provider's routes already exist, regardless of registration order.
- **`Route::middleware()` called on an already-registered route object is standard, supported Laravel API** — not a monkey-patch. It appends to that one route's middleware list; no other route, and no middleware group, is touched.

`PreventAccessFromCentralDomains` is attached alongside `InitializeTenancyByDomain` deliberately: ImageCache serves only tenant-owned media, and no real central/platform route consumes this endpoint today (no platform admin UI exists yet — see `docs/architecture/domain-routing.md`). Central-domain requests now receive a controlled 404 rather than ever falling back to central storage content. The one template this affects that doesn't touch tenant storage at all (`'logo'`, which fetches Bagisto's own remote logo over HTTP) is not carved out specially — if a future central platform UI needs it, this policy should be revisited then, not worked around preemptively for a use case that doesn't exist yet.

### Verification

`tests/Feature/Platform/TenantImageCacheIsolationTest.php`, 5 tests, all real HTTP requests through the real route, no mocking:

1. The `RetargetImageCachePaths` listener correctly retargets `imagecache.paths` per tenant (proven independent of routing).
2. A real request to `cache/small/{path}` returns 200 for both tenants at the identical relative path, and each response is decoded and pixel-compared to prove it's genuinely derived from that tenant's own source image, not the other's or a shared/default one.
3. Repeated requests never go stale or leak: changing tenant A's source and re-requesting immediately reflects the change (there is no actual server-side resize-cache file in the real code path — see the note below — so this is the meaningful, real version of "no cache collision"); a filename that only ever existed for tenant B is unreachable from tenant A's domain, and vice versa.
4. An unknown domain gets 404.
5. Both configured central domains (`localhost`, `127.0.0.1`) get 404.

**A note on "cached resized files":** `Webkul\ImageCache\Http\Controllers\ImageCacheController` never persists a resized image to disk anywhere — it reads the source, resizes in memory, and returns it with only HTTP `Cache-Control`/`ETag` headers (browser-side caching). A separate class, `Webkul\ImageCache\ImageCache`, does use Laravel's `Cache` repository to persist encoded bytes by checksum — but a full-repo grep confirmed it is dead code, never instantiated anywhere in `packages/`. Testing "physical cache file isolation" against a mechanism that doesn't exist in the real request path would have meant fabricating behavior rather than proving it — so this document states that finding plainly instead.

## Storage symlink — production note

`php artisan storage:link` creates `public/storage -> storage/app/public` at the OS level. A real reverse proxy (Nginx/Apache) typically serves an existing static file at that path *before* the request ever reaches PHP — which would bypass the new `/storage/{path}` route entirely for any file that happens to exist there. This is not a problem in this architecture specifically because tenant files are never written under the *central*, non-suffixed `storage/app/public` anymore (all writes go through the now-tenant-remapped `Storage::` disk) — so the symlink target stays empty of tenant content, the static-file check misses, and the request falls through to PHP/the new route correctly. **Do not run `storage:link` as a tenant-provisioning step** (it's irrelevant/inert for tenant content either way); if it's present from a stock Bagisto install script, it's harmless but should not be relied upon.

## Object storage (S3) — future-proofing, not implemented

Nothing in this design is local-filesystem-specific. `config('tenancy.filesystem.disks')` already supports `'s3'` (commented out, not enabled here per the task's explicit scope). The same tenant-namespace principle maps directly: an S3-backed tenant disk would prefix the *bucket key* with `tenants/{tenant-id}/` instead of suffixing a local directory — `FilesystemTenancyBootstrapper`'s `root_override` mechanism supports this without any application code change, only a config addition, when that migration is actually undertaken (not now).

## Global vs. tenant storage classification

- **Global**: framework/build assets (`public/themes/*/build/`, never touched by any of this), `.env` (install-time only, never used for tenant provisioning), Admin's streamed product export (never persisted to any disk).
- **Tenant-owned**: everything else found in the audit — see the table above. All of it is isolated automatically by the disk-root remap; none of it required a `packages/Webkul` change.
- **Temporary/job**: `DataTransfer`'s `imports/{id}/processed/*` (self-cleaning, already disciplined in stock Bagisto), the downloadable-link "external URL" OS-temp-dir copy (low risk, single-request lifecycle).

**A real production defect this classification predicts, and that TASK-MVP-004
found and fixed (RISK_REGISTER.md R63):** the "Global" build assets above are
referenced by Bagisto's own Blade views via the plain `asset()` helper - and
`config/tenancy.php`'s `filesystem.asset_helper_tenancy` (a stancl/tenancy
package default, left at its shipped `true` value from this project's very
first multi-tenancy commit) rewrites EVERY `asset()` call during a tenant
request to point at the tenant-storage asset route instead, regardless of
whether the thing being referenced is actually tenant-owned. Global build
assets were never supposed to go through that route at all - this is now set
to `false`. Nothing above changes: `Storage::url()` calls for genuinely
tenant-owned content (product images, theme uploads, etc.) never went through
`asset_helper_tenancy` in the first place - they resolve via the separate,
deliberately-registered `/storage/{path}` route (see above), which this flag
does not affect either way.

## Logs

Verified: `storage/logs` lives under the tenant-suffixed `storage_path()` once `FilesystemTenancyBootstrapper` is active (same mechanism as `framework/*`), and `ensureFilesystemPrepared()` creates it during provisioning. This means **Laravel's default log channel writes tenant-request logs into that tenant's own `storage/logs`**, not a shared central log. This was not a deliberate design goal of this task (no logging redesign was in scope) but is a direct, correct consequence of the chosen mechanism — flagged explicitly per the task's own instruction to verify this rather than assume. Platform-wide *operational* logging (needed regardless of any single tenant) is a separate, deferred observability decision — central application boot/console logs (anything logged before tenancy initializes, or during central-context requests) are unaffected and continue writing to the central `storage/logs`.

## Cache regression

TASK-ARCH-004's cache isolation was re-verified after every filesystem change (not assumed intact) — `CacheTenancyBootstrapper` was never disabled or altered. All prior cache tests plus the new filesystem and image-cache tests pass together: 30/30, 149 assertions (see `tests/Feature/Platform/`).

## `.gitignore` and test cleanup

`Storage_path()` suffixing creates real directories on disk at `storage/tenant{id}/...`, inside the repository working tree. `/storage/tenant*/` in `.gitignore` (same convention as the existing `storage/framework/cache/data/.gitignore`-style entries) is a **backstop**, not the primary cleanup mechanism — every test that provisions its own throwaway tenant is responsible for removing that tenant's physical storage root when it finishes, not just its database rows. This was a real gap found during finalization: the "provisioning creates the required tenant storage skeleton safely" test (`TenantStorageIsolationTest.php`) cleaned up its dedicated `tenant-storage-c` tenant's database/user/domain rows but left `storage/tenanttenant-storage-c/` behind on disk. Fixed with `Illuminate\Support\Facades\File::deleteDirectory(storage_path('tenant'.$tenant->id))` alongside the existing DB cleanup. The shared `tenant-a`/`tenant-b`/`tenant-prov-a`/`tenant-prov-b` fixtures reused across the whole Platform suite (a deliberate, separately-documented design predating this task, for cheap idempotent reuse across test files) are intentionally *not* torn down between runs — `cleanupStorageTestTenants()`/`cleanupTestTenants()` remain available in their respective files for manual full-reset use.
