<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Signup\Http\Controllers\SignupController;
use Platform\Signup\Http\Controllers\SignupRetryController;
use Platform\Signup\Http\Middleware\EnsureCentralDomain;

/**
 * TASK-MVP-001. Registered by Platform\Signup\Providers\
 * SignupServiceProvider::boot() under the `platform` middleware group
 * (bootstrap/app.php - the `web` group minus `InitializeTenancyByDomain`)
 * plus this package's own `EnsureCentralDomain` - the identical shape
 * `Platform\Admin\Routes\platform-routes.php` already uses, so a
 * self-service signup request can never initialize tenancy regardless of
 * Host header.
 *
 * `throttle` is applied to every state-changing (POST) route only -
 * each successful submission creates a real MySQL database, so this is
 * a genuine resource-exhaustion control, not decoration.
 *
 * `signed` on both retry routes is the REQUIRED SECURITY ADJUSTMENT from
 * the approved plan - see SignupRetryController's own docblock.
 */
Route::middleware([EnsureCentralDomain::class, 'platform'])
    ->group(function () {
        Route::get('join', [SignupController::class, 'create'])->name('signup.create');

        Route::post('join', [SignupController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('signup.store');

        Route::get('join/retry/{tenant}', [SignupRetryController::class, 'show'])
            ->middleware('signed')
            ->name('signup.retry.show');

        Route::post('join/retry/{tenant}', [SignupRetryController::class, 'store'])
            ->middleware(['signed', 'throttle:10,1'])
            ->name('signup.retry.store');
    });
