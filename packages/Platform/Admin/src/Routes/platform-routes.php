<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Admin\Http\Controllers\DashboardController;
use Platform\Admin\Http\Controllers\PlanController;
use Platform\Admin\Http\Controllers\PlanFeatureController;
use Platform\Admin\Http\Controllers\SessionController;
use Platform\Admin\Http\Controllers\TenantController;
use Platform\Admin\Http\Middleware\Authenticate;
use Platform\Admin\Http\Middleware\EnsureCentralDomain;

/**
 * TASK-ARCH-011. Registered by Platform\Admin\Providers\
 * PlatformAdminServiceProvider::boot() under the 'platform' middleware
 * group (bootstrap/app.php - the framework's own 'web' group minus
 * `InitializeTenancyByDomain`, see that file's own comment) plus
 * EnsureCentralDomain (host allowlist check). Neither of those group
 * names is 'web' - Bagisto's Shop/Admin routes and this file share no
 * middleware group, so a change to one cannot silently affect the other.
 *
 * Every route here lives under the `platform` guard (`auth:platform` via
 * the local Authenticate override, see that class) except the login
 * routes themselves.
 */
Route::middleware([EnsureCentralDomain::class, 'platform'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('login', [SessionController::class, 'create'])->name('login');
        Route::post('login', [SessionController::class, 'store'])->name('login.store');

        Route::middleware([Authenticate::class.':platform'])->group(function () {
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
            Route::post('tenants/{tenant}/provision', [TenantController::class, 'provision'])->name('tenants.provision');
            Route::post('tenants/{tenant}/migrate-pending', [TenantController::class, 'migratePending'])->name('tenants.migrate-pending');
            Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');
            Route::post('tenants/{tenant}/change-plan', [TenantController::class, 'changePlan'])->name('tenants.change-plan');

            // TASK-ARCH-015. GET routes ('plans.index'/'plans.create'/
            // 'plans.show') must be declared before 'plans/{plan}' would
            // otherwise be ambiguous with 'plans/create' - Laravel resolves
            // routes in registration order, so 'plans/create' is declared
            // first to avoid it being swallowed by the '{plan}' wildcard.
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
            Route::patch('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
            Route::post('plans/{plan}/activate', [PlanController::class, 'activate'])->name('plans.activate');
            Route::post('plans/{plan}/deactivate', [PlanController::class, 'deactivate'])->name('plans.deactivate');

            Route::post('plans/{plan}/features', [PlanFeatureController::class, 'store'])->name('plans.features.store');
            Route::patch('plans/{plan}/features/{feature}', [PlanFeatureController::class, 'update'])->name('plans.features.update');
            Route::delete('plans/{plan}/features/{feature}', [PlanFeatureController::class, 'destroy'])->name('plans.features.destroy');
        });
    });
