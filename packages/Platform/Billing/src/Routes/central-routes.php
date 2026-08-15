<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Platform\Billing\Http\Controllers\StripeWebhookController;

/**
 * TASK-ARCH-019. Registered by BillingServiceProvider::boot() under the
 * 'platform' middleware group (web state minus InitializeTenancyByDomain
 * - see that group's own definition in bootstrap/app.php) - no tenant DB
 * connection is ever established for this request. `billing/webhook/*`
 * is CSRF-exempted in bootstrap/app.php (a distinct path from the
 * pre-existing `stripe/*` exemption, which belongs to
 * packages/Webkul/Stripe's own, unrelated storefront-order webhook -
 * never reused here to avoid any collision with that existing route).
 */
Route::post('billing/webhook/stripe', StripeWebhookController::class)->name('billing.webhook.stripe');
