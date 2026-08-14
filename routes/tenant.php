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
| what's actually exercised in tests/Feature/Platform/TenantDomainRoutingTest.php.
|
| TASK-ARCH-009 (RISK_REGISTER.md R32): this file used to also register a
| placeholder `Route::get('/', function () {...})` smoke-test route, kept
| "only as a minimal smoke test that a request reaching THIS file also has
| a resolved tenant" per its own original comment. Removed here - found
| live, the hard way, that it was silently destroying Bagisto's real
| storefront homepage route (`shop.home.index`, packages/Webkul/Shop/src/
| Routes/store-front-routes.php) for every tenant, this whole engagement.
| Root cause: this file is loaded via TenancyServiceProvider::mapRoutes()'s
| $this->app->booted(...) callback, which fires AFTER every other package's
| boot() (including Shop's) - so this placeholder's identical, unnamed
| `GET /` registration was always the LAST one Laravel's RouteCollection
| saw for that exact URI+method, completely replacing (not merely
| shadowing) Shop's named, real route at the same array key - `Route::has
| ('shop.home.index')` returned false because the named route object no
| longer existed in the collection at all, not because it was merely
| unreachable. Invisible until now because no test in this engagement had
| ever rendered a page needing `route('shop.home.index')` (the admin
| layout's own header does, surfaced by TASK-ARCH-009's first real admin
| page) or asserted on the real storefront homepage's actual content
| (existing tests only ever hit API endpoints, never bare `/`). The
| placeholder's own stated purpose (prove a request reaching this file has
| a resolved tenant) has been fully superseded by real tests since
| TASK-ARCH-003 (TenantDomainRoutingTest.php etc., against real Bagisto
| endpoints) - nothing else in this file needs to occupy `/` at all.
|
*/

Route::middleware(['web'])->group(function () {
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
