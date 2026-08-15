# Billing / Payment Provider

**Status: IMPLEMENTED, PROVIDER-AGNOSTIC (TASK-ARCH-018, 2026-08-15).** This document originally recorded Phase 0's speculative sketch. It now records what was actually built: a real, provider-neutral billing domain (`packages/Platform/Billing`) - pricing, a `Payment` state machine, a `PaymentProvider` contract, and a Stripe reference adapter - with **no checkout flow and no webhook endpoint yet** (TASK-ARCH-019's scope).

## Stripe is a reference/test adapter, not a production commitment

Per the task's own explicit instruction: Stripe is used only as this platform's first development/reference provider, exercised exclusively in sandbox/test mode. Nothing in this codebase requires a production Stripe account, and nothing assumes Stripe will be the eventual production provider - a future Palestinian bank gateway or any other regional processor plugs in behind the exact same `PaymentProvider` contract (see "Future provider adapter" below). `laravel/cashier ^16.0` remains an unused dependency, deliberately not used - `stripe/stripe-php ^17.3` (the raw SDK) is used directly instead, specifically because Cashier owns its own Stripe-shaped `Subscription`/`Customer` model concepts that would conflict with this codebase's already-built, provider-neutral `Platform\Subscriptions` domain (TASK-ARCH-016).

## Package boundary

`packages/Platform/Billing` (namespace `Platform\Billing`). Dependency direction:

```
Platform\Billing -> Platform\Subscriptions -> Platform\Plans -> Platform\Tenancy
```

`Platform\Subscriptions` remains completely unaware `Platform\Billing` exists - no class in that package imports anything from this one. `Platform\Plans\Models\Plan` likewise has **no** relation into Billing (`PlanPrice::plan()` is a one-way `belongsTo`; `PlanPrice` rows are queried by `plan_id` directly wherever needed, e.g. `Platform\Admin\Http\Controllers\PlanController::show()` - see that controller's own docblock for why a `$plan->prices()` relation was deliberately never added).

## The `PaymentProvider` contract

```php
interface PaymentProvider
{
    public function createPayment(Payment $payment): PaymentResult;
    public function retrievePayment(string $providerReference): PaymentResult;
}
```

Deliberately minimal, expressed in this platform's own terms (no "PaymentIntent"/"checkout session"/"charge" naming copied from Stripe's API shape). `PaymentResult` (a plain, immutable DTO: `providerReference`, `status`, `failureCode`, `failureMessage`) is the **only** shape any implementation may return - no provider SDK type (e.g. `\Stripe\PaymentIntent`) is ever allowed to cross this boundary, proven by test (`PlatformBillingManagementTest.php` tests 19-21).

Checkout-initiation and webhook-specific methods are deliberately **not** part of this contract yet - they belong to TASK-ARCH-019, once a real caller (an actual checkout flow) exists to justify their exact shape, rather than being guessed at now.

## Provider selection

```
BILLING_PROVIDER=stripe
```

`Platform\Billing\Services\BillingProviderResolver::resolve()` is the **one** place this config value is read - a single `match` expression, not scattered `if ($provider === 'stripe')` checks. An unrecognized value throws `UnknownBillingProviderException` immediately, never silently falling back to a default. `Platform\Billing\Providers\BillingServiceProvider` binds `PaymentProvider::class` to this resolver, so any code needing the configured provider just type-hints `PaymentProvider`.

## Configuration (`config/platform-billing.php`)

