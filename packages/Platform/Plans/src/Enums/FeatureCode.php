<?php

declare(strict_types=1);

namespace Platform\Plans\Enums;

/**
 * TASK-ARCH-008. Stable, machine-readable feature identifiers - the
 * `dot.notation` convention this project uses for any generic, extensible
 * identifier namespace: `{domain}.{aspect}`, lowercase, plural where the
 * underlying concept is countable ("products", "staff") mirroring
 * docs/architecture/feature-limits.md's own conceptual examples
 * (`Feature::PRODUCT_LIMIT` etc., written here as enum cases instead of
 * class constants so call sites get IDE autocomplete and a typo becomes a
 * compile-time error rather than a silent no-match at runtime).
 *
 * These are EXAMPLES, not a business-final catalog - the four cases below
 * are exactly the four from this task's own instructions and from
 * feature-limits.md's Phase 0 sketch, seeded as illustrative data by
 * Platform\Plans\Services\PlanSeeder. Adding a new feature later never
 * requires a migration (plan_features.feature_code is a plain string
 * column, not an enum-backed one - see PlanFeature's docblock for why the
 * column deliberately stays looser than this enum) - only a new case here
 * (for call-site type safety) and new seeded rows.
 *
 * Deliberately NOT cast onto PlanFeature::feature_code at the Eloquent
 * level: doing so would make the schema itself only accept these four
 * codes, defeating "centrally configured plan entitlements" (section 5).
 * TenantEntitlements accepts `FeatureCode|string` at its own call sites
 * instead, normalizing an enum instance to ->value - callers who want
 * type safety get it; the data layer stays open to codes not yet enumerated.
 */
enum FeatureCode: string
{
    case ProductsLimit = 'products.limit';
    case StaffLimit = 'staff.limit';
    case CustomDomain = 'domains.custom';
    case AdvancedReports = 'reports.advanced';
}
