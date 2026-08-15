<?php

declare(strict_types=1);

namespace Platform\Subscriptions\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Plans\Models\Plan;
use Platform\Plans\Services\TenantPlanAssignment;
use Platform\Subscriptions\Enums\SubscriptionStatus;
use Platform\Subscriptions\Exceptions\InvalidSubscriptionTransitionException;
use Platform\Subscriptions\Models\Subscription;
use Platform\Tenancy\Models\Tenant;

/**
 * TASK-ARCH-016. The one explicit entry point for every subscription
 * state transition - mirrors `Platform\Tenancy\Services\TenantLifecycle`'s
 * shape exactly (a plain, request/session-independent service; no
 * controller ever sets `$subscription->status`/`plan_id`/
 * `cancel_at_period_end` directly).
 *
 * PLAN CHANGES ALWAYS GO THROUGH `TenantPlanAssignment` (task section 9):
 * `start()`/`startTrial()`/`changePlan()` all call
 * `Platform\Plans\Services\TenantPlanAssignment::assign()` internally -
 * the ONLY place `tenants.plan_id` is ever written, unchanged since
 * TASK-ARCH-015. This is what keeps `subscription.plan_id ==
 * tenant.plan_id` true by construction after every mutation this class
 * makes, without a second, independently-maintained synchronization
 * mechanism - reusing `TenantPlanAssignment`'s own "is this plan
 * assignable" (active-only) rule for free.
 *
 * ONE CURRENT SUBSCRIPTION PER TENANT / "RESTART IN PLACE" (task
 * sections 3, 20): the `subscriptions` table has a database-level
 * `unique('tenant_id')` constraint (see that migration), so a SECOND row
 * can never be created for a tenant that already has one. Given that
 * constraint, `start()`/`startTrial()` do double duty: creating a fresh
 * row for a tenant with none, AND resetting an existing Canceled/Expired
 * row back to Trialing/Active in place for a tenant who wants to resume -
 * the smallest coherent behavior available under the one-row invariant,
 * per the task's own explicit instruction to prefer that over inventing
 * subscription-history/versioning now. Calling `start()`/`startTrial()`
 * against an already-Trialing/Active subscription is rejected (that's
 * what `changePlan()` is for).
 *
 * TRANSITION MATRIX (the complete set this class allows):
 *
 *   (none) or Canceled/Expired -> Trialing   startTrial()
 *   (none) or Canceled/Expired -> Active     start()
 *   Trialing                   -> Active     activate()
 *   Trialing                   -> Canceled   cancelImmediately()
 *   Active                     -> Canceled   cancelImmediately()
 *   Trialing/Active             -> Expired    expire()
 *   Active (cancel_at_period_end only,
 *           status stays Active)             cancelAtPeriodEnd()
 *   Trialing/Active             -> (new plan) changePlan()
 *
 * Every other transition throws `InvalidSubscriptionTransitionException`.
 * No automatic time-based transition exists anywhere in this class (task
 * sections 11-14): no scheduler, no cron, nothing observes
 * `current_period_end`/`trial_ends_at` and acts on it on its own -
 * `activate()`/`cancelAtPeriodEnd()`/`cancelImmediately()`/`expire()` are
 * ALL manually invoked, today exclusively from Platform Admin.
 *
 * TENANTSTATUS INDEPENDENCE (task section 18, strict): this class NEVER
 * reads or writes `$tenant->status`, and nothing in `Platform\Tenancy`
 * ever reads or writes a `Subscription`. `TenantStatus = Ready` +
 * `SubscriptionStatus = Canceled` is a fully valid, unremarkable state
 * under this design - canceling a subscription does not suspend a
 * tenant, and reactivating a suspended tenant does not touch its
 * subscription. A future billing-policy task may explicitly orchestrate
 * both services together; this task deliberately does not.
 */
class SubscriptionLifecycle
{
    public function __construct(protected TenantPlanAssignment $planAssignment)
    {
    }

    /**
     * Starts (or restarts, see class docblock) a subscription in the
     * ACTIVE state - no trial. For a FREE default tenant during
     * provisioning, this is the normal path: the task explicitly does
     * not want trial behavior invented merely because plans MIGHT one
     * day support a `trial_days` column that does not exist today (task
     * section 6).
     */
    public function start(Tenant $tenant, Plan $plan, ?DateTimeInterface $startsAt = null): Subscription
    {
        return $this->startAs($tenant, $plan, SubscriptionStatus::Active, $startsAt, null);
    }

    /**
     * Starts (or restarts) a subscription in the TRIALING state.
     * `$trialEndsAt` must be strictly after the effective start time
     * (task section 14's own validation requirement).
     */
    public function startTrial(Tenant $tenant, Plan $plan, DateTimeInterface $trialEndsAt, ?DateTimeInterface $startsAt = null): Subscription
    {
        $effectiveStartsAt = $startsAt ?? now();

        if ($trialEndsAt <= $effectiveStartsAt) {
            throw new InvalidArgumentException('trial_ends_at must be after starts_at.');
        }

        return $this->startAs($tenant, $plan, SubscriptionStatus::Trialing, $startsAt, $trialEndsAt);
    }

