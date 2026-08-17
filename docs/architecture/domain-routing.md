# Domain / Routing Design

## Starting point confirmed by repo research

No domain-routing infrastructure exists in this codebase at all: `grep -rn "Route::domain"` across `packages/Webkul` returns nothing, and there is no URL locale-prefix scheme either (locale is resolved via query param → session → channel default, not a `/{locale}/...` segment). This is favorable — subdomain-per-tenant routing can be layered on top of the entire app as an outermost wrapper without fighting any existing domain or URL-segment logic.

## Phase 1 (MVP): platform subdomains

`{tenant-slug}.platform.<ourdomain>.com` — resolved by `stancl/tenancy`'s `InitializeTenancyByDomain` middleware against the `domains` table. Must run before any Bagisto middleware (see [tenancy.md](tenancy.md) — R9 ordering requirement).

Central domain (e.g. `platform.<ourdomain>.com` with no subdomain, or a dedicated `app.<ourdomain>.com`) serves: marketing/signup pages, the platform-admin panel (`platform` guard). `PreventAccessFromCentralDomains` middleware (stancl/tenancy) ensures central-domain requests can never accidentally resolve tenant context, and conversely a tenant subdomain request can never reach platform-admin routes.

## Phase 2 (later): custom domains

`www.customer-domain.com` → tenant. Requires:
- **DNS**: tenant points a CNAME (or A record, if we run a dedicated ingress IP) at our infrastructure.
- **Verification**: before a custom domain is activated (`domains.verified_at` set), require a DNS TXT record challenge or equivalent proof of ownership — prevents a tenant claiming a domain they don't control, and prevents one tenant claiming a domain another tenant (or the platform itself) already uses.
- **SSL**: HUMAN DECISION REQUIRED (see DECISION_LOG item 3) — whether via automated on-demand certificate issuance at the edge (e.g. Caddy/Traefik ACME, or a CDN's SaaS-custom-domain feature) or manual provisioning. This is an infrastructure decision, not an application-architecture one, and has real cost implications that should not be decided implicitly by writing code.
- **Central-domain protection**: the platform's own domain(s) must be permanently excluded from ever being claimable as a tenant custom domain — enforce this as a validation rule against `stancl/tenancy`'s `central_domains` config, not just a UI-level check.
- **Duplicate/inactive domain handling**: a domain row must be unique across the whole platform (one domain → at most one tenant, ever); a domain belonging to a `suspended`/`deleted` tenant must not silently keep resolving — the tenant-resolution middleware must check tenant `status`, not just domain existence (ties into suspension enforcement, see [security.md](security.md)).
- **Primary domain / www handling**: each tenant has exactly one `is_primary` domain used for canonical URL generation (emails, sitemaps, SEO); non-primary verified domains (e.g. both `example.com` and `www.example.com`) redirect to the primary rather than serving duplicate content.

## Domain → tenant → DB connection flow

```
Incoming request
   -> stancl/tenancy: match Host header against `domains` table
   -> if no match and host is a central domain: proceed as central (platform admin / marketing)
   -> if no match and host is not a central domain: 404 (do NOT fall back to a "default" tenant —
      this is the one existing Bagisto behavior we deliberately override: Core::getCurrentChannel()
      falls back to the *first* channel when no hostname match is found, which is a reasonable
      single-tenant default but wrong for a multi-tenant platform; our tenant-resolution middleware
      must short-circuit before Bagisto's own fallback ever runs)
   -> if match and tenant.status != 'ready': show suspension/pending page (see security.md), do not
      proceed to boot tenant DB connection
   -> if match and tenant.status == 'ready': DatabaseTenancyBootstrapper swaps connection,
      request proceeds into normal Bagisto routing (admin/shop) against the tenant's own database
```

## TASK-ARCH-014: the full lifecycle gate, implemented and proven

The sketch above ("if match and tenant.status != 'ready': show suspension page") is now fully real for every `TenantStatus` case. TASK-ARCH-013 first implemented this for `Suspended` only, via a dedicated `BlockSuspendedTenants` middleware; TASK-ARCH-014 generalized that class in place into `Platform\Tenancy\Http\Middleware\TenantAccessGate`, which now makes the complete, fail-closed readiness decision for every request - see [provisioning.md](provisioning.md)'s "Tenant lifecycle traffic matrix" for the full state-to-response table.

**Actual request flow**, confirmed live:

```
Host
  -> Platform\Tenancy\Http\Middleware\TenantAccessGate ('web' group, highest priority -
     runs before EVERYTHING else, including PreventAccessFromCentralDomains)
       -> Stancl\Tenancy\Resolvers\DomainTenantResolver::resolveWithoutCache($host)
          [a plain central-only `tenants`/`domains` query - the SAME resolver class
           Stancl\Tenancy\Middleware\InitializeTenancyByDomain itself uses a moment
           later, reused rather than duplicated; resolution caching is off by default
           in this app (DomainTenantResolver::$shouldCache is never overridden), so
           there is no stale-status risk from a cached resolution here]
       -> host doesn't resolve to any tenant -> pass through unchanged
          (InitializeTenancyByDomain's own existing unknown-domain 404 still applies,
           byte-for-byte - this middleware makes zero behavior change here)
       -> resolves, tenant.status == Ready -> pass through unchanged
       -> resolves, tenant.status == Suspended -> 423 response, request stops HERE
       -> resolves, any other status (Pending/Provisioning/Failed/Deleting/Deleted,
          or any future/unrecognized case - a PHP `match` with only Ready/Suspended
          as explicit arms and a reject-by-default `default` arm) -> 503 response,
          request stops HERE
       -> in every blocking case: InitializeTenancyByDomain never runs,
          DatabaseTenancyBootstrapper never runs, the tenant database connection
          is never opened
  -> (only reached for a Ready, resolvable host) InitializeTenancyByDomain runs
     normally, exactly as it always has
```

**Why a dedicated middleware, not a listener on the tenancy-initialization event** (the more "elegant"-looking option that turns out to be wrong): `Stancl\Tenancy\Events\InitializingTenancy` fires for every call to `Tenancy::initialize()`, including trusted, Platform-Admin-initiated internal calls like `platform.tenants.provision`/`migrate-pending` (`TenantProvisioner::provision()`/`remigrate()`, which must work against a Pending/Provisioning/Failed tenant by design - that is the entire point of the provision/retry action, TASK-ARCH-010/R33). A listener there cannot tell a real inbound HTTP request apart from Platform Admin's own trusted backend code touching the same tenant - both call the identical method. `TenantAccessGate`, scoped to the `web` HTTP middleware group only, naturally never runs for any internal `$tenant->run()` call (none of them pass through any HTTP middleware pipeline), so it can never block Platform Admin's own legitimate maintenance actions against a non-Ready tenant. This is proven live by `tests/Feature/Platform/TenantAccessGateTest.php` test 16 (a Failed tenant with no physical database is provisioned to Ready through the real `platform.tenants.provision` route while the gate is fully active).

**Response semantics**: `423 Locked` for `Suspended` only (unchanged since TASK-ARCH-013 - see docs/architecture/security.md's "Tenant suspension bypass" row and DECISION_LOG.md C28 for the reasoning); `503 Service Unavailable` for every other non-Ready status (DECISION_LOG.md C30) - HTML for a normal browser request (`tenancy::suspended` / `tenancy::unavailable`, small Platform-owned views - no `packages/Webkul` view touched), structured JSON (`{"message": "This store is currently unavailable."}`) for any request that `wantsJson()` - covering Shop, Admin, and API requests uniformly, since all three run through the identical `web` middleware group. Neither response body ever mentions the tenant's id, database name, provisioning state, or an exception message - both are static, generic text (`tests/Feature/Platform/TenantAccessGateTest.php` test 9 asserts this directly for the JSON body).

## TASK-MVP-004B/TASK-MVP-003B: an earlier layer, added later (R57/R58)

The flow above is still completely accurate for how `TenantAccessGate` itself works - but it is no longer the FIRST thing that runs for every request. `Illuminate\Routing\Router::runRoute()` fires `Illuminate\Routing\Events\RouteMatched` immediately after route matching, strictly BEFORE the 'web' middleware pipeline (including `TenantAccessGate`) ever runs - and Laravel's own `Route::gatherMiddleware()` (part of determining what that pipeline even contains) eagerly, fully container-resolves the matched controller for certain Bagisto controllers (any extending the classic base `Illuminate\Routing\Controller`, which is effectively all of them), running real constructor-injected database queries before `TenantAccessGate` gets a chance to run at all. `Platform\Tenancy\Providers\TenancyServiceProvider` listens to `RouteMatched` directly (`preInitializeTenancyOnRouteMatch()`) to handle this, reusing the SAME `TenantHostResolver`/status decision `TenantAccessGate` uses (never a second, independently-maintained matrix):

```
RouteMatched (fires before EVERYTHING, including TenantAccessGate)
  -> not a 'web'-group route -> do nothing, unaffected (Platform Admin, /join, billing webhook)
  -> host doesn't resolve to any tenant -> do nothing, existing 404 handling proceeds unaffected
  -> resolves, tenant Ready -> tenancy()->initialize($tenant) NOW (R57, RISK_REGISTER.md)
       -> the throwaway eager-controller-construction probe (if any) now runs against
          the CORRECT tenant database instead of central
       -> InitializeTenancyByDomain still runs normally moments later - a safe,
          complete no-op (Tenancy::initialize()'s own idempotency guard)
  -> resolves, tenant NOT Ready -> throw TenantNotReadyHttpException($tenant) NOW (R58)
       -> NEVER initializes tenancy for this tenant - the exact TASK-ARCH-013/014
          invariant above is preserved by construction, not convention
       -> the exception's own render() method produces the identical 423/503
          TenantAccessGate would have (Platform\Tenancy\Services\
          TenantUnavailableResponder - the SAME class TenantAccessGate itself now
          delegates to, so the two paths can never drift into different response bodies)
       -> TenantAccessGate never even runs for this request - it remains a full,
          independent defense-in-depth check for anything that reaches it without
          having already been rejected here
```

Why THROW rather than return a response from the listener: confirmed by reading `Illuminate\Routing\Router::runRoute()`'s own source that `RouteMatched` is dispatched with Laravel's default `$halt = false`, so `Illuminate\Events\Dispatcher::invokeListeners()` silently discards every listener's return value - only an actual thrown exception reaches Laravel's exception-rendering pipeline from this point. Why a custom exception with its own `render()` method rather than a new `bootstrap/app.php` `$exceptions->render()` registration: confirmed by reading `Illuminate\Foundation\Exceptions\Handler::render()`'s own source that `method_exists($e, 'render')` is checked and honored unconditionally, structurally BEFORE `renderViaCallbacks()` - immune, by construction, to the exact registration-order race RISK_REGISTER.md R59 already proved live for a different exception under real `APP_DEBUG=false`. See RISK_REGISTER.md R57/R58/R59 and DECISION_LOG.md C70/C73 for the full evidentiary record.

**Known, separate, narrower gaps this layer does NOT close** (RISK_REGISTER.md R61/R62, both open, both deliberately out of scope for R58): (1) `Illuminate\Foundation\Http\Kernel::terminate()` unconditionally re-runs the same eager-controller-construction step, completely independently, AFTER the response has already been sent - invisible to the client, but a real internal crash-and-log event for a non-ready tenant on this route family; (2) an unknown domain (not a non-ready tenant - no tenant resolves at all) hitting the same eager-crash route family still raw-crashes, because `InitializeTenancyByDomain::$onFail` (R53's own fix for this exact "unknown domain" scenario) never gets a chance to run either, for the identical timing reason.

## TASK-ARCH-003: implemented and proven — the actual wiring

The flow diagram above described the intent; this section documents what was actually built and verified with real HTTP requests through real Bagisto routes (`tests/Feature/Platform/TenantDomainRoutingTest.php`, 7 tests, all passing).

**Middleware placement.** `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` is prepended to the **`web` middleware group definition itself**, in `bootstrap/app.php` (`$middleware->prependToGroup('web', [...])`) — not just to our own `routes/tenant.php`. Since both `Webkul\Admin\Providers\AdminServiceProvider` and `Webkul\Shop\Providers\ShopServiceProvider` register their routes with `['web', ...]` by name, this makes every real Bagisto admin and shop route tenant-aware automatically, with **zero changes to any `packages/Webkul` file** — the same extension point Bagisto's own team already uses one line above it (`replaceInGroup('web', BaseEncryptCookies::class, EncryptCookies::class)`) for the EncryptCookies override. It must run before `StartSession` (part of the base `web` group), since `SESSION_DRIVER=database` and the `sessions` table lives inside each tenant's own database — `prependToGroup()` plus `Platform\Tenancy\Providers\TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` (pins relative middleware priority) together guarantee this ordering.

**Unresolved-domain handling.** `Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException` (a plain `Exception`, not an `HttpExceptionInterface` by default — would otherwise render as a raw 500) is mapped to a clean `404 {"message": "Not Found"}` JSON response via `bootstrap/app.php`'s `withExceptions()` closure. This covers both genuinely unknown hosts and, for now, the central domains too (see below).

**Platform vs. tenant boundary — current, honest state.** No real platform-only routes exist yet (Phase 8 work, explicitly out of scope for TASK-ARCH-003). Central-domain requests to any Bagisto route therefore 404 today — the *same* 404 an unknown domain gets, since neither resolves a tenant and nothing else is registered to handle them. This is deliberate, not accidental: it's the honest, minimal, correctly-scoped boundary for this task ("do not implement the production platform domain yet if not needed... establish the architectural boundary"). The `central_domains` config entry already reserves the identifiers Phase 8 will use to register real platform-only routes that explicitly bypass tenant resolution. Verified live: `GET http://localhost/api/products` (central domain, real Bagisto route) → 404, exactly like `GET http://unknown.localhost/api/products` (never-registered domain) → 404. Neither ever falls back to any tenant or exposes tenant data.

**Security properties verified live**, not just claimed (see `tests/Feature/Platform/TenantDomainRoutingTest.php`, security-focused tests):
- The Host header alone cannot select an arbitrary tenant database — resolution is a `WHERE domain = ?` lookup against the central `domains` table (`Stancl\Tenancy\Resolvers\DomainTenantResolver::resolveWithoutCache()`); a host with no matching row (e.g. `tenant-c.localhost`, deliberately never registered) 404s, full stop.
- Sequential requests to different tenant hosts within the same process do not leak connection state — verified by asserting `DB::connection()->getDatabaseName()` changes correctly across `tenant-a → tenant-b → tenant-a` in one test.
- A real, unmodified Bagisto Admin route (`/admin/login`, served by `Webkul\User\Http\Middleware\Bouncer` for unauthenticated requests) resolves tenant-aware, proving the wiring covers Admin, not just Shop.
- **Known, deliberately-not-yet-fixed gap**: `RISK_REGISTER.md` R20 — `trustProxies(at: '*')` (pre-existing Bagisto setting) means `X-Forwarded-Host` is honored from any client unless a real trusted reverse proxy sits in front and strips it; this is a production deployment checklist item (Phase 18), not something silently changed here.
- **Known, deliberately-not-yet-fixed, now empirically confirmed gap**: `RISK_REGISTER.md` R21 — the real Shop API's cached (no-query) product listing endpoint leaks Tenant A's response to Tenant B, reproduced live. Deferred to Phase 13 (same fix as R15), and deliberately kept visible as a failing-when-fixed regression test rather than avoided.
