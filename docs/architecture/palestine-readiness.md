# Palestine Readiness / Defaults (TASK-MVP-016)

## Product decision — IMPLEMENTED

**New tenants are Palestine-first by default.** The current, exclusive target market is Palestinian merchants/customers. Every newly provisioned tenant now gets: ILS as its only currency, `Asia/Hebron` as its channel-facing (order-view/order-email) timezone, the 16 real governorates of the State of Palestine seeded as selectable address states, postcode marked optional, and Cash on Delivery enabled by default. This builds directly on TASK-MVP-012's Arabic-first work — the same tenant is now Arabic-first *and* Palestine-first, not two separate postures.

**Explicitly NOT part of this decision**: Platform Admin stays unaffected (all of this is tenant-scoped or, for the one genuinely global lever, chosen because every current tenant is already Palestine-focused — see "Global default-country assumption" below). Existing Ready tenants are never auto-migrated. No tax rate, no shipping fee, and no Money Transfer bank details are set — see "Explicit non-goals" below.

## What already existed vs. what this task added

Bagisto already fully supported everything this task needed as *data*: ILS is a recognized currency (`Webkul\Core\Helpers\SupportedCurrencies`), Palestine (`PS`) already exists in `countries.json`, `channels.timezone` already exists as a column `Core::formatDate()` already reads, `country_states`/`country_state_translations` already exist as generic seedable tables, and Cash on Delivery/Money Transfer are both fully built payment methods. Nothing here needed new Bagisto functionality — only new *provisioning-time data*, following the exact idiom TASK-MVP-012 already established for Arabic locale/theme content.

## The five new Platform-owned provisioning steps

All five live in `Platform\Tenancy\Services\TenantProvisioner`, inserted into `provision()` right after `ensureArabicThemeContentSeeded()`. Each is idempotent (safe on a retried Pending/Failed tenant) and unreachable for an already-`Ready` tenant (the same `provision()`-level early-return every TASK-MVP-012 addition already relies on).

### 1. `ensurePalestineCurrencySeeded()`

Updates the tenant's single seeded currency row (always `config('app.currency')`, USD by default) in place to ILS — code/name/symbol (₪)/decimal(2). No new currency row, no FK rewiring: `channels.base_currency_id`/`channel_currencies` already point at the one row that exists.

**Deliberately ILS-only.** A secondary (USD) currency was evaluated and rejected for now: `Core::convertPrice()` silently returns the *unconverted* raw number when no `currency_exchange_rates` row exists for the target currency (confirmed by reading its source) — exactly the risk the product owner wanted to avoid. Bagisto does have automation for this (`exchange-rate:update`, schedulable via `general.exchange_rates.schedule.*`), but its correctness in *this* multi-tenant fork's scheduler context was not verified during this task. **Dual-currency/USD-secondary support is explicitly deferred** until that's verified — see RISK_REGISTER.md.

### 2. `ensurePalestineGovernoratesSeeded()`

Seeds the 16 governorates of the State of Palestine into `country_states`/`country_state_translations`. Authoritative source and the full list live in `Platform\Tenancy\Support\PalestineGovernorates::ALL` — see "Authoritative governorate list" below.

**Important, disclosed trade-off — read before changing anything here.** `Webkul\Core\Core::groupedStatesByCountries()` — which powers the *real* storefront checkout state dropdown (`GET shop.api.core.states`) — reads `country_states.default_name` via a raw `DB::table(...)->get()`, confirmed by direct source reading to bypass the `CountryState` Eloquent model (and therefore Astrotomic Translatable) **entirely**. This means the live checkout dropdown always shows whichever single language is in that one base column, regardless of the shopper's active locale. Since Arabic is this tenant type's *primary* locale (English is explicitly secondary), the base column is set to the **Arabic** name — the opposite of every other country in Bagisto's own `states.json`, which stores English there (correct for a platform where English is normally primary). `country_state_translations` is *also* populated (`ar` + `en` rows) for schema completeness and any future code path that does honor the Eloquent model, but this will **not** change what the live dropdown shows today.

**Residual limitation, disclosed, not fixed here**: a shopper who switches to the secondary `en` locale (`?locale=en`) will still see Arabic governorate names in the state dropdown. Fixing that needs a `packages/Webkul` change to `groupedStatesByCountries()` itself — out of scope for this task (see "Deferred" below).

### 3. `ensurePalestineTimezoneSet()`

