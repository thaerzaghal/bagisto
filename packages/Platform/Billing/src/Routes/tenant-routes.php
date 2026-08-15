<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Billing\Http\Controllers\Tenant\CheckoutController;

/**
 * TASK-ARCH-019. Registered by BillingServiceProvider::boot() under the
 * EXACT same middleware stack `Platform\Plans\Http\Controllers\Admin\
 * MyPlanController`'s own `admin-routes.php` uses (see that file's own
 * docblock for the full reasoning) - 'web' + PreventRequestsDuringMaintenance
 * + 'admin' (session auth + ACL) + NoCacheMiddleware, under
 * `config('app.admin_url')`. GET routes declared before the implicit
 * `checkout/{...}` shape is possible confusion is avoided entirely here
 * (no wildcard segment in this file at all).
 */
Route::prefix('saas/plan/checkout')->name('admin.saas.checkout.')->group(function () {
    Route::get('/', [CheckoutController::class, 'index'])->name('index');
    Route::post('/', [CheckoutController::class, 'store'])->name('store');
    Route::get('success', [CheckoutController::class, 'success'])->name('success');
    Route::get('cancel', [CheckoutController::class, 'cancel'])->name('cancel');
});