    protected function startAs(Tenant $tenant, Plan $plan, SubscriptionStatus $status, ?DateTimeInterface $startsAt, ?DateTimeInterface $trialEndsAt): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $status, $startsAt, $trialEndsAt) {
            $existing = Subscription::currentFor($tenant);

            if ($existing && in_array($existing->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                throw new InvalidSubscriptionTransitionException(
                    "Tenant [{$tenant->getTenantKey()}] already has a {$existing->status->value} subscription - use changePlan() to change its plan, or cancel it first."
                );
            }

            // Reused (not duplicated): the same "is this plan assignable"
            // rule TASK-ARCH-015's Platform Admin manual assignment uses.
            // Throws InactivePlanAssignmentException for an inactive plan,
            // which also keeps subscription creation from ever succeeding
            // with a plan tenants.plan_id was refused.
            $this->planAssignment->assign($tenant, $plan);

            $attributes = [
                'plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $startsAt ?? now(),
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'ended_at' => null,
            ];

            if ($existing) {
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return Subscription::create($attributes + ['tenant_id' => $tenant->getTenantKey()]);
        });
    }

    /**
     * Trialing -> Active. The only manual trial-conversion transition
     * this task builds (task section 14) - no scheduler ever calls this.
     */
    public function activate(Subscription $subscription): void
    {
        if ($subscription->status !== SubscriptionStatus::Trialing) {
            throw new InvalidSubscriptionTransitionException(
                "Cannot activate subscription for tenant [{$subscription->tenant_id}] from status [{$subscription->status->value}] - only a Trialing subscription can be activated."
            );
        }

        $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
    }

    /**
     * Changes an existing Trialing/Active subscription's plan. Reuses
     * `TenantPlanAssignment::assign()` (the same call `start()` makes) -
     * no duplicated "is this plan assignable" logic. Wrapped in a
     * transaction so `subscriptions.plan_id` and `tenants.plan_id` can
     * never observably disagree, even under a mid-write failure.
     */
    public function changePlan(Subscription $subscription, Plan $plan): void
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw new InvalidSubscriptionTransitionException(
                "Cannot change plan for tenant [{$subscription->tenant_id}]'s subscription - status is [{$subscription->status->value}], not Trialing/Active. Use start()/startTrial() to resume it first."
            );
        }

        DB::transaction(function () use ($subscription, $plan) {
            $this->planAssignment->assign($subscription->tenant, $plan);

            $subscription->forceFill(['plan_id' => $plan->id])->save();
        });
    }

    /**
     * Sets cancellation INTENT only - task section 11.A, explicit: does
     * NOT change `status`, does NOT touch tenant access/entitlements in
     * any way, and no scheduler exists anywhere in this codebase to act
     * on this flag later (that rollover mechanism is explicitly out of
     * this task's scope). Only meaningful from Active - a Trialing
     * subscription has no "period" yet to cancel at the end of; use
     * `cancelImmediately()` to stop during a trial.
     */
    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            throw new InvalidSubscriptionTransitionException(
                "Cannot schedule cancel-at-period-end for tenant [{$subscription->tenant_id}]'s subscription - status is [{$subscription->status->value}], not Active."
            );
        }

        $subscription->forceFill(['cancel_at_period_end' => true])->save();
    }

    /**
     * Records a billing period on the subscription - task section 13:
     * "provider-neutral metadata only in this task... may be manually
     * set at subscription creation/update where needed." No automatic
     * rollover/scheduler calls this; it exists purely so a period can be
     * recorded (e.g. by a future manual Platform Admin action, or a
     * provider integration in Phase 11) with its ordering validated -
     * `current_period_end >= current_period_start` when both are
     * present, matching the task's own explicit validation requirement.
     * Not exposed in Platform Admin's UI in this foundation task (task
     * section 15 does not list it), but real, tested domain capability.
     */
    public function setPeriod(Subscription $subscription, ?DateTimeInterface $currentPeriodStart, ?DateTimeInterface $currentPeriodEnd): void
    {
        if ($currentPeriodStart && $currentPeriodEnd && $currentPeriodEnd < $currentPeriodStart) {
            throw new InvalidArgumentException('current_period_end must not be before current_period_start.');
        }

        $subscription->forceFill([
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd,
        ])->save();
    }

    /**
     * Ends the subscription NOW. Task section 11.B, strict: does NOT
     * touch `tenants.plan_id`, does NOT touch `TenantStatus` - a
     * canceled subscription's tenant keeps its last entitlements and
     * keeps being Ready (or whatever its independent lifecycle status
     * already was) until an operator (or a future, explicit billing
     * policy) separately decides otherwise. See class docblock,
     * "TENANTSTATUS INDEPENDENCE".
     */
    public function cancelImmediately(Subscription $subscription): void
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw new InvalidSubscriptionTransitionException(
                "Cannot cancel tenant [{$subscription->tenant_id}]'s subscription - status is already [{$subscription->status->value}]."
            );
        }

        $now = now();

        $subscription->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'cancelled_at' => $now,
            'ended_at' => $now,
        ])->save();
    }

    /**
     * Manual-only expiration (task section 12, strict: "Do NOT
     * automatically expire based on current_period_end in this task
     * unless a scheduler/time-transition mechanism is deliberately
     * implemented and tested" - none is). Same TenantStatus-independence
     * guarantee as `cancelImmediately()`. Not exposed as a Platform Admin
     * UI action in this task (see `docs/architecture/platform-admin.md`)
     * - it exists here for completeness/testability per the task's own
     * "manual expiration through lifecycle service is sufficient for
     * foundation" instruction, without adding UI surface nothing asked
     * for yet.
     */
    public function expire(Subscription $subscription): void
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
            throw new InvalidSubscriptionTransitionException(
                "Cannot expire tenant [{$subscription->tenant_id}]'s subscription - status is already [{$subscription->status->value}]."
            );
        }

        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'ended_at' => now(),
        ])->save();
    }
}
