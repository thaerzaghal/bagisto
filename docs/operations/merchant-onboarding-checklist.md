# Merchant Onboarding Checklist (TASK-MVP-015)

Practical, step-by-step checklist for onboarding one real merchant through
managed onboarding (`Platform Admin -> Create Merchant`). Public self-service
signup (`/join`) is disabled in production — this is the only active path.

**Tags**: `[Automated]` the platform does this for you · `[Operator]` you do
this in Platform Admin or manually · `[Merchant]` the merchant does this ·
`[Verify]` a manual check with no automated pass/fail — use your own judgment.

The tenant's own **Onboarding Status** panel (`platform/tenants/{id}`) shows a
few live, derived facts (tenant Ready, domain configured, owner identity
recorded, plan/subscription consistent, Arabic locale configured). It does
**not** know whether payment/shipping/tax/catalog is configured, whether
checkout works, or whether the merchant has activated their account — those
stay on you, in this checklist, verified by hand.

---

## 1. Information to collect

`[Operator]` Before opening "Create Merchant", have ready:

- [ ] Store display name
- [ ] Desired store address (slug) — lowercase letters/numbers/hyphens only
- [ ] Owner first name
- [ ] Owner last name
- [ ] Owner email (the merchant must actually receive mail at this address —
      they set their own password here)
- [ ] Plan to assign

Do not collect anything else at this stage (business type, phone, address,
etc.) — none of it is used by tenant creation, and it can be gathered later
during store configuration if actually needed.

## 2. Create merchant

`[Operator]` Platform Admin → Tenants → **Create Merchant**
(`platform/tenants/create`).

- [ ] Fill in the fields from Section 1
- [ ] Submit — this blocks for **~20-30 seconds** (real, synchronous
      provisioning: physical database, migrations, seed data, Arabic locale,
      subscription, owner Admin row). Do not refresh or double-submit.
- [ ] `[Automated]` Tenant + domain rows created, physical database
      provisioned, Arabic (`ar`, RTL) set as default locale with English as
      secondary, subscription started on the selected plan, owner Admin row
      seeded with the real name/email (temporary password generated and
      discarded server-side — never operator-visible)

## 3. Confirm provisioning

`[Verify]` On the redirect to `platform/tenants/{id}`:

- [ ] Status shows **Ready** (not Pending/Provisioning/Failed)
- [ ] If **Failed**: read `Last error`, fix the underlying cause, then use
      **Provision / Retry** on the same page — safe to click multiple times
- [ ] Onboarding Status panel shows: Tenant provisioned = Yes, Domain
      configured = Yes, Owner identity recorded = Yes, Plan & subscription
      consistent = Yes, Arabic locale configured = Yes
- [ ] A confirmation message names the tenant and confirms whether the
      activation email was sent

## 4. Merchant activation

- [ ] `[Automated]` An activation email is sent automatically on successful
      creation (a real Bagisto Admin password-reset email, addressed to the
      tenant's own domain, in the tenant's own default language)
- [ ] `[Operator]` If the page showed "owner activation email could not be
      sent", use **Resend activation email** on the tenant page once the
      underlying issue (usually SMTP) is resolved
- [ ] `[Merchant]` Merchant checks their inbox (and spam folder), opens the
      reset link, sets their own password
- [ ] `[Merchant]` Merchant logs into their tenant Admin
      (`https://{slug}.{base-domain}/admin/login`)
- [ ] `[Verify]` **Ask the merchant to confirm they logged in successfully.**
      Platform Admin has no automated way to detect this — there is
      deliberately no activation-tracking table. Do not assume activation
      happened just because the email was sent.

## 5. Configure store

