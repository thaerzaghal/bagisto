<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Services;

use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-016 (task section 8). One-time (but safely rerunnable)
 * central-only backfill: every pre-existing tenant that already has
 * `tenants.plan_id` set but no `Subscription` row yet gets one created,
 * so this codebase never has two permanent architectural tenant "modes"
 * (with a subscription vs. without one) - see task section 0.B.
 *
 * NOT CALLED FROM ANY MIGRATION (historical migration stability review,
 * post-acceptance fix): `database/migrations/2026_08_15_170001_
 * backfill_subscriptions_from_tenant_plan_id.php` used to call
 * `self::run()` directly, but a migration must keep producing the same
 * result on a brand-new install regardless of how application code
 * evolves later - since this class encodes real interpretive decisions
 * (status choice, anchor date, skip criteria) rather than a fixed
 * mechanical transformation, that migration now carries its own frozen,
 * inlined copy of this same logic instead (see that migration file's own
 * docblock for the full reasoning). This class remains fully real and
 * tested - `tests/Feature/Platform/PlatformSubscriptionManagementTest.php`
 * tests 3-4 call it directly - and is the natural implementation a
 * future on-demand repair command would wrap (mirroring
 * `platform:tenants:migrate-pending`'s established "migration once,
 * repair tool separately" split, TASK-ARCH-010), should one ever be
 * needed; no such command exists yet.
 *
 * DELIBERATELY BYPASSES `TenantPlanAssignment`/`SubscriptionLifecycle::
 * start()` - this is the one place in the whole Subscription domain that
 * does NOT reuse that "is this plan still active" gate. Backfill is not
 * a NEW assignment decision; it is recording an already-existing
 * historical fact ("this tenant already has this plan_id today").
 * TASK-ARCH-015/DECISION_LOG C33 already established that deactivating a
 * plan must never disturb a tenant already on it - if backfill routed
 * through the active-only gate, a tenant whose plan happened to be
 * deactivated between TASK-ARCH-015 and this task would be silently
 * skipped (or worse, made to error), which would be exactly the
 * "disturbing an existing assignment" C33 forbids, just via a different
 * code path. Writing the Subscription row directly avoids that.
 *
 * WHAT THIS DOES NOT CLAIM (task section 8, explicit): the resulting
 * `Active` status and `starts_at = tenant.created_at` are NOT a claim
 * that the tenant historically paid for, or was ever billed for, that
 * plan - there is no payment history anywhere in this codebase. It is
 * only "the current SaaS entitlement/lifecycle baseline at the moment
 * the Subscription domain was introduced," using the tenant's own
 * creation date as the most defensible available anchor (not `now()`,
 * which would misrepresent every backfilled tenant as having started
 * today).
 *
 * Tenants with `plan_id IS NULL` are DELIBERATELY skipped, not silently
 * given an invented plan (task section 8, explicit) - counted and
 * returned separately so a caller/test can assert on them without
 * needing to re-derive the set independently.
 */
class SubscriptionBackfill
{
    /**
     * @return array{backfilled: int, skipped_no_plan: int, already_had_subscription: int}
     */
    public function run(): array
    {
        $backfilled = 0;
        $skippedNoPlan = 0;
        $alreadyHadSubscription = 0;

        Tenant::query()->chunkById(100, function ($tenants) use (&$backfilled, &$skippedNoPlan, &$alreadyHadSubscription) {
            foreach ($tenants as $tenant) {
                if ($tenant->plan_id === null) {
                    $skippedNoPlan++;

                    continue;
                }

                $subscription = Subscription::firstOrCreate(
                    ['tenant_id' => $tenant->getTenantKey()],
                    [
                        'plan_id' => $tenant->plan_id,
                        'status' => SubscriptionStatus::Active,
                        'starts_at' => $tenant->created_at,
                    ]
                );

                if ($subscription->wasRecentlyCreated) {
                    $backfilled++;
                } else {
                    $alreadyHadSubscription++;
                }
            }
        });

        return [
            'backfilled' => $backfilled,
            'skipped_no_plan' => $skippedNoPlan,
            'already_had_subscription' => $alreadyHadSubscription,
        ];
    }
}
