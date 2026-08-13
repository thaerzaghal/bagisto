# Storage Isolation

## Current state (verified)

`config/filesystems.php` defines `local` (root `storage/app`), `private` (root `storage/app/private`), `public` (root `storage/app/public`, symlinked to `public/storage`), and `s3`. Default disk: `env('FILESYSTEM_DISK', 'public')`.

Product images (`Webkul\Product\ProductImage`) consistently use the `Storage` facade abstraction (`Storage::has()`, `Storage::url()`, `Storage::getAdapter()`) with **relative paths stored in the database** — never absolute or hardcoded paths. `Webkul\ImageCache` serves resized variants via a route that reads from a fixed disk root. `Webkul\DataTransfer` (import/export) uses the `private` disk extensively for CSV/XLS/XLSX/XML sources and downloaded images during import, again via relative paths.

**This consistent use of the Storage abstraction, with no hardcoded tenant-unaware absolute paths anywhere, is exactly what makes tenant isolation here a configuration change rather than a code change.**

## Isolation strategy

`stancl/tenancy`'s `FilesystemTenancyBootstrapper` prefixes local-disk roots (e.g. `storage/app/public` → `storage/tenants/{tenant-id}/app/public`) and/or S3 key prefixes automatically per tenant, at the disk-resolution level — since every call site already goes through `Storage::disk(...)`/the default disk rather than raw paths, no application code changes are required in `ProductImage`, `DataTransfer`, or anywhere else.

## Verification required (not just trust-by-inspection)

- `Webkul\ImageCache\Http\Controllers\ImageCacheController` resolves a disk root to serve cached/resized images — confirm this controller re-resolves the disk *after* tenancy has bootstrapped (i.e., per-request, not cached at boot), since a resolved-once-at-boot disk instance would defeat the tenant prefixing. This is a Phase 12 test, not assumed safe from static reading alone.
- The `public` disk's symlink (`public_path('storage') → storage_path('app/public')`) is a single fixed OS-level symlink — if `FilesystemTenancyBootstrapper` prefixes the *disk root* underneath that symlink (e.g. `storage/app/public/tenants/{id}/...`), the existing symlink still works unmodified; if instead it creates entirely separate top-level directories outside `storage/app/public`, the symlink and any hardcoded `public/storage` URL assumptions elsewhere would break. Confirm which strategy the bootstrapper uses and align the URL-generation path (`Storage::url()`, already used everywhere) accordingly — this should resolve itself automatically since `Storage::url()` is disk-config-driven, but must be tested, not assumed.

## Scope

Applies to: product/category images, uploaded files (import sources), generated exports, logos, any theme customization assets stored via Bagisto's `Webkul\Theme` package. Does not apply to Vite-compiled frontend assets (`public/themes/*/build/`) — those are shared, platform-wide, not tenant data (per `AGENTS.md`'s "Do Not Edit" list, these are build output, identical for every tenant using the default theme).
