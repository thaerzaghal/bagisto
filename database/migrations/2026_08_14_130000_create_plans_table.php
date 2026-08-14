<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-008 (RISK_REGISTER.md/DECISION_LOG.md, Plan & Feature
 * Entitlement Foundation). Central-database-only migration, matching the
 * exact convention `2026_08_13_100000_add_status_and_last_error_to_tenants_
 * table.php` already documents: lives in database/migrations (root), never
 * registered via loadMigrationsFrom() from any package ServiceProvider.
 *
 * Why this matters here specifically: Platform\Tenancy\Services\
 * TenantProvisioner::ensureMigrated() runs `tenants:migrate` with
 * `--path` = app('migrator')->paths() - EVERY path any ServiceProvider
 * registered via loadMigrationsFrom() (Illuminate\Database\Migrations\
 * Migrator::paths(), confirmed by reading BaseCommand::getMigrationPaths()
 * back in TASK-ARCH-002/R17). If Plan's migrations were registered that
 * way from packages/Platform/Plans's own ServiceProvider (the normal,
 * self-contained-package convention every Webkul package and a naive new
 * Platform package would reach for), they would be swept into EVERY
 * TENANT database too - the exact violation this task's "domain boundary"
 * section explicitly warns against (SaaS plan definitions must never be
 * duplicated into tenant commerce databases). Root database/migrations is
 * the one migration location Migrator::paths() structurally never returns,
 * which is precisely why it is central-safe. See RISK_REGISTER.md R30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
