<?php

use Illuminate\Support\Facades\Route;
use Platform\Plans\Http\Controllers\Admin\MyPlanController;

/**
 * TASK-ARCH-009. Registered by PlansServiceProvider::boot() inside the
 * exact same middleware stack Webkul\Admin\Providers\AdminServiceProvider
 * uses for its own routes (see that provider's boot() and
 * Resources/Routes/web.php) - 'web' + PreventRequestsDuringMaintenance
 * for the outer request lifecycle, 'admin' (Webkul\User\Http\Middleware\
 * Bouncer - session auth + ACL enforcement) + NoCacheMiddleware for the
 * admin-panel-specific behavior, under the same `config('app.admin_url')`
 * prefix. This is what makes an unauthenticated request to this route
 * behave identically to any other Bagisto admin route (redirect to
 * admin.session.create), and what makes the route require a matching
 * `acl.php` entry (Config/acl.php in this same package) or fail closed
 * with 401 - see Webkul\User\Http\Middleware\Bouncer::checkIfAuthorized().
 */
Route::get('saas/plan', [MyPlanController::class, 'index'])->name('admin.saas.plan.index');