Sets `channels.timezone = 'Asia/Hebron'` for the tenant's one channel. The **global** `config('app.timezone')` stays `UTC`, untouched, per explicit instruction — Platform Admin and any future non-Palestine tenant are unaffected.

**Correction to an earlier finding.** TASK-ARCH-003's own decision record (`DECISION_LOG.md` C60) concluded "no per-tenant/per-channel timezone override exists anywhere in Bagisto's own config surface." A deeper check during this task found that's only partially true: `channels.timezone` is a real, pre-existing, nullable column, and `Core::formatDate()` reads it *first* (`$channel->timezone ?: config('app.timezone', 'UTC')`). `formatDate()` is what the Admin order detail view, the Shop customer order view, invoices, refunds, shipments, and every order-related email template (created/canceled/invoiced/refunded/shipped, both Admin- and Shop-side — 22 call sites, confirmed via grep) already use. C60's own conclusion stands for the *global* `app.timezone` lever it was evaluating; it simply didn't find this narrower, already-present per-channel column.

**Admin Orders DataGrid timezone — audited, NOT covered by this fix.** Traced `Webkul\Admin\DataGrids\Sales\OrderDataGrid`'s `created_at` column end to end: it declares `'type' => 'date'` with no `closure`, and `Webkul\DataGrid\DataGrid::formatRecords()` only applies per-column formatting when a closure is explicitly registered — confirmed none is, for this column. `Webkul\DataGrid\ColumnTypes\Date` (the class the `'date'` type maps to) only implements *filtering* (date-range query building), never value formatting. The raw, unconverted stored value reaches the frontend as-is. **The Orders listing grid remains displayed relative to the global `UTC` timezone, not `Asia/Hebron`**, unlike the single-order view and every order email. This is a genuine, disclosed, deliberately-not-fixed gap — see RISK_REGISTER.md R75.

### 4. `ensurePalestineAddressDefaultsSeeded()`

Writes one `core_config` row: `customer.address.requirements.postcode = 0` (off), channel-scoped. Palestine has no nationwide postal-code system in common use. `state` requirement is deliberately left at Bagisto's own default (on) — real governorates now exist, so the requirement is meaningful. `country` pre-selection is handled separately (see next section) — it is not a per-channel `core_config` field in Bagisto at all.

### 5. `ensurePalestinePaymentDefaultsSeeded()`

Writes `sales.payment_methods.cashondelivery.active = 1` (channel-scoped) plus a `title` row per tenant locale (`ar`: "الدفع عند الاستلام", `en`: "Cash on Delivery"). Confirmed via source reading that `Webkul\Payment\Listeners\GenerateInvoice` is the only real consumer of the *other* config fields (`order_status`/`invoice_status`), and only when `generate_invoice` is itself truthy — left unset/off here, so `active` + `title` alone are sufficient for a fully functional, selectable checkout option.

**Money Transfer is explicitly written as `sales.payment_methods.moneytransfer.active = 0`.** This corrects an assumption this task's own first implementation draft got wrong: `SystemConfig::getDefaultConfig()`'s fallback, when no `core_config` row exists, does not resolve to falsy — it resolves against a *separate* Laravel config file, `packages/Webkul/Payment/src/Config/payment-methods.php`, which hardcodes `'active' => true` for **both** `cashondelivery` and `moneytransfer`. Both payment methods are therefore active out of the box in stock Bagisto with zero configuration. Caught live by this task's own regression test (test 9) before deployment — the explicit `0` write is required, not optional, to achieve the approved "available but inactive until the merchant's real bank details are configured" posture.

## Global default-country assumption

`config('app.default_country')` (`config/app.php`, now `env('APP_DEFAULT_COUNTRY')`, previously hardcoded `null`) is set to `PS` for this deployment. This is **not** tenant-scoped — no per-tenant mechanism exists for this value anywhere in Bagisto; it is read directly by customer address-edit forms (Admin and Shop) for country pre-selection, and by `Webkul\Tax\Tax` as a fallback "no address yet" country for tax estimation. It is a genuine, disclosed **global** business assumption: every current and near-term tenant is Palestine-focused, so this carries no practical risk today, but **it must be revisited (made per-tenant, or reconsidered entirely) before this platform ever supports a merchant outside the current Palestine-focused market.** `.env.example` documents this and defaults to blank; only this deployment's own `.env` sets it.

## Authoritative governorate list

