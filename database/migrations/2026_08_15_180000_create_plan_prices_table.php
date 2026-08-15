<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-018. Central-only (see Platform\Billing\Models\PlanPrice's own
 * docblock) - never a tenant-schema migration, matching every other
 * Platform table (plans/plan_features/subscriptions).
 *
 * A SEPARATE table from `plans`, not price_monthly/price_yearly columns on
 * Plan itself - the task's own explicit preference, and the only design
 * that cleanly supports more than exactly two fixed intervals later
 * without a schema change (task section 8/9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_interval');
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['plan_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
