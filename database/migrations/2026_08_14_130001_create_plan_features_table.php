<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-008. Central-database-only, see create_plans_table's docblock
 * for why this lives in database/migrations (root) rather than a
 * loadMigrationsFrom()-registered package path.
 *
 * Generic key-value entitlement design (docs/architecture/feature-limits.md,
 * originally sketched Phase 0): one row per (plan, feature_code). `type`
 * distinguishes three semantics: 'boolean' (value is 0/1), 'numeric'
 * (value is the integer cap), 'unlimited' (value is ignored/null - no cap).
 * A single nullable `value` column, not three type-specific columns,
 * matches the already-signed-off Phase 0 schema in database-per-tenant.md
 * ("value (nullable numeric)") rather than introducing a different shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_code');
            $table->string('type');
            $table->integer('value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
