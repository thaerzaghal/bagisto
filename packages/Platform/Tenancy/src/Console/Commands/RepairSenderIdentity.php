<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use RuntimeException;
use Throwable;

/**
 * TASK-MVP-018 (RISK_REGISTER.md R73). Backfills the tenant-scoped mail
 * sender DISPLAY NAME (see `TenantProvisioner::seedSenderIdentity()`) for
 * tenants provisioned before that step existed - mirrors
 * `RepairChannelHostname`'s exact shape/precedent: `--tenant` selector
 * (all Ready tenants if omitted), Ready-only, idempotent, safe to run
 * repeatedly, never run automatically during deployment.
 *
 * TRUSTWORTHINESS CHECK IS THIS COMMAND'S OWN RESPONSIBILITY (deliberately
 * NOT inside `TenantProvisioner::seedSenderIdentity()` itself - see that
 * method's own docblock for why): a tenant's `channel_translations.name` is
 * only used as the seeded `sender_name` if it is not still Bagisto's own
 * generic seeded placeholder ("Default" / "افتراضي" -
 * `packages/Webkul/Installer/src/Resources/lang/{en,ar}/app.php`
 * `installer::app.seeders.core.channels.name`, confirmed by reading source,
 * not assumed). A tenant that never received a real store name (managed
 * onboarding without one, CLI-provisioned, or a pre-TASK-MVP-007 tenant) is
 * SKIPPED, not seeded with a meaningless placeholder - matches this task's
 * explicit "do not silently derive a merchant name from unrelated fields"
 * instruction.
 *
 * NEVER writes `sender_email` (see `seedSenderIdentity()`'s own docblock).
 * NEVER overwrites an existing `sender_name` row, whether seeded by a prior
 * repair run or manually configured by a merchant/admin via Admin ->
 * Configuration -> Emails -> Email Settings.
 *
 * Returns a non-zero exit code if any tenant genuinely fails (a real
 * exception - e.g. missing schema) - a "skipped, no trustworthy name" or
 * "already configured" outcome is NOT a failure and does not affect the
 * exit code, matching this task's explicit instruction that this command
 * report/return failure only for genuine failures.
 */
class RepairSenderIdentity extends Command
{
    protected $signature = 'platform:tenants:repair-sender-identity {--tenant=* : Specific tenant id(s); all Ready tenants if omitted}';

    protected $description = 'Seed the tenant-scoped mail sender display name for existing tenants that have a real store name but no sender_name configured yet (idempotent, Ready tenants only, never writes sender_email, never overwrites an existing value).';

    /**
     * Bagisto's own generic seeded channel name (`Webkul\Installer\Database\
     * Seeders\Core\ChannelTableSeeder` -> `installer::app.seeders.core.
     * channels.name`) - not a real store name, never used as a seeded
     * sender identity. Both shipped locales listed; a tenant translated
     * into a third-party locale's own placeholder (if any is ever added) is
     * not covered by this narrow list - accepted, matches this task's scope
     * (see class docblock).
     */
    protected const PLACEHOLDER_CHANNEL_NAMES = ['Default', 'افتراضي'];

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

        $hadFailure = false;

        foreach ($tenants as $tenant) {
            if ($tenant->status !== TenantStatus::Ready) {
                $this->line("Skipping tenant [{$tenant->getTenantKey()}] - status is [{$tenant->status->value}], not ready.");

                continue;
            }

            try {
                $outcome = $this->repairOne($tenant, $provisioner);

                match ($outcome) {
                    'seeded' => $this->info("Tenant [{$tenant->getTenantKey()}] sender_name seeded."),
                    'already_configured' => $this->line("Tenant [{$tenant->getTenantKey()}] already has a sender_name configured - left unchanged."),
                    'no_trustworthy_name' => $this->line("Tenant [{$tenant->getTenantKey()}] has no real store name (still the seeded placeholder channel name) - skipped."),
                };
            } catch (Throwable $e) {
                $hadFailure = true;

                $this->error("Tenant [{$tenant->getTenantKey()}] failed: {$e->getMessage()}");
            }
        }

        $this->info('Done.');

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return 'seeded'|'already_configured'|'no_trustworthy_name'
     */
    protected function repairOne(Tenant $tenant, TenantProvisioner $provisioner): string
    {
        return $tenant->run(function () use ($tenant, $provisioner) {
            if (! Schema::hasTable('channel_translations') || ! Schema::hasTable('channels')) {
                throw new RuntimeException(
                    'channel_translations/channels table does not exist yet.'
                );
            }

            $channelName = trim((string) DB::table('channel_translations')
                ->where('channel_id', 1)
                ->orderBy('id')
                ->value('name'));

            if ($channelName === '' || in_array($channelName, self::PLACEHOLDER_CHANNEL_NAMES, true)) {
                return 'no_trustworthy_name';
            }

            // seedSenderIdentity() runs its own Tenant::run() - safe to
            // nest, see that method's own docblock.
            return $provisioner->seedSenderIdentity($tenant, $channelName);
        });
    }
}
