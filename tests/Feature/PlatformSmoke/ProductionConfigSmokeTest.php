<?php

/**
 * TASK-ARCH-017 - Production-Configuration Smoke Lane.
 *
 * Run ONLY via `vendor/bin/pest -c phpunit.smoke.xml` (see that file's own
 * docblock) - running this directory under the default phpunit.xml would
 * silently reintroduce the exact array/sync/array masking this lane exists
 * to catch (the R29/R33 class of bug).
 *
 * DELIBERATELY SMALL - one focused test per requirement (TASK-ARCH-017's
 * resume-after-INCIDENT-001 instruction, section 7 - test 9 added on resume,
 * covering the INCIDENT-001 safeguard), not a re-test of every edge case
 * already covered by the full regression suite (`tests/Feature/Platform`,
 * run under phpunit.xml's own "Platform Feature Test" suite). This lane's
 * job is to prove the PRODUCTION-INTENDED config values themselves
 * (SESSION_DRIVER=database, CACHE_STORE=redis, QUEUE_CONNECTION=redis,
 * RESPONSE_CACHE_ENABLED=false - see phpunit.smoke.xml for the one
 * documented QUEUE_CONNECTION exception) don't silently break the golden
 * path end-to-end, AND that INCIDENT-001's safeguard survives under this
 * lane's own distinct config.
 *
 * Dedicated `tenant-smoke-a`/`tenant-smoke-b` fixtures - never the shared
 * fixtures other Platform test files/directories assert exact values against.
 */

use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Admin\Models\PlatformUser;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Jobs\TenantIsolationProbeJob;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\CentralDatabaseWipeGuard;
use Platform\Tenancy\Services\TenantProvisioner;

uses(Tests\Feature\Platform\PlatformIntegrationTestCase::class);

const SMOKE_TENANT_IDS = ['tenant-smoke-a', 'tenant-smoke-b'];

function ensureSmokeTenants(): array
{
    $provisioner = app(TenantProvisioner::class);

    $tenants = [];

    foreach (SMOKE_TENANT_IDS as $id) {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
            $tenant->domains()->create(['domain' => $id.'.localhost']);
        }

        if ($tenant->status !== TenantStatus::Ready) {
            $provisioner->provision($tenant);
        }

        $tenants[] = $tenant->fresh();
    }

    return $tenants;
}

function ensureSmokePlatformAdmin(): void
{
    PlatformUser::firstOrCreate(
        ['email' => 'smoke-test-admin@example.test'],
        ['name' => 'Smoke Test Admin', 'password' => 'platform-secret-1']
    );
}

function smokeLoginAsTenantAdmin(Tests\TestCase $test, string $domain): void
{
    $test->post('http://'.$domain.'/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'admin123',
    ])->assertRedirect();
}

/**
 * Minimal reuse of the permanent TenantIsolationProbeJob (Platform\Tenancy,
 * TASK-ARCH-006) - its own docblock requires the tenant-side probe table to
 * be created by the test suite itself (never a real migration). Kept
 * intentionally to ONE table/one dispatch, not the full queue isolation
 * matrix TenantQueueIsolationTest already proves.
 */
function ensureSmokeQueueProbeSchema(Tenant $tenant): void
{
    $tenant->run(function () {
        if (! Schema::hasTable('queue_isolation_probes')) {
            Schema::create('queue_isolation_probes', function ($table) {
                $table->id();
                $table->string('marker');
                $table->string('observed_tenant_id')->nullable();
                $table->unsignedInteger('observed_attempt')->nullable();
                $table->unsignedInteger('worker_pid')->nullable();
                $table->unsignedInteger('core_facade_object_id')->nullable();
                $table->string('observed_channel_code')->nullable();
                $table->timestamps();
            });
        }
    });
}

beforeEach(function () {
    ensureSmokePlatformAdmin();

    [$this->tenantA, $this->tenantB] = ensureSmokeTenants();
});

test('1. tenant storefront request returns 200 under production-intended config', function () {
    $response = $this->get('http://'.SMOKE_TENANT_IDS[0].'.localhost/');

    $response->assertOk();
});

test('2. tenant admin login/session works under production-intended config', function () {
    smokeLoginAsTenantAdmin($this, SMOKE_TENANT_IDS[0].'.localhost');

    $response = $this->get('http://'.SMOKE_TENANT_IDS[0].'.localhost/admin/dashboard');

    $response->assertOk();
});

test('3. Platform Admin login/session works under production-intended config', function () {
    $response = $this->post('http://localhost/platform/login', [
        'email' => 'smoke-test-admin@example.test',
        'password' => 'platform-secret-1',
    ]);

    $response->assertRedirect(route('platform.dashboard'));

    $this->get('http://localhost/platform')->assertOk();
});

