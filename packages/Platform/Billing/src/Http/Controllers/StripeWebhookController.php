<?php

declare(strict_types=1);

namespace Platform\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Platform\Billing\Adapters\StripeWebhookVerifier;
use Platform\Billing\Exceptions\InvalidWebhookSignatureException;
use Platform\Billing\Exceptions\MissingProviderCredentialsException;
use Platform\Billing\Services\WebhookEventProcessor;

/**
 * TASK-ARCH-019 (task section 10/13). Central-context only - registered
 * under the 'platform' middleware group (`Platform\Billing\Providers\
 * BillingServiceProvider::boot()`), the SAME group Platform Admin's own
 * central-only routes use, which is `web` minus `InitializeTenancyByDomain`
 * - no tenant DB connection is ever established for this request,
 * regardless of which host it was reached through. CSRF-exempted (see
 * `bootstrap/app.php`'s `validateCsrfTokens(except: [...])` - a webhook is
 * a server-to-server call with no browser session, CSRF protection has no
 * meaning here). No Bagisto/Platform auth of any kind - Stripe's own
 * signature is the entire trust boundary (task section 10).
 *
 * DOES NOT MUTATE ANYTHING ITSELF (task section 10/13, strict): this
 * controller's only two jobs are (1) verify the signature and (2)
 * delegate the resulting provider-neutral event to
 * `Platform\Billing\Services\WebhookEventProcessor`. It never touches a
 * `Payment`/`Subscription` model directly.
 *
 * INVALID SIGNATURE (task section 11): a clean 400, zero mutation, zero
 * `BillingProviderEvent` row created - `InvalidWebhookSignatureException`
 * is thrown by the verifier BEFORE any event is even parsed, so
 * `WebhookEventProcessor` is never reached at all for a forged/tampered
 * payload.
 *
 * DELIBERATELY TYPE-HINTS `StripeWebhookVerifier` DIRECTLY, not the
 * generic `WebhookVerifier` contract (unlike `PaymentProvider`, which IS
 * resolved generically elsewhere) - this controller's own header-name
 * (`Stripe-Signature`) and route path (`billing/webhook/stripe`) are
 * already Stripe-specific from the first line; a future bank adapter gets
 * its OWN dedicated controller/route/header-extraction logic (task
 * section 26 - a signed callback, polling, or a completely different
 * event format may not even use an HTTP header the same way), not a
 * shared dynamic dispatch that would add indirection without real
 * provider-agility here.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookVerifier $verifier, WebhookEventProcessor $processor): Response
    {
        $signatureHeader = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $verifier->verify($request->getContent(), $signatureHeader);
        } catch (InvalidWebhookSignatureException) {
            return response('Invalid signature.', 400);
        } catch (MissingProviderCredentialsException) {
            // Misconfiguration, not an attack - still must not leak
            // internal detail or process anything.
            return response('Webhook not configured.', 503);
        }

        $processor->process('stripe', $event);

        return response('OK', 200);
    }
}
