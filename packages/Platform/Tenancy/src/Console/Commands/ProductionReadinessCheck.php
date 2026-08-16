<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * TASK-MVP-004A (task section 6). Read-only production-configuration
 * sanity check - reports obvious misconfiguration against the posture
 * docs/architecture/production-deployment.md documents; mutates nothing,
 * never prints a secret VALUE (only whether one is set/blank, or a
 * derived boolean like "looks like root").
 *
 * Deliberately narrow: every check here reads a single config value (or
 * makes one real, already-safe-to-attempt connection check - Redis ping)
 * and compares it against a known-good production posture. It does NOT
 * attempt to guess deployment topology, does not require network access
 * to anything other than the already-configured Redis connection, and
 * never fails the command's own exit code merely to be dramatic - a
 * misconfigured pilot environment should see a clear report, not a
 * crashed command.
 */
class ProductionReadinessCheck extends Command
{
    protected $signature = 'platform:production:check';

    protected $description = 'Read-only report of obvious production-configuration issues (APP_DEBUG, trusted proxies, cache/session/response-cache posture, DB provisioning credentials, Redis reachability, mail/Stripe posture). Mutates nothing.';

    protected int $failures = 0;

    protected int $warnings = 0;

    public function handle(): int
    {
        $rows = [
            $this->checkAppDebug(),
            $this->checkTrustedProxies(),
            $this->checkPlatformBaseDomain(),
            $this->checkPlatformCentralDomains(),
            $this->checkCacheStore(),
            $this->checkSessionDriver(),
            $this->checkResponseCache(),
            $this->checkProvisioningCredentials(),
            $this->checkRedis(),
            $this->checkStripe(),
            $this->checkMail(),
        ];

        $this->table(['Check', 'Status', 'Detail'], $rows);

        if ($this->failures > 0) {
            $this->error("{$this->failures} check(s) failed, {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->info("All checks passed ({$this->warnings} warning(s)).");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkAppDebug(): array
    {
        if (config('app.debug') === true) {
            return $this->resultFail('APP_DEBUG', 'true - MUST be false in production (leaks stack traces/secrets on error pages).');
        }

        return $this->resultPass('APP_DEBUG', 'false');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkTrustedProxies(): array
    {
        if ((string) env('TRUSTED_PROXIES', '') === '') {
            return $this->resultWarn('TRUSTED_PROXIES', 'unset - trusting ALL proxies ("*"). Acceptable for local development only; production MUST set this to the real reverse proxy\'s IP(s)/CIDR range(s).');
        }

        return $this->resultPass('TRUSTED_PROXIES', 'configured');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkPlatformBaseDomain(): array
    {
        $value = (string) config('platform.base_domain');

        if ($value === '' || $value === 'platform.test') {
            return $this->resultWarn('PLATFORM_BASE_DOMAIN', "currently [{$value}] - the local-dev default. Production MUST set this to the real tenant parent domain.");
        }

        return $this->resultPass('PLATFORM_BASE_DOMAIN', $value);
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkPlatformCentralDomains(): array
    {
        $domains = (array) config('tenancy.central_domains');

        if ((string) env('PLATFORM_CENTRAL_DOMAINS', '') === '') {
            return $this->resultWarn('PLATFORM_CENTRAL_DOMAINS', 'unset - only the local-dev defaults ('.implode(', ', $domains).') are central. Production MUST set this to the real central-app hostname(s).');
        }

        return $this->resultPass('PLATFORM_CENTRAL_DOMAINS', implode(', ', $domains));
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkCacheStore(): array
    {
        $store = (string) config('cache.default');

        if ($store !== 'redis') {
            return $this->resultWarn('CACHE_STORE', "currently [{$store}] - production MUST use [redis] (CacheTenancyBootstrapper requires a taggable store; 'array' gives no real cross-request caching under PHP-FPM).");
        }

        return $this->resultPass('CACHE_STORE', 'redis');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkSessionDriver(): array
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            return $this->resultWarn('SESSION_DRIVER', "currently [{$driver}] - the proven, tested architecture is [database] (see docs/architecture). Changing this is not required for MVP.");
        }

        return $this->resultPass('SESSION_DRIVER', 'database');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkResponseCache(): array
    {
        if (config('responsecache.enabled') === true) {
            return $this->resultFail('RESPONSE_CACHE_ENABLED', 'true - MUST remain false (RISK_REGISTER.md R1: no tenant-scoped cache keys exist for this mechanism yet).');
        }

        return $this->resultPass('RESPONSE_CACHE_ENABLED', 'false');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkProvisioningCredentials(): array
    {
        $username = (string) config('database.connections.tenant_provisioning.username');

        if ($username === 'root') {
            return $this->resultFail('DB_PROVISION_USERNAME', 'is [root] - production MUST use a dedicated, narrowly-scoped provisioning user (see docs/architecture/production-deployment.md).');
        }

        if ($username === '') {
            return $this->resultWarn('DB_PROVISION_USERNAME', 'unset (falling back to DB_USERNAME) - fine for local dev, NOT for production.');
        }

        return $this->resultPass('DB_PROVISION_USERNAME', 'configured, not root');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkRedis(): array
    {
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            if (config('cache.default') === 'redis') {
                return $this->resultFail('Redis', 'unreachable: '.$e->getMessage());
            }

            return $this->resultWarn('Redis', 'unreachable (CACHE_STORE is not redis, so this is not currently blocking): '.$e->getMessage());
        }

        return $this->resultPass('Redis', 'reachable');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkStripe(): array
    {
        $secret = (string) config('platform-billing.stripe.secret');

        if ($secret === '') {
            return $this->resultInfo('Stripe', 'STRIPE_SECRET unset - billing checkout is intentionally unavailable (safe pilot posture; see docs/architecture/production-deployment.md).');
        }

        return $this->resultPass('Stripe', 'STRIPE_SECRET configured');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkMail(): array
    {
        $host = (string) config('mail.mailers.smtp.host', '');
        $port = (string) config('mail.mailers.smtp.port', '');

        // config/mail.php's own env() defaults never leave this blank (the
        // dev fallback is '127.0.0.1'), so an emptiness check alone would
        // never fire. The specific '127.0.0.1' + '2525' pair is Mailpit,
        // this project's known local-dev-only mail catcher - unambiguous
        // evidence no real SMTP fallback has been configured yet.
        if ($host === '' || ($host === '127.0.0.1' && $port === '2525')) {
            return $this->resultWarn('Mail (central SMTP fallback)', 'still pointing at the local-dev mail catcher (Mailpit) or unset - tenants that have not configured their own SMTP (Admin -> Configuration -> Emails) will fail to send order-confirmation emails.');
        }

        return $this->resultPass('Mail (central SMTP fallback)', 'configured');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function resultPass(string $check, string $detail): array
    {
        return [$check, '<info>PASS</info>', $detail];
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function resultWarn(string $check, string $detail): array
    {
        $this->warnings++;

        return [$check, '<comment>WARN</comment>', $detail];
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function resultFail(string $check, string $detail): array
    {
        $this->failures++;

        return [$check, '<error>FAIL</error>', $detail];
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function resultInfo(string $check, string $detail): array
    {
        return [$check, 'INFO', $detail];
    }
}
