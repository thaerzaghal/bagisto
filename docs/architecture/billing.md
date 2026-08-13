# Billing / Payment Provider

## Confirmed starting point

`laravel/cashier ^16.0` is already a `composer.json` dependency (transitively present, likely pulled in as a common Laravel SaaS starter package or added speculatively) but **completely unused** in this codebase — no `Billable` trait usage, no `Laravel\Cashier` import anywhere in `packages/Webkul` or `app/`. This is a clean slate, not a decision already made for us — its presence in `composer.lock` does not constitute an architectural commitment to Stripe.

Bagisto's existing payment packages (`Webkul\Stripe`, `Webkul\Paypal`, `Webkul\Razorpay`, `Webkul\PayU`, `Webkul\PayGlocal`, `Webkul\PhonePe`) are storefront checkout gateways — they let a *tenant's own shoppers* pay for orders. They share no code path with SaaS subscription billing (charging the *tenant* for their platform subscription) and require no changes; the two billing concerns are architecturally unrelated beyond both ultimately calling out to payment-provider SDKs.

## Billing abstraction

Per the brief's explicit requirement, the subscription/plan domain logic must not import a payment-provider SDK directly:

```
BillingProvider (interface, packages/Platform/Billing/Contracts)
    |
    +-- CashierStripeBillingProvider   (implementation, wraps laravel/cashier)
    +-- <FutureProvider>BillingProvider
```

`Subscription`/`Plan` domain code depends only on `BillingProvider`'s interface (create subscription, cancel, resume, swap plan, record payment-method-on-file status). The concrete implementation is bound in a service provider, swappable via config — the same pattern Bagisto itself uses for its own payment gateways (`Webkul\Payment`'s base `Payment` class, extended per gateway).

## Provider selection — deferred, HUMAN DECISION REQUIRED

Not decided in Phase 0, per the brief's explicit instruction not to assume Stripe. `laravel/cashier` (Stripe-only) is the path of least implementation effort given it's already a dependency, well-maintained, and directly supported by Laravel core team — but the actual choice depends on business inputs not available from repository analysis: supported countries/currencies for charging tenants, payout requirements, fee structure, and whether Stripe is legally/commercially viable in the platform's target markets. See DECISION_LOG.md HUMAN DECISION REQUIRED item 2.

Other Laravel-ecosystem options worth evaluating once those business inputs are known (not researched further in Phase 0 since the decision is blocked on non-technical input): Laravel Cashier Paddle (merchant-of-record model, handles tax/VAT globally, worth considering specifically because it removes a lot of tax-compliance burden a small SaaS team would otherwise carry), or a direct Stripe/Paddle SDK integration behind our own `BillingProvider` implementation if Cashier's opinions don't fit.

## What Phase 11 will need regardless of provider

- Webhook endpoint(s) for subscription lifecycle events (payment succeeded/failed, subscription updated/canceled) — must live on the **central** domain (not a tenant subdomain) since it updates central `subscriptions` rows, and must authenticate the webhook signature per the provider's mechanism.
- Idempotent webhook handling (providers redeliver events) — mirrors the idempotency requirement already designed into tenant provisioning.
- A reconciliation job (scheduled) that cross-checks provider-reported subscription status against our central `subscriptions` table, catching any missed webhook.
