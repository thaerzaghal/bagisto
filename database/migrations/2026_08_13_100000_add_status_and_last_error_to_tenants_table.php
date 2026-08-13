<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-database-only migration (lives alongside stancl's own
 * create_tenants_table/create_domains_table, never registered via
 * loadMigrationsFrom - see packages/Platform/Tenancy's ServiceProvider
 * for why that distinction matters: anything auto-discovered there is
 * treated as a TENANT migration by TenantProvisioner's dynamic path
 * discovery, and this migration must never run against a tenant database).
 *
 * Adds the two real (queryable/indexable) columns TASK-ARCH-002 needs.
 * Everything else about a tenant (db_name/db_username/db_password, and any
 * other future metadata) is stored in the existing `data` JSON column via
 * stancl's own HasDataColumn mechanism - no schema change needed for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('id');
            $table->text('last_error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_error']);
        });
    }
};