`Platform\Tenancy\Support\PalestineGovernorates::ALL` — the 16 governorates of the State of Palestine (11 West Bank + 5 Gaza Strip), with English and Arabic names and a bare state code matching this project's own existing `states.json` code-format convention (e.g. `JEN`, not `PS-JEN`):

| Code | English | Arabic | Region |
|---|---|---|---|
| JEN | Jenin | جنين | West Bank |
| TBS | Tubas | طوباس | West Bank |
| TKM | Tulkarm | طولكرم | West Bank |
| NBS | Nablus | نابلس | West Bank |
| QQA | Qalqilya | قلقيلية | West Bank |
| SLT | Salfit | سلفيت | West Bank |
| RBH | Ramallah and Al-Bireh | رام الله والبيرة | West Bank |
| JRH | Jericho and Al Aghwar | أريحا والأغوار | West Bank |
| JEM | Jerusalem | القدس | West Bank |
| BTH | Bethlehem | بيت لحم | West Bank |
| HBN | Hebron | الخليل | West Bank |
| NGZ | North Gaza | شمال غزة | Gaza Strip |
| GZA | Gaza | غزة | Gaza Strip |
| DEB | Deir al-Balah | دير البلح | Gaza Strip |
| KYS | Khan Yunis | خان يونس | Gaza Strip |
| RFH | Rafah | رفح | Gaza Strip |

**Source, disclosed honestly**: applied from established public administrative-geography knowledge (matches the Palestinian Central Bureau of Statistics' own governorate list and the ISO 3166-2:PS standard) during this offline implementation pass — not fetched from a live registry. The English/Arabic **names** are standard and uncontroversial; the 3-letter **codes** carry that one disclosed provenance caveat. If these codes are ever relied on for an external integration requiring certified ISO compliance, cross-check against the official ISO 3166 registry first.

## Explicit non-goals (this task)

- **No tax rate of any kind.** Confirmed via audit: Bagisto ships no tax seeder at all — every fresh tenant already starts genuinely tax-free (zero categories, zero rates). This task adds nothing here, intentionally — no legal/accounting assumption about Palestinian VAT was made, and none should be, without real operator/legal input.
- **No shipping/delivery fee.** The platform already supports Flat Rate/Free Shipping; no rate is pre-filled. A real delivery fee is merchant/operator-specific and must be entered manually. Delivery pricing by governorate/city remains a future task.
- **No Stripe or new payment gateway configuration.**
- **No USD secondary currency, no exchange-rate automation** — see "ILS-only" above.
- **No fix for the Admin Orders DataGrid timezone gap** — documented, not implemented (would need a `packages/Webkul` change).
- **No fix for the storefront state-dropdown locale-blindness** — documented, not implemented (would need a `packages/Webkul` change to `groupedStatesByCountries()`).
- **No fix for country-name Arabic translation** (`country_translations` remains empty for every country, not just Palestine — a pre-existing, platform-wide, cosmetic gap found during the audit phase).
- **No fix for phone-number format flexibility** (`Webkul\Core\Rules\PhoneNumber` accepts digits+optional leading `+` only; formatted numbers with dashes/spaces are rejected — a pre-existing, global, non-Palestine-specific limitation).
- **No per-tenant mail-sender identity.** Every tenant's order/shipment/invoice emails are currently sent "From: Technify" (`MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`, a global Laravel mail config with no per-tenant override anywhere in this codebase) — a real, pre-existing, platform-wide (not Palestine-specific) finding, formally recorded as RISK_REGISTER.md R73 and a future task, not fixed here.

## Existing-tenant policy

Identical to TASK-MVP-012's own policy: new tenants get these defaults automatically. **Existing Ready tenants (`pilot-smoke`/`test1`/`thaertest`/`mvp007-check`/`arabic-mvp-check`) are never auto-migrated** — protected by `provision()`'s own no-op-when-`Ready` guard, proven by a dedicated regression test. The global `APP_DEFAULT_COUNTRY=PS` change is the one exception to "per-tenant only," by nature of being a global Laravel config value, not tenant data — it does not write to, or otherwise touch, any existing tenant's database.

## Deferred (explicitly, not accidentally)

See RISK_REGISTER.md's TASK-MVP-016 findings section for the formally tracked list: R73 (mail sender identity), R74 (storefront state-dropdown locale-blindness), R75 (Admin Orders DataGrid timezone gap). Also deferred, not yet risk-register-tracked as they are pure scope decisions rather than defects: USD secondary currency/exchange-rate automation, governorate/city-based delivery pricing, a real local Palestinian payment gateway.
