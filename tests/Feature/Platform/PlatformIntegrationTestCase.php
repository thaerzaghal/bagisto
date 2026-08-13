<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * TASK-ARCH-003 FINDING (correcting a wrong claim made in TASK-ARCH-002):
 * Tests\TestCase uses the DatabaseTransactions trait, and that trait DOES
 * correctly auto-engage via Illuminate\Foundation\Testing\Concerns\
 * InteractsWithTestCaseLifecycle::setUpTraits(), which explicitly checks
 * `isset($uses[DatabaseTransactions::class])` and calls
 * beginDatabaseTransaction() - TASK-ARCH-002's report/comments claimed this
 * trait "doesn't auto-engage in this Laravel version," based on an
 * incomplete grep that only checked TestCase.php and missed the actual call
 * site in a different file. That claim was wrong; this comment is the
 * correction (also reflected in RISK_REGISTER.md).
 *
 * The REAL, confirmed-by-reproduction behavior: DatabaseTransactions wraps
 * the DEFAULT connection ('mysql', our central connection) in a transaction
 * for the duration of EACH test and rolls it back in tearDown - correct,
 * intentional Laravel test isolation. It does NOT wrap our dynamically-bound
 * `tenant_provisioning` or `tenant` connections (they're not the "default"
 * connection Laravel's testing bootstrap knows about at setUp time).
 *
 * This mismatch broke our Platform integration tests specifically: they
 * deliberately share expensive tenant fixtures (~40-90s each to provision)
 * across multiple tests in one file via central-DB tenant rows as the
 * "already provisioned" signal - but if the central 'tenants' row created in
 * test 1 gets rolled back at the end of test 1, while the physical tenant
 * database (created via the separate, non-transacted `tenant_provisioning`
 * connection) persists, test 2 sees an inconsistent world: no central
 * record, but a real, already-seeded database - and re-running product
 * creation against it hits duplicate-key errors.
 *
 * Fix: this TestCase disables DatabaseTransactions' wrapping entirely
 * (`connectionsToTransact = []`) for the platform integration test files
 * that use it. This is a deliberate, scoped opt-out - not a suggestion that
 * transaction-wrapped test isolation is wrong in general (it's the right
 * default for ordinary Bagisto feature tests) - only that these specific
 * multi-request, multi-connection, expensive-fixture integration tests need
 * real, persisted, cross-test state and manage their own cleanup explicitly
 * (see each test file's cleanup*() helper) instead.
 */
abstract class PlatformIntegrationTestCase extends TestCase
{
    protected $connectionsToTransact = [];
}
