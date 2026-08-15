<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-016 (task section 8). Central-only data backfill, runs once
 * via `php artisan migrate` immediately after `create_subscriptions_table`
 * (matching RISK_REGISTER.md R31's own precedent: `2026_08_14_140000_
 * backfill_tenant_real_columns_from_legacy_data.php` - a plain data
 * migration for exactly this "new column/table, need to backfill
 * existing central rows" shape).
 *
 * HISTORICAL MIGRATION STABILITY (reviewed and deliberately fixed after
 * the original TASK-ARCH-016 report): this `up()` method is now
 * self-contained rather than calling `Platform\Subscriptions\Services\
 * SubscriptionBackfill::run()`. A migration, once shipped, must keep
 * producing the SAME result on a brand-new installation running every
 * migration from scratch in the future, even if application code evolves
 * in the meantime - `SubscriptionBackfill` is a real, reusable SERVICE
 * CLASS that encodes actual interpretive decisions (which status to
 * assign, which anchor date to use, which tenants to skip) that could
 * legitimately be revised later as the Subscription domain matures. If
 * this migration kept calling it, a fresh install today and a fresh
 * install after some future, unrelated change to `SubscriptionBackfill`
 * would silently backfill DIFFERENT data from the SAME already-shipped
 * migration file - exactly the kind of drift a migration must never
 * have. `SubscriptionBackfill` itself is unchanged and still exists,
 * still fully tested (`tests/Feature/Platform/
 * PlatformSubscriptionManagementTest.php` tests 3-4 call it directly) -
 * it is simply no longer invoked FROM a migration, only from tests and
 * (potentially) a future on-demand repair command, mirroring this
 * project's own established "one-time migration + separate repeatable
 * repair tool" split (`platform:tenants:migrate-pending`, TASK-ARCH-010).
 *
 * This inlined copy references only `Tenant`/`Subscription`
 * (plain Eloquent models - the same category of dependency R31's own
 * migration already established as acceptable, since a model's basic
 * read/write contract for its own table is foundational, not a business
 * decision likely to change) and `SubscriptionStatus` (a small, closed,
 * effectively schema-adjacent enum - see that enum's own docblock). It
 * deliberately does NOT reference `SubscriptionLifecycle`/
 * `TenantPlanAssignment`/`SubscriptionBackfill` - none of those services'
 * behavior is depended upon here.
 *
 * Idempotent, safe to rerun: `firstOrCreate()`, matching TASK-ARCH-015's
 * `PlanSeeder` fix. Tenants with `plan_id IS NULL` are skipped, not given
 * an invented plan (task section 8, explicit). `starts_at = tenant.
 * created_at` (not `now()`) - the most defensible available historical
 * anchor; this is NOT a claim the tenant historically paid for the plan,
 * only "the current SaaS entitlement/lifecycle baseline at the moment
 * the Subscription domain was introduced" - see docs/architecture/
 * subscriptions.md "Existing-tenant backfill".
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->whereNotNull('plan_id')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                Subscription::firstOrCreate(
                    ['tenant_id' => $tenant->getTenantKey()],
                    [
                        'plan_id' => $tenant->plan_id,
                        'status' => SubscriptionStatus::Active,
                        'starts_at' => $tenant->created_at,
                    ]
                );
            }
        });
    }

    /**
     * Deliberately a no-op, matching R31's backfill migration precedent:
     * this migration only ever CREATES rows in a table that
     * `create_subscriptions_table`'s own down() already drops entirely -
     * there is nothing this migration alone needs to reverse, and
     * reversing it independently (deleting every backfilled row while
     * leaving manually-created ones) is not a meaningful operation.
     */
    public function down(): void
    {
        //
    }
};
