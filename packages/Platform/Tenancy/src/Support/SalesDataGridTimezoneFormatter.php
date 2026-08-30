<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

use Illuminate\Support\Facades\Event;
use Webkul\Admin\DataGrids\Customers\View\OrderDataGrid as CustomerOrderDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderInvoiceDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderRefundDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderShipmentDataGrid;
use Webkul\Admin\DataGrids\Sales\OrderTransactionDataGrid;
use Webkul\Admin\DataGrids\Sales\RMA\RMADataGrid;
use Webkul\DataGrid\Column;

/**
 * TASK-MVP-020 (RISK_REGISTER.md R75). Makes a fixed, explicit list of
 * merchant-facing Sales/RMA Admin DataGrid event-timestamp columns render in
 * the current tenant channel's timezone, instead of the raw stored UTC
 * value - the same conversion the single-order view, invoices, refunds,
 * shipments, and every order email already correctly apply via
 * `Core::formatDate()` (see `TenantProvisioner::ensurePalestineTimezoneSet()`'s
 * own docblock, which already documents this as the established mechanism
 * everywhere else in this codebase).
 *
 * ROOT CAUSE (confirmed by reading source, not assumed): `Webkul\DataGrid\
 * DataGrid::formatRecords()` only reformats a column's value if that column
 * has a registered `closure` (`Column::getClosure()`) - a `'type' => 'date'`
 * column with no closure passes the raw stored value straight through.
 * Every grid listed in `TARGETS` below has exactly this shape on the
 * columns listed - confirmed individually by reading each grid class.
 *
 * WHY `Event::listen()`, NOT `Container::resolving()` (a real correction
 * made during this task's own implementation, not the originally-assumed
 * mechanism): `Container::resolving($class, $callback)` fires immediately
 * after a DataGrid is CONSTRUCTED - but `DataGrid::prepareColumns()` (which
 * actually populates the columns this class needs to mutate) is not called
 * until later, inside `DataGrid::prepare()` (itself only invoked from
 * `DataGrid::process()`) - confirmed by reading `DataGrid::process()`/
 * `prepare()` directly. A `resolving()` hook would therefore always find
 * zero columns to touch. `DataGrid::prepare()` DOES already, unconditionally,
 * dispatch a real Laravel event immediately after `prepareColumns()` runs -
 * `dispatchEvent('columns.prepare.after', $this)`, resolving to
 * `datagrid.{snake_class_short_name}.columns.prepare.after` - unmodified,
 * pre-existing `packages/Webkul` code, exactly the extension point this
 * class needs. A wildcard listener (`datagrid.*.columns.prepare.after`) is
 * used rather than per-class event names because two target classes share
 * the identical short name (`Webkul\Admin\DataGrids\Sales\OrderDataGrid`
 * and `Webkul\Admin\DataGrids\Customers\View\OrderDataGrid` both snake-case
 * to `order_data_grid`) - the wildcard listener disambiguates via the real
 * payload's own `get_class()` instead of relying on the (colliding)
 * event-name string.
 *
 * DELIBERATELY A FIXED, CLOSED LIST - never "every DataGrid with a `date`
 * column" (see this task's own RISK_REGISTER.md R75 audit for the broader
 * candidate list found and deliberately NOT included: `AttributeDataGrid`,
 * `GDPRDataGrid`, `ReviewDataGrid`, `EventDataGrid`, `ImportDataGrid` -
 * semantically plausible but out of this task's approved scope, a separate
 * decision if ever revisited). Adding a grid here is a conscious, reviewed
 * decision.
 *
 * NEVER overrides a pre-existing closure - if a targeted column already has
 * one (today none do; a future Bagisto upgrade might add one), `applyTo()`
 * skips it, leaving upstream behavior untouched. Fail-safe by construction
 * against a targeted class or column disappearing in a future upgrade: the
 * wildcard listener simply never matches a class no longer in `TARGETS`
 * results in, and a missing column index is silently skipped in the
 * `getColumns()` loop - never a lookup failure/exception.
 */
class SalesDataGridTimezoneFormatter
{
    /**
     * class => [column indexes]. See this class's own docblock for why the
     * list is fixed rather than derived from "any DataGrid with type=date".
     */
    public const TARGETS = [
        OrderDataGrid::class => ['created_at'],
        OrderInvoiceDataGrid::class => ['created_at'],
        OrderRefundDataGrid::class => ['created_at'],
        OrderShipmentDataGrid::class => ['order_date', 'shipment_created_at'],
        CustomerOrderDataGrid::class => ['created_at'],
        RMADataGrid::class => ['created_at'],
        OrderTransactionDataGrid::class => ['created_at'],
    ];

    /**
     * Registers the single wildcard listener that applies `TARGETS` to
     * every matching DataGrid, every time Bagisto's own `DataGrid::
     * prepare()` finishes building that grid's columns.
     */
    public static function register(): void
    {
        Event::listen('datagrid.*.columns.prepare.after', function (string $eventName, array $payload) {
            $grid = $payload[0] ?? null;

            if (! is_object($grid)) {
                return;
            }

            $columnIndexes = static::TARGETS[get_class($grid)] ?? null;

            if ($columnIndexes === null) {
                return;
            }

            static::applyTo($grid, $columnIndexes);
        });
    }

    /**
     * Attaches a `Core::formatDate()`-backed closure to each column in
     * $grid whose index is in $columnIndexes - skipping any column that
     * doesn't exist (nothing to iterate) or already has a closure (never
     * overwritten). `core()->formatDate()` remains the single source of
     * truth for timezone resolution - this method never computes or
     * caches a timezone itself.
     *
     * @param  object  $grid  A `Webkul\DataGrid\DataGrid` instance (typed
     *                        as `object` to avoid this Platform class
     *                        importing the Webkul base class as a hard
     *                        dependency beyond the concrete grids already
     *                        listed in `TARGETS`).
     * @param  array<int, string>  $columnIndexes
     */
    public static function applyTo(object $grid, array $columnIndexes): void
    {
        foreach ($grid->getColumns() as $column) {
            if (! $column instanceof Column) {
                continue;
            }

            if (! in_array($column->getIndex(), $columnIndexes, true)) {
                continue;
            }

            if ($column->getClosure()) {
                continue;
            }

            $index = $column->getIndex();

            $column->setClosure(fn ($row) => core()->formatDate($row->{$index}));
        }
    }
}
