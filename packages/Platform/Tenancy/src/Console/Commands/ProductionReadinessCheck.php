<?php

declare(strict_types=1);

namespace Platform\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
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

    protected $description = 'Read-only report of obvious production-configuration issues (APP_DEBUG, trusted proxies, cache/session/response-cache posture, DB provisioning credentials, Redis reachability, mail/Stripe posture, app/web static-asset consistency). Mutates nothing.';

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
            $this->checkAssetHelperTenancy(),
            $this->checkDeployedSource(),
            $this->checkAdminStaticAssets(),
            $this->checkShopStaticAssets(),
            $this->checkPublicSignup(),
            $this->checkSignupAbuseProtection(),
            $this->checkRedis(),
            $this->checkStripe(),
            $this->checkMail(),
            $this->checkBackupHealth(),
            $this->checkOffsiteBackupHealth(),
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

    /**
     * A real production regression (2026-08-18, deployment-drift incident -
     * see RISK_REGISTER.md R65): `config('tenancy.filesystem.asset_helper_tenancy')`
     * MUST stay `false` (RISK_REGISTER.md R63) - `true` (stancl/tenancy's own
     * package default) breaks the tenant Admin/Storefront Vue app entirely by
     * routing Bagisto's own compiled build assets through the tenant-storage
     * asset route instead of their real public path. R63's fix was proven
     * live once already; this incident showed the value can silently regress
     * via an unrelated image rebuild if the deployed host source tree ever
     * drifts from the approved git commit (see docs/architecture/
     * production-deployment.md "Deployment source of truth" for the full
     * incident and the durable process fix). A narrow, explicit FAIL here -
     * not a WARN - because this specific value has a KNOWN, already-proven
     * correct answer; there is no legitimate reason for it to ever be `true`
     * in this project.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkAssetHelperTenancy(): array
    {
        if (config('tenancy.filesystem.asset_helper_tenancy') !== false) {
            return $this->resultFail('asset_helper_tenancy', 'is NOT false - this WILL break tenant Admin/Storefront asset loading entirely (RISK_REGISTER.md R63/R65). Set config/tenancy.php\'s filesystem.asset_helper_tenancy to false and redeploy from the approved git source.');
        }

        return $this->resultPass('asset_helper_tenancy', 'false');
    }

    /**
     * RISK_REGISTER.md R65. Reports the git commit this deployment claims to
     * be running, read from a plain `APP_COMMIT` file at the app root -
     * written fresh by the deployment process itself (`git rev-parse HEAD`),
     * never committed to git (it would go stale the instant a new commit
     * landed). Deliberately best-effort/observational, the same posture
     * `Platform\Backup\Services\BackupRunner::appCommit()` already uses for
     * this identical file - this check exists to make "what source is this
     * server actually running" visible to a human at a glance, closing the
     * exact blind spot that let R65's deployment drift go undetected until
     * a human happened to look at the real browser. It cannot itself verify
     * the deployed FILES match that commit (no `.git` exists inside the
     * production image, by design - see `.dockerignore`) - only that a
     * commit marker was set at all. `checkAssetHelperTenancy()` above is the
     * concrete, self-verifying safeguard; this row is the general-purpose
     * "what am I actually running" visibility improvement.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkDeployedSource(): array
    {
        $marker = base_path('APP_COMMIT');

        if (! is_file($marker)) {
            return $this->resultWarn('Deployed source', 'no APP_COMMIT marker found - this deployment cannot report which git commit it was built from. See docs/architecture/production-deployment.md "Deployment source of truth".');
        }

        $commit = trim((string) file_get_contents($marker));

        return $this->resultPass('Deployed source', $commit !== '' ? $commit : '(APP_COMMIT file is empty)');
    }

    /**
     * RISK_REGISTER.md R76. Production runs TWO separately-built Docker
     * images from the same `Dockerfile.production` (`app` - PHP-FPM, holds
     * the current Vite manifest; `web` - nginx, the one that actually
     * SERVES `/themes/...` static assets over HTTP, built via a build-time
     * `COPY --from=app /var/www/html/public ...` in a third Dockerfile
     * stage - deliberately NOT a shared runtime volume, see that file's
     * own comments). If `web` is ever rebuilt/recreated LATER than `app`,
     * it silently keeps serving whatever assets existed at ITS OWN last
     * build - exactly what happened live: `web` sat 10 days stale while
     * `app` was redeployed three times, until a Vite content-hash changed
     * enough that `web`'s stale copy no longer had the file the CURRENT
     * manifest referenced, breaking Merchant Admin's Vue app-mount (and
     * therefore login/reset-password/every interactive Admin page)
     * platform-wide. `checkDeployedSource()` above could not have caught
     * this - it only ever runs inside `app`, with zero visibility into
     * `web`'s own independent state.
     *
     * `checkAdminStaticAssets()`/`checkShopStaticAssets()` both delegate to
     * this one shared implementation - the underlying architectural risk
     * applies identically to both themes; the real incident only broke
     * Admin because Shop's own content hash happened not to change between
     * builds, not because Shop is structurally safe.
     *
     * ALGORITHM, deliberately never hardcoding a generated filename - only
     * `$buildDirectory` (a fixed, structural path) and the entry-point
     * SOURCE names (which don't change) are fixed; the actual hashed
     * output filename is read fresh, every run, from the real current
     * manifest:
     *
     * 1. Read `public_path("{$buildDirectory}/manifest.json")` directly
     *    from THIS container's (`app`'s) own filesystem - always correct,
     *    since `app` was just rebuilt in any real deployment.
     * 2. For each of the two entry points every theme's own root layout
     *    actually requests via `@bagistoVite([...])` (confirmed by
     *    reading `anonymous.blade.php`/`index.blade.php` directly, not
     *    assumed) - `src/Resources/assets/css/app.css` and
     *    `src/Resources/assets/js/app.js` - look up its manifest `file`
     *    entry. Checking BOTH, not just JS, is deliberate: cheap (one
     *    extra HTTP request), and the real incident's own CSS survival was
     *    coincidence (an unchanged content hash), not a structural
     *    guarantee that would hold for a different future drift.
     * 3. Perform a REAL, LIVE HTTP GET for that exact derived file through
     *    `http://web/...` - Docker Compose's own internal service-name DNS
     *    on the shared `internal` network (confirmed reachable this
     *    direction by reading `docker/production/nginx.conf`'s own
     *    `fastcgi_pass app:9000` line, which proves the identical
     *    mechanism already works in the other direction). Deliberately
     *    NOT a public HTTPS/domain request - no dependency on external
     *    DNS, TLS, or the reverse proxy in front of `web` (all separate,
     *    already-covered concerns) - this isolates exactly the one thing
     *    that broke: does the `web` CONTAINER ITSELF currently have this
     *    file. A short 3-second timeout and ZERO retries are used
     *    deliberately - this must fail loudly and deterministically, not
     *    mask a real problem behind a retry/backoff that could paper over
     *    the exact drift this check exists to catch.
     * 4. PASS only on a genuine HTTP 200 from `web` for every entry point -
     *    this proves the asset is actually BEING SERVED by the live web
     *    layer, not merely that a same-named file happens to exist
     *    somewhere inside `app` (which would prove nothing about the
     *    actual production defect).
     *
     * Five distinct, clearly-worded outcomes, never conflated:
     *   - no manifest at all -> INFO (not applicable, e.g. a non-production
     *     environment with no real Vite build)
     *   - a manifest with an unexpected structure -> WARN (a build-config
     *     concern, not asset drift specifically)
     *   - `web` reachable but returning a non-200 for the CURRENT manifest
     *     asset -> FAIL, HTTP-status wording, naming R76 directly - this
     *     IS the exact incident condition this check exists to catch
     *   - `web` unreachable at all (connection refused/timeout/DNS/any
     *     transport-level exception) -> **also FAIL, not WARN** (revised
     *     after the initial TASK-MVP-017 implementation - see below)
     *   - `web` reachable, HTTP 200, but with the WRONG Content-Type for
     *     the entry point (e.g. an HTML error page masquerading as a 200)
     *     -> FAIL, the same "not actually correctly serving this asset"
     *     failure mode as a non-200
     *
     * A manifest existing on `app`'s own filesystem PROVES this is an
     * environment with a real, compiled Vite build - i.e. exactly the
     * shape a real production deployment has. Once that's established, an
     * unreachable `web` is no longer an ambiguous "maybe this environment
     * just doesn't have the app/web split" signal (the original, now-
     * corrected reasoning) - it is a production-readiness failure in its
     * own right: `app` healthy + correct `APP_COMMIT` + a real manifest +
     * `web` unreachable is precisely the kind of false-green state this
     * whole check exists to eliminate, and WARNing on it (never affecting
     * the command's exit code) would have let it slip through silently.
     * Local/non-production environments stay green correctly anyway,
     * because they simply have no `manifest.json` on disk at all (the
     * INFO branch above) - there was never a real need for a second,
     * separate "unreachable" escape hatch once that was understood.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkAdminStaticAssets(): array
    {
        return $this->checkThemeStaticAssets('Admin static assets', 'themes/admin/default/build');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function checkShopStaticAssets(): array
    {
        return $this->checkThemeStaticAssets('Shop static assets', 'themes/shop/default/build');
    }

    /**
     * Shared implementation - see `checkAdminStaticAssets()`'s own docblock
     * for the full algorithm/rationale. Deliberately private to this class,
     * not a reusable service - this is a narrow, one-purpose diagnostic,
     * not a general asset-management abstraction.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function checkThemeStaticAssets(string $label, string $buildDirectory): array
    {
        $manifestPath = public_path("{$buildDirectory}/manifest.json");

        if (! is_file($manifestPath)) {
            return $this->resultInfo($label, "no Vite manifest found at {$buildDirectory}/manifest.json - not applicable in this environment.");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return $this->resultWarn($label, "manifest at {$buildDirectory}/manifest.json could not be parsed as valid JSON.");
        }

        $entryPoints = [
            'src/Resources/assets/css/app.css',
            'src/Resources/assets/js/app.js',
        ];

        $verifiedFiles = [];

        foreach ($entryPoints as $entry) {
            if (empty($manifest[$entry]['file'])) {
                return $this->resultWarn($label, "manifest at {$buildDirectory}/manifest.json has no entry for [{$entry}] - unexpected build structure.");
            }

            $file = $manifest[$entry]['file'];
            $url = "http://web/{$buildDirectory}/{$file}";

            try {
                $response = Http::timeout(3)->get($url);
            } catch (Throwable $e) {
                // FAIL, not WARN (corrected per explicit product-owner
                // instruction after the initial implementation): a real
                // manifest already exists on THIS container's own
                // filesystem, which proves this environment has a real,
                // compiled Vite build - exactly production's own shape.
                // An unreachable `web` at that point is not an ambiguous
                // "maybe there's no app/web split here" signal, it's a
                // real production-readiness failure - app healthy +
                // correct APP_COMMIT + a real manifest + web unreachable
                // is precisely the false-green state R76/this check exists
                // to eliminate. See this method's own docblock.
                return $this->resultFail($label, "could not reach the internal web service for the CURRENT manifest asset [{$buildDirectory}/{$file}]: {$e->getMessage()} - web is likely down, unreachable, or needs rebuilding/recreating alongside app (docker/production/deploy.sh). See RISK_REGISTER.md R76.");
            }

            if ($response->status() !== 200) {
                return $this->resultFail($label, "web returned HTTP {$response->status()} for the CURRENT manifest asset [{$buildDirectory}/{$file}] - web is likely stale and needs rebuilding/recreating alongside app (docker/production/deploy.sh). See RISK_REGISTER.md R76.");
            }

            // Optional but cheap: a 200 with the WRONG Content-Type (e.g. an
            // HTML error/placeholder page served with a 200 status, which a
            // misconfigured nginx `error_page`/fallback directive could
            // produce) would otherwise pass the status-code check alone
            // while still not actually being the asset this check exists to
            // verify. Substring match (not an exact-type match) deliberately,
            // to tolerate a real `; charset=...` suffix or an equally valid
            // synonym (`application/javascript` vs `text/javascript`)
            // without false-failing on a harmless server/mime-db difference.
            $expectedType = str_ends_with($entry, '.css') ? 'css' : 'javascript';
            $contentType = strtolower($response->header('Content-Type'));

            if (! str_contains($contentType, $expectedType)) {
                return $this->resultFail($label, "web returned HTTP 200 but Content-Type [{$contentType}] for the CURRENT manifest asset [{$buildDirectory}/{$file}] does not look like {$expectedType} - web may be serving an error/fallback page instead of the real asset. See RISK_REGISTER.md R76.");
            }

            $verifiedFiles[] = $file;
        }

        return $this->resultPass($label, 'current manifest assets verified being served by web: '.implode(', ', $verifiedFiles));
    }

    /**
     * TASK-MVP-006. Deliberately a LOCAL config-only check - never calls
     * Cloudflare (matching this command's existing "no expensive provider
     * calls on every execution" posture, e.g. `checkOffsiteBackupHealth()`).
     * `Platform\Signup\Services\TurnstileVerifier` is the real enforcement
     * point; this row only reports whether it is actually armed.
     *
     * Disabled is a WARN, not a FAIL - a legitimate posture for an
     * invited-only pilot (this command's own established philosophy: never
     * fail the exit code merely to be dramatic about a deliberate choice).
     * Enabled-but-misconfigured (a missing site/secret key) is a FAIL, not
     * a WARN - unlike "disabled", there is no legitimate reason to be in
     * that state; public signup would fail unpredictably for every real
     * merchant the moment `TurnstileVerifier` tries to verify against an
     * empty secret.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkSignupAbuseProtection(): array
    {
        if (! config('platform.signup.turnstile.enabled')) {
            return $this->resultWarn('Signup abuse protection', 'Turnstile disabled - acceptable for an invited-only pilot; required before opening public self-service signup.');
        }

        [$ok, $detail] = $this->signupAbuseProtectionStatus();

        if (! $ok) {
            return $this->resultFail('Signup abuse protection', "{$detail} Public signup will fail for every real merchant.");
        }

        return $this->resultPass('Signup abuse protection', $detail);
    }

    /**
     * TASK-MVP-007. Reports the managed-onboarding product decision itself
     * (`config('platform.signup.enabled')`, see config/platform.php's own
     * docblock) - a SEPARATE row from `checkSignupAbuseProtection()` above,
     * which reports whether Turnstile is armed regardless of whether public
     * signup is even reachable. Disabled (the production default/posture)
     * is a PASS, not a WARN - unlike "Turnstile disabled", "no public
     * signup" is this project's own deliberate, approved posture for the
     * initial commercial/pilot phase (docs/architecture/onboarding.md), not
     * a gap to flag.
     *
     * When enabled, this deliberately does NOT re-derive the site/secret-key
     * validation matrix - it reuses `signupAbuseProtectionStatus()`, the
     * same helper `checkSignupAbuseProtection()` uses, so if public signup
     * is ever enabled with Turnstile disabled or misconfigured, this row
     * FAILS clearly instead of silently passing.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkPublicSignup(): array
    {
        if (! config('platform.signup.enabled')) {
            return $this->resultPass('Public signup', 'disabled (managed onboarding) - merchants are onboarded via Platform Admin. See docs/architecture/onboarding.md.');
        }

        if (! config('platform.signup.turnstile.enabled')) {
            return $this->resultFail('Public signup', 'enabled, but Turnstile abuse protection is disabled - public signup MUST NOT run without it.');
        }

        [$ok, $detail] = $this->signupAbuseProtectionStatus();

        if (! $ok) {
            return $this->resultFail('Public signup', "enabled, but abuse protection is not correctly configured: {$detail}");
        }

        return $this->resultPass('Public signup', "enabled - {$detail}");
    }

    /**
     * TASK-MVP-007. Shared by `checkSignupAbuseProtection()` and
     * `checkPublicSignup()` above - the single source of truth for whether
     * Turnstile's own site/secret keys are actually present, so the two
     * callers can never drift into disagreement about what "correctly
     * configured" means. Deliberately reports ONLY the key-presence
     * question, not whether Turnstile itself is enabled - each caller
     * already handles that distinctly (WARN vs N/A vs FAIL).
     *
     * @return array{0: bool, 1: string}
     */
    protected function signupAbuseProtectionStatus(): array
    {
        $siteKey = (string) config('platform.signup.turnstile.site_key');
        $secretKey = (string) config('platform.signup.turnstile.secret_key');

        if ($siteKey === '' || $secretKey === '') {
            return [false, 'Turnstile is ENABLED but '.($siteKey === '' ? 'TURNSTILE_SITE_KEY' : 'TURNSTILE_SECRET_KEY').' is missing.'];
        }

        return [true, 'Turnstile enabled, site key and secret key configured.'];
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

    /**
     * TASK-MVP-003A. Deliberately reads `config('platform-backup.root')`
     * and the newest finalized backup's `manifest.json` directly (plain
     * `json_decode`, no dependency on `Platform\Backup`'s own classes) -
     * same cross-package-config-only pattern `checkStripe()`/`checkMail()`
     * already use for `Platform\Billing`'s config, avoiding a circular
     * package dependency (`Platform\Backup` itself depends on
     * `Platform\Tenancy`'s `Tenant` model; this class must not depend back
     * on `Platform\Backup`). Only ever WARNs, never FAILs the command's own
     * exit code - a missing/stale backup is a real operational concern
     * worth surfacing, but not something that should block an otherwise
     * legitimate deploy/emergency-fix workflow, matching this command's
     * own established philosophy (see class docblock).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkBackupHealth(): array
    {
        $dailyRoot = rtrim((string) config('platform-backup.root'), '/').'/daily';

        if (! is_dir($dailyRoot)) {
            return $this->resultWarn('Backups', 'no backup directory found yet - platform:backup:run has never succeeded here.');
        }

        $finalized = array_values(array_filter(
            scandir($dailyRoot) ?: [],
            fn (string $name): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name)
        ));

        if ($finalized === []) {
            return $this->resultWarn('Backups', 'no successful backup found yet - platform:backup:run has never succeeded here.');
        }

        sort($finalized);
        $newest = $finalized[array_key_last($finalized)];

        $manifestPath = "{$dailyRoot}/{$newest}/manifest.json";
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

        if (! is_array($manifest) || ($manifest['status'] ?? null) !== 'success') {
            return $this->resultWarn('Backups', "newest backup [{$newest}] does not have a valid, successful manifest - inspect it manually.");
        }

        // diffInHours()'s un-absolute-valued result is signed (negative
        // for a past timestamp, confirmed empirically against this app's
        // actual configured timezone) and can carry noisy sub-hour
        // decimals - `true` (absolute) + round() give a clean "how many
        // whole hours ago" figure fit for a one-line WARN/PASS message.
        $ageHours = (int) round(now()->diffInHours($manifest['finished_at'] ?? $manifest['started_at'], true));

        if ($ageHours > 48) {
            return $this->resultWarn('Backups', "newest successful backup is from [{$newest}], {$ageHours}h ago - STALE (expected at most ~24h for a daily schedule). Check the backup schedule/cron.");
        }

        return $this->resultPass('Backups', "newest successful backup: [{$newest}] ({$ageHours}h ago, {$manifest['tenant_count']} tenant(s)).");
    }

    /**
     * TASK-MVP-005. Deliberately a LIGHTWEIGHT, purely LOCAL metadata check
     * (task section 14: "Do NOT make production:check ... perform expensive
     * provider calls every execution") - reads the newest
     * `<timestamp>.offsite-status.json` sibling file `Platform\Backup\
     * Services\OffsiteSyncRunner` already writes on every sync attempt,
     * never a live call to the offsite provider. Same
     * cross-package-config-only pattern as `checkBackupHealth()` (no
     * dependency on `Platform\Backup`'s own classes, plain `json_decode`).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function checkOffsiteBackupHealth(): array
    {
        if (! config('platform-backup.offsite.enabled')) {
            return $this->resultInfo('Offsite Backup', 'disabled (BACKUP_OFFSITE_ENABLED is not true) - local backups only. See docs/implementation/backup-and-recovery.md "Offsite sync".');
        }

        $dailyRoot = rtrim((string) config('platform-backup.root'), '/').'/daily';

        if (! is_dir($dailyRoot)) {
            return $this->resultWarn('Offsite Backup', 'enabled, but no local backup directory exists yet - platform:backup:run has never succeeded here.');
        }

        $statusFiles = array_values(array_filter(
            scandir($dailyRoot) ?: [],
            fn (string $name): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}\.offsite-status\.json$/', $name)
        ));

        if ($statusFiles === []) {
            return $this->resultWarn('Offsite Backup', 'enabled, but platform:backup:sync-offsite has never run here yet.');
        }

        sort($statusFiles);
        $newestFile = $statusFiles[array_key_last($statusFiles)];
        $status = json_decode((string) file_get_contents("{$dailyRoot}/{$newestFile}"), true);

        if (! is_array($status) || ($status['status'] ?? null) !== 'success') {
            $backupName = str_replace('.offsite-status.json', '', $newestFile);

            return $this->resultWarn('Offsite Backup', "newest offsite sync attempt [{$backupName}] did not succeed (status: ".($status['status'] ?? 'unknown').') - inspect it manually.');
        }

        $ageHours = (int) round(now()->diffInHours($status['finished_at'] ?? $status['synced_at'], true));

        if ($ageHours > 48) {
            return $this->resultWarn('Offsite Backup', "newest successful offsite sync is from [{$status['timestamp']}], {$ageHours}h ago - STALE (expected at most ~24h for a daily schedule). Check the offsite sync schedule/cron.");
        }

        return $this->resultPass('Offsite Backup', "newest successful offsite sync: [{$status['timestamp']}] ({$ageHours}h ago, {$status['object_count']} object(s) at {$status['remote_path']}).");
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
