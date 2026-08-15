# Tenant Admin Integration

**Status: IMPLEMENTED (TASK-ARCH-009, 2026-08-14).** The first real page inside a tenant's own Bagisto Admin, presenting the Plan/Entitlement domain TASK-ARCH-008 built. Confirmed against the current source tree (`packages/Webkul/Admin`), not Phase 0 notes.

## Bagisto's admin extension mechanism, confirmed live

No Webkul business-logic package owns its own admin routes/controllers/views — all of that lives physically inside `packages/Webkul/Admin` (menu/ACL entries for Sales, Catalog, CMS, Marketing, etc. all sit in that one package's `Config/menu.php`/`Config/acl.php`). This is a mechanically valid, generic Laravel/Bagisto pattern for a **third-party** package too, even though nothing in `packages/Webkul` demonstrates it — confirmed by tracing every extension point to its actual registration mechanism rather than assuming:

- **Menu**: `AdminServiceProvider::registerConfig()` calls `mergeConfigFrom(Config/menu.php, 'menu.admin')` — a plain list-array merge (`array_merge`, so a second provider merging into the same key just appends). Read by `Webkul\Core\Menu::getItems('admin')`, rendered by the sidebar via `menu()->getItems('admin')`. A menu item's `key` must match an ACL `key`, or `Webkul\Core\Menu::getItems()`'s own `bouncer()->hasPermission($item['key'])` filter hides it.
- **ACL**: `AdminServiceProvider::registerConfig()` calls `mergeConfigFrom(Config/acl.php, 'acl')`, same merge semantics. Enforced by `Webkul\User\Http\Middleware\Bouncer` (the `admin` route middleware alias), via `Webkul\Core\Acl::getRoles()` (route-name → acl-key map) and `Webkul\User\Bouncer::allow($key)` → `Admin::hasPermission($key)` (`in_array($key, $role->permissions)`, unless `role->permission_type === 'all'`, which bypasses the check entirely). **A route with no ACL entry `abort(401)`s for every role — fails closed.**
- **Routes**: `AdminServiceProvider::boot()` registers `Route::middleware(['web', PreventRequestsDuringMaintenance::class])->group(Routes/web.php)`, which itself wraps its `require`d route files in `Route::group(['middleware' => ['admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url')], ...)`. A third-party provider replicates this exact stack directly (see below) — there's no shared "register into Admin's group" hook, just the same generic Laravel primitives.
- **Views/layout**: `<x-admin::layouts>` is an anonymous Blade component (`Blade::anonymousComponentPath` registered by `AdminServiceProvider::boot()`), not an `@extends` layout. Its only slots are `title` and the default body slot — a page wraps its whole body in `<x-admin::layouts>...</x-admin::layouts>` and hand-rolls its own header markup as the first child (no named `@section`s). Because `admin::`/`<x-admin::...>` are process-global namespaces once `AdminServiceProvider` boots (always true — Admin is a core, always-loaded package), any package's views can use them without living inside `packages/Webkul/Admin` — a separate `loadViewsFrom(..., 'plans')` namespace for the package's *own* views is all that's needed.

## Package ownership

Lives inside `packages/Platform/Plans` (`Http/Controllers/Admin/MyPlanController.php`, `Routes/admin-routes.php`, `Config/menu.php`, `Config/acl.php`, `Resources/views/admin/my-plan/`, `Presentation/FeaturePresenter.php`) — not a new package, and not `packages/Platform/Tenancy`. The UI is specifically presenting the Plan/Entitlement domain Plans already owns; a dedicated integration package would have split one small feature across two packages for no genuine architectural boundary (`Platform\Tenancy` has no admin-facing code today — confirmed by search before this task began — and should stay that way; it's tenancy infrastructure, not a SaaS-business-domain UI owner).

## Route registration (mirrors `AdminServiceProvider` exactly)

`PlansServiceProvider::boot()`:
```php
Route::middleware(['web', PreventRequestsDuringMaintenance::class, 'admin', NoCacheMiddleware::class])
    ->prefix(config('app.admin_url'))
    ->group(__DIR__.'/../Routes/admin-routes.php');
```
One route: `GET admin/saas/plan` → `admin.saas.plan.index` → `MyPlanController::index()`. Reaching it as an unauthenticated request redirects to `admin.session.create`, exactly like every other Bagisto admin route — proven, not asserted, by a real HTTP test with no prior login.

## Menu / ACL entries

Two-level menu: `saas` (top, icon `icon-settings` — reused, not invented) → `saas.plan` ("My Plan"). Matching ACL keys `saas`/`saas.plan`, `route` → `admin.saas.plan.index`. `name` fields are plain literal strings, not translation keys — `Webkul\Core\Menu` wraps every name in `trans()`, and Laravel's `trans()` returns an unmatched string unchanged, so this needs no dedicated lang file for four words (smallest maintainable approach, not a localization framework).

## Central-data access from an admin request

`MyPlanController::index()` calls `TenantEntitlements::current()->currentPlan()` — the exact TASK-ARCH-008 mechanism (`Plan`/`PlanFeature` on `Stancl\Tenancy\Database\Concerns\CentralConnection`, reading `config('tenancy.database.central_connection')` fresh every query). No `tenancy()->end()`, no `tenancy()->central()` wrapper, nothing that could leave the tenant context disturbed. Proven at the HTTP layer (not just the service layer, which TASK-ARCH-008 already proved directly): after rendering the plan page, the *same* test hits a genuinely tenant-scoped admin page (`admin/catalog/products`) in the same session and gets a normal 200 — proof the central read didn't leave any connection/tenancy confusion behind for the rest of the request pipeline.

## Tenant A/B isolation

No request parameter selects the tenant anywhere in this feature — `TenantEntitlements::current()` resolves via `tenant()`, which is set exclusively by `InitializeTenancyByDomain` from the request's Host header. Proven live: the identical route, hit with two different Host headers in the same test, returns Tenant A's `free` plan for one and Tenant B's `pro` plan for the other — see `tests/Feature/Platform/TenantAdminPlanPageTest.php`.

## Feature presentation

`Platform\Plans\Presentation\FeaturePresenter` — a label lookup (four known `FeatureCode`s, falling back to a readable auto-derived label for any code not yet enumerated) and a `FeatureType`-driven value formatter (`Enabled`/`Disabled` for boolean, the raw integer for numeric, the literal word `Unlimited` for unlimited — never a raw `0`/`1`/`true`/`false`/`null`). Deliberately not a localization/metadata framework — the smallest maintainable approach the task asked for.

## What this task deliberately does not include

**Updated (TASK-ARCH-016)**: real subscription lifecycle state (status/trial/period/cancellation intent) is now shown on this same page - see docs/architecture/subscriptions.md "Tenant-visible subscription status". No Billing/Invoices/payment-method/price UI exists, because that domain doesn't exist yet (no payment provider - see docs/architecture/billing.md). No separate `saas.subscription` menu entry was added - the existing single "My Plan" page grew a new section rather than the menu gaining a sibling child, since one page composing both plan and subscription data reads more naturally than two separately-navigated pages for what is, from a tenant's perspective, one coherent "my account status" concern. `saas.billing` remains the future conceptual location once Phase 11 builds it. `products.limit` enforcement is real since TASK-ARCH-012 (no longer "displayed, never checked" - see docs/architecture/feature-limits.md).

## Visual verification performed

A real HTTP response (real login, real session, real tenant domain) was captured and inspected structurally: correct `<title>`, the `SaaS`/`My Plan` sidebar entries present twice (collapsed-icon and expanded views, matching Bagisto's own dual sidebar markup) with the correct href and "active" styling on the current page, `<x-admin::accordion>` components rendering both the Plan and Features sections with real content, and real Vite-compiled asset tags (`app-*.css`/`app-*.js`, served through the existing tenant-aware asset URL scheme) present in `<head>` — Vite compilation is available in this environment, not missing. **Not verified**: actual browser-rendered pixels/CSS layout or interactive JS behavior (accordion collapse/expand) — no browser/screenshot tooling was available in this session, only real HTTP response inspection. The accordion component defaults to its open state server-side (`isActive: true`), so its content is present in the raw HTML regardless of whether client-side Vue ever mounts.

## A real, unrelated bug found and fixed while building this page (RISK_REGISTER.md R32)

Rendering the full authenticated admin layout for the first time in this engagement (every prior admin-route test only ever hit the standalone login page) surfaced that `routes/tenant.php`'s leftover TASK-ARCH-003 placeholder route (`Route::get('/', function () {...})`, unnamed) had been silently replacing Bagisto's real storefront homepage route (`shop.home.index`) for every tenant, this whole engagement — invisible until the admin layout's own header needed to generate a URL for it. Removed; see that row for the full mechanism and evidence. Unrelated to this task's own scope (storefront, not admin) but found and fixed here since it was blocking this task's own page from rendering.
