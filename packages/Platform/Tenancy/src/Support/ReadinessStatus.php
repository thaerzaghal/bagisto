<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-OPS-MONITORING-001. The machine-readable status a single
 * `platform:production:check` row resolves to - the typed counterpart to
 * the colored Symfony Console tag (`<info>PASS</info>` etc.) the console
 * table has always rendered. Introduced specifically so
 * `Platform\Tenancy\Services\ProductionMonitorRunner` (and any future
 * consumer) can inspect a check's real status without parsing console
 * markup or the rendered ASCII table - see `ReadinessCheckResult`.
 */
enum ReadinessStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Info = 'info';

    /**
     * PASS/INFO are never incidents; WARN/FAIL are - matches
     * `ProductionReadinessCheck::handle()`'s own existing exit-code rule
     * (FAIL blocks the deploy gate, WARN does not) applied one level up,
     * to "is this worth an operator's attention at all."
     */
    public function isIncident(): bool
    {
        return $this === self::Fail || $this === self::Warn;
    }
}
