# ADR-005: Domain Strategy — Subdomain First, Custom Domains Later

## Status
Accepted

## Context
No domain-routing infrastructure exists in this codebase today (confirmed: no `Route::domain()` usage anywhere in `packages/Webkul`, no URL locale-prefix scheme). Bagisto's own `Channel.hostname` mechanism provides single-database multi-store-by-hostname, which is a related but distinct concept from tenant-domain routing.

## Decision
Phase 1 (MVP): platform-issued subdomains only, `{tenant-slug}.platform.<ourdomain>.com`, resolved by `stancl/tenancy`'s `InitializeTenancyByDomain`. Phase 2 (post-MVP): bring-your-own custom domains, gated behind DNS ownership verification and a deliberate SSL-provisioning decision (HUMAN DECISION REQUIRED — see DECISION_LOG item 3, this is an infrastructure/cost decision, not resolved by this ADR).

## Consequences
- Ships a working multi-tenant platform without depending on the unresolved SSL-automation decision, since platform subdomains can share a single wildcard certificate for `*.platform.<ourdomain>.com` — a well-understood, low-cost setup — while custom domains (which each need their own certificate) are deferred.
- The `domains` table design (see [database-per-tenant.md](../architecture/database-per-tenant.md)) already accommodates both cases (`is_custom` flag) so Phase 2 is additive, not a redesign.

## Critical implementation constraint (not optional, carried from repo evidence)
Tenant-resolution middleware must execute before Bagisto's own `Channel` hostname resolution (`Webkul\Core\Core::getCurrentChannel()`) — see [tenancy.md](../architecture/tenancy.md) R9. This isn't a design preference, it's a correctness requirement discovered by reading `Core.php:137-158`: Bagisto's channel lookup runs unconditionally and falls back to the *first* channel on no match, which is safe for a single-tenant app but actively wrong (silently serves the wrong tenant's default channel) in a multi-tenant context if it ever runs before or without tenant resolution having occurred.

## Alternatives considered
- **Path-based tenancy** (`platform.com/tenant-slug/...`) — rejected: Bagisto's URL structure (checkout, CMS pages, product URLs) assumes it owns the full path from root; retrofitting a tenant-slug path prefix would touch far more of the URL-generation code (`url()`, `route()` calls throughout ~40 packages) than a domain-based approach, which requires zero changes to Bagisto's own URL generation.
- **Custom domains from day one** — rejected: couples MVP launch to the unresolved SSL-automation decision, delaying everything else in the roadmap behind an infrastructure question that doesn't need to block application-layer development.
