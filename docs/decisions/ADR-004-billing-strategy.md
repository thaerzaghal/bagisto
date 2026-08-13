# ADR-004: Billing Strategy — Abstraction Now, Provider Deferred

## Status
Accepted for the abstraction; provider selection **HUMAN DECISION REQUIRED** (not yet made)

## Context
`laravel/cashier ^16.0` is already present in `composer.json`/`composer.lock` but entirely unused in this codebase (verified: no `Billable` trait, no `Laravel\Cashier` import anywhere). The brief explicitly forbids assuming Stripe/Cashier as the provider without justification, while allowing the provider decision to be deferred behind an abstraction.

## Decision
1. Define a `BillingProvider` interface (`packages/Platform/Billing/Contracts`) that all subscription/plan domain logic depends on exclusively — no direct SDK imports outside the concrete provider implementation.
2. Do not select a concrete provider in Phase 0. `laravel/cashier`'s presence in `composer.lock` is incidental (likely a common SaaS-starter dependency), not a prior architectural commitment.
3. Existing Bagisto payment packages (`Webkul\Stripe`, `Paypal`, `Razorpay`, `PayU`, `PayGlocal`, `PhonePe`) are storefront checkout gateways for tenant's shoppers — confirmed to share no code path with SaaS subscription billing and require zero changes.

## Consequences
- Provider selection can be made later (Phase 11) based on business inputs (target countries, currencies, fee structure, tax/compliance burden) without having designed the subscription domain model around a specific vendor's data model.
- Slightly more upfront engineering (an interface + one implementation, versus calling Cashier directly) — accepted as worthwhile given the brief's explicit requirement and the real possibility the eventual choice isn't Cashier/Stripe at all (e.g. Paddle as a merchant-of-record for global tax handling).

## Open question flagged for the provider decision (HUMAN DECISION REQUIRED, see DECISION_LOG item 2)
Target countries/currencies for charging tenants, and whether merchant-of-record tax handling (Paddle-style) is worth its typically higher fees versus direct Stripe/Cashier plus in-house tax compliance. Not resolvable from repository analysis — requires business input.

## Alternatives considered
- **Commit to Cashier now since it's already a dependency** — rejected: the brief explicitly instructs against this, and the dependency's presence doesn't reflect an actual prior decision (confirmed unused).
- **No abstraction, direct Stripe/Cashier calls throughout the subscription domain** — rejected: cheaper short-term, but couples core business logic (plan changes, cancellation, trial handling) to one vendor's API shape, making a future provider switch (or a second-provider requirement, e.g. for a market Stripe doesn't serve) a rewrite rather than a new adapter.
