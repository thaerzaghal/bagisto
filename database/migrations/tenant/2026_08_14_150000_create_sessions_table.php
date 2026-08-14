<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-010 (RISK_REGISTER.md R33). TENANT-only migration - lives in
 * database/migrations/tenant/, registered by Platform\Tenancy\Providers\
 * TenancyServiceProvider::boot() via loadMigrationsFrom(database_path
 * ('migrations/tenant')), which (like every packages/Webkul/*
 * registration) feeds app('migrator')->paths() -
 * Platform\Tenancy\Services\TenantProvisioner::ensureMigrated() picks it
 * up automatically for every tenant, with zero TenantProvisioner code
 * change needed.
 *
 * Root cause this fixes: `SESSION_DRIVER=database` (the real .env value
 * - phpunit.xml overrides it to 'array' for the whole Platform test
 * suite, which is exactly why this was invisible until now) makes
 * Illuminate\Session\Middleware\StartSession - part of the 'web'
 * middleware group every Bagisto Shop/Admin route runs under - read/
 * write the `sessions` table on whatever the CURRENT default connection
 * is. Stancl\Tenancy\Middleware\InitializeTenancyByDomain runs earlier
 * in the same middleware pipeline and has already swapped that
 * connection to the tenant's own database by the time StartSession
 * runs - but no tenant database had a `sessions` table at all, because
 * the central-only `database/migrations/create_sessions_table.php`
 * (stock Laravel scaffolding) was never in scope for
 * TenantProvisioner::ensureMigrated() (app('migrator')->paths() never
 * includes database_path('migrations') root - the same mechanism R17
 * relies on to keep central-only tables OUT of tenant databases, here
 * cutting the other way for a table that DOES need to be tenant-scoped).
 * Confirmed live: a real request to a freshly provisioned tenant's
 * storefront homepage under session.driver=database threw
 * Illuminate\Database\QueryException, "Base table or view not found:
 * 1146 Table '{tenant_db}.sessions' doesn't exist", from
 * DatabaseSessionHandler::read() - not a CMS/theme/channel/seeding
 * issue, and not specific to the storefront (every 'web'-group tenant
 * route, including Admin, hits the identical failure under the real
 * session driver).
 *
 * Schema is an exact copy of the central sessions table
 * (database/migrations/2026_02_03_151924_create_sessions_table.php) -
 * `user_id` uses foreignId() without ->constrained(), so it is a plain
 * indexed column, not an actual foreign key - safe in a tenant database
 * that has no `users` table (Bagisto doesn't use Laravel's generic
 * `users` table at all; it has its own `admins`/`customers`).
 *
 * The CENTRAL sessions table is left exactly as it was - not removed,
 * not touched - it predates this task and nothing here needs to
 * disturb it (see RISK_REGISTER.md R33 for why it stays, currently
 * unused, harmless).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