`[Merchant]` (or `[Operator]`, if you are setting the store up on the
merchant's behalf before handoff) — all via the tenant's own Admin
**Configure** settings, already fully supported by stock Bagisto:

- [ ] `[Automated]` Store logo and favicon are NOT set automatically — still
      needs `[Merchant]`/`[Operator]` action (Configure → General → Design)
- [ ] Store contact email / notification settings (Configure → Emails)
- [ ] `[Automated]` Currency — ILS (₪), set automatically at provisioning
      (TASK-MVP-016). No secondary currency is enabled; changing the base
      currency after real products/orders exist is risky (existing prices
      would be silently reinterpreted under the new currency) — do not
      change it on a live store.
- [ ] `[Automated]` Address requirements — country defaults to Palestine,
      postcode requirement is off, state (governorate) stays required with
      the real 16 Palestinian governorates already seeded and selectable.
      `[Verify]` this still matches what the merchant actually needs.
- [ ] Shipping origin address (Configure → Sales → Shipping) — still
      `[Merchant]`/`[Operator]` input, not automated
- [ ] Theme/homepage content — provisioning clones the English placeholder
      content into Arabic verbatim (untranslated) so the page doesn't crash;
      **it is not real Arabic marketing copy**. Replace it with the
      merchant's actual content.

`[Verify]` Spot-check the storefront homepage renders correctly in Arabic
(`lang="ar" dir="rtl"`) after any theme changes.

## 6. Add/verify catalog

`[Merchant]` (or `[Operator]` on their behalf):

- [ ] At least one category created
- [ ] At least one product created, with:
  - [ ] Name, description (Arabic, since this is an Arabic-first store)
  - [ ] Price
  - [ ] At least one image
  - [ ] Stock/inventory quantity set
  - [ ] Status = enabled, visible individually = yes

`[Verify]` Product appears on the storefront (category page and/or homepage),
correct name/price/image render.

## 7. Verify shipping/payment

- [ ] `[Automated]` **Cash on Delivery is enabled by default** (TASK-MVP-016)
      — `[Verify]` it appears and is selectable at checkout, no configuration
      needed.
- [ ] `[Automated]` **Money Transfer is present but inactive** — `[Merchant]`/
      `[Operator]` must enable it AND enter the merchant's real bank/account
      details (Configure → Sales → Payment Methods → Money Transfer) before
      it's usable. Never invent placeholder bank details.
- [ ] `[Merchant]`/`[Operator]` At least one shipping method enabled (Free
      Shipping or Flat Rate — built in, require no external account). The
      delivery fee itself is never pre-filled — enter the real amount.
- [ ] Stripe requires real API keys the operator does not have for merchants
      by default — not configured, not expected for a first Palestinian
      merchant.
- [ ] `[Automated]` Tax — **intentionally left unconfigured** (zero
      categories/rates seeded, by design). Do not assume a rate; use
      whatever the merchant/operator has actually confirmed applies with
      real legal/accounting input (Configure → Sales → Taxes). The store
      runs correctly tax-free until you do.

`[Verify]` The configured payment/shipping combination is actually
selectable at checkout (see Section 8).

## 8. Test storefront/order flow

`[Verify]` — walk this exactly as a real shopper would, on the real tenant
domain:

**Must pass before calling the store ready:**
- [ ] Storefront homepage loads (200, correct Arabic/RTL rendering)
- [ ] Merchant Admin login works
- [ ] At least one real product is visible and reachable from the storefront
- [ ] Add to cart works
- [ ] Checkout completes for the shipping/payment methods actually enabled
- [ ] The resulting order appears in Admin → Sales → Orders
- [ ] No other tenant was touched by any of this

**Should verify:**
- [ ] Product image renders correctly (not a placeholder)
- [ ] Price and stock reflect what was configured
- [ ] Order confirmation behaves reasonably (Bagisto already logs and
      swallows mail failures without affecting the order itself — a mail
      failure here is not a launch blocker, but is still worth noticing)

**Optional / later:**
- [ ] Sitemap/SEO fields
- [ ] GDPR/cookie banner content
- [ ] Custom CSS/scripts

## 9. Handoff

`[Operator]` Send the merchant (email, WhatsApp, or however you already
communicate with them):

- [ ] Their tenant Admin URL
- [ ] Their storefront URL
- [ ] Their own email (their login identity)
- [ ] Instructions to set their password via the reset email (if not already
      done in Section 4)
- [ ] What you configured for them (plan, Arabic-first store, any sample
      catalog you added)
- [ ] What they're expected to configure themselves (logo, real catalog,
      payment/shipping details specific to their business)
- [ ] How to reach you for support

**Never send a plaintext password.** None exists to send — the merchant
always sets their own via the reset link.

### Arabic handoff message template

Copy, fill in the placeholders, send directly (email/WhatsApp) — this is a
message template, not an automated notification:

```
مرحباً {اسم صاحب المتجر}،

متجرك الإلكتروني أصبح جاهزاً على منصتنا.

رابط لوحة تحكم المتجر (Admin):
https://{slug}.{النطاق}/admin/login

رابط المتجر (الواجهة الأمامية):
https://{slug}.{النطاق}

بريدك الإلكتروني لتسجيل الدخول: {owner_email}

ستصلك رسالة بريد إلكتروني منفصلة لتعيين كلمة المرور الخاصة بك - إذا لم تصلك
خلال دقائق، يرجى مراجعة مجلد الرسائل غير المرغوب فيها (Spam).

ما تم إعداده لك:
- متجر باللغة العربية (مع إمكانية التبديل إلى الإنجليزية)
- خطة الاشتراك: {اسم الخطة}
{- أي فئات/منتجات نموذجية إن وُجدت}

ما يتبقى عليك إعداده:
- شعار المتجر والصور
- المنتجات الفعلية وأسعارها
- طرق الشحن والدفع المناسبة لك

لأي استفسار أو مساعدة، تواصل معنا على: {معلومات التواصل}

بالتوفيق!
```

## 10. Post-launch sanity check

`[Verify]`, once the merchant is live and using the store for real:

- [ ] `platform/tenants` still lists every other existing tenant unaffected
- [ ] This tenant's plan/subscription still shows consistent on its
      Onboarding Status panel
- [ ] No unexpected entries in production logs tied to this tenant
- [ ] `php artisan platform:production:check` still all-PASS (run this only
      if you have server access and something seems off — not a required
      step for every single onboarding)

---

See `docs/architecture/onboarding.md` for the underlying provisioning
architecture and `docs/architecture/localization.md` for the Arabic-first
design this checklist assumes.
