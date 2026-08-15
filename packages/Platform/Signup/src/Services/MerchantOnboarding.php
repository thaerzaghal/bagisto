<?php

declare(strict_types=1);

namespace Platform\Signup\Services;

use Illuminate\Support\Facades\DB;
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
 */
class MerchantOnboarding
{
    public function __construct(protected TenantProvisioner $provisioner)
    {
    }

    /**
     * Creates the central Tenant + Domain rows (one transaction, so a
     * mid-way failure between the two can never leave an orphaned
     * domain), then immediately attempts provisioning. The transaction
     * covers ONLY those two central-connection writes - provisioning
     * itself (physical tenant database creation, migration, seeding) is
     * a separate, non-transactional, cross-connection operation and must
     * run outside it, exactly like every other provisioning call site in
     * this codebase.
     */
    public function register(string $slug, string $domain, string $ownerName, string $ownerEmail, string $password): array
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

        return $this->attempt($tenant, $ownerName, $ownerEmail, $password);
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

    protected function attempt(Tenant $tenant, string $ownerName, string $ownerEmail, string $password): array
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

        return ['tenant' => $tenant->fresh(), 'succeeded' => true];
    }
}
