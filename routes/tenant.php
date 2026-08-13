<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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
});
