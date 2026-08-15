# Billing / Payment Provider

**Status: IMPLEMENTED, PROVIDER-AGNOSTIC (TASK-ARCH-018 + TASK-ARCH-019, 2026-08-15).** This document originally recorded Phase 0's speculative sketch. It now records what was actually built: a real, provider-neutral billing domain (`packages/Platform/Billing`) - pricing, a `Payment` state machine, a `PaymentProvider` contract, a Stripe reference adapter, a real Stripe Sandbox Checkout flow, and a signature-verified, idempotent webhook endpoint that confirms payment and changes the tenant's Subscription plan.

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
    public function createCheckout(Payment $payment, string $successUrl, string $cancelUrl): CheckoutSession;
}
```

Deliberately minimal, expressed in this platform's own terms (no "PaymentIntent"/"checkout session"/"charge" naming copied from Stripe's API shape). `PaymentResult`/`CheckoutSession` (plain, immutable DTOs) are the **only** shapes any implementation may return - no provider SDK type (e.g. `\Stripe\PaymentIntent`/`\Stripe\Checkout\Session`) is ever allowed to cross this boundary, proven by test.

`createCheckout()` was added in TASK-ARCH-019, once a real caller (`Platform\Billing\Services\CheckoutService`) existed to justify its exact shape - not spec'd speculatively ahead of time in TASK-ARCH-018. Webhook signature verification is a genuinely SEPARATE contract, `Platform\Billing\Contracts\WebhookVerifier` (see "Webhook endpoint" below) - not every future provider shares Stripe's signature-header concept, so it does not belong on `PaymentProvider` itself (task section 26).

## Provider selection

```
BILLING_PROVIDER=stripe
```

`Platform\Billing\Services\BillingProviderResolver::resolve()`/`resolveWebhookVerifier()` are the **only** places this config value is read - one `match` expression per contract, not scattered `if ($provider === 'stripe')` checks. An unrecognized value throws `UnknownBillingProviderException` immediately, never silently falling back to a default. `Platform\Billing\Providers\BillingServiceProvider` binds both `PaymentProvider::class` and `WebhookVerifier::class` to this resolver, so any code needing the configured provider just type-hints the contract.

## Configuration (`config/platform-billing.php`)

```
BILLING_PROVIDER=stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

`.env.example` contains empty placeholders only - no real credentials are ever committed. **The application does not fail to boot merely because these are blank** - `StripePaymentProvider`/`StripeWebhookVerifier`'s own constructors are where a missing `STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET` actually throw (`MissingProviderCredentialsException`), the moment something genuinely tries to resolve/use them, never during ordinary application boot for an unrelated request. `STRIPE_WEBHOOK_SECRET` (TASK-ARCH-019) is read by `StripeWebhookVerifier::verify()` to validate the `Stripe-Signature` header on every inbound webhook request.

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

`Platform\Billing\Adapters\StripePaymentProvider` uses `\Stripe\StripeClient` directly. Constructed lazily (only when `BillingProviderResolver::resolve()` is actually called, never at application boot) with the configured `STRIPE_SECRET` - throws `MissingProviderCredentialsException` immediately if blank. `createPayment()`/`retrievePayment()` operate on a raw `PaymentIntent` (TASK-ARCH-018); `createCheckout()` (TASK-ARCH-019) creates a **Stripe Checkout Session in "payment" mode** - see "Checkout flow" below for why payment mode, not subscription mode.

**Testing without network access**: tests use Stripe's own supported test seam, `\Stripe\ApiRequestor::setHttpClient()` (a real, documented, SDK-provided static override point, reset after every test), so the SDK's real request/response-deserialization path runs against canned JSON instead of a live network call. Pure status-mapping logic is additionally tested via `\Stripe\PaymentIntent::constructFrom()` fixtures with no HTTP layer involved at all. Webhook signature verification tests compute a REAL, validly-signed `Stripe-Signature` header themselves (the exact HMAC-SHA256 algorithm `\Stripe\WebhookSignature::verifyHeader()` expects: `HMAC-SHA256("{timestamp}.{payload}", secret)`), so signature verification is genuinely exercised end-to-end, not stubbed out. No test in the normal regression suite requires internet access or real Stripe credentials.

## Checkout flow

