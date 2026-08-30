<?php

/**
 * TASK-MVP-020 (RISK_REGISTER.md R75). Merchant-facing Sales/RMA Admin
 * DataGrid timestamps used to always show the raw stored UTC value -
 * `Webkul\DataGrid\DataGrid::formatRecords()` only reformats a column if it
 * has a registered `closure` (confirmed by reading that method directly),
 * and none of the columns listed in `SalesDataGridTimezoneFormatter::
 * TARGETS` ever had one. The single-order view, invoices, refunds,
 * shipments, and every order email all already correctly show
 * `Core::formatDate()`'s tenant-channel-timezone-converted value - only
 * these LISTING grids were left showing UTC.
 *
 * `Platform\Tenancy\Support\SalesDataGridTimezoneFormatter` decorates a
 * fixed, explicit list of unmodified `packages/Webkul` DataGrid classes via
 * a real Laravel event (`datagrid.*.columns.prepare.after`, dispatched
 * unconditionally by Bagisto's own `DataGrid::prepare()`) - NOT
 * `Container::resolving()`, which cannot work here: it fires at
 * construction time, before `prepareColumns()` has populated any columns
 * to mutate (confirmed by reading `DataGrid::process()`/`prepare()`
 * directly - see that class's own docblock for the full record).
 *
 * Every assertion below invokes the REAL attached closure (the exact same
 * one `DataGrid::formatRecords()` itself calls), never a raw DB-value
 * comparison alone - this is the same discipline this project's other
 * cache/config-write tasks (R73/C95) already established: reproduce the
 * real defect through the real read path, not merely inspect stored state.
 */

use Illuminate\Support\Facades\DB;
use Platform\Tenancy\Enums\TenantStatus;
use Platform\Tenancy\Models\Tenant;
use Platform\Tenancy\Services\TenantProvisioner;
use Platform\Tenancy\Support\SalesDataGridTimezoneFormatter;
use Tests\Feature\Platform\PlatformIntegrationTestCase;
use Webkul\Admin\DataGrids\Catalog\AttributeDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderShipmentDataGrid;
use Webkul\DataGrid\Column;

uses(PlatformIntegrationTestCase::class);

const SDTF_TENANT_HEBRON = 'sdtf-tenant-hebron';
const SDTF_TENANT_UTC = 'sdtf-tenant-utc';

/**
 * Persistent, idempotently-reused fixtures - the same "expensive
 * provisioning, reused across runs" discipline this project's own sibling
 * Platform integration test files already use. Plain `provision()` already
 * sets `channels.timezone = Asia/Hebron` via `ensurePalestineTimezoneSet()`
 * - no extra setup needed for the non-UTC tenant.
 */
function ensureSdtfTenant(string $id): Tenant
{
    $tenant = Tenant::find($id);

    if (! $tenant) {
        $tenant = Tenant::create(['id' => $id, 'status' => TenantStatus::Pending]);
        $tenant->domains()->create(['domain' => $id.'.platform.test']);
    }

    if ($tenant->status !== TenantStatus::Ready) {
        app(TenantProvisioner::class)->provision($tenant);
        $tenant = $tenant->fresh();
    }

    return $tenant->fresh();
}

/**
 * Resolves a real DataGrid instance and runs Bagisto's own (unmodified,
 * protected) `prepare()` directly - the exact step `DataGrid::process()`
 * itself calls first, and the exact step that dispatches the
 * `datagrid.*.columns.prepare.after` event `SalesDataGridTimezoneFormatter`
 * listens for. Reflection is used only to reach that protected method
 * directly, skipping `process()`'s own pagination/export/HTTP-response
 * concerns, none of which this test needs (matches this project's own
 * established technique for testing another protected step in isolation,
 * TASK-MVP-019's `invokeProtectedProvisionerStep()`).
 */
function prepareSdtfGrid(Tenant $tenant, string $gridClass): object
{
    return $tenant->run(function () use ($gridClass) {
        $grid = app($gridClass);

        $ref = new ReflectionMethod($grid, 'prepare');
        $ref->setAccessible(true);
        $ref->invoke($grid);

        return $grid;
    });
}

function sdtfColumn(object $grid, string $index): ?Column
{
    foreach ($grid->getColumns() as $column) {
        if ($column->getIndex() === $index) {
            return $column;
        }
    }

    return null;
}

