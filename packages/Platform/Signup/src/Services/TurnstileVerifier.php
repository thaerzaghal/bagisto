<?php

declare(strict_types=1);

namespace Platform\Signup\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TASK-MVP-006 (RISK_REGISTER.md - public signup abuse). The ONE
 * server-side gate `SignupController::store()` calls BEFORE
 * `MerchantOnboarding::register()` - every real side effect of a signup
 * (Tenant/Domain rows, a physical MySQL database, ~137/138-table
 * migration, seeding, a Subscription, an Admin) is expensive, so this
 * check happens before any of it, never after.
 *
 * FAIL-CLOSED WHEN ENABLED (deliberate, task-mandated): a missing token,
 * an invalid/expired token, a Cloudflare timeout/network error, a
 * non-2xx response, or a malformed response body are ALL treated
 * identically - `verify()` returns `false`. There is no "verification
 * service unavailable, let the merchant through anyway" branch; a
 * Cloudflare outage means public signup is temporarily unavailable, not
 * temporarily unprotected. `TURNSTILE_ENABLED=false` is the ONLY way to
 * bypass this, and is a deliberate, explicit, config-level choice - never
 * an implicit side effect of Cloudflare being unreachable.
 *
 * Uses Laravel's own `Http` facade (real HTTP client, real short timeout)
 * rather than a hand-rolled cURL call - `Http::fake()` in tests is the
 * same accepted "mock the external network boundary" exception this
 * project already uses for the Stripe SDK's own official test seam
 * (RISK_REGISTER.md/PROJECT_CONTEXT.md's own stated testing policy) -
 * Cloudflare's `siteverify` endpoint is external infrastructure, not this
 * project's own database/filesystem/cache/queue.
 *
 * Never logs the secret, the raw token, or the full Cloudflare response
 * body - only ever returns a plain boolean to its caller.
 */
class TurnstileVerifier
{
    protected const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected const TIMEOUT_SECONDS = 5;

    public function verify(?string $token, ?string $remoteIp): bool
    {
        if (! (bool) config('platform.signup.turnstile.enabled')) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        $secret = (string) config('platform.signup.turnstile.secret_key');

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::VERIFY_URL, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);
        } catch (Throwable) {
            // Network error, DNS failure, connection timeout, etc. - fail
            // closed, exactly like every other rejection path below.
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = $response->json();

        return is_array($body) && ($body['success'] ?? false) === true;
    }
}