```
Tenant Admin selects a paid PlanPrice ("My Plan" -> "Upgrade Plan")
    |
    v
CheckoutController::store() - reads ONLY plan_price_id from the request
    |
    v
CheckoutService::initiate()
    |  - loads the real PlanPrice from central DB (PlanPrice::find(), never
    |    a bare `exists:` rule - see CheckoutController's own docblock for
    |    the R42-class ambient-connection reason)
    |  - rejects an inactive PlanPrice / inactive parent Plan
    |  - reuses an existing Pending Payment for the same (tenant, plan_price)
    |    pair if one exists (double-click idempotency), else calls
    |    BillingService::createPendingPayment() (amount/currency copied from
    |    PlanPrice - never client-supplied, see BillingService's own
    |    docblock)
    |  - calls PaymentProvider::createCheckout()
    v
StripePaymentProvider::createCheckout() - real Stripe Checkout Session,
"payment" mode, line item built from Payment's own already-authoritative
amount_minor/currency
    |
    v
tenant admin's browser redirected to Stripe's hosted checkout page
    |
    v
tenant completes payment on STRIPE's OWN page (this platform's UI never
collects card data)
    |
    v
browser returns to our success_url/cancel_url - reads current Payment
status, NEVER writes it (see "Success/cancel URL semantics" below)
    |
    v
(independently, server-to-server) Stripe delivers a signed webhook event
    |
    v
StripeWebhookController -> StripeWebhookVerifier -> WebhookEventProcessor
    |
    v
Payment marked Succeeded via PaymentLifecycle, then
SubscriptionLifecycle::changePlan() - see "Payment success flow" below
```

**"Payment" mode, not "subscription" mode (task section 6, deliberate).** Stripe Checkout supports a `mode: 'subscription'` that creates a Stripe-owned `Subscription`/`Customer` object pair - using it would mean Stripe silently becomes a SECOND subscription-lifecycle source of truth alongside `Platform\Subscriptions\Models\Subscription`, exactly the conflict TASK-ARCH-018 already rejected Cashier over (see "Stripe is a reference/test adapter" above). `mode: 'payment'` creates only a one-off payment - Stripe never learns this platform has a concept of "subscription" at all; `Platform\Subscriptions` remains the sole lifecycle source of truth, updated only after this platform's own webhook processing decides to call `changePlan()`.

## Tenant-facing entry point

`Platform\Billing\Http\Controllers\Tenant\CheckoutController` (`admin.saas.checkout.index`/`.store`/`.success`/`.cancel`), registered under the exact same tenant-admin middleware/ACL stack `Platform\Plans\Http\Controllers\Admin\MyPlanController` already uses (`Platform\Billing\Providers\BillingServiceProvider::boot()`). Reached from a single "Upgrade Plan" link on the existing "My Plan" page - a plain Blade `route()` call, not a PHP-level dependency (`Platform\Plans` must never import from `Platform\Billing` - see "Package boundary"). Deliberately not a pricing marketplace: one page listing every active Plan's active `PlanPrice` rows, one "Select" button per price, nothing else. No tenant self-service cancellation/downgrade exists.

## Authoritative price / ownership validation (task section 4/5, strict)

The browser submits only `plan_price_id`. `CheckoutController::store()` resolves the real `PlanPrice` via `PlanPrice::find()` - never a bare `exists:plan_prices,id` validation rule, which would query a table that structurally does not exist on this tenant-domain request's own ambient (tenant) connection at all, the same class of gap RISK_REGISTER.md R42 already found and fixed elsewhere. `CheckoutService::initiate()` then validates: the `PlanPrice` is active, its parent `Plan` is active, and (via `BillingService`) the `Subscription` genuinely belongs to the requesting `Tenant`. Amount/currency are never accepted as request input anywhere in this path - see `BillingService`'s own docblock (unchanged since TASK-ARCH-018).

## Success/cancel URL semantics - never proof of payment (task section 0/8/14, the central invariant)

`CheckoutController::success()`/`cancel()` **only ever read** the tenant's own most recent `Payment`'s CURRENT status from the database and display it - Pending if the webhook has not landed yet, Succeeded if it has already landed by the time the browser redirects back (both are simply what the database already says; neither route ever writes to it). There is no URL-embedded payment identifier to spoof (task section 27) - the query is unconditionally scoped to the current tenant context, so this is also structurally immune to "Tenant A views Tenant B's payment" by construction, not by an ownership check that could be forgotten.

