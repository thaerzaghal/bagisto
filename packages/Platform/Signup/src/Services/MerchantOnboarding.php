<?php

declare(strict_types=1);

namespace Platform\Signup\Services;

use Illuminate\Support\Facades\DB;
use Platform\Plans\Models\Plan;
use Platform\Subscriptions\Services\SubscriptionLifecycle;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Throwable;

/**
 * TASK-MVP-001. The one entry point for both a fresh self-service signup
 * and a retried attempt after a failed one - both funnel through the
 * same `attempt()` helper so they can never diverge in how provisioning
 * is invoked or how success/failure is reported. Reuses
 * `Platform\Tenancy\Services\TenantProvisioner::provision()` unchanged in
 * every way except the new, optional `$ownerAdmin` parameter that
 * task/provisioner itself now supports (see that class's own docblock) -
 * this service creates NO parallel tenant-creation, plan-assignment, or
 * subscription-starting logic of its own.
 *
 * PLAINTEXT PASSWORD HANDLING (required security adjustment from the
 * approved TASK-MVP-001 plan): the plaintext password passed into
 * register()/retry() lives only in the PHP call stack of the single
 * request that supplied it - it is never written to `$tenant`, `data`,
 * cache, a queue payload, a log, or any other row. A failed attempt
 * leaves nothing to resume from except re-asking the merchant for their
 * password (see Platform\Signup\Http\Controllers\SignupRetryController) -
 * there is deliberately no "saved" password to retry with.
 *
 * TASK-MVP-007. `$plan`/`$storeName` are ADDITIVE, optional parameters on
 * `register()` only (never `retry()` - a retried tenant already has
 * whatever plan its original attempt started) so that `Platform\Admin\
 * Http\Controllers\TenantController`'s managed-onboarding flow can reuse
 * this exact same method - the same Tenant+Domain-creation transaction,
 * the same `TenantProvisioner` call, the same success/failure contract -
 * rather than duplicating any of it. Public `/join` never passes either
 * argument, so its own behavior is byte-for-byte unchanged (both default
 * to `null`, matching the shape they always had here).
 */
class MerchantOnboarding
{
    public function __construct(
        protected TenantProvisioner $provisioner,
        protected SubscriptionLifecycle $subscriptions,
    ) {}

    /**
     * Creates the central Tenant + Domain rows (one transaction, so a
     * mid-way failure between the two can never leave an orphaned
     * domain), then immediately attempts provisioning. The transaction
     * covers ONLY those two central-connection writes - provisioning
     * itself (physical tenant database creation, migration, seeding) is
     * a separate, non-transactional, cross-connection operation and must
     * run outside it, exactly like every other provisioning call site in
     * this codebase.
     *
     * `$plan`, when given, is started via `SubscriptionLifecycle::start()`
     * - the SAME service/rules `Platform\Admin\Http\Controllers\
     * TenantController::changePlan()` already uses (inactive-plan
     * rejection included, `InactivePlanAssignmentException` propagates
     * to the caller unchanged) - BEFORE `TenantProvisioner::provision()`
     * runs, so `ensureInitialSubscriptionStarted()`'s own existing
     * `if ($tenant->plan_id !== null) return;` guard correctly treats the
     * plan as already assigned and never double-starts a second
     * subscription. `$storeName`, when given, sets the tenant's default
     * channel display name (`channel_translations.name`, ALL locale rows)
     * after successful provisioning only - the identical `$tenant->run()`
     * + `DB::table(...)->update()` shape `TenantProvisioner::
     * ensureChannelHostnameCorrect()` already establishes for the sibling
     * `channels.hostname` correction, just for a cosmetic field that step
     * doesn't own.
     *
     * TASK-MVP-018 (RISK_REGISTER.md R73): the same `$storeName`, at the
     * same point (only once provisioning has succeeded and the real value
     * is known - never earlier), also seeds the tenant's mail sender
     * DISPLAY NAME via `TenantProvisioner::seedSenderIdentity()` - see that
     * method's own docblock for the full architecture. Public `/join` never
     * passes `$storeName`, so its tenants are unaffected (existing
     * "Technify" fallback unchanged) - an intentional, disclosed current
     * limitation, not an oversight (see RepairSenderIdentity for the
     * backfill path once a real store name becomes known).
     */
    public function register(string $slug, string $domain, string $ownerName, string $ownerEmail, string $password, ?Plan $plan = null, ?string $storeName = null): array
    {
        $tenant = DB::transaction(function () use ($slug, $domain, $ownerName, $ownerEmail) {
            $tenant = Tenant::create([
                'id' => $slug,
                'status' => TenantStatus::Pending,
                'owner_name' => $ownerName,
                'owner_email' => $ownerEmail,
            ]);

            $tenant->domains()->create(['domain' => $domain]);

            return $tenant;
        });

        if ($plan !== null) {
            $this->subscriptions->start($tenant, $plan);
        }

        return $this->attempt($tenant, $ownerName, $ownerEmail, $password, $storeName);
    }

    /**
     * Re-attempts provisioning for an already-existing (Pending/
     * Provisioning/Failed) tenant, reusing its already-stored
     * `owner_name`/`owner_email` and the FRESHLY supplied password - the
     * only piece of owner-admin identity this class never persists.
     */
    public function retry(Tenant $tenant, string $password): array
    {
        return $this->attempt($tenant, (string) $tenant->owner_name, (string) $tenant->owner_email, $password);
    }

    protected function attempt(Tenant $tenant, string $ownerName, string $ownerEmail, string $password, ?string $storeName = null): array
    {
        try {
            $this->provisioner->provision($tenant, [
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => $password,
            ]);
        } catch (Throwable) {
            // TenantProvisioner::provision() has already recorded status
            // Failed + last_error on the tenant itself - nothing further
            // to capture here, and the original exception is deliberately
            // not re-thrown: a signup/retry failure is an ordinary,
            // expected, user-facing outcome (matching Platform Admin's
            // own provision()/TenantController precedent), not a 500.
            return ['tenant' => $tenant->fresh(), 'succeeded' => false];
        }

        if ($storeName !== null) {
            $tenant->run(function () use ($storeName) {
                DB::table('channel_translations')
                    ->where('channel_id', 1)
                    ->update(['name' => $storeName]);
            });

            $this->provisioner->seedSenderIdentity($tenant, $storeName);
        }

        return ['tenant' => $tenant->fresh(), 'succeeded' => true];
    }
}
