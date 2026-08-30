# Email Delivery (TASK-MVP-004, SMTP)

Real implementation record - central SMTP fallback, tenant SMTP override,
merchant password recovery, shopper order confirmation. This document
describes what actually exists and was actually verified; see the task's own
final report for exact evidence/dates.

## Architecture (unmodified Bagisto - nothing built here)

```
Any Bagisto mail (password reset, order confirmation, ...)
    -> Laravel's Mail/Notification facade (config('mail.default') = 'bagisto-dynamic-smtp')
    -> Webkul\Core\Mail\Transport\DynamicSmtpTransport::buildTransport()
         -> core()->getConfigData('emails.configure.smtp.host'/'port'/'encryption'/'username'/'password')
              (TENANT-scoped: reads that tenant's OWN `core_config` table -
               Admin -> Configuration -> Emails -> SMTP; empty for a tenant
               that has never saved this page)
         -> falls back to config('mail.mailers.smtp.*') (central .env) only
            when the tenant has no stored value
```

**Nothing in `packages/Platform` implements or overrides any part of this** -
`DynamicSmtpTransport` (`packages/Webkul/Core`) already provides exactly the
tenant-override-with-central-fallback behavior a SaaS platform needs, and
already existed before this task. This task is configuration + verification,
not a feature build - see the task's own audit findings for the full source-
level trace (`Illuminate\Auth\Notifications\ResetPassword`-based Admin
password reset, `Mail::queue()`-based order confirmation, both routing
through the identical transport).

**A confirmed nuance, not a bug**: `core()->getConfigData()` itself already
falls back to `config('mail.mailers.smtp.*')` too (via that field's own
declared `'default'` in `packages/Webkul/Admin/src/Config/system.php`) - so
the central fallback is effectively enforced twice, redundantly, by two
independent, unmodified Bagisto mechanisms. See
`tests/Feature/Platform/TenantMailConfigurationTest.php` tests 1/2 for the
live proof.

