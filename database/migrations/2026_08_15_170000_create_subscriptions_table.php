<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-016 (Provider-Agnostic Subscription Foundation). Central-
 * database-only migration - lives in `database/migrations/` root, exactly
 * like `tenants`/`domains`/`plans`/`plan_features`/`platform_users`, never
 * registered via a package's own `loadMigrationsFrom()`. See those
 * migrations' own docblocks for why this placement is load-bearing:
 * `Platform\Tenancy\Services\TenantProvisioner::ensureMigrated()` runs
 * `tenants:migrate --path=app('migrator')->paths()`, which structurally
 * never includes this root directory - so `subscriptions` can never be
 * swept into a tenant database by that mechanism.
 *
 * ONE CURRENT SUBSCRIPTION PER TENANT (task section 3): a database-level
 * invariant, not just an application convention - `unique('tenant_id')`
 * makes it structurally impossible for two subscription rows to exist for
 * the same tenant simultaneously, regardless of what application code
 * does. This is a deliberate foundation-task simplification (no
 * subscription history/versioning yet) - a canceled/expired subscription
 * is REUSED in place (reset to Active/Trialing) rather than superseded by
 * a new row, precisely because only one row per tenant can ever exist -
 * see `Platform\Subscriptions\Services\SubscriptionLifecycle::start()`'s
 * own docblock for the full "restart in place" reasoning.
 *
 * `tenant_id` is a `string` FK to `tenants.id` (stancl's own base
 * migration: `$table->string('id')->primary()`) - this is the FIRST
 * table in this codebase with a foreign key directly into `tenants`
 * (every other central table until now only referenced `plans`).
 * `cascadeOnDelete()` is deliberate: nothing in this codebase deletes a
 * tenant row today (TASK-ARCH-014's own docblock confirms `Deleting`/
 * `Deleted` remain unreachable states), so this is defensive/future-safe
 * rather than an exercised path - if a tenant row is ever hard-deleted,
 * its subscription row should not become an orphan requiring separate
 * cleanup.
 *
 * NO PROVIDER-SPECIFIC COLUMNS (task section 21, strict): no
 * `provider_customer_id`/`provider_subscription_id`/`stripe_id`/
 * `pm_type`/`pm_last_four`/`invoice_*`/currency/price column exists here
 * or anywhere in this task. Every column is provider-neutral lifecycle
 * metadata this application can set and interpret entirely on its own,
 * matching docs/architecture/billing.md's own "what Phase 11 will need
 * regardless of provider" boundary - those columns belong on a future,
 * separate `billing_accounts` table (that doc's own recommendation),
 * never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status');
            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // The database-level "one current subscription per tenant" invariant.
            $table->unique('tenant_id');

            // Supports the Platform Admin tenant-detail lookup and the
            // backfill/reporting query shape ("every subscription for a
            // given status") without a full table scan.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