beforeEach(function () {
    $this->tenantHebron = ensureSdtfTenant(SDTF_TENANT_HEBRON);
    $this->tenantUtc = ensureSdtfTenant(SDTF_TENANT_UTC);

    // Simulate an ordinary (non-Palestine) tenant that never got a channel
    // timezone set - Core::formatDate() falls back to config('app.timezone'),
    // 'UTC' in this project.
    $this->tenantUtc->run(fn () => DB::table('channels')->where('id', 1)->update(['timezone' => null]));
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

// --- A. Order DataGrid ---------------------------------------------------

test('1. Order DataGrid created_at renders in the tenant channel timezone, not raw UTC', function () {
    $grid = prepareSdtfGrid($this->tenantHebron, OrderDataGrid::class);
    $column = sdtfColumn($grid, 'created_at');

    expect($column)->not->toBeNull();
    expect($column->getClosure())->not->toBeNull();

    $raw = '2026-01-15 00:30:00';

    [$rendered, $expected] = $this->tenantHebron->run(function () use ($column, $raw) {
        return [
            ($column->getClosure())((object) ['created_at' => $raw]),
            core()->formatDate($raw),
        ];
    });

    expect($rendered)->toBe($expected);
    expect($rendered)->not->toBe($raw);
});

// --- B. Shipment DataGrid (both order_date and shipment_created_at) ------

test('2. Shipment DataGrid renders both order_date and shipment_created_at in the tenant channel timezone', function () {
    $grid = prepareSdtfGrid($this->tenantHebron, OrderShipmentDataGrid::class);

    $orderDateColumn = sdtfColumn($grid, 'order_date');
    $shipmentDateColumn = sdtfColumn($grid, 'shipment_created_at');

    expect($orderDateColumn)->not->toBeNull();
    expect($shipmentDateColumn)->not->toBeNull();
    expect($orderDateColumn->getClosure())->not->toBeNull();
    expect($shipmentDateColumn->getClosure())->not->toBeNull();

    $rawOrderDate = '2026-02-01 22:15:00';
    $rawShipmentDate = '2026-02-02 05:45:00';

    $results = $this->tenantHebron->run(function () use ($orderDateColumn, $shipmentDateColumn, $rawOrderDate, $rawShipmentDate) {
        return [
            'order' => ($orderDateColumn->getClosure())((object) ['order_date' => $rawOrderDate]),
            'order_expected' => core()->formatDate($rawOrderDate),
            'shipment' => ($shipmentDateColumn->getClosure())((object) ['shipment_created_at' => $rawShipmentDate]),
            'shipment_expected' => core()->formatDate($rawShipmentDate),
        ];
    });

    expect($results['order'])->toBe($results['order_expected']);
    expect($results['order'])->not->toBe($rawOrderDate);
    expect($results['shipment'])->toBe($results['shipment_expected']);
    expect($results['shipment'])->not->toBe($rawShipmentDate);
});

// --- C. UTC/fallback safety ------------------------------------------------

test('3. a UTC-channel tenant renders the expected value without an artificial offset', function () {
    $grid = prepareSdtfGrid($this->tenantUtc, OrderDataGrid::class);
    $column = sdtfColumn($grid, 'created_at');

    expect($column->getClosure())->not->toBeNull();

    $raw = '2026-01-15 00:30:00';

    [$rendered, $expected] = $this->tenantUtc->run(function () use ($column, $raw) {
        return [
            ($column->getClosure())((object) ['created_at' => $raw]),
            core()->formatDate($raw),
        ];
    });

    // Still goes through the real formatting path (translatedFormat(), not
    // a raw passthrough) - but with the channel timezone falling back to
    // UTC, so no artificial offset is introduced for a tenant that never
    // opted into a non-UTC timezone.
    expect($rendered)->toBe($expected);
});

// --- D. Existing closure preservation --------------------------------------

test('4. applyTo() never replaces a column that already has a closure', function () {
    $preset = fn ($row) => 'UPSTREAM_VALUE';

    $column = new Column([
        'index' => 'created_at',
        'label' => 'Date',
        'type' => 'date',
        'closure' => $preset,
    ]);

    $fakeGrid = new class($column)
    {
        public function __construct(private Column $column) {}

        public function getColumns(): array
        {
            return [$this->column];
        }
    };

    SalesDataGridTimezoneFormatter::applyTo($fakeGrid, ['created_at']);

    expect($column->getClosure())->toBe($preset);
});

// --- E. Scope control -------------------------------------------------------

test('5. an unrelated Admin date-type DataGrid remains untouched', function () {
    $grid = prepareSdtfGrid($this->tenantHebron, AttributeDataGrid::class);
    $column = sdtfColumn($grid, 'created_at');

    expect($column)->not->toBeNull();
    expect($column->getClosure())->toBeNull();
});

// --- F. Full in-scope matrix -------------------------------------------------

test('6. every registered grid/column in SalesDataGridTimezoneFormatter::TARGETS has a closure attached', function () {
    foreach (SalesDataGridTimezoneFormatter::TARGETS as $gridClass => $columnIndexes) {
        $grid = prepareSdtfGrid($this->tenantHebron, $gridClass);

        foreach ($columnIndexes as $index) {
            $column = sdtfColumn($grid, $index);

            expect($column)
                ->not->toBeNull("Expected column [{$index}] to exist on [{$gridClass}] - see SalesDataGridTimezoneFormatter::TARGETS.");

            expect($column->getClosure())
                ->not->toBeNull("Expected column [{$index}] on [{$gridClass}] to have a closure attached by SalesDataGridTimezoneFormatter.");
        }
    }
});