**`MAIL_ENCRYPTION=tls` (STARTTLS on port 587) is correct, verified against
Symfony Mailer's actual source, not assumed**: `DynamicSmtpTransport`
constructs `new EsmtpTransport(..., tls: strtolower($encryption) === 'ssl')`
- for `encryption=tls` this passes `tls: false` to the constructor, which
only means "do not use *implicit* TLS from the first byte" (correct - that
mode is for port 465). `EsmtpTransport`'s own `$autoTls` property defaults
`true` and is never touched by Bagisto's code, so a real STARTTLS upgrade is
still automatically attempted after the initial plaintext connection,
provided the server advertises the `STARTTLS` capability (Zoho's `smtp.
zoho.com:587` does). Confirmed by reading `vendor/symfony/mailer/Transport/
Smtp/EsmtpTransport.php` directly.

## Current production provider

| | |
|---|---|
| Provider | Zoho Mail (Free Organization plan) |
| SMTP host | `smtp.zoho.com` |
| Port | `587` |
| Encryption | `TLS` (STARTTLS) |
| Sender / `MAIL_USERNAME` | `support@technify.dev` |
| `MAIL_FROM_NAME` | `Technify` |
| Password | A Zoho **Application-Specific Password** (never documented here, never committed, never logged - see "Secrets" below) |

**Substituting a future provider needs no code change** - only these `.env`
values (`MAIL_HOST`/`MAIL_PORT`/`MAIL_ENCRYPTION`/`MAIL_USERNAME`/
`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`) on a real, ordinary
SMTP-compatible relay. `MAIL_MAILER` stays `bagisto-dynamic-smtp` regardless
of provider - that value selects Bagisto's own tenant-aware transport, not
the provider itself.

**A separate, distinct identity: the email "Contact us" footer address.**
Every Bagisto shop/admin email's footer ("If you need any kind of help
please contact us at...") uses `core()->getContactEmailDetails()['email']`
(`packages/Webkul/Core/src/Core.php`), which reads a tenant-overridable
Admin Configuration field (`emails.configure.email_settings.contact_email`)
falling back to `config('mail.contact.address')` -> `env('CONTACT_MAIL_ADDRESS')`
- a DIFFERENT env var from `MAIL_USERNAME`/`MAIL_FROM_ADDRESS`, pre-existing
since TASK-MVP-004A's own `.env.example` hardening, currently set to
`contact@technify.dev` on the pilot server (not touched by this task). This
is intentional Bagisto design (a merchant's customer-facing "get help"
address does not have to be the same mailbox that technically sends mail),
not a defect - but it means `contact@technify.dev` should be verified as a
real, monitored mailbox (or repointed to `support@technify.dev` via
`CONTACT_MAIL_ADDRESS`, or the tenant's own Admin Configuration -> Emails ->
Email Settings -> Contact Email field) before depending on it for real
customer support traffic.

## Per-tenant sender identity (TASK-MVP-018, RISK_REGISTER.md R73)

**A third, distinct identity: the actual `From:` name/address on every
transactional email** (order confirmation, invoice, shipment, refund,
cancellation, customer/Admin password reset) - separate from both the SMTP
credentials above and the "Contact us" footer address. Every recipient of
every one of these emails, for every tenant, used to see `From: Technify
<support@technify.dev>` - the platform's own identity, never the merchant's.

**Root cause, found by this task's own audit, not a missing feature**:
`Webkul\Core\Core::getSenderEmailDetails()` (unmodified `packages/Webkul`)
already reads a TENANT-scoped `core_config` row
(`emails.configure.email_settings.sender_name`/`sender_email`, the same
per-tenant-DB mechanism already proven isolated for SMTP above), falling
back to `config('mail.from.name')`/`config('mail.from.address')` only when
no row exists - and every Bagisto order/invoice/shipment/refund/cancellation
Mailable plus the customer/Admin password-reset Notifications already
consume it (`Shop\Mail\Mailable`/`Admin\Mail\Mailable::buildFrom()`, or an
explicit `->from(core()->getSenderEmailDetails()...)` call). The row was
simply never written by anything - populating it has always required a
human to save Admin -> Configuration -> Emails -> Email Settings, which no
tenant had ever done.

**Fix: seed the row automatically, build no new send-time mechanism.**
`Platform\Tenancy\Services\TenantProvisioner::seedSenderIdentity()` (new,
idempotent, never overwrites an existing value) is called from
`Platform\Signup\Services\MerchantOnboarding::attempt()` at the exact point
a tenant's real store name first becomes known - Platform Admin's managed
"Create Merchant" flow only; public `/join` and CLI (`tenant:provision`)
provisioning pass no store name at all and are unaffected, an intentional,
disclosed current limitation, not an oversight (revisit only if either path
is given a real store-name field in a future task).

**Model A only, explicit product decision (see DECISION_LOG.md C94): display
NAME only, never the ADDRESS.** `sender_email` is never written by either
the provisioning-time seed or the repair command below - `getSenderEmailDetails()`
keeps falling back to `config('mail.from.address')`, i.e. the same
already-proven-deliverable `support@technify.dev` documented above. No
Reply-To is set anywhere in this task's scope (`owner_email` is unverified
input). This was a deliberate choice, not an oversight: repository evidence
does not establish whether Zoho's SMTP relay accepts a `From:` address other
than the authenticated mailbox, and a merchant-owned domain's own
SPF/DKIM/DMARC alignment is a real deliverability risk this project has no
infrastructure to solve - both genuinely out of scope, not merely deferred.

**Backfill for existing tenants**: `platform:tenants:repair-sender-identity`
(`packages/Platform/Tenancy/src/Console/Commands/RepairSenderIdentity.php`)
mirrors the existing `platform:tenants:repair-channel-hostname` precedent -
a `--tenant` selector (all Ready tenants if omitted), Ready-only, safe to
run repeatedly, never run automatically during deployment. It reads each
tenant's own `channel_translations.name` as the store name to seed, but
SKIPS a tenant whose channel name is still Bagisto's own generic seeded
placeholder ("Default"/"افتراضي" - `Webkul\Installer\Database\Seeders\Core\
ChannelTableSeeder`) rather than seeding a meaningless value, and NEVER
overwrites a `sender_name` already configured (whether by a prior repair run
or a real Admin -> Configuration save). Returns a non-zero exit code only
for a genuine per-tenant failure, never for a normal skip.

## Secrets

The Zoho Application-Specific Password is supplied as `MAIL_PASSWORD` via
the server's real `.env` (or the equivalent secret-injection mechanism the
existing pilot deployment process already uses for every other credential in
this project - never through git, never printed to a terminal this session
can see, never pasted into chat). `.env.example` documents every OTHER
non-secret key with real values/placeholders; `MAIL_PASSWORD=` is left
blank there.

## DNS (found, not changed - technify.dev already points at Zoho)

| Record | Status |
|---|---|
| MX | Correctly points at Zoho (`mx.zoho.com`/`mx2.zoho.com`/`mx3.zoho.com`) |
| SPF | Correctly configured - `v=spf1 include:dc-8e814c8572._spfm.technify.dev ~all`, which itself resolves to `v=spf1 include:zoho.com ~all` (Zoho's own SPF-flattening subdomain) |
| DKIM | Correctly configured - a real `zmail._domainkey.technify.dev` TXT record with a valid `v=DKIM1; k=rsa; p=...` public key |
| DMARC | **Not configured** - no `_dmarc.technify.dev` TXT record exists. Found during this task's own audit; deliberately NOT added (task instruction: no unrelated DNS changes without evidence they're needed) - a real, open, low-priority deliverability-hardening item, not a functional blocker (SPF+DKIM alone already give reasonable deliverability). |

## Queued vs. synchronous mail, and tenant isolation

Bagisto's own listeners (`Webkul\Shop\Listeners\Order::afterCreated()`,
`Webkul\Admin\Mail\Admin\ResetPasswordNotification`) call `Mail::queue()`/
`Notification::send()`. Under this project's current `QUEUE_CONNECTION=sync`
posture, `Mail::queue()` executes inline, in the same request/tenant
context that dispatched it - no separate worker process is involved. If a
future task ever switches to an async queue connection, mail would flow
through the exact same, already-proven tenant-isolated queue architecture
(`Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper`,
`Platform\Tenancy\Listeners\EndTenancyAfterJobRelease` - RISK_REGISTER.md
R26/R27/TASK-ARCH-006) every other queued job in this codebase already
uses - not a new mail-specific isolation mechanism.

## Failure semantics (already graceful, unmodified Bagisto code)

`Webkul\Shop\Listeners\Base::prepareMail()` wraps `Mail::queue()` in its own
try/catch, logging (`\Log::error('Error in Sending Email'...)`) without
affecting the HTTP response - confirmed by reading source directly, and
re-confirmed by a real (not mocked) connection-refused SMTP target in
`tests/Feature/Platform/TenantMailConfigurationTest.php` tests 5/6: order
placement still succeeds, no SMTP host/port/credential or stack trace
appears in the response body.

## Manual real-delivery verification — COMPLETE

Automated tests (`TenantMailConfigurationTest.php`) prove the ARCHITECTURE
(correct recipient, correct tenant, graceful failure) using `Mail::fake()`/
`Notification::fake()`/a real-but-unreachable SMTP target - they do **not**
prove Zoho itself ever received or delivered anything. That proof is manual,
against the real pilot server, using real external mailboxes. All three
checks below were completed for real, with the receiving operator personally
confirming inbox receipt for each one (see TASK-MVP-004's own final report
for full evidentiary detail/timestamps):

1. **DONE.** A single diagnostic email sent through the real application
   stack (not a raw SMTP client, wrapped in the real `pilot-smoke` tenant
   context), received in a real external Gmail inbox, from
   `Technify <support@technify.dev>`, over a real TLS/STARTTLS connection.
2. **DONE.** A real merchant password-reset: real forgot-password submission
   on the real `pilot-smoke` tenant, real email received externally, the
   real reset link opened in a real browser, a new password set through the
   real (asset-fix-verified, see RISK_REGISTER.md R63) Bagisto Reset
   Password UI, real Admin login succeeded afterward with the real Bagisto
   dashboard loading correctly.
3. **DONE.** A real shopper guest order on `pilot-smoke` (product -> cart ->
   address -> Flat Rate -> Cash on Delivery -> place order, all real HTTP),
   real order-confirmation email received externally, with recipient,
   order number, product, quantity, shipping, and grand total all confirmed
   to match the persisted order exactly.

See TASK-MVP-004's own final report for the exact real results, timestamps,
and evidence chain for this checklist.
