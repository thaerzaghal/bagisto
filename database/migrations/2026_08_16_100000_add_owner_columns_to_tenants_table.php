<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-MVP-001. `owner_name`/`owner_email` identify the merchant who
 * self-registered this tenant - central-only contact/uniqueness metadata,
 * NOT a second authentication system (the merchant's real login identity
 * remains a `Webkul\User\Models\Admin` row inside their own tenant
 * database, unchanged - see Platform\Tenancy\Services\TenantProvisioner::
 * ensureOwnerAdminSeeded()). `owner_email` is unique so one email can only
 * ever own one self-service signup (a nullable unique index still allows
 * any number of NULLs - every tenant created before this task, and any
 * still created via `tenant:provision` without `--domain`-style owner
 * info, is unaffected).
 *
 * Both columns MUST be added to Platform\Tenancy\Models\Tenant::
 * getCustomColumns() (done in the same commit) - see RISK_REGISTER.md R31
 * for why: Stancl\VirtualColumn silently redirects any attribute not
 * named there into the `data` JSON blob instead of these real columns,
 * exactly the bug this project already hit once and fixed for
 * `status`/`last_error`/`plan_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('plan_id');
            $table->string('owner_email')->nullable()->unique()->after('owner_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['owner_email']);
            $table->dropColumn(['owner_name', 'owner_email']);
        });
    }
};
