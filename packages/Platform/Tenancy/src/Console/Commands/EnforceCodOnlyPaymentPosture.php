<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Support\UnsupportedPaymentGateways;
use RuntimeException;
use Throwable;
use Webkul\Core\Repositories\CoreConfigRepository;

/**
 * TASK-MVP-023 (RISK_REGISTER.md R78). Converges EXISTING managed tenants
 * to the current MVP payment-posture PRODUCT DECISION: Cash On Delivery
 * is the only supported storefront payment method; Money Transfer and
 * every bundled external gateway (see `UnsupportedPaymentGateways::CODES`)
 * are out of current scope and must read inactive.
 *
 * Mirrors `RepairSenderIdentity`/`RepairChannelHostname`'s established
 * shape (`--tenant` selector, Ready-only, per-tenant try/catch, non-zero
 * exit only on a genuine failure) with one deliberate difference this
 * task's own instructions require: those commands only ever SEED a
 * missing value and NEVER overwrite an existing one. This command
 * ENFORCES a policy value regardless of the tenant's current state - an
 * existing `active=1` for an unsupported gateway is a real product-scope
 * violation this command corrects, not a merchant customization to
 * preserve (the merchant never chose to activate Stripe/PayPal/etc. -
 * every one of these methods was already active by upstream default
 * before this task existed; see `UnsupportedPaymentGateways`'s own
 * docblock).
 *
 * CACHE-SAFE BY CONSTRUCTION: writes exclusively through
 * `CoreConfigRepository::create()` (the R73/C95-established pattern) -
 * confirmed by reading that method directly, it already upserts (a
 * missing row is inserted via `parent::create()`, an existing row is
 * updated via `parent::update()`), and BOTH paths dispatch Prettus's own
 * cache-invalidating event - so no separate update()/cache-clear
 * mechanism is ever needed here.
 *
 * SURGICAL WRITES ONLY: reads each method's CURRENT value first via the
 * real `core()->getConfigData()` read path (never a raw `core_config`
 * table read) and only includes a method in the write payload if its
 * normalized value actually differs from policy - a tenant already fully
 * compliant triggers zero writes at all, not merely zero net VALUE
 * change. This is what makes a second run genuinely idempotent (no
 * further configuration changes, not just no further net effect).
 *
 * `--dry-run`: computes and reports the exact same diff, writes nothing.
 *
 * Deliberately never run automatically during deployment/provisioning -
 * this is a one-time, operator-triggered convergence for tenants
 * provisioned BEFORE this task's own new provisioning step
 * (`ensureUnsupportedPaymentGatewaysDeactivated()`) existed. New tenants
 * never need this command at all.
 */
class EnforceCodOnlyPaymentPosture extends Command
{
    protected $signature = 'platform:tenants:enforce-cod-only-payments
        {--tenant=* : Specific tenant id(s); all Ready tenants if omitted}
        {--dry-run : Report the exact changes that would be made without writing anything}';

    protected $description = 'Converge existing tenants to the current MVP payment posture: Cash On Delivery active, Money Transfer and every bundled external gateway inactive (idempotent, cache-safe, Ready tenants only).';

    /**
     * @var array<string, string>
     */
    protected const POLICY = [
        'cashondelivery' => '1',
        'moneytransfer' => '0',
        'stripe' => '0',
        'razorpay' => '0',
        'payu' => '0',
        'phonepe' => '0',
        'paypal_smart_button' => '0',
        'paypal_standard' => '0',
        'payglocal' => '0',
    ];

    public function handle(): int
    {
        // TARGETS is defined by explicit literal keys above; this assertion
        // exists only so a future edit that accidentally drops a gateway
        // from UnsupportedPaymentGateways::CODES without updating POLICY is
        // caught here rather than silently under-enforcing the policy.
        foreach (UnsupportedPaymentGateways::CODES as $code) {
            if (! array_key_exists($code, self::POLICY)) {
                throw new RuntimeException("UnsupportedPaymentGateways::CODES contains [{$code}], which is missing from EnforceCodOnlyPaymentPosture::POLICY.");
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        $ids = $this->option('tenant');

        $tenants = empty($ids)
            ? Tenant::all()
            : Tenant::whereIn('id', $ids)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('DRY RUN - no configuration will be written.');
        }

        $hadFailure = false;

        foreach ($tenants as $tenant) {
            if ($tenant->status !== TenantStatus::Ready) {
                $this->line("Skipping tenant [{$tenant->getTenantKey()}] - status is [{$tenant->status->value}], not ready.");

                continue;
            }

            try {
                $diff = $this->reconcileOne($tenant, $dryRun);

                if (empty($diff)) {
                    $this->line("Tenant [{$tenant->getTenantKey()}] already fully compliant - no changes.");

                    continue;
                }

                foreach ($diff as $method => [$current, $desired]) {
                    $verb = $dryRun ? 'would change' : 'changed';
                    $this->info("Tenant [{$tenant->getTenantKey()}] sales.payment_methods.{$method}.active: {$verb} [".$this->label($current).'] -> ['.$this->label($desired).']');
                }
            } catch (Throwable $e) {
                $hadFailure = true;

                $this->error("Tenant [{$tenant->getTenantKey()}] failed: {$e->getMessage()}");
            }
        }

        $this->info('Done.');

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }

    protected function label(string $value): string
    {
        return $value === '1' ? 'active' : 'inactive';
    }

    /**
     * @return array<string, array{0: string, 1: string}> method => [current, desired], only for methods that differ
     */
    protected function reconcileOne(Tenant $tenant, bool $dryRun): array
    {
        return $tenant->run(function () use ($dryRun) {
            if (! Schema::hasTable('core_config') || ! Schema::hasTable('channels')) {
                throw new RuntimeException('core_config/channels tables do not exist yet.');
            }

            $channelCode = DB::table('channels')->where('id', 1)->value('code');

            $diff = [];

            foreach (self::POLICY as $method => $desired) {
                $current = (bool) core()->getConfigData("sales.payment_methods.{$method}.active");
                $desiredBool = $desired === '1';

                if ($current !== $desiredBool) {
                    $diff[$method] = [$current ? '1' : '0', $desired];
                }
            }

            if (empty($diff) || $dryRun) {
                return $diff;
            }

            $repository = app(CoreConfigRepository::class);

            $repository->create([
                'locale' => 'ar',
                'channel' => $channelCode,
                'sales' => [
                    'payment_methods' => collect($diff)
                        ->mapWithKeys(fn (array $pair, string $method) => [$method => ['active' => $pair[1]]])
                        ->all(),
                ],
            ]);

            return $diff;
        });
    }
}