```
BILLING_PROVIDER=stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

`.env.example` contains empty placeholders only - no real credentials are ever committed. **The application does not fail to boot merely because these are blank** - `StripePaymentProvider`'s own constructor is where a missing `STRIPE_SECRET` actually throws (`MissingProviderCredentialsException`), the moment something genuinely tries to resolve/use the Stripe provider, never during ordinary application boot for an unrelated request. `STRIPE_WEBHOOK_SECRET` is present in config now (so `.env.example` documents the full eventual shape in one place) but read nowhere in this task - no webhook endpoint exists yet.

## Money representation

Every amount in this domain is an **integer count of the currency's smallest unit** (minor units) - e.g. `1000` = $10.00 for a 2-decimal currency. No float ever represents money anywhere in this codebase, persisted or in-memory. Currency is a plain 3-character code (`char(3)` at the schema level), normalized to uppercase by the one place that accepts it as user input (`Platform\Admin\Http\Controllers\PlanPriceController`) - no fixed currency allowlist exists, since this platform does not hardcode USD (a Palestinian-market deployment might reasonably use ILS/JOD/USD).

## Pricing: `plan_prices`

A **separate** central table from `plans` - not `price_monthly`/`price_yearly` columns on `Plan` itself. This is the only design that cleanly supports more than exactly two fixed intervals later without a schema change.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `plan_id` | FK → `plans.id`, `cascadeOnDelete()` | |
| `billing_interval` | string, cast to `BillingInterval` (`monthly`/`yearly`) | Not tied to any provider's own interval identifiers |
| `interval_count` | unsigned int, default 1 | |
| `amount_minor` | unsigned bigint | |
| `currency` | char(3) | |
| `is_active` | boolean, default true | Deactivation is the only lifecycle mechanism - no hard delete, matching `Plan`'s own established precedent. A deactivated price cannot be used to create a NEW `Payment` (`InactivePlanPriceException`), but is never removed - a historical `Payment.plan_price_id` must remain resolvable. |

## Payments: `payments`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `tenant_id` | string, FK → `tenants.id`, `cascadeOnDelete()` | |
| `subscription_id` | FK → `subscriptions.id`, nullable, `nullOnDelete()` | |
| `plan_price_id` | FK → `plan_prices.id`, nullable, `nullOnDelete()` | Traceability metadata only - see "Historical snapshot" below |
| `provider` | string | e.g. `'stripe'` |
| `provider_reference` | string, nullable | The provider's own ID for this attempt (Stripe's PaymentIntent ID today) - one provider-neutral column, no `stripe_id`/`payment_intent_id` column |
| `status` | string, cast to `PaymentStatus` | |
| `amount_minor` / `currency` | unsigned bigint / char(3) | The authoritative historical snapshot - see below |
| `failure_code` / `failure_message` | nullable | |
| `paid_at` / `failed_at` / `refunded_at` | nullable timestamps | |
| `provider_metadata` | nullable JSON | Raw provider payload, audit/debugging only - **no business logic anywhere reads this column** |

**Historical snapshot, not a live join (load-bearing).** `amount_minor`/`currency` are copied onto the `Payment` row at creation time and never re-derived from `plan_price_id` afterward. Changing a `PlanPrice`'s amount later, or even deleting it (hence `nullOnDelete()`), must never alter what a real historical `Payment` says it charged/attempted to charge - proven by test (`PlatformBillingManagementTest.php` test 7).

## `PaymentStatus`

```php
enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
```

Deliberately minimal - not Stripe's complete PaymentIntent status set. Stripe mapping (`StripePaymentProvider::mapStatus()`): `requires_payment_method`/`requires_confirmation`/`processing`/`requires_capture` → `Pending`; `requires_action` → `RequiresAction`; `succeeded` → `Succeeded`; `canceled` → `Canceled`. `Refunded` has no PaymentIntent equivalent (Stripe represents a refund as a separate object) - this platform sets it explicitly once a refund is recorded against an already-`Succeeded` payment; the operation to actually perform a refund is not built in this task.

## `BillingService` - the entry point for creating a Payment

```php
BillingService::createPendingPayment(Tenant $tenant, Subscription $subscription, PlanPrice $planPrice): Payment
```

**Never accepts amount/currency as parameters at all** - there is structurally no code path for a caller to supply its own values; `amount_minor`/`currency` are always copied from the given `PlanPrice`. This is what makes "a client cannot override the authoritative amount/currency" true by construction, not by discipline at each call site.

**Ownership enforced once, here**: the given `Subscription` must belong to the given `Tenant` (`PaymentOwnershipException` otherwise) - every future caller (Platform Admin, TASK-ARCH-019's tenant-facing checkout) gets this guarantee for free.

**Plan consistency is deliberately not checked here**: `$planPrice->plan_id` is expected to normally *differ* from `$subscription->plan_id` (the common case is "tenant on Free buys a Pro `PlanPrice`" - the subscription only moves to the new plan after a confirmed payment, via `SubscriptionLifecycle::changePlan()`, which this task does not call). Which `PlanPrice` correctly represents what the tenant actually selected is the caller's responsibility.

## `PaymentLifecycle` - the one entry point for every Payment transition

Mirrors `SubscriptionLifecycle`'s shape exactly - no controller anywhere mutates `$payment->status` directly.

```
Pending                -> RequiresAction   markRequiresAction()
Pending/RequiresAction -> Succeeded        markSucceeded()
Pending/RequiresAction -> Failed           markFailed()
Pending/RequiresAction -> Canceled         markCanceled()
Succeeded              -> Refunded         markRefunded()
```

**No controller calls this in TASK-ARCH-018** - no checkout/webhook HTTP endpoint exists yet, which is precisely the point: a `Payment`'s success/failure state can only ever be recorded through this internal, domain-level service, never through any public client-facing mutation path. There is no "mark paid" endpoint anywhere in this codebase.

**Refunds are NOT an implemented capability in this task.** `markRefunded()` exists purely so `PaymentStatus::Refunded` is a structurally representable, transition-matrix-validated state (task section 20/28: the status may exist without the operation) - it does exactly what every other method above does and no more, a local status/`refunded_at` write with zero provider interaction. It does **not** call any `PaymentProvider` method, does not reverse a charge with Stripe or any other provider, has no Platform Admin button, and has no real caller anywhere in this codebase. Actually executing a refund (calling the provider, then recording the result) is undesigned and unbuilt - a future task's decision, not assumed here.

## Failed-payment policy: explicitly deferred

`markFailed()` changes **only** the `Payment`'s own status/`failure_code`/`failure_message`/`failed_at`. It does not suspend the tenant, does not touch `Subscription` status/plan, does not cancel or expire anything - proven by test (tests 13-14). Deciding what SHOULD happen after repeated payment failures (grace periods, suspension, downgrade) is an explicit, separate policy decision for a future task, not something this foundation invents speculatively.

## Free subscriptions require no `Payment`

The TASK-ARCH-016 invariant remains: every tenant has a `Subscription`. It does **not** follow that every `Subscription` requires a `Payment` - a tenant provisioned on the free plan gets a `Subscription` (as always) and zero `Payment` rows; no `$0` Stripe transaction is ever created merely because every tenant has a subscription (proven by test 15).

## Stripe reference adapter

`Platform\Billing\Adapters\StripePaymentProvider` uses `\Stripe\StripeClient` directly. Constructed lazily (only when `BillingProviderResolver::resolve()` is actually called, never at application boot) with the configured `STRIPE_SECRET` - throws `MissingProviderCredentialsException` immediately if blank. `createPayment()` creates a real server-side `PaymentIntent` via the SDK (proving the SDK-call/response-mapping path end-to-end); `retrievePayment()` re-queries a previously-created one by its `provider_reference`. Neither builds any browser-facing confirmation UI or webhook handling - that is TASK-ARCH-019.

**Testing without network access**: tests use Stripe's own supported test seam, `\Stripe\ApiRequestor::setHttpClient()` (a real, documented, SDK-provided static override point, reset after every test), so the SDK's real request/response-deserialization path runs against canned JSON instead of a live network call. Pure status-mapping logic is additionally tested via `\Stripe\PaymentIntent::constructFrom()` fixtures with no HTTP layer involved at all. No test in the normal regression suite requires internet access or real Stripe credentials.

## Security invariants (established now, exercised fully in TASK-ARCH-019)

- The client never supplies the authoritative amount or currency - `BillingService` derives both from `PlanPrice` exclusively.
- Tenant/payment ownership is validated structurally (`PaymentOwnershipException`).
- Provider selection is server-controlled (`BILLING_PROVIDER` env var, never a client-supplied value).
- **A payment's provider reference is never trusted as proof of payment on its own** - `PaymentProvider::retrievePayment()` exists specifically so a caller can re-verify against the provider directly rather than accepting an unverified claim.
- **No public "mark paid" endpoint exists anywhere.** `PaymentLifecycle`'s transition methods are plain service-class methods with zero HTTP route bound to them in this task.
- Never trust a browser "success" redirect as proof of payment (TASK-ARCH-019's own explicit invariant to build against - documented here so the constraint is visible before that task starts).

## Future provider adapter (Palestinian bank gateway, or any other)

```php
class BankXPaymentProvider implements PaymentProvider
{
    public function createPayment(Payment $payment): PaymentResult { /* ... */ }
    public function retrievePayment(string $providerReference): PaymentResult { /* ... */ }
}
```

```
BILLING_PROVIDER=bank_x
```

Plus one new `match` arm in `BillingProviderResolver::resolve()`. **Nothing else changes**: the `payments`/`plan_prices` schema, `Platform\Subscriptions`, `Platform\Plans`, `TenantPlanAssignment`, `TenantEntitlements`, product enforcement, and tenant lifecycle are all completely unaware any specific provider exists.

## What TASK-ARCH-019 will still need

A real checkout-initiation flow, a webhook endpoint with signature verification and idempotent event processing, wiring a confirmed payment to `SubscriptionLifecycle::changePlan()`, Platform Admin billing visibility (recent payment/status/amount on the tenant detail page), and the "never trust the browser redirect" enforcement this document already commits to. `billing_accounts` (provider-specific customer identity) remains deferred until a real checkout flow proves it is needed.
