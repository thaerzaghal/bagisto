<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-011. CENTRAL-only migration - lives in database/migrations/
 * root, exactly like `tenants`/`domains`/`plans`, and is therefore NEVER
 * picked up by Platform\Tenancy\Services\TenantProvisioner::ensureMigrated()
 * (which only ever scans packages/Webkul/*'s own migration directories plus
 * database/migrations/tenant/ - see RISK_REGISTER.md R17/R33 for the exact
 * mechanism). Applied only via `php artisan platform:migrate:central`
 * (Platform\Tenancy\Console\Commands\MigrateCentral, TASK-ARCH-008/R30).
 *
 * `platform_users` is the identity table for PLATFORM admins - the people
 * who manage tenants/plans/provisioning for the SaaS business itself - and
 * is completely distinct from Bagisto's own tenant-scoped `admins` table
 * (one per tenant database, for that merchant's store staff). A platform
 * admin row here has no relationship whatsoever to any tenant's `admins`
 * rows; nothing in this migration or its model ever writes to a tenant
 * database. See docs/architecture/platform-admin.md for the full boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
