<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-018. Central-only, matching every other Platform billing/
 * subscription table - never a tenant-schema migration.
 *
 * `subscription_id`/`plan_price_id` are both NULLABLE (task section 5's
 * own "nullable if justified"): `plan_price_id` nullOnDelete because
 * `amount_minor`/`currency` on THIS row are the authoritative historical
 * snapshot (task section 10) - a deleted/changed PlanPrice must never
 * rewrite or orphan a Payment's own money data, so the FK is purely
 * traceability metadata, safe to null out. `subscription_id` nullOnDelete
 * for the same reason plus forward-compatibility: nothing deletes a
 * Subscription row today, but Payment should not assume every payment
 * this platform will ever need to record is necessarily tied to a
 * still-existing Subscription (see PaymentLifecycle's own docblock).
 *
 * NO STRIPE-SPECIFIC COLUMNS (task section 18, strict): `provider` is a
 * plain string ('stripe' today), `provider_reference` is a single
 * provider-neutral column holding whatever ID that provider uses for this
 * payment attempt (Stripe's PaymentIntent ID today) - no `stripe_id`/
 * `payment_intent_id`/`checkout_session_id` column exists. A future bank
 * adapter reuses the exact same two columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Raw provider payload, audit/debugging only (task section 5,
            // explicit) - no business logic anywhere reads this column;
            // amount_minor/currency/status above are the authoritative
            // domain data.
            $table->json('provider_metadata')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
