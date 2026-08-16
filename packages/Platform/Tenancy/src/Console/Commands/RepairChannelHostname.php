<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Throwable;

/**
 * TASK-MVP-003. The repair/backfill mechanism for tenants provisioned
 * before `TenantProvisioner::ensureChannelHostnameCorrect()` existed -
 * mirrors `platform:tenants:migrate-pending`'s exact shape (same
 * `--tenant=*` option, same "all tenants if omitted" default, same one-
 * line-per-tenant reporting), deliberately NOT folded into that command -
 * this is a narrowly scoped, single-purpose repair for one specific
 * correctness issue, not a general "repair everything" command.
 *
 * Unlike `platform:tenants:migrate-pending` (safe against a tenant with
 * no physical database at all, since Laravel's own migration tracking
 * handles that gracefully), this command deliberately only processes
 * `Ready` tenants and clearly SKIPS (not errors) any other status - a
 * Pending/Provisioning/Failed tenant has no reliable guarantee its
 * `channels` table exists yet, and `provision()` will already run this
 * exact same step correctly once such a tenant successfully reaches
 * Ready. Never reprovisions, reseeds, or migrates anything - purely the
 * one idempotent `UPDATE ... SET hostname = ...` `TenantProvisioner::
 * repairChannelHostname()` performs.
 */
class RepairChannelHostname extends Command
{
    protected $signature = 'platform:tenants:repair-channel-hostname {--tenant=* : Specific tenant id(s); all Ready tenants if omitted}';

    protected $description = 'Correct the default channel hostname for existing tenants (idempotent, Ready tenants only, touches no other channel field).';

    public function handle(TenantProvisioner $provisioner): int
    {
        $ids = $this->option('tenant');

        $tenants = empty($ids)
            ? Tenant::all()
            : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            if ($tenant->status !== TenantStatus::Ready) {
                $this->line("Skipping tenant [{$tenant->getTenantKey()}] - status is [{$tenant->status->value}], not ready.");

                continue;
            }

            try {
                $provisioner->repairChannelHostname($tenant);

                $this->info("Tenant [{$tenant->getTenantKey()}] channel hostname corrected (or already correct).");
            } catch (Throwable $e) {
                $this->error("Tenant [{$tenant->getTenantKey()}] failed: {$e->getMessage()}");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