test('4. tenant database session isolation holds under SESSION_DRIVER=database', function () {
    smokeLoginAsTenantAdmin($this, SMOKE_TENANT_IDS[0].'.localhost');
    $sessionIdA = $this->app['session']->getId();

    $centralHasSession = DB::connection('mysql')->table('sessions')->where('id', $sessionIdA)->exists();
    expect($centralHasSession)->toBeFalse();

    $tenantHasSession = $this->tenantA->run(fn () => DB::table('sessions')->where('id', $sessionIdA)->exists());
    expect($tenantHasSession)->toBeTrue();
});

test('5. Redis tenant cache isolation holds under CACHE_STORE=redis', function () {
    $this->tenantA->run(fn () => Cache::put('smoke-cache-key', 'tenant-a-value'));
    $this->tenantB->run(fn () => Cache::put('smoke-cache-key', 'tenant-b-value'));

    $valueA = $this->tenantA->run(fn () => Cache::get('smoke-cache-key'));
    $valueB = $this->tenantB->run(fn () => Cache::get('smoke-cache-key'));

    expect($valueA)->toBe('tenant-a-value');
    expect($valueB)->toBe('tenant-b-value');
});

test('6. Redis queue tenant context survives real, asynchronous execution', function () {
    ensureSmokeQueueProbeSchema($this->tenantA);

    $this->tenantA->run(function () {
        TenantIsolationProbeJob::dispatch('smoke-queue-check');
    });

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

    $observedTenantId = $this->tenantA->run(
        fn () => DB::table('queue_isolation_probes')->where('marker', 'smoke-queue-check')->value('observed_tenant_id')
    );

    expect($observedTenantId)->toBe(SMOKE_TENANT_IDS[0]);
});

test('7. tenant access gate still blocks a non-ready tenant before tenant DB access', function () {
    $this->tenantB->forceFill(['status' => TenantStatus::Pending])->save();

    [$response, $connections] = [null, []];
    DB::listen(function ($query) use (&$connections) {
        $connections[] = $query->connectionName;
    });
    $response = $this->get('http://'.SMOKE_TENANT_IDS[1].'.localhost/');

    $response->assertStatus(503);
    expect($connections)->not->toContain('tenant');
    expect(tenancy()->initialized)->toBeFalse();

    $this->tenantB->forceFill(['status' => TenantStatus::Ready])->save();
});

test('8. Subscription/Plan My Plan basic read path works under production-intended config', function () {
    smokeLoginAsTenantAdmin($this, SMOKE_TENANT_IDS[0].'.localhost');

    $response = $this->get('http://'.SMOKE_TENANT_IDS[0].'.localhost/admin/saas/plan');

    $response->assertOk();
    $response->assertSee('Plan');
});

/**
 * INCIDENT-001 (RISK_REGISTER.md R44). Deliberately environment-agnostic -
 * asserts the classification RULE itself (a database literally named
 * `bagisto_central` is never safe for db:wipe/migrate:fresh), not "the
 * CURRENT process's own database is protected" - this smoke lane's own
 * database is legitimately a disposable bagisto_test_/bagisto_ci_/
 * bagisto_probe_-prefixed database in CI (section 3 of this task), which
 * the guard correctly does NOT prohibit destructive commands against. A
 * test asserting "my own database is protected" would therefore correctly
 * fail in CI and correctly pass locally - contradictory and wrong. This
 * instead proves the mechanism itself is intact and reachable under this
 * lane's specific production-intended php config (CACHE_STORE=redis/
 * SESSION_DRIVER=database/etc. - proving none of those interfere with
 * TenancyServiceProvider::boot()'s registration of the guard), and that
 * WipeCommand/FreshCommand's own static Prohibitable flag genuinely
 * reflects what CentralDatabaseWipeGuard computed for THIS process - not
 * just that the classification function returns the right answer in
 * isolation.
 */
test('9. central database destructive-command safeguard remains active under production-intended config', function () {
    expect(CentralDatabaseWipeGuard::databaseIsSafeForDestructiveCommands(
        CentralDatabaseWipeGuard::centralDatabaseName()
    ))->toBeFalse();

    CentralDatabaseWipeGuard::apply();

    $shouldBeProhibited = ! CentralDatabaseWipeGuard::currentDefaultDatabaseIsSafeForDestructiveCommands();

    $wipeProperty = new ReflectionProperty(WipeCommand::class, 'prohibitedFromRunning');
    $wipeProperty->setAccessible(true);

    $freshProperty = new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning');
    $freshProperty->setAccessible(true);

    expect($wipeProperty->getValue())->toBe($shouldBeProhibited);
    expect($freshProperty->getValue())->toBe($shouldBeProhibited);
});
