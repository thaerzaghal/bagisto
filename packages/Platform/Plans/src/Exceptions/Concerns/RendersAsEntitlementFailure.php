<?php

declare(strict_types=1);

namespace Platform\Plans\Exceptions\Concerns;

use Illuminate\Http\Request;
use Platform\Plans\Enums\FeatureCode;
use Platform\Plans\Exceptions\LimitExceededException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-MVP-013 (RISK_REGISTER.md R72). Replaces `Platform\Enforcement\
 * Providers\EnforcementServiceProvider::registerExceptionRenderer()`'s
 * now-broken `Container::afterResolving(\Illuminate\Foundation\Exceptions\
 * Handler::class, ...)` registration - confirmed BROKEN, not merely
 * suspected, by direct reading of `Illuminate\Container\Container::
 * resolve()`: extenders (`Container::extend()`, the mechanism `Platform\
 * Tenancy\Exceptions\CentralSafeExceptionHandler` uses, TASK-MVP-008)
 * apply to the resolved object BEFORE `fireResolvingCallbacks()` runs,
 * and `Container::getCallbacksForType()`'s own `afterResolving()` type
 * match is `$type === $abstract || $object instanceof $type` - since
 * `CentralSafeExceptionHandler implements ExceptionHandler` directly
 * (never extends `Illuminate\Foundation\Exceptions\Handler`), neither
 * condition can ever hold once it wraps the resolved handler, so the old
 * registration's callback body (which attached the actual `renderable()`)
 * has never fired since TASK-MVP-008 shipped - not a timing race like
 * R36's ORIGINAL bug, a structural type-identity break.
 *
 * WHY EXCEPTION-OWNED `render()` INSTEAD OF ANOTHER REGISTRATION
 * MECHANISM: the exact same reasoning `Platform\Tenancy\Exceptions\
 * TenantNotReadyHttpException` (R57/R58) already established in this
 * codebase - confirmed by reading `Illuminate\Foundation\Exceptions\
 * Handler::render()`'s own source directly, `method_exists($e, 'render')`
 * is checked and, if it returns a response, used UNCONDITIONALLY,
 * structurally BEFORE `renderViaCallbacks()` (where every registration-
 * based mechanism, including the one this replaces, actually lives).
 * `Platform\Tenancy\Exceptions\CentralSafeExceptionHandler`'s own FIRST
 * check is the identical `method_exists($e, 'render')`, delegating
 * immediately to the inner handler when true - so an `EntitlementException`
 * implementor using this trait is ALWAYS delegated correctly, completely
 * independent of Container resolution order, `bind()` vs `singleton()`,
 * or any `Container::extend()` wrapping, now or in the future.
 *
 * DELIBERATELY DOES NOT CHANGE EXCEPTION IDENTITY: nothing about what
 * throws or catches these exceptions changes - `Platform\Plans\Services\
 * TenantLimits::assertWithinLimit()`/`TenantEntitlements` still throw the
 * exact same concrete classes; `Platform\Enforcement\Importers\
 * EnforcingProductImporter`'s own `catch (EntitlementException $e)` and
 * `Platform\Plans\Http\Controllers\Admin\MyPlanController`'s own
 * `catch (NoPlanAssignedException)` both intercept the exception LOCALLY,
 * before it could ever reach an HTTP exception-rendering pipeline, so
 * `render()` existing on the class is simply never invoked there - purely
 * additive, zero behavior change for any non-HTTP caller.
 *
 * MESSAGE CONTRACT PRESERVED EXACTLY, byte-for-byte, from the historical
 * R36 renderer this replaces - not a new UX decision. `LimitExceededException`
 * for the `products.limit` feature gets the specific "up to N products"
 * wording; every other `EntitlementException` implementor (including
 * `LimitExceededException` for any OTHER feature code) gets the identical
 * generic fallback - both status 422, both a plain top-level `message`
 * key, matching the exact JSON shape Laravel's own `ValidationException`
 * already renders for the same `admin.catalog.products.store` endpoint
 * (confirmed live in R36's own original investigation - Bagisto's Admin
 * Vue layer already reads `error.response.data.message` broadly).
 */
trait RendersAsEntitlementFailure
{
    public function render(Request $request): Response
    {
        $message = $this instanceof LimitExceededException && $this->feature === FeatureCode::ProductsLimit->value
            ? "Your current plan allows up to {$this->limit} products."
            : 'Your current plan does not allow this action.';

        return response()->json(['message' => $message], 422);
    }
}