## Webhook endpoint

`POST /billing/webhook/stripe` (`Platform\Billing\Http\Controllers\StripeWebhookController`), registered under the 'platform' middleware group (central context only - no tenant DB connection is ever established for this request, regardless of which host it is reached through) and CSRF-exempted (`billing/webhook/*` in `bootstrap/app.php` - a deliberately DIFFERENT path from the pre-existing `stripe/*` exemption, which belongs to `packages/Webkul/Stripe`'s own, unrelated storefront-order webhook). No Bagisto/Platform auth - Stripe's own signature is the entire trust boundary.

**Signature verification** (task section 11): `Platform\Billing\Adapters\StripeWebhookVerifier` uses Stripe's own official `\Stripe\Webhook::constructEvent()` - no hand-rolled HMAC parsing. An invalid signature returns a clean 400 with zero `Payment`/`Subscription` mutation and zero `BillingProviderEvent` row created - the verifier throws before any event is even parsed.

**Event scope** (task section 12, deliberately minimal): only two Stripe event types are mapped to a real outcome - `checkout.session.completed` (with `payment_status === 'paid'`) -> `Succeeded`, and `checkout.session.expired` -> `Canceled` (Stripe's own documented signal that the customer never completed payment within the session). Every other event type - including `payment_intent.payment_failed`, which can fire mid-session while Stripe's own hosted page is still prompting the customer to retry - is acknowledged (200 OK, so Stripe does not retry-storm) but produces zero mutation; it is still recorded in `billing_provider_events` with status `Ignored`, for audit.

**The controller never mutates anything itself** (task section 10/13) - it verifies the signature, then delegates entirely to `Platform\Billing\Services\WebhookEventProcessor`, the only caller of `PaymentLifecycle`'s transition methods and `SubscriptionLifecycle::changePlan()` anywhere in this flow.

## Provider-event idempotency: `billing_provider_events`

A new central table, `unique(['provider', 'provider_event_id'])` - the load-bearing, database-level invariant (a second row for the same event can never exist, mirroring `subscriptions.unique('tenant_id')`'s own precedent). Columns: `provider`, `provider_event_id`, `event_type`, `payment_id` (nullable - an event correlating to no known Payment is still recorded, for audit), `processing_status` (`Received`/`Processed`/`Ignored`/`Rejected` - `Platform\Billing\Enums\ProviderEventProcessingStatus`), `received_at`/`processed_at`.

**Atomicity is what actually makes this safe** (task section 15/16), not the table alone: `WebhookEventProcessor::process()` wraps the ENTIRE sequence - reading/creating the `BillingProviderEvent` row, resolving and validating the `Payment`, calling `PaymentLifecycle`, calling `SubscriptionLifecycle::changePlan()`, and marking the event `Processed` - inside ONE `DB::transaction()`. A crash anywhere inside that closure rolls back everything (MySQL/InnoDB atomicity); there is no durably-observable "Payment succeeded but plan not yet changed" intermediate state, and a retry after a genuine mid-processing crash re-enters cleanly from a `Received` (or nonexistent) row. A duplicate delivery of an already-`Processed`/`Ignored`/`Rejected` event short-circuits immediately with zero mutation attempted a second time. A genuine concurrent-delivery race on the row's own creation is caught via `UniqueConstraintViolationException` and treated as a safe no-op - the database constraint is the real backstop, not applicaton-level locking alone.

## Payment success flow

On a verified `checkout.session.completed` event: `WebhookEventProcessor` resolves the `Payment` by `provider_reference`, rejects (status `Rejected`, zero mutation) if no `Payment` matches OR if the event's own reported `amount_minor`/`currency` disagree with the `Payment`'s stored snapshot (task section 17 - "never activate a plan on ambiguous correlation"), then: `PaymentLifecycle::markSucceeded()` (skipped if already `Succeeded` - duplicate-safe), then `SubscriptionLifecycle::changePlan($subscription, $planPrice->plan)` - the SAME method TASK-ARCH-016 built and TASK-ARCH-018's Platform Admin "Change Plan" action already uses, which itself calls `TenantPlanAssignment::assign()` internally (the sole writer of `tenants.plan_id`, unchanged since TASK-ARCH-015). The webhook controller/processor never writes `payment.status`/`subscription.plan_id`/`subscription.status`/`tenant.plan_id` directly - only through these existing lifecycle services. Because `TenantEntitlements`/`TenantLimits` read `tenants.plan_id` fresh on every call with no cache layer (TASK-ARCH-016's own already-proven guarantee), `products.limit` enforcement reflects the new plan on the very next request - proven live (`PlatformCheckoutWebhookTest.php` test 13/16/17/18/19).

## Payment failure flow - unchanged from TASK-ARCH-018's policy

A `checkout.session.expired` event marks the `Payment` `Canceled` through `PaymentLifecycle` only - it does not suspend the tenant, does not touch `Subscription` status/plan, does not downgrade anything (proven live: tests 26-28). Failed-payment consequences remain an explicit, deferred policy decision, unchanged from TASK-ARCH-018.

## Platform Admin billing visibility

Tenant detail page gained a "Recent Payments" table (`Platform\Admin\Http\Controllers\TenantController::show()` - date/provider/amount/currency/status/provider reference, last 10, no raw `provider_metadata` ever rendered). Not an accounting dashboard - a minimal operational view for support/debugging.

## Security invariants - now fully exercised, not just established

- The client never supplies the authoritative amount or currency - structurally impossible (no such parameter exists anywhere in `CheckoutService`/`BillingService`).
- Tenant/payment ownership is validated structurally (`PaymentOwnershipException`), and the checkout result page has no URL-embedded identifier to spoof at all.
- Provider selection is server-controlled (`BILLING_PROVIDER` env var), never client-supplied.
- **A payment's provider reference is never trusted as proof of payment on its own** - only a signature-verified webhook event (or a future `retrievePayment()` reconciliation call) may transition a `Payment`.
- **No public "mark paid" endpoint exists anywhere** - `PaymentLifecycle`'s transition methods are called ONLY from `WebhookEventProcessor`, itself called ONLY from the signature-verified webhook controller.
- **The browser "success" redirect never proves payment** - `CheckoutController::success()`/`cancel()` are read-only.
- **Invalid webhook signatures are rejected before any event is even parsed** - zero mutation, zero audit row.
- **Replayed/duplicate webhook events are idempotent** - proven live (tests 20-21).
- **Amount/currency mismatches between a webhook event and its correlated Payment are rejected**, never activating a plan on ambiguous correlation (tests 24-25).
- **An event correlating to no known Payment is rejected** (tests 22-23), never guessed at.
- Secrets (`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`) are never logged - read only inside the adapter/verifier constructors to build SDK clients.

## Future provider adapter (Palestinian bank gateway, or any other)

```php
class BankXPaymentProvider implements PaymentProvider
{
    public function createPayment(Payment $payment): PaymentResult { /* ... */ }
    public function retrievePayment(string $providerReference): PaymentResult { /* ... */ }
    public function createCheckout(Payment $payment, string $successUrl, string $cancelUrl): CheckoutSession { /* ... */ }
}

// If BankX has an inbound webhook/callback concept at all - not every
// provider will (task section 26: a bank might use polling or
// server-to-server verification instead):
class BankXWebhookVerifier implements WebhookVerifier
{
    public function verify(string $payload, string $signatureHeader): WebhookEvent { /* ... */ }
}
```

```
BILLING_PROVIDER=bank_x
```

Plus one new `match` arm in `BillingProviderResolver::resolve()`/`resolveWebhookVerifier()`, and (only if BankX has a webhook concept) its own dedicated controller/route at its own URL - deliberately NOT a shared, dynamically-dispatched webhook controller, since the HTTP-level concerns (header names, raw body format, even the delivery mechanism itself) are provider-specific from the first line (`StripeWebhookController`'s own docblock). **Nothing else changes**: the `payments`/`plan_prices`/`billing_provider_events` schema, `Platform\Subscriptions`, `Platform\Plans`, `TenantPlanAssignment`, `TenantEntitlements`, product enforcement, and tenant lifecycle are all completely unaware any specific provider exists.

## What TASK-ARCH-019 still leaves for later

`billing_accounts` (provider-specific customer identity) remains deferred - no real recurring/saved-payment-method need has emerged yet. Refunds remain unimplemented as a capability (`PaymentStatus::Refunded` exists as a representable state only - see `PaymentLifecycle`'s own docblock). Tenant self-service cancellation/downgrade, invoices, automatic renewal, and failed-payment suspension policy all remain explicitly out of scope, unchanged.
