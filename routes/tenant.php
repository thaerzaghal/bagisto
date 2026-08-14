<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Controllers\TenantAssetsController;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| TASK-ARCH-003: tenant domain resolution (InitializeTenancyByDomain) is now
| applied globally to the 'web' middleware group in bootstrap/app.php, so it
| no longer needs to be (and no longer is) declared again here - see that
| file for the full ordering rationale. This file now only needs the plain
| 'web' group like any other route file. Real tenant-facing routing
| (delegating to Bagisto's own already-tenant-aware Shop/Admin routes) is
| what's actually exercised in tests/Feature/Platform/TenantDomainRoutingTest.php;
| this placeholder route is kept only as a minimal smoke test that a request
| reaching THIS file also has a resolved tenant.
|
*/

Route::middleware(['web'])->group(function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });

    /*
    |----------------------------------------------------------------------
    | TASK-ARCH-005 (R16): tenant-aware "/storage/{path}" serving
    |----------------------------------------------------------------------
    | Every Bagisto consumer that renders an image (Storage::url() on the
    | `public` disk) generates a URL like APP_URL.'/storage/product/1/x.webp'
    | (config/filesystems.php: 'public'.'url' => env('APP_URL').'/storage') -
    | that URL string is NOT affected by FilesystemTenancyBootstrapper (it
    | only remaps disk ROOTS, not the static .url config), and in stock
    | Laravel that path is served by the OS-level 'public/storage' symlink
    | (public_path('storage') -> storage_path('app/public')) - which, once
    | tenant storage lives at storage/tenant{id}/app/public instead, points
    | at the wrong (central, now-empty-of-tenant-content) directory.
    |
    | Rather than inventing a new controller, this reuses stancl/tenancy's
    | own Stancl\Tenancy\Controllers\TenantAssetsController - it already does
    | exactly what's needed: response()->file(storage_path("app/public/$path"))
    | with real path-traversal protection (realpath() + startsWith() check,
    | see the class itself), which is tenant-correct automatically since
    | storage_path() is suffixed by the time this route's middleware
    | (InitializeTenancyByDomain, global per TASK-ARCH-003) has run. Also
    | fixes Webkul\Theme\Repositories\ThemeCustomizationRepository's slider
    | images, which hardcode a 'storage/'.$path DB value (see
    | docs/architecture/storage.md) - same URL scheme, now tenant-correct too.
    |
    | Production note: this route only takes effect for requests that reach
    | Laravel at all. A real reverse proxy serving the 'public/storage'
    | symlink as a static file BEFORE proxying to PHP would bypass this route
    | entirely - but since tenant files are no longer written under the
    | symlink's (central) target at all, no static file exists there to
    | serve, so the proxy's static-file check naturally falls through to
    | PHP/this route. See docs/architecture/storage.md for the full
    | production-deployment note (do not rely on `storage:link` for tenant
    | content).
    |
    */
    Route::get('/storage/{path}', [TenantAssetsController::class, 'asset'])
        ->where('path', '.*')
        ->name('tenant.storage.asset');
});
