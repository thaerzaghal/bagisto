<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-MVP-023 (RISK_REGISTER.md R78). PRODUCT DECISION: the only
 * supported storefront payment method for the current MVP is Cash On
 * Delivery. Money Transfer remains present but deliberately inactive
 * (TASK-MVP-016). Every OTHER bundled Bagisto payment method is
 * explicitly OUT OF CURRENT MVP PRODUCT SCOPE - not merely "missing
 * credentials."
 *
 * ROOT CAUSE this list closes (confirmed by direct source reading of
 * each package's own `Config/payment-methods.php`, not assumed or
 * inferred from one gateway to another): every one of these seven
 * bundled gateways ships `'active' => true` as its OWN raw Laravel
 * config default. Six of the seven override `isAvailable()` to also
 * require `hasValidCredentials()` (`stripe`/`razorpay`/`payu`/`phonepe`/
 * `payglocal`) - but that check only verifies the configured credential
 * fields are non-empty STRINGS, and every one of them ships a non-empty
 * PLACEHOLDER string as its own default (e.g. Stripe's
 * `api_test_key => 'API_TEST_KEY'`, Razorpay's
 * `test_client_id => 'TEST_CLIENT_ID'`) - so the check always passes.
 * `paypal_smart_button`/`paypal_standard` have NO `isAvailable()`
 * override at all - a bare `active` check only. The real, authoritative
 * checkout entry point, `Webkul\Payment\Payment::getPaymentMethods()`
 * (via the `Payment` facade, consumed by
 * `Webkul\Shop\Http\Controllers\API\OnepageController` for both listing
 * AND server-side validating a submitted payment method), iterates
 * every configured method and includes any whose `isAvailable()` is
 * true - so, absent this list, every one of these seven methods is
 * structurally selectable by a real shopper on any tenant that never
 * explicitly configures them, using literally-fake placeholder
 * credentials.
 *
 * Deliberately a plain, closed, reviewed list of bare
 * `sales.payment_methods.{code}` codes - never derived at runtime from
 * "every configured payment method other than COD/Money Transfer," so a
 * future Bagisto upgrade adding a new bundled gateway does not silently
 * become deactivated (or, worse, silently stay exposed) without a
 * deliberate review of this list. Shared by
 * `TenantProvisioner::ensureUnsupportedPaymentGatewaysDeactivated()`
 * (new tenants) and `Platform\Tenancy\Console\Commands\
 * EnforceCodOnlyPaymentPosture` (existing-tenant remediation) so the
 * policy is defined exactly once.
 */
final class UnsupportedPaymentGateways
{
    /**
     * Bare `sales.payment_methods.{code}` codes for every bundled
     * Bagisto payment method that is currently out of MVP product scope
     * (i.e. every method except `cashondelivery` and `moneytransfer`,
     * which are governed by their own established, separate policy).
     *
     * @var array<int, string>
     */
    const CODES = [
        'stripe',
        'razorpay',
        'payu',
        'phonepe',
        'paypal_smart_button',
        'paypal_standard',
        'payglocal',
    ];
}
